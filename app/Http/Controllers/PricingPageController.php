<?php

namespace App\Http\Controllers;

use App\Services\PricingPageRenderer;
use Symfony\Component\HttpFoundation\Response;

final class PricingPageController extends Controller
{
    public function __invoke(PricingPageRenderer $renderer, string $theme = 'light'): Response
    {
        return response($renderer->render($theme), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
