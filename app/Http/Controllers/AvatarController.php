<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Multiavatar;

class AvatarController extends Controller
{
    public function default(string $seed): Response
    {
        $multiavatar = new Multiavatar();
        $svg = $multiavatar(trim($seed) ?: 'user', null, null);

        return response($svg, 200)
            ->header('Content-Type', 'image/svg+xml')
            ->header('Cache-Control', 'public, max-age=604800');
    }
}
