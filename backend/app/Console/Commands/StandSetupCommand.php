<?php

namespace App\Console\Commands;

use App\Services\StandSetup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('namaa:stand:setup {--dry-run : print what would change} {--move-real-nodes : move Model A, Model B and Camera 1 onto the stand} {--nodes= : comma-separated node ids to move instead (implies --move-real-nodes)}')]
#[Description('Turn the Workshop park into the NAMAA demo stand; optionally move the real boards onto it (tokens unchanged).')]
class StandSetupCommand extends Command
{
    public function handle(StandSetup $setup): int
    {
        $ids = null;
        if ($this->option('nodes')) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('nodes')))));
            if ($ids === []) {
                $this->error('--nodes needs node ids, e.g. --nodes=1,2,3.');

                return self::FAILURE;
            }
        }

        try {
            $plan = $setup->run((bool) $this->option('move-real-nodes') || $ids !== null, $ids, (bool) $this->option('dry-run'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(($plan['dry_run'] ? 'DRY RUN, nothing changed. ' : '')."Stand #{$plan['stand']['id']}: {$plan['stand']['from']} -> {$plan['stand']['to']} (site_type demo_stand)");
        $this->table(['Node', 'Name', 'From park'], array_map(fn ($m) => [$m['node_id'], $m['name'], $m['from_park']], $plan['moves']));
        $this->line('Set to satellite_only: '.($plan['satellite_only'] === [] ? 'none' : implode(', ', $plan['satellite_only'])));

        if ($plan['bench_board_token']) {
            $this->warn(StandSetup::BENCH_BOARD.' created. Its device token is shown ONCE and is not stored anywhere readable; copy it into the firmware secrets now:');
            $this->line($plan['bench_board_token']);
        } else {
            $this->line(StandSetup::BENCH_BOARD.': '.($plan['bench_board'] === 'create' ? 'would be created (token shown once on a real run)' : 'already exists (regenerate its token from the Hardware page if lost)'));
        }

        return self::SUCCESS;
    }
}
