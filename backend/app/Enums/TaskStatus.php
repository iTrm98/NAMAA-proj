<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';

    /** Statuses a worker still has to act on. @return list<self> */
    public static function active(): array
    {
        return [self::Open, self::InProgress];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }
}
