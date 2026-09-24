<?php

namespace Database\States;

use App\Models\User;

class UserState
{
    public function __invoke(): void
    {
        if (User::query()->where('email', 'info@pixelarbeit.de')->exists()) {
            return;
        }

        User::query()->forceCreate([
            'name' => 'Dennis Koch',
            'email' => 'info@pixelarbeit.de',
            'email_verified_at' => now(),
            'password' => '{{ passwordHash }}',
        ]);
    }
}
