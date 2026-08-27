<?php

namespace App\Http\Controllers;

use App\Services\LocalMvpOperatorService;
use App\Services\MvpOperatorWorkspaceResponseService;
use Illuminate\Http\Request;
use Inertia\Response;

class RemoteMvpOperatorSessionController extends Controller
{
    public function store(
        Request $request,
        LocalMvpOperatorService $operator,
        MvpOperatorWorkspaceResponseService $response,
    ): Response {
        abort_unless($operator->isRemoteEnabled(), 404);

        return $response->open($request);
    }
}
