<?php

namespace Tests\Feature\ParkTruth;

use App\Models\ActivityLog;
use App\Models\Company;
use App\Models\Park;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Sprint 6.2 Phase A (BE-4): outline v2 (boundary + green zone, traced, verified) and the cover photo. */
class ParkOutlineV2Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Park $park;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        $company = Company::factory()->create();
        $this->admin = User::factory()->admin()->create(['company_id' => $company->id]);
        $this->park = Park::factory()->create(['company_id' => $company->id, 'lat' => 21.415, 'lng' => 39.865, 'area_m2' => 6000]);
    }

    /** @return list<list<float>> closed square ring [lng, lat] */
    private function box(float $lng, float $lat, float $size): array
    {
        return [[$lng, $lat], [$lng + $size, $lat], [$lng + $size, $lat + $size], [$lng, $lat + $size], [$lng, $lat]];
    }

    private function boundary(): array
    {
        return ['type' => 'Polygon', 'coordinates' => [$this->box(39.864, 21.414, 0.002)]];
    }

    private function save(array $body, ?User $as = null)
    {
        return $this->asUser($as ?? $this->admin)->putJson("/api/admin/parks/{$this->park->id}/outline", $body);
    }

    public function test_boundary_is_traced_unverified_and_recomputes_area_and_centre(): void
    {
        $b = $this->boundary();
        $this->save(['boundary' => $b, 'imagery' => 'esri', 'checked' => false])->assertOk()
            ->assertJsonPath('data.geometry_source', 'traced')
            ->assertJsonPath('data.verification_status', 'unverified')
            ->assertJsonPath('warnings', [])
            ->assertJsonPath('data.boundary_area_m2', (int) round(Geo::area($b)));

        $park = $this->park->refresh();
        $this->assertSame('traced', $park->outline_source);
        $this->assertSame('Traced on Esri World Imagery in the NAMAA editor', $park->outline_reference);
        $this->assertNotNull($park->geometry_updated_at);
        $this->assertEqualsWithDelta(39.865, $park->lng, 1e-6);
        $this->assertEqualsWithDelta(21.415, $park->lat, 1e-6);
        $this->assertSame(1, ActivityLog::where('action', 'park.outline_updated')->count());
    }

    public function test_checked_verifies_and_a_later_unchecked_edit_resets_it(): void
    {
        $this->save(['boundary' => $this->boundary(), 'imagery' => 'maptiler', 'checked' => true])->assertOk()
            ->assertJsonPath('data.verification_status', 'verified');
        $park = $this->park->refresh();
        $this->assertSame($this->admin->id, $park->verified_by);
        $this->assertNotNull($park->verified_at);
        $this->assertStringContainsString('MapTiler', $park->outline_reference);

        $this->save(['boundary' => $this->boundary(), 'imagery' => 'esri', 'checked' => false])->assertOk()
            ->assertJsonPath('data.verification_status', 'unverified');
        $this->assertNull($this->park->refresh()->verified_by);
    }

    public function test_green_zone_with_a_hole_and_a_multipolygon_get_their_areas(): void
    {
        $outer = $this->box(39.8642, 21.4142, 0.0016);
        $hole = $this->box(39.8647, 21.4147, 0.0006);
        $polygon = ['type' => 'Polygon', 'coordinates' => [$outer, $hole]];
        $expected = (int) round(Geo::ringArea($outer) - Geo::ringArea($hole));

        $this->save(['boundary' => $this->boundary(), 'green_zone' => $polygon, 'imagery' => 'esri', 'checked' => false])->assertOk()
            ->assertJsonPath('data.green_zone_area_m2', $expected)
            ->assertJsonPath('warnings', []);
        $this->assertLessThan(Geo::ringArea($outer), $expected);

        $a = $this->box(39.8642, 21.4142, 0.0005);
        $b = $this->box(39.8652, 21.4152, 0.0005);
        $multi = ['type' => 'MultiPolygon', 'coordinates' => [[$a], [$b]]];
        $this->save(['green_zone' => $multi, 'imagery' => 'esri', 'checked' => false])->assertOk()
            ->assertJsonPath('data.green_zone_area_m2', (int) round(Geo::ringArea($a) + Geo::ringArea($b)))
            ->assertJsonPath('data.geometry_source', 'traced');
        // The boundary was not sent, so the one saved above is untouched.
        $this->assertSame((int) round(Geo::area($this->boundary())), $this->park->refresh()->area_m2);
    }

    public function test_warnings_for_a_green_zone_outside_or_larger_than_the_boundary(): void
    {
        $outside = ['type' => 'Polygon', 'coordinates' => [$this->box(39.8635, 21.4135, 0.0005)]];
        $this->save(['boundary' => $this->boundary(), 'green_zone' => $outside, 'imagery' => 'esri', 'checked' => false])->assertOk()
            ->assertJsonPath('warnings', ['green_zone_outside_boundary']);

        $bigger = ['type' => 'Polygon', 'coordinates' => [$this->box(39.860, 21.410, 0.01)]];
        $this->save(['green_zone' => $bigger, 'imagery' => 'esri', 'checked' => false])->assertOk()
            ->assertJsonPath('warnings', ['green_zone_outside_boundary', 'green_zone_larger_than_boundary']);
    }

    public function test_invalid_rings_are_422(): void
    {
        $open = ['type' => 'Polygon', 'coordinates' => [array_slice($this->box(39.864, 21.414, 0.002), 0, 4)]];
        $bowtie = ['type' => 'Polygon', 'coordinates' => [[[39.864, 21.414], [39.866, 21.416], [39.866, 21.414], [39.864, 21.416], [39.864, 21.414]]]];
        $range = ['type' => 'Polygon', 'coordinates' => [[[200, 21.0], [201, 21.0], [201, 22.0], [200, 21.0]]]];
        $short = ['type' => 'Polygon', 'coordinates' => [[[39.864, 21.414], [39.866, 21.414], [39.864, 21.414]]]];

        foreach ([$open, $bowtie, $range, $short] as $bad) {
            $this->save(['boundary' => $bad, 'imagery' => 'esri', 'checked' => false])->assertUnprocessable()->assertJsonValidationErrors('boundary');
        }
        $this->save(['green_zone' => $bowtie, 'imagery' => 'esri', 'checked' => false])->assertUnprocessable()->assertJsonValidationErrors('green_zone');
        $this->save(['boundary' => $this->boundary(), 'imagery' => 'bing', 'checked' => false])->assertUnprocessable()->assertJsonValidationErrors('imagery');
        $this->save(['imagery' => 'esri', 'checked' => false])->assertUnprocessable();
        $this->assertSame(6000, $this->park->refresh()->area_m2);
    }

    public function test_legacy_body_still_works(): void
    {
        $ring = array_slice($this->box(39.864, 21.414, 0.002), 0, 4);
        $this->save(['polygon' => ['type' => 'Polygon', 'coordinates' => [$ring]], 'source' => 'traced'])->assertOk()
            ->assertJsonPath('data.geometry_source', 'traced')
            ->assertJsonPath('data.verification_status', 'unverified');
    }

    public function test_scoping_and_roles(): void
    {
        $other = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id]);
        $body = ['boundary' => $this->boundary(), 'imagery' => 'esri', 'checked' => false];
        $this->save($body, $other)->assertNotFound();

        $muni = User::factory()->municipality()->create();
        $this->save($body, $muni)->assertForbidden();
    }

    public function test_cover_photo_upload_replace_stream_and_delete(): void
    {
        $url = "/api/admin/parks/{$this->park->id}/photo";
        $this->asUser($this->admin)->get($url)->assertNotFound();

        $this->asUser($this->admin)->post($url, ['photo' => $this->fakeImage('a.png')], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('data.cover_photo.url', $url);
        Storage::disk('local')->assertExists("parks/{$this->park->id}/cover.png");
        $this->assertNotNull($this->park->refresh()->cover_photo_at);
        $this->asUser($this->admin)->get($url)->assertOk();

        // Replace with a WebP: the old file goes away.
        $this->asUser($this->admin)->post($url, ['photo' => $this->fakeImage('b.webp')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('local')->assertMissing("parks/{$this->park->id}/cover.png");
        Storage::disk('local')->assertExists("parks/{$this->park->id}/cover.webp");

        $this->asUser($this->admin)->deleteJson($url)->assertNoContent();
        Storage::disk('local')->assertMissing("parks/{$this->park->id}/cover.webp");
        $this->assertNull($this->park->refresh()->cover_photo_path);
        $this->asUser($this->admin)->get($url)->assertNotFound();
    }

    public function test_cover_photo_rejects_non_images_and_big_files_and_other_companies(): void
    {
        $url = "/api/admin/parks/{$this->park->id}/photo";
        $h = ['Accept' => 'application/json'];
        $this->asUser($this->admin)->post($url, ['photo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')], $h)->assertUnprocessable();
        $this->asUser($this->admin)->post($url, ['photo' => $this->fakeImage('big.png', 6 * 1024 * 1024)], $h)->assertUnprocessable();
        $this->asUser($this->admin)->post($url, [], $h)->assertUnprocessable();

        $this->asUser($this->admin)->post($url, ['photo' => $this->fakeImage('a.png')], $h)->assertOk();
        $other = User::factory()->admin()->create(['company_id' => Company::factory()->create()->id]);
        $this->asUser($other)->get($url)->assertNotFound();
        $this->asUser($other)->post($url, ['photo' => $this->fakeImage('a.png')], $h)->assertNotFound();
        $this->asUser($other)->deleteJson($url)->assertNotFound();
    }
}
