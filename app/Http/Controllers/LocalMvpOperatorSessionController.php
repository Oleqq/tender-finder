<?php

namespace App\Http\Controllers;

use App\Services\LocalMvpOperatorService;
use App\Services\MvpOperatorWorkspaceResponseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocalMvpOperatorSessionController extends Controller
{
    public function store(
        Request $request,
        LocalMvpOperatorService $operator,
        MvpOperatorWorkspaceResponseService $response,
    ): RedirectResponse {
        abort_unless($operator->isLocalEnabled(), 404);

        return $response->open($request);
    }
}
