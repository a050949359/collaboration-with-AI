<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class AvatarController extends Controller
{
    public function default(string $seed): Response
    {
        $normalizedSeed = trim($seed);
        $initial = strtoupper(mb_substr($normalizedSeed !== '' ? $normalizedSeed : 'U', 0, 1));

        $hash = md5($normalizedSeed);
        $colorA = '#'.substr($hash, 0, 6);
        $colorB = '#'.substr($hash, 6, 6);

        $safeInitial = htmlspecialchars($initial, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="128" height="128" viewBox="0 0 128 128" role="img" aria-label="avatar">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{$colorA}" />
      <stop offset="100%" stop-color="{$colorB}" />
    </linearGradient>
  </defs>
  <rect width="128" height="128" rx="20" fill="url(#g)" />
  <text x="50%" y="54%" text-anchor="middle" dominant-baseline="middle" font-family="Arial, sans-serif" font-size="56" font-weight="700" fill="#ffffff">{$safeInitial}</text>
</svg>
SVG;

        return response($svg, 200)
            ->header('Content-Type', 'image/svg+xml')
            ->header('Cache-Control', 'public, max-age=604800');
    }
}
