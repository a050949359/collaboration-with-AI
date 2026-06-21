<?php

namespace App\Enums;

/**
 * 圖片用途/可見性。
 *
 * - Public:正常展示素材(如 gacha 卡圖),存 public disk,可由 URL 直接存取。
 * - Private:個人敏感 / NSFW,存 private disk,須登入經 controller 鑑權出圖。
 */
enum ImageVisibility: string
{
    case Public = 'public';
    case Private = 'private';
}
