<?php

namespace App\Http\Controllers;

use App\Services\LegalDocumentsUnavailableException;
use App\Services\TrialAlreadyUsedException;
use App\Services\TrialConsentRequiredException;
use App\Services\TrialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrialController extends Controller
{
    public function store(Request $request, TrialService $trials): JsonResponse
    {
        try {
            $access = $trials->start($request->user());
        } catch (TrialAlreadyUsedException) {
            return response()->json(['message' => 'Пробный период уже был использован.'], 422);
        } catch (TrialConsentRequiredException) {
            return response()->json(['message' => 'Сначала примите актуальные юридические документы.'], 422);
        } catch (LegalDocumentsUnavailableException) {
            return response()->json(['message' => 'Юридические документы пока не опубликованы.'], 503);
        }

        return response()->json(['access' => $access->toArray()], 201);
    }
}
