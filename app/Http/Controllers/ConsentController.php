<?php

namespace App\Http\Controllers;

use App\Enums\ConsentDocument;
use App\Services\ConsentService;
use App\Services\LegalDocumentsUnavailableException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConsentController extends Controller
{
    public function store(Request $request, ConsentService $consents): JsonResponse
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
        } catch (LegalDocumentsUnavailableException) {
            return response()->json(['message' => 'Юридические документы пока не опубликованы.'], 503);
        }

        return response()->json(['status' => 'accepted']);
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
