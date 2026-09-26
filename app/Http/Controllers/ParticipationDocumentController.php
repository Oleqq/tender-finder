<?php

namespace App\Http\Controllers;

use App\Models\ParticipationDocument;
use App\Models\ParticipationDocumentVersion;
use App\Models\Team;
use App\Models\Tender;
use App\Models\TenderParticipation;
use App\Models\User;
use App\Services\ParticipationDocumentService;
use App\Services\TeamActivityService;
use App\Services\TeamWorkspaceService;
use App\Services\TenderWorkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ParticipationDocumentController extends Controller
{
    private const TYPES = ['requirement', 'proposal', 'qualification', 'security', 'contract', 'other'];

    private const STATUSES = ['needed', 'ready', 'replace_required'];

    public function store(Request $request, Tender $tender, TenderWorkService $work, ParticipationDocumentService $documents): JsonResponse
    {
        [$user, $team, $participation] = $this->context($request, $tender, $work, true);
        $data = $request->validate($this->rules(false));
        abort_if($participation->documents()->count() >= 100, 422, 'В заявке допускается до 100 документов.');
        $assignee = app(TeamWorkspaceService::class)->assignee($user, $team, $data['assignee_id'] ?? null);
        $checklist = $this->checklist($participation, $data['checklist_item_id'] ?? null);
        $url = $this->url($data['source_url'] ?? null);
        abort_if($request->hasFile('file') && $url !== null, 422, 'Выберите файл или ссылку, но не оба варианта.');
        $source = $documents->storeSource($participation, $user, $request->file('file'), $url);

        try {
            $document = DB::transaction(function () use ($participation, $data, $assignee, $checklist, $source): ParticipationDocument {
                $document = $participation->documents()->create([
                    'title' => trim($data['title']), 'type' => $data['type'], 'status' => $data['status'],
                    'assignee_id' => $assignee, 'checklist_item_id' => $checklist,
                ]);
                if ($source !== null) {
                    $document->versions()->create($source);
                }

                return $document;
            });
        } catch (Throwable $exception) {
            $documents->discard($source);
            throw $exception;
        }

        $this->activity($team, $user, 'document_created', $participation, $document);

        return response()->json(['documents' => $documents->present($participation, $team)], 201);
    }

    public function update(Request $request, Tender $tender, ParticipationDocument $document, TenderWorkService $work, ParticipationDocumentService $documents): JsonResponse
    {
        [$user, $team, $participation] = $this->context($request, $tender, $work, true);
        $this->owned($document, $participation);
        $data = $request->validate($this->rules(false, true));
        abort_if($document->version !== (int) $data['version'], 409, 'Документ изменён в другой вкладке. Обновите страницу.');
        $document->fill([
            'title' => trim($data['title']), 'type' => $data['type'], 'status' => $data['status'],
            'assignee_id' => app(TeamWorkspaceService::class)->assignee($user, $team, $data['assignee_id'] ?? null),
            'checklist_item_id' => $this->checklist($participation, $data['checklist_item_id'] ?? null),
            'version' => $document->version + 1,
        ])->save();
        $this->activity($team, $user, 'document_updated', $participation, $document);

        return response()->json(['documents' => $documents->present($participation, $team)]);
    }

    public function replace(Request $request, Tender $tender, ParticipationDocument $document, TenderWorkService $work, ParticipationDocumentService $documents): JsonResponse
    {
        [$user, $team, $participation] = $this->context($request, $tender, $work, true);
        $this->owned($document, $participation);
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,txt,zip', 'required_without:source_url'],
            'source_url' => ['nullable', 'url:http,https', 'max:2000', 'required_without:file'],
        ]);
        abort_if($document->version !== (int) $data['version'], 409, 'Документ изменён в другой вкладке. Обновите страницу.');
        $url = $this->url($data['source_url'] ?? null);
        abort_if($request->hasFile('file') && $url !== null, 422, 'Выберите файл или ссылку, но не оба варианта.');
        $source = $documents->storeSource($participation, $user, $request->file('file'), $url);

        try {
            DB::transaction(function () use ($document, $source): void {
                ParticipationDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail()->update([
                    'status' => 'ready', 'version' => $document->version + 1,
                ]);
                $document->versions()->create($source ?? []);
            });
        } catch (Throwable $exception) {
            $documents->discard($source);
            throw $exception;
        }
        $this->activity($team, $user, 'document_replaced', $participation, $document);

        return response()->json(['documents' => $documents->present($participation, $team)]);
    }

    public function archive(Request $request, Tender $tender, ParticipationDocument $document, TenderWorkService $work, ParticipationDocumentService $documents): JsonResponse
    {
        [$user, $team, $participation] = $this->context($request, $tender, $work, true);
        $this->owned($document, $participation);
        $data = $request->validate(['archived' => ['required', 'boolean'], 'version' => ['required', 'integer', 'min:1']]);
        abort_if($document->version !== (int) $data['version'], 409, 'Документ изменён в другой вкладке. Обновите страницу.');
        $document->update(['archived_at' => $data['archived'] ? now() : null, 'version' => $document->version + 1]);
        $this->activity($team, $user, $data['archived'] ? 'document_archived' : 'document_restored', $participation, $document);

        return response()->json(['documents' => $documents->present($participation, $team)]);
    }

    public function destroy(Request $request, Tender $tender, ParticipationDocument $document, TenderWorkService $work, ParticipationDocumentService $documents): JsonResponse
    {
        [$user, $team, $participation] = $this->context($request, $tender, $work, true);
        $this->owned($document, $participation);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        abort_if($document->version !== (int) $data['version'], 409, 'Документ изменён в другой вкладке. Обновите страницу.');
        abort_if($document->archived_at === null, 422, 'Сначала переместите документ в архив.');
        $documents->deleteFiles($document);
        $id = $document->id;
        $document->delete();
        $this->activity($team, $user, 'document_deleted', $participation, null, $id);

        return response()->json(['documents' => $documents->present($participation, $team)]);
    }

    public function download(Request $request, Tender $tender, ParticipationDocument $document, ParticipationDocumentVersion $version, TenderWorkService $work): StreamedResponse
    {
        [, , $participation] = $this->context($request, $tender, $work, false);
        $this->owned($document, $participation);
        abort_unless($version->document_id === $document->id && $version->source_kind === 'file' && $version->storage_path !== null, 404);
        $disk = Storage::disk($version->disk ?? 'local');
        abort_unless($disk->exists($version->storage_path), 404);

        return $disk->download($version->storage_path, $version->original_name ?? 'document');
    }

    /** @return array{User, Team|null, TenderParticipation} */
    private function context(Request $request, Tender $tender, TenderWorkService $work, bool $write): array
    {
        $user = $request->user();
        abort_if($user === null, 401);
        $team = app(TeamWorkspaceService::class)->context($request, $write);
        $work->authorize($user, $tender, $team);
        $participation = $work->participations($user, $team)->where('tender_id', $tender->id)->firstOrFail();

        return [$user, $team, $participation];
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(bool $sourceRequired, bool $updating = false): array
    {
        return [
            'title' => ['required', 'string', 'max:240'],
            'type' => ['required', Rule::in(self::TYPES)],
            'status' => ['required', Rule::in(self::STATUSES)],
            'assignee_id' => ['nullable', 'integer'],
            'checklist_item_id' => ['nullable', 'integer'],
            'version' => $updating ? ['required', 'integer', 'min:1'] : ['sometimes', 'integer'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:pdf,doc,docx,xls,xlsx,png,jpg,jpeg,txt,zip', ...($sourceRequired ? ['required_without:source_url'] : [])],
            'source_url' => ['nullable', 'url:http,https', 'max:2000', ...($sourceRequired ? ['required_without:file'] : [])],
        ];
    }

    private function checklist(TenderParticipation $participation, mixed $id): ?int
    {
        if ($id === null) {
            return null;
        }
        abort_unless(is_numeric($id) && $participation->items()->whereKey((int) $id)->exists(), 422, 'Задача должна принадлежать этой заявке.');

        return (int) $id;
    }

    private function url(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function owned(ParticipationDocument $document, TenderParticipation $participation): void
    {
        abort_unless($document->participation_id === $participation->id, 404);
    }

    private function activity(?Team $team, User $user, string $action, TenderParticipation $participation, ?ParticipationDocument $document, ?int $id = null): void
    {
        if ($team !== null) {
            app(TeamActivityService::class)->record($team, $user, $action, [
                'participation_id' => $participation->id, 'document_id' => $document !== null ? $document->id : $id,
            ]);
        }
    }
}
