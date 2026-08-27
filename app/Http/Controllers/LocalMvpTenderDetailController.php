<?php

namespace App\Http\Controllers;

use App\Models\Tender;
use App\Services\LocalMvpOperatorService;
use App\Services\LocalMvpTenderWorkspaceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LocalMvpTenderDetailController extends Controller
{
    public function show(
        Request $request,
        Tender $tender,
        LocalMvpOperatorService $operator,
        LocalMvpTenderWorkspaceService $workspace,
    ): Response {
        abort_unless($operator->canUseWorkspace($request->user()), 404);

        $detail = $workspace->tenderDetailFor($request->user(), $tender);
        abort_if($detail === null, 404);

        return Inertia::render('MvpTenderDetail', ['tender' => $detail]);
    }
}
