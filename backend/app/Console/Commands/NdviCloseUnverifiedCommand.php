<?php

namespace App\Console\Commands;

use App\Enums\AlertType;
use App\Enums\GeometrySource;
use App\Models\Alert;
use App\Models\Task;
use App\Services\AlertService;
use App\Services\TaskService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Closes ndvi_drop alerts that were raised on an outline nobody traced (the old rough rectangles, raw OSM
 * outlines): the number was measured on paths and open land. The note is the reason of the alert.resolved log.
 */
#[Signature('namaa:ndvi:close-unverified {--dry-run : list the alerts, change nothing}')]
#[Description('Close open ndvi_drop alerts raised on untraced geometry ("Raised on an unverified outline").')]
class NdviCloseUnverifiedCommand extends Command
{
    public const NOTE = 'Raised on an unverified outline';

    public function handle(AlertService $alerts, TaskService $tasks): int
    {
        $found = Alert::open()->where('type', AlertType::NdviDrop->value)
            ->whereHas('park', fn ($q) => $q->where('geometry_source', '!=', GeometrySource::Traced->value))
            ->with('park')->orderBy('id')->get();

        if ($found->isEmpty()) {
            $this->info('No open ndvi_drop alert on untraced geometry.');

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry-run');
        $closed = 0;
        foreach ($found as $alert) {
            $active = Task::active()->where('alert_id', $alert->id)->get();
            $this->line(sprintf('%s alert #%d on #%d %s (%s), %d active task(s)',
                $dry ? 'Would close' : 'Closing', $alert->id, $alert->park_id, $alert->park->name_en, $alert->park->geometry_source->value, $active->count()));
            if ($dry) {
                continue;
            }
            $alerts->resolve($alert, self::NOTE);
            foreach ($active as $task) {
                $tasks->cancel($task, null, self::NOTE);
            }
            $closed++;
        }

        $this->info($dry ? "{$found->count()} alert(s) would be closed (dry run, nothing changed)." : "$closed closed: ".self::NOTE.'.');

        return self::SUCCESS;
    }
}
