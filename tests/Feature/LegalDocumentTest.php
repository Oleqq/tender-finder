<?php

use Inertia\Testing\AssertableInertia as Assert;

it('keeps legal document routes unavailable until publication is explicitly approved', function () {
    config()->set('tender.legal.documents_published', false);

    $this->get('/offer')->assertServiceUnavailable();
    $this->get('/privacy')->assertServiceUnavailable();
});

it('renders public legal document routes only after the publication gate is enabled', function () {
    config()->set('tender.legal.documents_published', true);
    config()->set('tender.legal.offer_version', 'test-offer-v1');
    config()->set('tender.legal.privacy_version', 'test-privacy-v1');

    $this->get('/offer')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LegalDocument')
            ->where('document.type', 'offer')
            ->where('document.version', 'test-offer-v1'));

    $this->get('/privacy')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('LegalDocument')
            ->where('document.type', 'privacy')
            ->where('document.version', 'test-privacy-v1'));
});
