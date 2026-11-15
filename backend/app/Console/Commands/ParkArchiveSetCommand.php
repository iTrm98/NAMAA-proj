<?php

namespace App\Console\Commands;

use App\Models\Park;
use App\Services\ParkModeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Archives the parks of namaa.parks.archive_set (Sprint 6.3) through ParkModeService::archive,
 * so alerts are resolved, tasks cancelled and nodes detached the usual way. Names are matched
 * loosely: every word of the configured name must appear in the park's English or Arabic name.
 */
#[Signature('namaa:parks:archive-set {--dry-run : print what would change}')]
#[Description('Archive the configured set of parks that leave the demo (name match is tolerant; not-found names are reported).')]
class ParkArchiveSetCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
        $this->setAliases(['namaa:parks-archive']);
    }

    public function handle(ParkModeService $modes): int
    {
        $dry = (bool) $this->option('dry-run');
        $rows = [];

        foreach ((array) config('namaa.parks.archive_set') as $name) {
            $matches = self::match((string) $name);
            if ($matches === []) {
                $rows[] = [$name, '-', 'NOT FOUND (or already archived)'];

                continue;
            }
            if (count($matches) > 1) {
                $rows[] = [$name, '-', 'AMBIGUOUS: '.implode(', ', array_map(fn (Park $p) => "#{$p->id} {$p->name_en}", $matches))];

                continue;
            }

            $park = $matches[0];
            try {
                $plan = $modes->archive($park, $dry);
                $rows[] = [$name, "#{$park->id} {$park->name_en}", ($dry ? 'would archive' : 'archived')." (alerts {$plan['alerts']}, tasks {$plan['tasks']})"];
            } catch (InvalidArgumentException $e) {
                $rows[] = [$name, "#{$park->id} {$park->name_en}", 'SKIPPED: '.$e->getMessage()];
            }
        }

        $this->table(['Configured name', 'Park', 'Result'], $rows);
        $this->line(($dry ? 'DRY RUN, nothing changed. ' : '').collect($rows)->filter(fn ($r) => str_starts_with($r[2], 'would archive') || str_starts_with($r[2], 'archived'))->count().' of '.count($rows).' name(s) archived.');

        return self::SUCCESS;
    }

    /** @return list<Park> non-archived parks whose English or Arabic name holds every word of $name */
    public static function match(string $name): array
    {
        $words = self::words($name);
        if ($words === []) {
            return [];
        }

        return Park::whereNull('archived_at')->get()
            ->filter(function (Park $park) use ($words) {
                foreach ([$park->name_en, $park->name_ar] as $candidate) {
                    $have = self::words((string) $candidate);
                    if ($have !== [] && array_diff($words, $have) === []) {
                        return true;
                    }
                }

                return false;
            })->values()->all();
    }

    /** @return list<string> lower-case words without apostrophes and punctuation */
    public static function words(string $text): array
    {
        $text = mb_strtolower(str_replace(["'", '’', '`'], '', $text));

        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $text) ?: []));
    }
}
