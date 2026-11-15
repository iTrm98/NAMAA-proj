<?php

namespace App\Services;

use App\Enums\NodeType;
use App\Models\Node;
use App\Models\NdviReading;
use App\Models\Park;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The pre-demo check on the Live device page (GET /api/admin/stand/readiness, polled every 10 s).
 * One line per part: {key, ok, detail}; `ready` is true when every line is ok. Nothing secret is returned.
 * Each check catches its own failure, so one part being down is a red line, never an error response.
 */
class StandReadiness
{
    public function __construct(
        private readonly StandLive $live,
        private readonly VisionClient $vision,
    ) {}

    /** @return array{ready: bool, checks: list<array{key: string, ok: bool, detail: string, required: bool, testable: bool}>} */
    public function check(Park $stand): array
    {
        $cameras = Node::where('park_id', $stand->id)->where('type', NodeType::Camera)->orderBy('id')->get();
        $zoned = Node::hasZoneColumn() && Node::where('park_id', $stand->id)->whereNotNull('zone')->exists();
        $cam = fn (?string $zone) => $zone === null ? $cameras->first() : $cameras->first(fn ($c) => $c->zone === $zone);

        // key => [run, required, testable]
        $plan = $zoned ? [
            'gateway' => [fn () => $this->gatewayLink(), true, false],
            'zone_a' => [fn () => $this->zone($stand, 'A'), true, true],
            'zone_b' => [fn () => $this->zone($stand, 'B'), true, true],
            'camera_a' => [fn () => $this->camera($cam('A')), true, true],
            'camera_b' => [fn () => $this->camera($cam('B')), true, true],
            'vision' => [fn () => $this->vision(), true, false],
            'litter_forward' => [fn () => $this->litterForward(), true, true],
            'queue' => [fn () => $this->queue(), true, false],
            'scheduler' => [fn () => $this->scheduler(), true, false],
            'ingest_token' => [fn () => $this->ingestToken(), false, false],
            'mode' => [fn () => $this->mode(), true, false],
            'tray' => [fn () => $this->tray($cam('A')), false, false],
            'ndvi' => [fn () => $this->ndvi($stand), false, false],
        ] : [
            'board' => [fn () => $this->board($stand), true, false],
            'camera' => [fn () => $this->camera($cameras->first()), true, false],
            'vision' => [fn () => $this->vision(), true, false],
            'tray' => [fn () => $this->tray($cameras->first()), true, false],
            'scheduler' => [fn () => $this->scheduler(), true, false],
            'mode' => [fn () => $this->mode(), true, false],
            'ndvi' => [fn () => $this->ndvi($stand), true, false],
        ];

        $checks = [];
        foreach ($plan as $key => [$run, $required, $testable]) {
            try {
                [$ok, $detail] = $run();
            } catch (Throwable $e) {
                [$ok, $detail] = [false, class_basename($e).': '.str($e->getMessage())->limit(100)];
            }
            $checks[] = ['key' => $key, 'ok' => $ok, 'detail' => $detail, 'required' => $required, 'testable' => $testable];
        }

        $required = array_filter($checks, fn ($c) => $c['required']);

        return ['ready' => ! in_array(false, array_column($required, 'ok'), true), 'checks' => $checks];
    }

