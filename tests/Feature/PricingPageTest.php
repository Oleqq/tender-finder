<?php

use App\Models\Payment;
use App\Models\User;
use App\Services\PricingPageRenderer;

it('serves standalone pricing to visitors without creating an account or payment', function (string $path, string $filename) {
    $response = $this->get($path)->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

    $theme = str_contains($filename, '-dark') ? 'dark' : 'light';
    expect($response->getContent())->toBe(app(PricingPageRenderer::class)->render($theme))
        ->toBe(file_get_contents(base_path('docs/'.$filename)))
        ->not->toContain('{{', '/demo', 'checkout');
    expect(User::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
})->with([
    ['/pricing', 'subscription-offer-preview.html'],
    ['/pricing/light', 'subscription-offer-preview.html'],
    ['/pricing/dark', 'subscription-offer-preview-dark.html'],
]);

it('does not resolve arbitrary files through the pricing theme', function () {
    $this->get('/pricing/unknown')->assertNotFound();
    $this->get('/pricing/.env')->assertNotFound();
});
