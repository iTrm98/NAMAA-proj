<?php

use App\Models\Park;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3.1, D1: where each park's outline and area come from
 * (official | traced | osm | approximate, a free-text reference, who, when),
 * and D3: when an NDVI recompute was queued after an outline change.
 *
 * Existing parks are marked honestly: an outline written from OpenStreetMap by
 * namaa:parks:outlines (a park.outline_updated log with an OSM id) is `osm`,
 * every other outline is the seeder's `approximate` rectangle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parks', function (Blueprint $table) {
            $table->string('outline_source', 16)->default('approximate')->after('polygon');
            $table->string('outline_reference', 255)->nullable()->after('outline_source');
            $table->foreignId('outline_by')->nullable()->after('outline_reference')->constrained('users')->nullOnDelete();
            $table->timestamp('outline_at')->nullable()->after('outline_by');
            $table->string('area_source', 16)->default('approximate')->after('area_m2');
            $table->string('area_reference', 255)->nullable()->after('area_source');
            $table->foreignId('area_by')->nullable()->after('area_reference')->constrained('users')->nullOnDelete();
            $table->timestamp('area_at')->nullable()->after('area_by');
            $table->timestamp('ndvi_recompute_at')->nullable()->after('last_reading_at');
        });

        $rectangle = 'Rectangle of the estimated area around the park centre (seed data), not verified';
        DB::table('parks')->update([
            'outline_reference' => $rectangle,
            'area_reference' => 'Estimate (seed data), not verified',
        ]);

        $osm = DB::table('activity_logs')->where('action', 'park.outline_updated')->where('subject_type', (new Park)->getMorphClass())
            ->orderBy('id')->get(['subject_id', 'diff', 'created_at']);
        foreach ($osm as $log) {
            $diff = json_decode((string) $log->diff, true);
            $id = $diff['after']['osm'] ?? null;
            if (! $id) {
                continue;
            }
            DB::table('parks')->where('id', $log->subject_id)->update([
                'outline_source' => 'osm',
                'outline_reference' => "OpenStreetMap $id (ODbL)",
                'outline_at' => $log->created_at,
                'area_source' => 'osm',
                'area_reference' => "Computed from the OpenStreetMap outline ($id)",
                'area_at' => $log->created_at,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('parks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('outline_by');
            $table->dropConstrainedForeignId('area_by');
            $table->dropColumn(['outline_source', 'outline_reference', 'outline_at', 'area_source', 'area_reference', 'area_at', 'ndvi_recompute_at']);
        });
    }
};
