<?php

namespace App\Services;

use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Models\Node;
use App\Support\DeviceRejections;
use App\Support\Namaa;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * The pre-demo checklist (namaa:doctor and the demo page's System health
 * panel). Each check is {key, status: ok|warn|fail|info, detail}. Nothing
 * secret is returned: no token, no password, no .env value except DEMO_MODE
 * and APP_DEBUG.
 *
 * $quick (the panel, every 10 s) skips the camera's /capture call and uses the
 * camera node's last frame instead.
 */
class SystemHealth
{
    public const QUEUE_BEAT = 'namaa:heartbeat:queue';

    public const SCHEDULER_BEAT = 'namaa:heartbeat:scheduler';

    /** Heartbeats are written every minute; older than this is a stopped container. */
    private const BEAT_MAX_SECONDS = 180;

    /** The scheduler beats every 60 s: older than this it is stalled (banner for admins). */
    public const SCHEDULER_STALL_SECONDS = 90;

    private const MIN_FREE_BYTES = 2 * 1024 ** 3;

    public function __construct(
        private readonly VisionClient $vision,
        private readonly Migrator $migrator,
    ) {}

    /** @return list<array{key: string, status: string, detail: string}> */
    public function checks(bool $quick = false, ?int $companyId = null): array
    {
        $checks = [];
        foreach ([
            'demo_mode' => fn () => $this->demoMode(),
            'app_debug' => fn () => $this->appDebug(),
            'mysql' => fn () => $this->mysql(),
            'migrations' => fn () => $this->migrations(),
            'queue' => fn () => $this->heartbeat(self::QUEUE_BEAT, 'queue'),
            'scheduler' => fn () => $this->scheduler(),
            'reverb' => fn () => $this->reverb(),
            'vision' => fn () => $this->visionHealth(),
            'camera' => fn () => $this->camera($quick),
            'real_nodes' => fn () => $this->realNodes(),
            'bench_nodes' => fn () => $this->benchNodes(),
            'node_tokens' => fn () => $this->nodeTokens(),
            'rejected' => fn () => $this->rejected($companyId),
            'disk' => fn () => $this->disk(),
        ] as $key => $check) {
            try {
                [$status, $detail] = $check();
            } catch (Throwable $e) {
                [$status, $detail] = ['fail', class_basename($e).': '.str($e->getMessage())->limit(120)];
            }
            $checks[] = ['key' => $key, 'status' => $status, 'detail' => $detail];
        }

        return $checks;
    }

    /** @param list<array{status: string}> $checks */
    public static function overall(array $checks): string
    {
        $statuses = array_column($checks, 'status');

        return in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok');
    }

    public const RESUMED = 'namaa:heartbeat:scheduler:resumed';

    /** Called by the scheduler every minute. Remembers when it comes back after a long gap. */
    public static function beat(): void
    {
        $previous = Cache::get(self::SCHEDULER_BEAT);
        if ($previous && Carbon::parse($previous)->diffInSeconds(now(), true) > Namaa::timing('offline_seconds')) {
            Cache::put(self::RESUMED, now()->toIso8601String(), now()->addDay());
        }
        Cache::put(self::SCHEDULER_BEAT, now()->toIso8601String(), now()->addDay());
    }

    /**
     * True for one offline-check cycle after a long scheduler gap, and while the heartbeat
     * is older than the offline window. A missing heartbeat is not a stall here.
     */
    public static function schedulerJustResumed(): bool
    {
        $window = Namaa::timing('offline_seconds');
        $at = Cache::get(self::SCHEDULER_BEAT);
        if ($at && Carbon::parse($at)->diffInSeconds(now(), true) > $window) {
            return true;
        }
        $resumed = Cache::get(self::RESUMED);

        return $resumed && Carbon::parse($resumed)->diffInSeconds(now(), true) < Namaa::timing('offline_check_seconds');
    }

    /** @return array{heartbeat_at: ?string, age_s: ?int, stalled: bool} */
    public static function schedulerStatus(): array
    {
        $at = Cache::get(self::SCHEDULER_BEAT);
        $age = $at ? (int) Carbon::parse($at)->diffInSeconds(now(), true) : null;

        return [
            'heartbeat_at' => $at ? Carbon::parse($at)->utc()->format('Y-m-d\TH:i:s\Z') : null,
            'age_s' => $age,
            'stalled' => $age === null || $age > self::SCHEDULER_STALL_SECONDS,
        ];
    }

