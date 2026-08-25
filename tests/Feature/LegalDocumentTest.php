<?php

use Inertia\Testing\AssertableInertia as Assert;

it('keeps legal document routes unavailable until publication is explicitly approved', function () {
    config()->set('tender.legal.documents_published', false);

    $this->get('/offer')->assertServiceUnavailable();
    $this->get('/privacy')->assertServiceUnavailable();
});

it('renders public legal document routes only after the publication gate is enabled', function () {
    config()->set('tender.legal.documents_published', true);

    $this->get('/offer')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LegalDocument')
            ->where('document.type', 'offer'));

    $this->get('/privacy')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LegalDocument')
            ->where('document.type', 'privacy'));
});
