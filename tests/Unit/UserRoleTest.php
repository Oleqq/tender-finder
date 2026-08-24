<?php

use App\Enums\UserRole;

it('has only subscriber and server-assigned super-admin roles', function () {
    expect(UserRole::cases())
        ->toHaveCount(2)
        ->and(UserRole::Subscriber->value)->toBe('subscriber')
        ->and(UserRole::SuperAdmin->value)->toBe('super_admin');
});
