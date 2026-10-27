<?php

use App\Models\Payment;
use App\Models\User;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

it('serves standalone pricing to visitors without creating an account or payment', function (string $path, string $filename) {
    $response = $this->get($path)->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8');

    expect($response->baseResponse)->toBeInstanceOf(BinaryFileResponse::class);
    expect($response->baseResponse->getFile()->getRealPath())
        ->toBe(realpath(base_path('docs/'.$filename)));
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
