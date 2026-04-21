<?php

namespace App\Support;

class AvatarGenerator
{
    public static function defaultFor(?string $name, ?string $email, mixed $id = null): string
    {
        $seed = trim((string) ($name ?: $email ?: $id ?: 'user'));
        $encodedSeed = rawurlencode($seed);

        return url('/avatar/default/'.$encodedSeed.'.svg');
    }
}
