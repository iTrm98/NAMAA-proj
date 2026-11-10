<?php

namespace App\Console\Commands;

use App\Services\SystemHealth;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/** The pre-demo checklist. Exit code 1 when a check fails (warnings pass). */
#[Signature('namaa:doctor {--json : print the checks as JSON}')]
#[Description('Check that everything the demo needs is up: database, queue, scheduler, Reverb, vision, camera, nodes, tokens, disk.')]
class Doctor extends Command
{
    private const MARK = ['ok' => '<fg=green>OK  </>', 'warn' => '<fg=yellow>WARN</>', 'fail' => '<fg=red>FAIL</>', 'info' => '<fg=cyan>INFO</>'];

    public function handle(SystemHealth $health): int
    {
        $checks = $health->checks();
        $overall = SystemHealth::overall($checks);

        if ($this->option('json')) {
            $this->line(json_encode(['overall' => $overall, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->newLine();
            $this->line('<options=bold>NAMAA doctor</> '.now('Asia/Riyadh')->format('Y-m-d H:i:s').' (Makkah)');
            $this->newLine();
            foreach ($checks as $check) {
                $this->line(sprintf('  %s  %-12s %s', self::MARK[$check['status']], $check['key'], $check['detail']));
            }
            $this->newLine();
            $this->line('  Overall: '.self::MARK[$overall]);
        }

        return $overall === 'fail' ? self::FAILURE : self::SUCCESS;
    }
}
