<?php

namespace Tests\Feature\Camera;

use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\User;
use App\Support\Namaa;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Shared set-up for the camera tests: a company with a park, a camera node,
 * a fake camera at http://camera.test and a fake vision service. Nothing real
 * is called (Http::fake), photos go to a fake disk.
 */
trait CameraTestHelpers
{
    protected Company $company;

    protected User $admin;

    protected Park $park;

    protected Node $camera;

    /** Items /waste "finds" in the next frames (a queue; the last one repeats). @var list<int> */
    protected array $litterQueue = [0];

    /** @var list<array{confidence: float, box: list<float>}> */
    protected array $people = [];

    protected bool $cameraUp = true;

    protected bool $visionUp = true;

    protected function setUpCamera(): void
    {
        Storage::fake('local');
        config(['namaa.camera.url' => 'http://camera.test', 'namaa.vision.url' => 'http://vision.test']);

        $this->company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $this->company->id]);
        $this->park = Park::factory()->create(['company_id' => $this->company->id]);
        $this->camera = Node::factory()->camera()->create(['park_id' => $this->park->id, 'last_seen_at' => null]);

        // Faked once; the closures read the flags, so a test can take a service down later.
        Http::fake([
            'camera.test/*' => fn () => $this->cameraUp
                ? Http::response(self::jpeg(), 200, ['Content-Type' => 'image/jpeg'])
                : Http::failedConnection(),
            'vision.test/waste' => fn () => $this->visionUp
                ? Http::response($this->wasteResult())
                : Http::response(['detail' => 'down'], 500),
        ]);
    }

    protected function fakeServices(bool $cameraUp = true, bool $visionUp = true): void
    {
        $this->cameraUp = $cameraUp;
        $this->visionUp = $visionUp;
    }

    /** @return array<string, mixed> */
    protected function wasteResult(): array
    {
        $count = count($this->litterQueue) > 1 ? array_shift($this->litterQueue) : $this->litterQueue[0];
        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = ['label' => 'bottle', 'confidence' => 0.8, 'box' => [10.0 + $i * 20, 10.0, 25.0 + $i * 20, 40.0]];
        }

        return ['items' => $items, 'count' => $count, 'people' => $this->people, 'model' => 'yolo26n.pt',
            'inference_ms' => 120, 'image_w' => 160, 'image_h' => 120];
    }

    /** @param list<int> $counts items per frame */
    protected function frames(array $counts): void
    {
        foreach ($counts as $count) {
            $this->litterQueue = [$count];
            // One capture interval (5 min, 10 s demo), so every run takes a frame.
            $this->travel(Namaa::timing('camera_capture_seconds'))->seconds();
            $this->artisan('namaa:capture')->assertSuccessful();
        }
    }

    /** A real JPEG: a black and white checkerboard (high contrast, so a blur is measurable). */
    public static function jpeg(int $w = 160, int $h = 120): string
    {
        $image = imagecreatetruecolor($w, $h);
        $white = imagecolorallocate($image, 255, 255, 255);
        for ($x = 0; $x < $w; $x += 4) {
            for ($y = 0; $y < $h; $y += 4) {
                if ((($x + $y) / 4) % 2 === 0) {
                    imagefilledrectangle($image, $x, $y, $x + 3, $y + 3, $white);
                }
            }
        }
        ob_start();
        imagejpeg($image, null, 95);

        return (string) ob_get_clean();
    }

    /** Standard deviation of the grey values in a rectangle of a JPEG. */
    protected static function contrast(string $jpeg, int $x1, int $y1, int $x2, int $y2): float
    {
        $image = imagecreatefromstring($jpeg);
        $values = [];
        for ($x = $x1; $x < $x2; $x++) {
            for ($y = $y1; $y < $y2; $y++) {
                $rgb = imagecolorat($image, $x, $y);
                $values[] = (($rgb >> 16) & 0xFF) * 0.3 + (($rgb >> 8) & 0xFF) * 0.59 + ($rgb & 0xFF) * 0.11;
            }
        }
        $mean = array_sum($values) / count($values);

        return sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / count($values));
    }
}
