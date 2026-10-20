<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

it('returns a request id and logs a structured route without query secrets', function () {
    Log::spy();

    $response = $this->get('/open?screen=tenders&secret=never-log-this');

    $response->assertOk();
    $requestId = $response->headers->get('X-Request-ID');
    expect($requestId)->toMatch('/^[0-9a-f-]{36}$/');

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) use ($requestId) {
        return $level === 'info'
            && $message === 'http.request.completed'
            && $context['request_id'] === $requestId
            && $context['route'] === 'open'
            && $context['status'] === 200
            && $context['method'] === 'GET'
            && $context['duration_ms'] >= 0
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'never-log-this');
    })->once();
});

it('returns a request id for an exception response without logging unknown paths', function () {
    Log::spy();

    $response = $this->get('/missing-secret-path?secret=never-log-this');

    $response->assertNotFound();
    expect($response->headers->get('X-Request-ID'))->toMatch('/^[0-9a-f-]{36}$/');

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) {
        return $level === 'warning'
            && $message === 'http.request.completed'
            && $context['status'] === 404
            && $context['route'] === '[unmatched]'
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'missing-secret-path')
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'never-log-this');
    })->once();
});

it('correlates a server error with the same request id as its response', function () {
    Route::get('/forced-error', fn () => throw new RuntimeException('Test failure'));
    Log::spy();

    $response = $this->get('/forced-error?secret=never-log-this');

    $response->assertInternalServerError();
    $requestId = $response->headers->get('X-Request-ID');

    Log::shouldHaveReceived('log')->withArgs(function ($level, $message, $context) use ($requestId) {
        return $level === 'error'
            && $message === 'http.request.completed'
            && $context['request_id'] === $requestId
            && $context['route'] === 'forced-error'
            && $context['status'] === 500
            && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'never-log-this');
    })->once();
});