    private function scheduler(): array
    {
        $status = self::schedulerStatus();
        if ($status['age_s'] === null) {
            return ['fail', 'Scheduler stalled: no heartbeat yet (is the scheduler container running?)'];
        }

        return $status['stalled']
            ? ['fail', "Scheduler stalled: last heartbeat {$status['age_s']} s ago (over ".self::SCHEDULER_STALL_SECONDS.' s)']
            : ['ok', "Last heartbeat {$status['age_s']} s ago"];
    }

    /** With a company id (the demo page) only that company's nodes count; the CLI doctor stays global. */
    private function rejected(?int $companyId = null): array
    {
        $last = $companyId === null ? DeviceRejections::last() : $this->lastRejectedFor($companyId);
        if (! $last) {
            return ['info', 'No rejected device request'];
        }
        $who = $last['node_id'] !== null ? "node {$last['node_id']}" : 'unknown token '.($last['token_prefix'] ?? '').'...';

        return ['info', "Last rejected device request: HTTP {$last['status']} from $who at {$last['at']}: {$last['message']}"];
    }

    /** @return array<string, mixed>|null */
    private function lastRejectedFor(int $companyId): ?array
    {
        $best = null;
        foreach (Node::whereHas('park', fn ($p) => $p->where('company_id', $companyId))->pluck('id') as $id) {
            $entry = DeviceRejections::forNode($id)[0] ?? null;
            if ($entry && (! $best || $entry['at'] > $best['at'])) {
                $best = $entry + ['node_id' => $id, 'token_prefix' => null];
            }
        }

        return $best;
    }

    private function demoMode(): array
    {
        return Namaa::demoMode()
            ? ['ok', 'DEMO_MODE=true: short demo timings, demo login and scenarios on']
            : ['warn', 'DEMO_MODE=false: normal timings; the demo page and scenarios are off'];
    }

    private function appDebug(): array
    {
        return config('app.debug')
            ? ['warn', 'APP_DEBUG=true: error pages show stack traces; set it to false for the event']
            : ['ok', 'APP_DEBUG=false'];
    }

    private function mysql(): array
    {
        $driver = DB::connection()->getDriverName();
        $version = DB::selectOne($driver === 'sqlite' ? 'select sqlite_version() as v' : 'select version() as v')->v ?? '?';

        return ['ok', $driver.' '.$version];
    }

    private function migrations(): array
    {
        $this->migrator->setConnection(config('database.default'));
        if (! $this->migrator->repositoryExists()) {
            return ['fail', 'No migrations table'];
        }
        $files = $this->migrator->getMigrationFiles(database_path('migrations'));
        $pending = array_diff(array_keys($files), $this->migrator->getRepository()->getRan());

        return $pending === []
            ? ['ok', count($files).' migrations, none pending']
            : ['fail', count($pending).' pending: '.implode(', ', array_slice($pending, 0, 3))];
    }

    private function heartbeat(string $key, string $what): array
    {
        $at = Cache::get($key);
        if (! $at) {
            return ['fail', "No $what heartbeat yet (is the $what container running?)"];
        }
        $age = (int) Carbon::parse($at)->diffInSeconds(now(), true);

        return $age <= self::BEAT_MAX_SECONDS
            ? ['ok', "Last heartbeat {$age} s ago"]
            : ['fail', "Last heartbeat {$age} s ago (over ".self::BEAT_MAX_SECONDS.' s)'];
    }

