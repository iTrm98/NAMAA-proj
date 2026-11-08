<?php

namespace App\Http\Resources\Concerns;

use DateTimeInterface;
use Illuminate\Support\Carbon;

trait FormatsTimes
{
    /** ISO 8601 in UTC without fractions: 2026-11-18T09:30:00Z (api.md, Conventions). */
    protected function iso(?DateTimeInterface $time): ?string
    {
        return $time ? Carbon::instance($time)->utc()->format('Y-m-d\TH:i:s\Z') : null;
    }
}
