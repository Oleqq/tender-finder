<?php

namespace App\Http\Controllers;

use App\Services\LocalMvpOperatorService;
use App\Services\MvpOperatorWorkspaceResponseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RemoteMvpOperatorSessionController extends Controller
{
    public function store(
        Request $request,
        LocalMvpOperatorService $operator,
        MvpOperatorWorkspaceResponseService $response,
    ): RedirectResponse {
        abort_unless($operator->isRemoteEnabled(), 404);

        return $response->open($request);
    }
}
