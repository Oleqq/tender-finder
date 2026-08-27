<?php

namespace App\Http\Controllers;

use App\Services\LocalMvpOperatorService;
use App\Services\MvpOperatorWorkspaceResponseService;
use Illuminate\Http\Request;
use Inertia\Response;

class MvpWorkspaceController extends Controller
{
    public function show(
        Request $request,
        LocalMvpOperatorService $operator,
        MvpOperatorWorkspaceResponseService $workspace,
    ): Response {
        abort_unless($operator->canUseWorkspace($request->user()), 403);

        return $workspace->renderFor($request->user());
    }
}