    private function reverb(): array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return ['warn', 'BROADCAST_CONNECTION is '.(config('broadcasting.default') ?: 'not set').', live updates are off'];
        }
        $host = config('reverb.servers.reverb.host') === '0.0.0.0' ? 'reverb' : config('reverb.servers.reverb.host');
        $port = (int) config('reverb.servers.reverb.port', 8080);
        $server = config('broadcasting.connections.reverb.options.host') ?: $host;
        $serverPort = (int) (config('broadcasting.connections.reverb.options.port') ?: $port);

        try {
            // Any HTTP answer means the WebSocket server is listening.
            $status = Http::timeout(3)->get("http://{$server}:{$serverPort}/")->status();
        } catch (ConnectionException) {
            return ['fail', "Reverb does not answer at {$server}:{$serverPort}"];
        }

        return ['ok', "Reverb answers at {$server}:{$serverPort} (HTTP {$status})"];
    }

    private function visionHealth(): array
    {
        $health = $this->vision->health();
        if ($health === null) {
            return ['fail', 'The vision service does not answer at '.config('namaa.vision.url').'/health'];
        }
        $waste = $health['waste'] ?? null;
        if (! is_array($waste)) {
            return ['warn', 'Vision is up but the YOLO model is not loaded (/waste answers 503)'];
        }

        return ['ok', 'Vision up, model '.($waste['model'] ?? '?')];
    }

    private function camera(bool $quick): array
    {
        if (! CameraPipeline::enabled()) {
            return ['info', 'CAMERA_URL not set: no camera board; use the webcam or upload on the demo page'];
        }
        if ($quick) {
            $node = CameraPipeline::cameraNode();
            $last = $node?->last_seen_at;

            return $last && $last->gt(now()->subSeconds(OfflineChecker::offlineSeconds(NodeType::Camera)))
                ? ['ok', 'Last frame '.$last->diffForHumans()]
                : ['warn', 'No recent frame'.($last ? ' (last '.$last->diffForHumans().')' : '')];
        }

        try {
            $response = Http::timeout((int) config('namaa.camera.timeout_seconds'))->get(CameraPipeline::urlFor(CameraPipeline::cameraNode()).'/capture');
        } catch (ConnectionException) {
            return ['fail', 'The camera does not answer at CAMERA_URL/capture'];
        }
        $type = (string) $response->header('Content-Type');

        return $response->successful() && str_contains($type, 'image')
            ? ['ok', 'Camera /capture: '.round(strlen($response->body()) / 1024).' KB '.$type]
            : ['fail', 'Camera /capture answered HTTP '.$response->status().' '.$type];
    }

    private function realNodes(): array
    {
        $nodes = Node::where('source', DataSource::Real)->where('type', NodeType::Sensor)
            ->whereHas('park', fn ($p) => $p->where('is_test', false))->orderBy('id')->get();
        if ($nodes->isEmpty()) {
            return ['info', 'No real sensor node'];
        }
        $posting = $nodes->filter(fn (Node $n) => $n->last_seen_at?->gt(now()->subMinute()))->count();
        $limit = Namaa::timing('offline_seconds');
        $parts = [];
        $worst = 'ok';
        foreach ($nodes as $node) {
            if (! $node->last_seen_at) {
                $parts[] = "{$node->name}: never reported";
                $worst = 'warn';

                continue;
            }
            $age = (int) $node->last_seen_at->diffInSeconds(now(), true);
            $parts[] = "{$node->name}: last reading {$age} s ago";
            if ($age > $limit) {
                $worst = 'warn';
            }
        }

        return [$worst, implode('; ', $parts)."; {$posting} of {$nodes->count()} posting in the last minute"];
    }

    private function benchNodes(): array
    {
        $nodes = Node::whereHas('park', fn ($p) => $p->where('is_test', true))->where('type', NodeType::Sensor)->get();
        if ($nodes->isEmpty()) {
            return ['info', 'No test bench (php artisan namaa:bench:setup)'];
        }
        $posting = $nodes->filter(fn (Node $n) => $n->last_seen_at?->gt(now()->subMinute()))->count();

        return ['info', "Bench: {$posting} of {$nodes->count()} sensor nodes posting in the last minute"];
    }

    private function nodeTokens(): array
    {
        // Boards that post readings need a device token; the camera is polled by the server and has none.
        $ids = Node::where('type', '!=', NodeType::Camera)->pluck('id');
        $nodes = $ids->count();
        $withToken = PersonalAccessToken::where('tokenable_type', (new Node)->getMorphClass())
            ->whereIn('tokenable_id', $ids)->distinct()->count('tokenable_id');
        $file = storage_path('app/private/node-tokens.txt');
        $fileNote = is_file($file) ? 'node-tokens.txt present' : 'node-tokens.txt missing';

        return [$withToken >= $nodes ? 'ok' : 'fail', "$withToken of $nodes sensor nodes have a device token; $fileNote"];
    }

    private function disk(): array
    {
        $free = @disk_free_space(storage_path());
        if ($free === false) {
            return ['warn', 'Free space unknown'];
        }
        $gb = round($free / 1024 ** 3, 1);

        return [$free >= self::MIN_FREE_BYTES ? 'ok' : 'warn', "{$gb} GB free for storage/"];
    }
}
