<?php

it('returns an application health status', function () {
    $this->getJson('/health')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ok',
            'application' => config('app.name'),
        ]);
});
