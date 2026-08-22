<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renders the Tender Finder start page', function () {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
});
