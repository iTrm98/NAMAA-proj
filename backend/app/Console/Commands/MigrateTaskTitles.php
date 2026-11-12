<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Support\TaskTitles;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 6 (B5), one-off: gives existing tasks whose English title is a known
 * system title (with their park's name) a title_key and title_park_id. Free-text
 * titles are left alone; `title` is never changed. Safe to run again.
 */
#[Signature('namaa:tasks:title-keys {--dry-run : Print what would change, change nothing}')]
#[Description('Map existing tasks with known English system titles to translation keys.')]
class MigrateTaskTitles extends Command
{
    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = [];
        $skipped = 0;

        Task::with('park:id,name_en')->whereNull('title_key')->orderBy('id')->each(function (Task $task) use ($dry, &$changed, &$skipped) {
            $key = TaskTitles::keyFor($task->title, $task->park->name_en);

            if ($key === null) {
                $skipped++;

                return;
            }

            $changed[] = sprintf('task #%d "%s" -> %s (park #%d)', $task->id, $task->title, $key, $task->park_id);
            if (! $dry) {
                $task->forceFill(['title_key' => $key, 'title_park_id' => $task->park_id])->saveQuietly();
            }
        });

        $this->line(sprintf('%s %d task(s); %d free-text title(s) left alone.', $dry ? 'Dry run: would map' : 'Mapped', count($changed), $skipped));
        foreach ($changed as $line) {
            $this->line('  '.$line);
        }
        Log::info('namaa:tasks:title-keys', ['dry_run' => $dry, 'mapped' => $changed, 'skipped' => $skipped]);

        return self::SUCCESS;
    }
}
