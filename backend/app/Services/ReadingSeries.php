<?php

namespace App\Services;

use App\Models\Park;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bucketed averages of a park's readings for the charts, computed in SQL.
 * The WHERE (park_id = ? AND created_at >= ?) uses readings(park_id, created_at).
 */
class ReadingSeries
{
    /** range => [window in seconds, bucket in seconds] */
    public const RANGES = [
        '24h' => [86_400, 300],
        '7d' => [604_800, 3_600],
    ];

    /** @return array{range: string, bucket_seconds: int, from: string, to: string, source: string, data: list<array<string, mixed>>} */
    public function forPark(Park $park, string $range): array
    {
        [$window, $bucket] = self::RANGES[$range];
        $to = now()->utc()->startOfSecond();
        $from = $to->copy()->subSeconds($window);

        // Bucket start in seconds since the epoch of the naive UTC timestamp,
        // independent of the session time zone. SQLite (tests): integer division floors.
        $bucketSql = DB::connection()->getDriverName() === 'sqlite'
            ? "(CAST(strftime('%s', created_at) AS INTEGER) / {$bucket}) * {$bucket}"
            : "FLOOR(TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', created_at) / {$bucket}) * {$bucket}";

        $rows = DB::table('readings')
            ->selectRaw("{$bucketSql} AS bucket")
            ->selectRaw('AVG(moisture_pct) AS moisture_pct, AVG(temperature) AS temperature')
            ->selectRaw('AVG(humidity) AS humidity, AVG(flow) AS flow, COUNT(*) AS readings')
            ->where('park_id', $park->id)
            ->where('created_at', '>=', $from->format('Y-m-d H:i:s'))
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        $round = fn ($v, int $d) => $v === null ? null : round((float) $v, $d);

        return [
            'range' => $range,
            'bucket_seconds' => $bucket,
            'from' => $from->format('Y-m-d\TH:i:s\Z'),
            'to' => $to->format('Y-m-d\TH:i:s\Z'),
            'source' => $park->source->value,
            'data' => $rows->map(fn ($r) => [
                't' => Carbon::createFromTimestampUTC((int) $r->bucket)->format('Y-m-d\TH:i:s\Z'),
                'moisture_pct' => $round($r->moisture_pct, 1),
                'temperature' => $round($r->temperature, 1),
                'humidity' => $round($r->humidity, 1),
                'flow' => $round($r->flow, 3),
                'readings' => (int) $r->readings,
            ])->all(),
        ];
    }
}
