<?php

namespace Tests\Feature\Outlines;

use App\Console\Commands\ParkExportCommand;
use App\Models\Company;
use App\Models\Node;
use App\Models\Park;
use App\Models\User;
use App\Services\ParkAudit;
use App\Services\ParkOutlines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Sprint 3.1, D4: namaa:parks:audit (OSM comparison) and namaa:parks:export (back to parks.json). */
class ParkAuditExportTest extends TestCase
{
    use RefreshDatabase;

    /** A 0.001° square (about 104 x 111 m) with its south-west corner at (lat, lng). */
    private function square(float $lat, float $lng, float $size = 0.001): array
    {
        return [[$lng, $lat], [$lng + $size, $lat], [$lng + $size, $lat + $size], [$lng, $lat + $size], [$lng, $lat]];
    }

    /** Overpass "out geom" way element for a ring. */
    private function way(int $id, array $ring, array $tags): array
    {
        return ['type' => 'way', 'id' => $id, 'tags' => $tags, 'geometry' => array_map(fn ($p) => ['lat' => $p[1], 'lon' => $p[0]], $ring)];
    }

    private function park(array $ring): Park
    {
        return Park::factory()->create(['lat' => $ring[0][1] + 0.0005, 'lng' => $ring[0][0] + 0.0005,
            'polygon' => ['type' => 'Polygon', 'coordinates' => [$ring]], 'outline_source' => 'traced']);
    }

    public function test_compare_gives_match_partial_mismatch_and_no_osm_data(): void
    {
        $outline = $this->square(21.4, 39.8);
        $same = [['osm' => 'way/1', 'name' => 'A', 'tag' => 'leisure=park', 'area_m2' => 11_550, 'distance_m' => 0,
            'polygon' => ['type' => 'Polygon', 'coordinates' => [$this->square(21.4, 39.8)]]]];
        $r = ParkAudit::compare($outline, $same);
        $this->assertSame('match', $r['verdict']);
        $this->assertEqualsWithDelta(100, $r['overlap_pct'], 1.5);
        $this->assertEqualsWithDelta(100, $r['covered_pct'], 1.5);

        // Shifted by half its width: covered 50 %, overlap 1/3.
        $half = [['polygon' => ['type' => 'Polygon', 'coordinates' => [$this->square(21.4, 39.8005)]]] + $same[0]];
        $r = ParkAudit::compare($outline, $half);
        $this->assertSame('partial', $r['verdict']);
        $this->assertEqualsWithDelta(50, $r['covered_pct'], 2);
        $this->assertEqualsWithDelta(33.3, $r['overlap_pct'], 2);

        // A green area 300 m away, not touching the outline.
        $far = [['polygon' => ['type' => 'Polygon', 'coordinates' => [$this->square(21.4, 39.803)]]] + $same[0]];
        $this->assertSame('mismatch', ParkAudit::compare($outline, $far)['verdict']);

        $r = ParkAudit::compare($outline, []);
        $this->assertSame('no_osm_data', $r['verdict']);
        $this->assertNull($r['overlap_pct']);
        $this->assertEqualsWithDelta(11_550, $r['outline_m2'], 100);
    }

