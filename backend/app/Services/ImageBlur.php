<?php

namespace App\Services;

use GdImage;
use RuntimeException;

/**
 * Privacy: hides people in camera photos before they are saved. Each person
 * box (from /waste `people`) is pixelated hard (blocks of about 1/8 of the
 * box, at least 12 px) and then blurred, so faces cannot be recognised.
 * The result is always a JPEG.
 */
class ImageBlur
{
    /**
     * @param  list<array{box: list<float|int>}>  $people  boxes [x1, y1, x2, y2] in image pixels
     */
    public function blurPeople(string $bytes, array $people, int $quality = 85): string
    {
        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof GdImage) {
            throw new RuntimeException('The photo could not be decoded.');
        }

        $width = imagesx($image);
        $height = imagesy($image);

        foreach ($people as $person) {
            [$x1, $y1, $x2, $y2] = array_map('floatval', $person['box'] + [0, 0, 0, 0]);
            // A small margin around the box: a hand or hair outside it still counts.
            $marginX = ($x2 - $x1) * 0.08;
            $marginY = ($y2 - $y1) * 0.05;
            $x = (int) max(0, floor($x1 - $marginX));
            $y = (int) max(0, floor($y1 - $marginY));
            $w = (int) min($width - $x, ceil($x2 + $marginX) - $x);
            $h = (int) min($height - $y, ceil($y2 + $marginY) - $y);

            if ($w > 1 && $h > 1) {
                $this->pixelate($image, $x, $y, $w, $h);
            }
        }

        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    private function pixelate(GdImage $image, int $x, int $y, int $w, int $h): void
    {
        $block = max(12, (int) round(min($w, $h) / 8));
        $smallW = max(1, intdiv($w, $block));
        $smallH = max(1, intdiv($h, $block));

        $small = imagecreatetruecolor($smallW, $smallH);
        imagecopyresampled($small, $image, 0, 0, $x, $y, $smallW, $smallH, $w, $h);
        imagecopyresized($image, $small, $x, $y, 0, 0, $w, $h, $smallW, $smallH);

        // Soften the block edges inside the box only.
        $region = imagecreatetruecolor($w, $h);
        imagecopy($region, $image, 0, 0, $x, $y, $w, $h);
        for ($i = 0; $i < 3; $i++) {
            imagefilter($region, IMG_FILTER_GAUSSIAN_BLUR);
        }
        imagecopy($image, $region, $x, $y, 0, 0, $w, $h);
    }
}
