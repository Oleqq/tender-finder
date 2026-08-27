<?php

namespace App\Http\Controllers;

use App\Services\LocalMvpOperatorService;
use App\Services\LocalMvpTenderWorkspaceService;
use App\Services\TenderGuruPreviewImportService;
use App\Tenders\TenderGuruPreviewException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class LocalMvpTenderGuruPreviewController extends Controller
{
    public function store(
        Request $request,
        LocalMvpOperatorService $operator,
        TenderGuruPreviewImportService $importer,
        LocalMvpTenderWorkspaceService $workspace,
    ): JsonResponse {
        abort_unless($operator->canUseWorkspace($request->user()), 404);

        $attributes = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        try {
            $result = $importer->import($attributes['query']);
        } catch (TenderGuruPreviewException) {
            throw ValidationException::withMessages([
                'query' => 'Не удалось получить данные preview-источника. Попробуйте позже или измените фразу.',
            ]);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'query' => 'Preview-источник временно недоступен. Попробуйте позже.',
            ]);
        }

        return response()->json([
            'preview' => [
                'items_seen' => $result->itemsSeen,
                'items_matched' => $result->itemsMatched,
                'items_created' => $result->itemsCreated,
                'matches_created' => $result->matchesCreated,
            ],
            'tenders' => $workspace->tendersForSourceExternalIds(
                $request->user(),
                'tenderguru_preview',
                $result->externalIds,
            ),
        ]);
    }
}
