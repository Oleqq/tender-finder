<?php

namespace App\Http\Controllers;

use App\Models\Tender;
use App\Services\EisTenderEnrichmentService;
use App\Services\LocalMvpOperatorService;
use App\Services\LocalMvpTenderWorkspaceService;
use App\Tenders\RssSourceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class LocalMvpTenderEnrichmentController extends Controller
{
    public function __invoke(
        Request $request,
        Tender $tender,
        LocalMvpOperatorService $operator,
        LocalMvpTenderWorkspaceService $workspace,
        EisTenderEnrichmentService $enrichment,
    ): JsonResponse {
        abort_unless($operator->canUseWorkspace($request->user()), 404);
        abort_unless($workspace->canAccessTender($request->user(), $tender), 404);

        try {
            $enrichment->enrich($tender);
        } catch (RssSourceException $exception) {
            throw ValidationException::withMessages([
                'tender' => match ($exception->codeName) {
                    'enrichment_not_supported' => 'Для этой карточки источник не поддерживает обогащение ЕИС.',
                    'invalid_enrichment_html' => 'ЕИС изменила формат публичной карточки. Данные не сохранены.',
                    default => 'ЕИС временно не отдала публичную карточку. Повторите позже.',
                },
            ]);
        }

        return response()->json([
            'tender' => $workspace->tenderDetailFor($request->user(), $tender->refresh()),
        ]);
    }
}
