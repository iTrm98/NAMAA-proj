<?php

namespace App\Console\Commands;

use App\Services\CameraPipeline;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('namaa:prune-camera')]
#[Description('Delete camera photos older than 7 days, except frames attached to an alert (and so to its tasks).')]
class PruneCameraImages extends Command
{
    public function handle(CameraPipeline $pipeline): int
    {
        $this->info('Camera frames deleted: '.$pipeline->prune());

        return self::SUCCESS;
    }
}
