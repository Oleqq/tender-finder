<?php

namespace App\Services\CostGap;

use App\Models\ParticipationDocument;
use App\Models\ParticipationDocumentVersion;
use App\Models\TenderParticipation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Throwable;

final class SourceReader
{
    /** @return array<string, mixed> */
    public function text(string $text, string $title): array
    {
        try {
            return ['kind' => 'text', 'title' => $title, 'segments' => (new DocumentParser)->segments($text),
                'warnings' => ['Пользовательский текст: полноту и соответствие оригиналу нужно проверить.']];
        } catch (DocumentParseException $error) {
            throw ValidationException::withMessages(['source' => $error->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    public function upload(UploadedFile $file): array
    {
        return $this->file($file->getPathname(), $file->getClientOriginalName());
    }

    /** @return array<string, mixed> */
    public function document(TenderParticipation $participation, int $versionId): array
    {
        $version = ParticipationDocumentVersion::query()->whereHas('document', fn ($q) => $q->where('participation_id', $participation->id)->whereNull('archived_at'))->findOrFail($versionId);
        $document = $version->document;
        abort_unless($document->versions()->latest('id')->value('id') === $version->id, 409, 'Выберите актуальную версию документа.');
        abort_unless($version->source_kind === 'file' && $version->disk === 'local' && $version->storage_path !== null, 422,
            'Для ссылки загрузите PDF, DOCX или TXT: внешние адреса автоматически не скачиваются.');
        $source = $this->file(Storage::disk('local')->path($version->storage_path), $version->original_name ?? 'Документ');
        $source['kind'] = 'document';
        $source['document_id'] = $document->id;
        $source['version_id'] = $version->id;
        $source['document_version'] = $document->version;

        return $source;
    }

    /** @param array<string, mixed> $source */
    public function current(array $source, TenderParticipation $participation, bool $lock = false): bool
    {
        if (($source['kind'] ?? '') !== 'document') {
            return true;
        }
        $query = ParticipationDocument::query()->where('participation_id', $participation->id)->whereNull('archived_at')->whereKey($source['document_id']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $document = $query->first();

        return $document !== null && $document->version === $source['document_version']
            && $document->versions()->latest('id')->value('id') === $source['version_id'];
    }

    /** @return array<string, mixed> */
    private function file(string $path, string $name): array
    {
        $name = mb_substr(basename(str_replace('\\', '/', $name)), 0, 180);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (! in_array($extension, ['txt', 'docx', 'pdf'], true) || ! is_file($path) || filesize($path) > 5 * 1024 * 1024) {
            throw ValidationException::withMessages(['source' => 'Поддерживаются PDF с текстом, DOCX и TXT до 5 МБ.']);
        }
        $process = new Process([PHP_BINARY, '-d', 'memory_limit=96M', '-d', 'max_execution_time=10', '-d', 'display_errors=0',
            '-d', 'log_errors=0', '-d', 'allow_url_fopen=0', resource_path('scripts/cost-document.php'), $path, $extension]);
        $process->setTimeout(15);
        try {
            $process->run();
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages(['source' => 'Не удалось обработать документ в пределах лимита. Вставьте нужный текст или загрузите небольшой раздел.']);
        }
        if (! $process->isSuccessful() || ($result['ok'] ?? false) !== true) {
            throw ValidationException::withMessages(['source' => $result['message'] ?? 'Не удалось прочитать документ. Вставьте текст вручную.']);
        }

        return ['kind' => 'file', 'title' => $name, ...$result['data']];
    }
}
