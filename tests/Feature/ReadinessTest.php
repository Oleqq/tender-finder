<?php

it('keeps the detailed readiness endpoint hidden without its operator token', function () {
    config()->set('operations.readiness_token', 'operator-test-token');

    $this->getJson('/ops/readiness')->assertNotFound();
    $this->withToken('wrong-token')->getJson('/ops/readiness')->assertNotFound();
});
