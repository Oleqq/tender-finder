<?php

use App\Enums\UserRole;

it('reserves the admin role for future administration', function () {
    expect(UserRole::Admin->value)->toBe('admin');
});
