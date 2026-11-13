<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 6.2, Phase A: park truth. Site type, import keys, green zone, geometry
 * source, monitoring mode, verification, soft archive, demo role and cover photo.
 *
 * The backfill keeps today's behaviour and runs row by row in PHP so it works on
 * MySQL and SQLite alike.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parks', function (Blueprint $table) {
            $table->string('site_type', 16)->default('park')->index()->after('company_id');
            $table->string('slug', 80)->nullable()->unique()->after('site_type');
            $table->string('osm_id', 32)->nullable()->unique()->after('slug');
            $table->json('green_zone')->nullable()->after('polygon');
            $table->unsignedInteger('green_zone_area_m2')->nullable()->after('green_zone');
            $table->string('geometry_source', 16)->default('rough_legacy')->after('green_zone_area_m2');
            $table->timestamp('geometry_updated_at')->nullable()->after('geometry_source');
            $table->string('monitoring_mode', 16)->default('satellite_only')->index()->after('geometry_updated_at');
            $table->string('verification_status', 12)->default('unverified')->after('monitoring_mode');
            $table->foreignId('verified_by')->nullable()->after('verification_status')->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable()->after('verified_by');
            $table->timestamp('archived_at')->nullable()->index()->after('verified_at');
            $table->string('demo_role', 16)->nullable()->after('archived_at');
            $table->string('cover_photo_path', 255)->nullable()->after('demo_role');
            $table->timestamp('cover_photo_at')->nullable()->after('cover_photo_path');
        });

        $this->backfill();
    }

    /** Public so a test can run it on legacy-shaped rows. */
    public function backfill(): void
    {
        $usedOsm = [];
        foreach (DB::table('parks')->get(['id', 'source', 'is_test', 'outline_source', 'outline_reference', 'outline_at']) as $park) {
            $update = [
                'geometry_source' => match ($park->outline_source) {
                    'osm' => 'osm',
                    'traced', 'official' => 'traced',
                    default => 'rough_legacy',
                },
                'verification_status' => 'unverified',
                'monitoring_mode' => $park->source === 'simulated' ? 'simulated' : 'real_sensors',
                'site_type' => $park->is_test ? 'demo_stand' : 'park',
                'geometry_updated_at' => $park->outline_at,
            ];

            if (preg_match('#(way|relation)/(\d+)#', (string) $park->outline_reference, $m) && ! in_array($m[0], $usedOsm, true)) {
                $usedOsm[] = $m[0];
                $update['osm_id'] = $m[0];
                $update['slug'] = "osm-{$m[1]}-{$m[2]}";
            }

            DB::table('parks')->where('id', $park->id)->update($update);
        }
    }

    public function down(): void
    {
        Schema::table('parks', function (Blueprint $table) {
            $table->dropForeign(['verified_by']);
            $table->dropIndex(['site_type']);
            $table->dropUnique(['slug']);
            $table->dropUnique(['osm_id']);
            $table->dropIndex(['monitoring_mode']);
            $table->dropIndex(['archived_at']);
            $table->dropColumn([
                'site_type', 'slug', 'osm_id', 'green_zone', 'green_zone_area_m2', 'geometry_source',
                'geometry_updated_at', 'monitoring_mode', 'verification_status', 'verified_by', 'verified_at',
                'archived_at', 'demo_role', 'cover_photo_path', 'cover_photo_at',
            ]);
        });
    }
};