    /**
     * Run one part on request (POST /admin/stand/test). Never throws: a failure is {ok: false, detail}.
     *
     * @return array{ok: bool, detail: string}
     */
    public function test(Park $stand, string $key): array
    {
        [$kind, $zone] = explode('_', $key);
        $zone = strtoupper($zone);
        $client = app(GatewayClient::class);

        try {
            if ($kind === 'pump') {
                $seconds = (int) config('gateway.test_pump_seconds');
                $client->command($zone, true, $seconds);

                return ['ok' => true, 'detail' => "Pump {$zone} started for {$seconds} s"];
            }
            $camera = Node::where('park_id', $stand->id)->where('type', NodeType::Camera)->where('zone', $zone)->first();
            if ($kind === 'camera') {
                [$ok, $detail] = $this->camera($camera);

                return ['ok' => $ok, 'detail' => $detail];
            }
            // litter: tell the Gateway "no litter" for the zone (harmless) and see that it answers.
            $client->litter($zone, 0, 0.0);

            return ['ok' => true, 'detail' => "Gateway accepted a litter message for zone {$zone}"];
        } catch (GatewayUnreachable $e) {
            return ['ok' => false, 'detail' => 'Gateway did not answer'];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => class_basename($e).': '.str($e->getMessage())->limit(100)];
        }
    }

    /** @return array{bool, string} */
    private function gatewayLink(): array
    {
        $state = app(GatewayState::class);
        $at = $state->lastEventAt();
        if (! GatewayClient::configured()) {
            return [false, 'GATEWAY_URL is not set'];
        }
        if ($at === null) {
            return [false, 'The Gateway has not sent anything yet'];
        }

        return [$state->reachable(), 'Last event '.(int) $at->diffInSeconds(now(), true).' s ago'];
    }

    /** @return array{bool, string} */
    private function zone(Park $stand, string $zone): array
    {
        $node = Node::where('park_id', $stand->id)->where('zone', $zone)->where('type', NodeType::Sensor)->first();
        if (! $node?->last_seen_at) {
            return [false, "Zone {$zone} has not reported"];
        }
        $age = (int) $node->last_seen_at->diffInSeconds(now(), true);

        return [$age < (int) config('gateway.offline_seconds'), "Zone {$zone}: last reading {$age} s ago"];
    }

    /** @return array{bool, string} */
    private function litterForward(): array
    {
        if (! GatewayClient::configured()) {
            return [false, 'GATEWAY_URL is not set'];
        }
        $ok = Cache::get(GatewayClient::LITTER_OK);

        return [$ok !== false, $ok === null ? 'No frame forwarded yet' : ($ok ? 'The last litter forward worked' : 'The last litter forward failed')];
    }

    /** @return array{bool, string} */
    private function queue(): array
    {
        $at = Cache::get(SystemHealth::QUEUE_BEAT);
        if (! $at) {
            return [false, 'No queue heartbeat yet'];
        }
        $age = (int) Carbon::parse($at)->diffInSeconds(now(), true);

        return [$age <= 180, "Last heartbeat {$age} s ago"];
    }

    /** @return array{bool, string} */
    private function ingestToken(): array
    {
        $set = (string) config('gateway.ingest_token') !== '';

        return [$set, $set ? 'The ingest route needs a token' : 'The ingest route is open (no NAMAA_INGEST_TOKEN)'];
    }

    /** @return array{bool, string} */
    private function board(Park $stand): array
    {
        $node = $this->live->node($stand);
        $seen = $node?->last_seen_at;
        if (! $seen) {
            return [false, 'Board never reported'];
        }
        $age = (int) $seen->diffInSeconds(now(), true);

        return [$age < (int) config('namaa.camera.ready.board_seconds'), "{$node->name}: last reading {$age} s ago"];
    }

    /** @return array{bool, string} */
    private function camera(?Node $camera): array
    {
        if (! $camera || CameraPipeline::urlFor($camera) === '') {
            return [false, 'No camera URL set'];
        }
        $url = CameraPipeline::frameUrl($camera);
        $limit = (int) config('namaa.camera.ready.frame_seconds');
        $bridge = preg_match('/\.(jpe?g|png)(\?.*)?$/i', CameraPipeline::urlFor($camera)) === 1;

        try {
            if ($bridge) {
                // The USB camera bridge: GET /health = {ok, camera, last_frame_age_ms}.
                $parts = parse_url($url);
                $health = Http::timeout(3)->acceptJson()->get(($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '').'/health');
                if (! $health->successful() && $health->status() !== 503) {
                    return [false, 'Camera bridge /health answered HTTP '.$health->status()];
                }
                $ageMs = $health->json('last_frame_age_ms');
                if ($ageMs === null) {
                    return [false, 'Camera bridge has no frame yet'];
                }
                $age = round($ageMs / 1000, 1);

                return [(bool) $health->json('ok') && $age < $limit, "Bridge /health ok, frame age {$age} s"];
            }

            $response = Http::timeout((int) config('namaa.camera.timeout_seconds'))->get($url);

            return [$response->successful() && str_contains((string) $response->header('Content-Type'), 'image'), 'Camera /capture answered HTTP '.$response->status()];
        } catch (ConnectionException) {
            return [false, 'Camera bridge does not answer'];
        }
    }

    /** @return array{bool, string} */
    private function vision(): array
    {
        $health = $this->vision->health();
        if ($health === null) {
            return [false, 'Vision service does not answer'];
        }
        $waste = $health['waste'] ?? null;
        if (! is_array($waste)) {
            return [false, 'Vision is up but the litter model is not loaded'];
        }
        $want = config('namaa.camera.expected');
        $model = (string) ($waste['model'] ?? '?');
        $imgsz = $waste['imgsz'] ?? null;
        $conf = (float) ($waste['confidence'] ?? 0);
        $ok = $model === $want['model'] && $imgsz === (int) $want['imgsz'] && abs($conf - (float) $want['threshold']) < 0.0005;

        return [$ok, "{$model}, imgsz ".($imgsz ?? '?').', threshold '.rtrim(rtrim(number_format($conf, 4), '0'), '.')
            .($ok ? '' : " (expected {$want['model']}, {$want['imgsz']}, {$want['threshold']})")];
    }

    /** @return array{bool, string} */
    private function tray(?Node $camera): array
    {
        $set = $camera?->camera_roi !== null;

        return [$set, $set ? 'Tray area set' : 'Tray area not set (the whole frame counts)'];
    }

    /** @return array{bool, string} */
    private function scheduler(): array
    {
        $status = SystemHealth::schedulerStatus();
        if ($status['age_s'] === null) {
            return [false, 'No scheduler heartbeat yet'];
        }

        return [! $status['stalled'], "Last heartbeat {$status['age_s']} s ago"];
    }

    /** @return array{bool, string} */
    private function mode(): array
    {
        $mode = CameraMode::current();

        return [$mode === CameraMode::DEMO, 'Camera mode: '.$mode];
    }

    /** @return array{bool, string} */
    private function ndvi(Park $stand): array
    {
        $date = NdviReading::whereHas('park', fn ($p) => $p->where('company_id', $stand->company_id))->max('measured_on');
        if (! $date) {
            return [false, 'No NDVI image yet'];
        }

        return [true, 'Newest NDVI image: '.substr((string) $date, 0, 10)];
    }
}
