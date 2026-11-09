<?php

namespace App\Enums;

use App\Support\Namaa;

/** Priority of an alert and of its task: low | normal | high | urgent. */
enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    /** Sort rank: urgent first. */
    public function rank(): int
    {
        return match ($this) {
            self::Urgent => 0,
            self::High => 1,
            self::Normal => 2,
            self::Low => 3,
        };
    }

    public function isHigherThan(self $other): bool
    {
        return $this->rank() < $other->rank();
    }

    /** One level up (urgent stays urgent). */
    public function next(): self
    {
        return match ($this) {
            self::Low => self::Normal,
            self::Normal => self::High,
            self::High, self::Urgent => self::Urgent,
        };
    }

    /** Response time in seconds (config namaa.timings.response_{priority}_seconds, normal or demo). */
    public function responseSeconds(): int
    {
        return Namaa::timing("response_{$this->value}_seconds");
    }

    /** SQL CASE for ORDER BY: urgent first. */
    public static function orderSql(string $column = 'priority'): string
    {
        return "case $column when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end";
    }
}
