<?php

namespace App\Enums;

enum AlertType: string
{
    case Dry = 'dry';
    case HeatStress = 'heat_stress';
    case Over = 'over';
    case NoFlow = 'no_flow';
    case Leak = 'leak';
    case Blockage = 'blockage';
    case Offline = 'offline';
    case SensorFault = 'sensor_fault';
    case LowBattery = 'low_battery';
    case Litter = 'litter';
    case LitterRepeated = 'litter_repeated';
    case NdviDrop = 'ndvi_drop';

    public function category(): AlertCategory
    {
        return AlertCategory::fromType($this);
    }

    /**
     * Base priority (decision table): how long we can wait before damage.
     * The first "yes" of the four questions sets it: irreversible damage ->
     * urgent, waste happening now -> high, visible to visitors / our own
     * devices -> normal, otherwise low. An alert's stored priority can rise
     * later (heat stress, offline raise).
     */
    public function priority(): TaskPriority
    {
        return match ($this) {
            self::Leak, self::NoFlow, self::HeatStress => TaskPriority::Urgent,
            self::Dry, self::Blockage, self::LitterRepeated => TaskPriority::High,
            self::Over, self::Litter, self::Offline, self::SensorFault => TaskPriority::Normal,
            self::NdviDrop, self::LowBattery => TaskPriority::Low,
        };
    }

    /** Kept for older callers: the priority a task made from this alert starts with. */
    public function taskPriority(): TaskPriority
    {
        return $this->priority();
    }

    /** Internal severity (never shown in the UI; users see the priority). */
    public function severity(): AlertSeverity
    {
        return match ($this) {
            self::Dry, self::HeatStress, self::NoFlow, self::Leak => AlertSeverity::Critical,
            self::Over, self::Blockage, self::Offline, self::SensorFault, self::Litter, self::LitterRepeated => AlertSeverity::Warning,
            self::NdviDrop, self::LowBattery => AlertSeverity::Info,
        };
    }

    /** Litter seen by a camera: the camera-frame rules own these, not the reading rules. */
    public function isLitter(): bool
    {
        return $this === self::Litter || $this === self::LitterRepeated;
    }

    /** Offline alerts wait (task-ready only after a delay); every other type can get a task at once. */
    public function taskReadyAtOnce(): bool
    {
        return $this !== self::Offline;
    }
}
