<?php

namespace App\Services;

use App\Models\Detection;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Stored camera photos and their small variants. Each frame keeps the original
 * (camera/{node}/{date}/{time}.jpg) plus a display JPEG (about 960 px wide,
 * quality 80: `{time}_display.jpg`) and a thumbnail (about 320 px wide,
 * quality 75: `{time}_thumb.jpg`). The UI only needs the variants.
 */
class CameraImages
{
    public const SIZES = [
        'display' => ['width' => 960, 'quality' => 80],
        'thumb' => ['width' => 320, 'quality' => 75],
    ];

    public static function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('namaa.camera.disk'));
    }

    /** Path of a variant next to the original; `original` (or an unknown size) is the original. */
    public static function variantPath(string $path, string $size): string
    {
        if (! isset(self::SIZES[$size])) {
            return $path;
        }

        return preg_replace('/\.jpg$/i', "_{$size}.jpg", $path) ?? $path;
    }

    /** Saves the original and both variants. */
    public function store(string $path, string $jpeg): void
    {
        $disk = self::disk();
        $disk->put($path, $jpeg);

        foreach ($this->variants($jpeg) as $size => $bytes) {
            $disk->put(self::variantPath($path, $size), $bytes);
        }
    }

    /**
     * @return array<string, string> size => JPEG bytes (never wider than the source)
     */
    public function variants(string $jpeg): array
    {
        $source = @imagecreatefromstring($jpeg);
        if (! $source instanceof GdImage) {
            throw new RuntimeException('The photo could not be decoded.');
        }

        $out = [];
        foreach (self::SIZES as $size => $spec) {
            $out[$size] = $this->resize($source, $spec['width'], $spec['quality']);
        }

        return $out;
    }

    private function resize(GdImage $source, int $targetWidth, int $quality): string
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $width = min($w, $targetWidth);
        $height = max(1, (int) round($h * $width / $w));

        $image = imagecreatetruecolor($width, $height);
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, $w, $h);

        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    /** Deletes the original and the variants. Returns the number of files removed. */
    public function delete(Detection|string|null $photo): int
    {
        $path = $photo instanceof Detection ? $photo->photo_path : $photo;
        if (! $path) {
            return 0;
        }

        $disk = self::disk();
        $n = 0;
        foreach ([$path, ...array_map(fn ($s) => self::variantPath($path, $s), array_keys(self::SIZES))] as $file) {
            if ($disk->exists($file)) {
                $disk->delete($file);
                $n++;
            }
        }

        return $n;
    }

    /** Creates the variants that are missing for a stored frame. Returns the number created. */
    public function backfill(Detection $detection): int
    {
        $disk = self::disk();
        if (! $detection->photo_path || ! $disk->exists($detection->photo_path)) {
            return 0;
        }

        $missing = array_filter(array_keys(self::SIZES), fn ($s) => ! $disk->exists(self::variantPath($detection->photo_path, $s)));
        if ($missing === []) {
            return 0;
        }

        $variants = $this->variants((string) $disk->get($detection->photo_path));
        foreach ($missing as $size) {
            $disk->put(self::variantPath($detection->photo_path, $size), $variants[$size]);
        }

        return count($missing);
    }
}
