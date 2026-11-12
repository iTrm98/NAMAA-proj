<?php

namespace Tests\Feature\Camera;

use App\Models\Company;
use App\Models\Detection;
use App\Models\Node;
use App\Models\Park;
use App\Models\User;
use App\Services\CameraImages;
use App\Services\CameraPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Phase F3: display and thumbnail variants, cache headers, backfill. */
class CameraSizesTest extends TestCase
{
    use CameraTestHelpers;
    use RefreshDatabase;

    private Node $simCam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCamera();
        $simPark = Park::factory()->simulated()->create(['company_id' => $this->company->id]);
        $this->simCam = Node::factory()->camera()->simulated()->create(['park_id' => $simPark->id, 'name' => 'Sim Cam', 'last_seen_at' => null]);
    }

    private function frame(int $w = 1600, int $h = 1200): Detection
    {
        $this->litterQueue = [1];

        return app(CameraPipeline::class)->process($this->simCam, self::jpeg($w, $h), 'upload');
    }

    private function width(string $path): int
    {
        return imagesx(imagecreatefromstring(Storage::disk('local')->get($path)));
    }

    public function test_a_capture_stores_the_original_a_display_and_a_thumbnail(): void
    {
        $d = $this->frame();

        $this->assertSame(1600, $this->width($d->photo_path));
        $this->assertSame(960, $this->width(CameraImages::variantPath($d->photo_path, 'display')));
        $this->assertSame(320, $this->width(CameraImages::variantPath($d->photo_path, 'thumb')));
    }

    public function test_a_small_photo_is_never_enlarged(): void
    {
        $d = $this->frame(200, 150);

        $this->assertSame(200, $this->width(CameraImages::variantPath($d->photo_path, 'display')));
        $this->assertSame(200, $this->width(CameraImages::variantPath($d->photo_path, 'thumb')));
    }

    public function test_the_resource_has_thumb_and_display_urls_and_keeps_image_url(): void
    {
        $d = $this->frame();

        $this->asUser($this->admin)->getJson("/api/admin/parks/{$this->simCam->park_id}/detections")
            ->assertOk()
            ->assertJsonPath('data.0.image_url', "/admin/detections/{$d->id}/image")
            ->assertJsonPath('data.0.thumb_url', "/admin/detections/{$d->id}/image?size=thumb")
            ->assertJsonPath('data.0.display_url', "/admin/detections/{$d->id}/image?size=display");
    }

    public function test_image_sizes_cache_headers_and_etag(): void
    {
        $d = $this->frame();

        $thumb = $this->asUser($this->admin)->get("/api/admin/detections/{$d->id}/image?size=thumb");
        $thumb->assertOk();
        $this->assertSame($this->width(CameraImages::variantPath($d->photo_path, 'thumb')), imagesx(imagecreatefromstring($thumb->streamedContent())));
        $this->assertStringContainsString('immutable', $thumb->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=31536000', $thumb->headers->get('Cache-Control'));
        $etag = $thumb->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $this->asUser($this->admin)->get("/api/admin/detections/{$d->id}/image?size=thumb", ['If-None-Match' => $etag])->assertStatus(304);

        $original = $this->asUser($this->admin)->get("/api/admin/detections/{$d->id}/image");
        $this->assertSame(1600, imagesx(imagecreatefromstring($original->streamedContent())));

        $this->asUser($this->admin)->get("/api/admin/detections/{$d->id}/image?size=huge")->assertStatus(422);
    }

    public function test_a_missing_variant_falls_back_to_the_original(): void
    {
        $d = $this->frame();
        Storage::disk('local')->delete(CameraImages::variantPath($d->photo_path, 'thumb'));

        $r = $this->asUser($this->admin)->get("/api/admin/detections/{$d->id}/image?size=thumb");
        $r->assertOk();
        $this->assertSame(1600, imagesx(imagecreatefromstring($r->streamedContent())));
    }

    public function test_another_companys_image_is_404_and_a_guest_is_401(): void
    {
        $d = $this->frame();
        $other = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id]);

        $this->asUser($other)->get("/api/admin/detections/{$d->id}/image?size=thumb")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Accept' => 'application/json'])->flushHeaders()->get("/api/admin/detections/{$d->id}/image")->assertUnauthorized();
    }

    public function test_backfill_creates_missing_variants_and_is_idempotent(): void
    {
        $d = $this->frame();
        $disk = Storage::disk('local');
        $disk->delete(CameraImages::variantPath($d->photo_path, 'thumb'));
        $disk->delete(CameraImages::variantPath($d->photo_path, 'display'));

        $this->artisan('namaa:camera:backfill-sizes')->expectsOutputToContain('Created 2 files for 1 frames')->assertSuccessful();
        $this->assertTrue($disk->exists(CameraImages::variantPath($d->photo_path, 'thumb')));
        $this->assertTrue($disk->exists(CameraImages::variantPath($d->photo_path, 'display')));
        $this->artisan('namaa:camera:backfill-sizes')->expectsOutputToContain('Created 0 files')->assertSuccessful();
    }

    public function test_pruning_removes_the_variants_too(): void
    {
        $d = $this->frame();
        $d->forceFill(['captured_at' => now()->subDays(10)])->save();

        app(CameraPipeline::class)->prune();

        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
