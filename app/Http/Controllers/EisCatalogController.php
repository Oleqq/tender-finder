<?php

namespace App\Http\Controllers;

use App\Services\EisOkpd2CatalogService;
use App\Services\LocalMvpOperatorService;
use App\Tenders\RssSourceException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class EisCatalogController extends Controller
{
    public function okpd2(
        Request $request,
        LocalMvpOperatorService $operator,
        EisOkpd2CatalogService $catalog,
    ): JsonResponse {
        abort_unless($operator->canUseWorkspace($request->user()), 404);

        $attributes = $request->validate([
            'search' => ['required', 'string', 'min:3', 'max:120'],
        ]);

        try {
            $options = $catalog->search($attributes['search']);
        } catch (RssSourceException) {
            throw ValidationException::withMessages([
                'search' => 'Справочник ОКПД2 ЕИС временно недоступен. Повторите позже.',
            ]);
        }

        return response()->json(['options' => $options]);
    }
}
