<?php

namespace App\Services;

use App\Enums\AlertType;
use App\Models\Alert;
use App\Models\Detection;
use App\Support\Namaa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The cleanliness rules (decision table, litter), run after every camera frame.
 * Frames are those of one camera node, consecutive, newest included.
 *
 *  - litter (normal): litter (>= 1 item) in 2 frames in a row. Resolves after
 *    2 clean frames in a row.
 *  - litter_repeated (high): the open litter alert is upgraded in place (one
 *    alert per problem) when
 *      1. litter came back 3 times within the window (1 h, 3 min demo): an
 *         "episode" is a litter frame after a clean frame (or the first one), so
 *         litter that simply stays does not count as repeated;
 *      2. one frame has 5 or more items;
 *      3. the litter alert is still open after 24 h (3 min demo).
 *
 * alerts.detection_id is the newest litter frame of the alert, so the photo
 * and the item count shown with it always describe the same frame; the frame
 * that raised it stays in data.first_detection_id.
 */
class LitterRules
{
    public function __construct(
        private readonly AlertService $alerts,
    ) {}

    public function afterFrame(Detection $frame): ?Alert
    {
        return DB::transaction(function () use ($frame) {
            $frame->loadMissing(['park', 'node']);
            $park = $frame->park;
            $open = $this->openAlert($frame);

            $recent = Detection::where('node_id', $frame->node_id)
                ->orderByDesc('captured_at')->orderByDesc('id')
                ->limit(max($this->confirm(), $this->resolve()))
                ->get();

            if ($open) {
                if ($this->all($recent, $this->resolve(), clean: true)) {
                    $this->alerts->resolve($open, 'clean_frames');

                    return $open;
                }

                if ($frame->hasLitter()) {
                    $open->detection_id = $frame->id; // saved by touch()'s update
                    $this->alerts->touch($open, $this->data($frame, $open));
                    $this->maybeRepeat($open, $frame);
                }

                return $open;
            }

            if (! $this->all($recent, $this->confirm(), clean: false)) {
                return null;
            }

            $alert = $this->alerts->raise(
                $park, $frame->node, AlertType::Litter, AlertType::Litter->severity(),
                $this->data($frame),
                attributes: ['detection_id' => $frame->id],
            );
            $alert->setRelation('park', $park);
            $this->maybeRepeat($alert, $frame);

            return $alert;
        });
    }

    /** The age condition, checked on a schedule (a camera may stop sending frames). Returns upgrades made. */
    public function checkAge(): int
    {
        $limit = now()->subSeconds(Namaa::timing('litter_open_long_seconds'));
        $upgraded = 0;

        foreach (Alert::open()->where('type', AlertType::Litter)->where('created_at', '<=', $limit)->with('park')->get() as $alert) {
            $this->alerts->convert($alert, AlertType::LitterRepeated, 'open_long', ($alert->data ?? []) + ['repeated_reason' => 'open_long']);
            $upgraded++;
        }

        return $upgraded;
    }

    private function maybeRepeat(Alert $alert, Detection $frame): void
    {
        if ($alert->type !== AlertType::Litter) {
            return;
        }

        $reason = match (true) {
            $frame->count >= (int) Namaa::rule('litter_repeated_items') => 'many_items',
            $this->episodes($frame) >= (int) Namaa::rule('litter_repeated_frames') => 'came_back',
            $alert->created_at->lte(now()->subSeconds(Namaa::timing('litter_open_long_seconds'))) => 'open_long',
            default => null,
        };

        if ($reason === null) {
            return;
        }

        // The frame that upgraded it is shown from now on (saved by convert()'s update).
        $alert->detection_id = $frame->id;
        $this->alerts->convert($alert, AlertType::LitterRepeated, $reason, [
            'repeated_reason' => $reason,
            'detection_id' => $frame->id,
            'count' => $frame->count,
        ] + ($alert->data ?? []));
    }

    /** Litter episodes of this camera within the window: litter frames that follow a clean frame (or none). */
    private function episodes(Detection $frame): int
    {
        $from = $frame->captured_at->copy()->subSeconds(Namaa::timing('litter_repeat_window_seconds'));

        $frames = Detection::where('node_id', $frame->node_id)
            ->where('captured_at', '<=', $frame->captured_at)
            ->where('captured_at', '>=', $from)
            ->orderBy('captured_at')->orderBy('id')
            ->get(['id', 'count', 'captured_at']);

        // The frame just before the window decides whether the first one starts an episode.
        $before = Detection::where('node_id', $frame->node_id)->where('captured_at', '<', $from)
            ->orderByDesc('captured_at')->orderByDesc('id')->value('count');
        $previousLitter = $before !== null && $before > 0;
        $episodes = 0;

        foreach ($frames as $f) {
            $litter = $f->count > 0;
            if ($litter && ! $previousLitter) {
                $episodes++;
            }
            $previousLitter = $litter;
        }

        return $episodes;
    }

    private function openAlert(Detection $frame): ?Alert
    {
        $alert = Alert::open()->where('park_id', $frame->park_id)->where('node_id', $frame->node_id)
            ->whereIn('type', [AlertType::Litter->value, AlertType::LitterRepeated->value])
            ->lockForUpdate()->first();
        $alert?->setRelation('park', $frame->park);

        return $alert;
    }

    /** @param Collection<int, Detection> $recent newest first */
    private function all(Collection $recent, int $n, bool $clean): bool
    {
        return $recent->count() >= $n && $recent->take($n)->every(fn (Detection $d) => $clean ? ! $d->hasLitter() : $d->hasLitter());
    }

    /** @return array<string, mixed> */
    private function data(Detection $frame, ?Alert $open = null): array
    {
        return [
            'count' => $frame->count,
            'labels' => array_values(array_unique(array_column($frame->items ?? [], 'label'))),
            'detection_id' => $frame->id,
            'first_detection_id' => $open?->data['first_detection_id'] ?? $frame->id,
            'frames' => $open ? (int) ($open->data['frames'] ?? $this->confirm()) + 1 : $this->confirm(),
        ] + array_intersect_key($open?->data ?? [], array_flip(['repeated_reason']));
    }

    private function confirm(): int
    {
        return (int) Namaa::rule('litter_confirm_frames');
    }

    private function resolve(): int
    {
        return (int) Namaa::rule('litter_resolve_frames');
    }
}
