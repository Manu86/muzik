<?php

declare(strict_types=1);

$sourcePath = dirname(__DIR__) . '/docs/icon-master.png';
$source = imagecreatefrompng($sourcePath);
if ($source === false) {
    fwrite(STDERR, "Impossible de charger {$sourcePath}\n");
    exit(1);
}

$sourceWidth = imagesx($source);
$sourceHeight = imagesy($source);

foreach ([192, 512] as $size) {
    $im = imagecreatetruecolor($size, $size);
    imagecopyresampled($im, $source, 0, 0, 0, 0, $size, $size, $sourceWidth, $sourceHeight);
    imagepng($im, __DIR__ . '/../public/assets/icon-' . $size . '.png');
    imagedestroy($im);
}

imagedestroy($source);
echo "Icônes générées depuis docs/icon-master.png\n";
