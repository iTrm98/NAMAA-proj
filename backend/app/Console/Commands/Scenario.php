<?php

namespace App\Console\Commands;

use App\Services\ScenarioService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('namaa:scenario {name : reset | dry | heat | leak | blockage | battery | offline | escalate | recover} {--full : with reset: also put the healthy parks back to the start}')]
#[Description('Demo scenario on the simulated parks (never the real ones).')]
class Scenario extends Command
{
    public function handle(ScenarioService $scenarios): int
    {
        $name = (string) $this->argument('name');

        if (! in_array($name, ScenarioService::NAMES, true)) {
            $this->error('Unknown scenario. Use one of: '.implode(', ', ScenarioService::NAMES));

            return self::INVALID;
        }

        try {
            $summary = $scenarios->run($name, full: (bool) $this->option('full'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info(json_encode($summary));

        return self::SUCCESS;
    }
}