    public function test_the_audit_asks_overpass_once_per_park_politely_and_changes_nothing(): void
    {
        config(['namaa.overpass_url' => 'https://overpass.test/api/interpreter']);
        $ring = $this->square(21.4, 39.8);
        $park = $this->park($ring);
        $empty = $this->park($this->square(21.5, 39.9));
        $before = $park->only(['polygon', 'area_m2', 'outline_source']);

        Http::fake(function (Request $request) {
            $q = $request['data'];

            return Http::response(['elements' => str_contains($q, '21.4005') ? [
                $this->way(11, $this->square(21.4, 39.8), ['leisure' => 'park', 'name' => 'Test Park']),
                $this->way(12, $this->square(21.4, 39.8005), ['landuse' => 'grass']),
            ] : []]);
        });

        $this->artisan('namaa:parks:audit')
            ->expectsOutputToContain('way/11 leisure=park Test Park')
            ->expectsOutputToContain('none within 300 m')
            ->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->hasHeader('User-Agent', ParkOutlines::USER_AGENT)
            && ! str_contains($r->header('User-Agent')[0], '@')
            && str_contains($r['data'], 'leisure"~"^(park|garden)$"') && str_contains($r['data'], 'landuse"="grass"')
            && str_contains($r['data'], 'around:300'));
        $this->assertSame($before, $park->fresh()->only(['polygon', 'area_m2', 'outline_source']), 'read-only');
        $this->assertSame(0, \App\Models\ActivityLog::count());
        $this->assertNotNull($empty->fresh());
    }

    public function test_an_overpass_failure_is_reported_and_fails_the_audit(): void
    {
        config(['namaa.overpass_url' => 'https://overpass.test/api/interpreter']);
        $this->park($this->square(21.4, 39.8));
        Http::fake(['*' => Http::response('busy', 400)]);

        $this->artisan('namaa:parks:audit')->expectsOutputToContain('Overpass failed')->assertFailed();
    }

    public function test_export_keeps_the_structure_keys_and_one_line_polygons_and_adds_new_parks(): void
    {
        $dir = sys_get_temp_dir().'/namaa-export-'.uniqid();
        mkdir($dir);
        $seed = $dir.'/parks.json';
        $out = $dir.'/out.json';
        file_put_contents($seed, json_encode(['notes' => ['Keep me.'], 'parks' => [
            ['key' => 'aziziyah', 'name_en' => 'Garden Aziziyah', 'name_ar' => 'حديقة العزيزية', 'district' => 'Al Mursalat', 'lat' => 21.4150694,
                'lng' => 39.8664169, 'park_type' => 'public_park', 'area_m2' => 6000, 'source' => 'real', 'scenario' => 'real',
                'polygon_approximate' => true, 'nodes' => [['name' => 'Model B', 'type' => 'sensor', 'has_pump' => false]]],
            ['key' => 'gone', 'name_en' => 'Not In The Database', 'name_ar' => 'x', 'district' => 'x', 'lat' => 21.0, 'lng' => 39.0,
                'park_type' => 'public_park', 'area_m2' => 100, 'source' => 'simulated', 'scenario' => 'healthy', 'polygon_approximate' => true, 'nodes' => []],
        ]], JSON_UNESCAPED_UNICODE));
        config(['namaa.parks_seed_path' => $seed]);

        $turki = User::factory()->admin()->create(['name' => 'Turki']);
        $ring = $this->square(21.4150, 39.8660);
        $aziziyah = Park::factory()->create(['company_id' => $turki->company_id, 'name_en' => 'Garden Aziziyah', 'name_ar' => 'حديقة العزيزية',
            'district' => 'Al Mursalat', 'lat' => 21.4150694, 'lng' => 39.8664169, 'area_m2' => 11_550, 'source' => 'real',
            'polygon' => ['type' => 'Polygon', 'coordinates' => [$ring]],
            'outline_source' => 'traced', 'outline_reference' => null, 'outline_by' => $turki->id, 'outline_at' => '2026-11-10 08:00:00',
            'area_source' => 'traced', 'area_by' => $turki->id, 'area_at' => '2026-11-10 08:00:00']);
        Node::factory()->create(['park_id' => $aziziyah->id, 'name' => 'Model B', 'type' => 'sensor', 'has_pump' => false]);
        $second = Company::factory()->create(['name' => \App\Console\Commands\DemoSecondCompany::COMPANY_NAME]);
        Park::factory()->simulated()->create(['company_id' => $second->id, 'name_en' => 'Al Masfalah Park', 'outline_source' => 'osm',
            'outline_reference' => 'OpenStreetMap way/457991700 (ODbL)']);

        $this->artisan('namaa:parks:export', ['--path' => $out])->expectsOutputToContain('new: Al Masfalah Park')->assertSuccessful();

        $text = file_get_contents($out);
        $data = json_decode($text, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('Keep me.', $data['notes'][0]);
        $this->assertSame(ParkExportCommand::SOURCES_NOTE, $data['notes'][1]);
        $this->assertSame(['aziziyah', 'al_masfalah', 'gone'], array_column($data['parks'], 'key'), 'keys kept, new park added, unknown park kept');

        $a = $data['parks'][0];
        $this->assertFalse($a['polygon_approximate']);
        $this->assertSame(11_550, $a['area_m2']);
        $this->assertSame([[$ring]], [$a['polygon']['coordinates']]);
        $this->assertSame(['type' => 'traced', 'reference' => null, 'by' => 'Turki', 'at' => '2026-11-10'], $a['outline_source']);
        $this->assertSame('real', $a['scenario']);
        $this->assertSame([['name' => 'Model B', 'type' => 'sensor', 'has_pump' => false]], $a['nodes']);
        $this->assertSame('second', $data['parks'][1]['company']);

        // Style: one-line polygons and nodes, two-space indent, Arabic unescaped.
        $this->assertMatchesRegularExpression('/\n      "polygon": \{"type": "Polygon", "coordinates": \[\[\[39\.866, 21\.415\], .*\]\]\]\},\n/', $text);
        $this->assertStringContainsString("\n        { \"name\": \"Model B\", \"type\": \"sensor\", \"has_pump\": false }\n", $text);
        $this->assertStringContainsString('"name_ar": "حديقة العزيزية"', $text);

        // A fresh seed reads the sources back (round trip), without repeating "by".
        $row = \Database\Seeders\DemoParksSeeder::dataSources($a);
        $this->assertSame('traced', $row['outline_source']);
        $this->assertSame('by Turki', $row['outline_reference']);
        $this->assertSame('Turki', ParkExportCommand::entry(Park::make(['name_en' => 'x', 'outline_source' => 'traced', 'outline_reference' => 'by Turki'])
            ->setRelation('outlineBy', $turki)->setRelation('areaBy', null)->setRelation('nodes', collect()), [], false)['outline_source']['by']);
        $this->assertNull(ParkExportCommand::entry(Park::make(['name_en' => 'x', 'outline_source' => 'traced', 'outline_reference' => 'by Turki'])
            ->setRelation('outlineBy', $turki)->setRelation('areaBy', null)->setRelation('nodes', collect()), [], false)['outline_source']['reference']);

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }
}
