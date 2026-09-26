<?php

namespace App\Services;

use App\Models\ParticipationDocument;
use App\Models\ParticipationDocumentVersion;
use App\Models\Team;
use App\Models\TenderParticipation;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class ParticipationDocumentService
{
    /** @return list<array<string, mixed>> */
    public function present(TenderParticipation $participation, ?Team $team = null): array
    {
        return $participation->documents()->with(['versions' => fn ($query) => $query->with('uploader')->latest('id')])
            ->orderByRaw('archived_at IS NOT NULL')->orderBy('id')->get()
            ->map(fn (ParticipationDocument $document): array => [
                'id' => $document->id,
                'title' => $document->title,
                'type' => $document->type,
                'status' => $document->status,
                'assignee_id' => $document->assignee_id,
                'checklist_item_id' => $document->checklist_item_id,
                'version' => $document->version,
                'archived_at' => $document->archived_at?->toAtomString(),
                'versions' => $document->versions->map(fn (ParticipationDocumentVersion $version): array => [
                    'id' => $version->id,
                    'source_kind' => $version->source_kind,
                    'source_url' => $version->source_kind === 'link' ? $version->source_url : null,
                    'download_url' => $version->source_kind === 'file' ? route('tenders.documents.download', [
                        'tender' => $participation->tender_id,
                        'document' => $document->id,
                        'version' => $version->id,
                        ...($team ? ['team_id' => $team->id] : []),
                    ]) : null,
                    'original_name' => $version->original_name,
                    'mime_type' => $version->mime_type,
                    'size_bytes' => $version->size_bytes,
                    'uploaded_by' => $version->uploader?->name,
                    'created_at' => $version->created_at->toAtomString(),
                ])->all(),
            ])->all();
    }

    /** @return array<string, mixed>|null */
    public function storeSource(TenderParticipation $participation, User $user, ?UploadedFile $file, ?string $url): ?array
    {
        if ($file === null && $url === null) {
            return null;
        }

        if ($file !== null) {
            $extension = strtolower($file->getClientOriginalExtension());
            $name = Str::uuid()->toString().($extension === '' ? '' : '.'.$extension);
            $path = $file->storeAs('participation-documents/'.$participation->id, $name, 'local');
            if (! is_string($path)) {
                throw new RuntimeException('Не удалось сохранить файл.');
            }

            return [
                'uploaded_by' => $user->id,
                'source_kind' => 'file',
                'disk' => 'local',
                'storage_path' => $path,
                'original_name' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'created_at' => now(),
            ];
        }

        return [
            'uploaded_by' => $user->id,
            'source_kind' => 'link',
            'source_url' => $url,
            'created_at' => now(),
        ];
    }

    /** @param array<string, mixed>|null $source */
    public function discard(?array $source): void
    {
        if (($source['source_kind'] ?? null) === 'file' && is_string($source['storage_path'] ?? null)) {
            Storage::disk((string) ($source['disk'] ?? 'local'))->delete($source['storage_path']);
        }
    }

    public function deleteFiles(ParticipationDocument $document): void
    {
        $document->versions()->where('source_kind', 'file')->get()->each(function (ParticipationDocumentVersion $version): void {
            if ($version->storage_path !== null) {
                Storage::disk($version->disk ?? 'local')->delete($version->storage_path);
            }
        });
    }
}
