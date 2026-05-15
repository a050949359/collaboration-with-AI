<?php
$files = ['tmp/project05.png'];
$targetW = 1600;
$targetH = 900;

foreach ($files as $file) {
    if (!file_exists($file)) {
        echo "File not found: $file\n";
        continue;
    }

    $src = imagecreatefrompng($file);
    if (!$src) {
        echo "Failed to load image: $file\n";
        continue;
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    $srcAspect = $srcW / $srcH;
    $targetAspect = $targetW / $targetH;

    if ($srcAspect > $targetAspect) {
        // Source is wider
        $newH = $srcH;
        $newW = (int)($srcH * $targetAspect);
        $srcX = (int)(($srcW - $newW) / 2);
        $srcY = 0;
    } else {
        // Source is taller
        $newW = $srcW;
        $newH = (int)($srcW / $targetAspect);
        $srcX = 0;
        $srcY = (int)(($srcH - $newH) / 2);
    }

    $dst = imagecreatetruecolor($targetW, $targetH);
    
    // Support transparency for PNG
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    
    imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $targetW, $targetH, $newW, $newH);

    $base = pathinfo($file, PATHINFO_FILENAME);
    $webpOut = "tmp/{$base}-1600.webp";
    $pngOut = "tmp/{$base}-1600.png";

    imagewebp($dst, $webpOut, 78);
    imagepng($dst, $pngOut, 9);

    imagedestroy($src);
    imagedestroy($dst);
    echo "Processed $file -> $webpOut, $pngOut\n";
}
