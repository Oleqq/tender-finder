<?php

namespace App\Http\Controllers;

use App\Enums\ConsentDocument;
use App\Services\ConsentService;
use App\Services\LegalDocumentsUnavailableException;
use App\Services\TrialAlreadyUsedException;
use App\Services\TrialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConsentController extends Controller
{
    public function store(
        Request $request,
        ConsentService $consents,
        TrialService $trials,
    ): JsonResponse
    {
        $validated = $request->validate([
            'documents' => ['required', 'array', 'size:2'],
            'documents.*' => ['required', 'string', 'distinct', Rule::in(array_column(ConsentDocument::cases(), 'value'))],
        ]);

        $documents = array_map(
            fn (string $document): ConsentDocument => ConsentDocument::from($document),
            $validated['documents'],
        );

        if (count(array_diff(array_column(ConsentDocument::cases(), 'value'), $validated['documents'])) > 0) {
            throw ValidationException::withMessages([
                'documents' => 'Для продолжения нужно принять оферту и политику конфиденциальности.',
            ]);
        }

        try {
            $consents->acceptCurrent($request->user(), $documents, $request->ip());

            // Accepting the current documents is the only user action needed to
            // begin a trial. Keeping this on the server avoids a half-completed
            // flow if the Mini App closes between two browser requests.
            $access = $trials->start($request->user());
        } catch (LegalDocumentsUnavailableException) {
            return response()->json(['message' => 'Юридические документы пока не опубликованы.'], 503);
        } catch (TrialAlreadyUsedException) {
            // Re-sending the form must be harmless after a user has already
            // used their one trial; it is not an activation error.
            return response()->json(['status' => 'accepted']);
        }

        return response()->json([
            'status' => 'trial_started',
            'access' => $access->toArray(),
        ], 201);
    }

    public function revoke(Request $request, ConsentService $consents): JsonResponse
    {
        $validated = $request->validate([
            'document' => ['required', 'string', Rule::in(array_column(ConsentDocument::cases(), 'value'))],
        ]);

        try {
            $consents->revoke($request->user(), ConsentDocument::from($validated['document']), $request->ip());
        } catch (LegalDocumentsUnavailableException) {
            return response()->json(['message' => 'Юридические документы пока не опубликованы.'], 503);
        }

        return response()->json(['status' => 'revoked']);
    }
}
