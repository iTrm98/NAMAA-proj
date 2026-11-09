<?php

namespace App\Enums;

/**
 * What the problem is and who handles it (decision table, "Category + priority").
 * Derived from the alert type, never stored.
 */
enum AlertCategory: string
{
    case Water = 'water';             // plumber / irrigation
    case Plant = 'plant';             // irrigation worker
    case Cleanliness = 'cleanliness'; // cleaner
    case Devices = 'devices';         // technician

    public static function fromType(AlertType $type): self
    {
        return match ($type) {
            AlertType::Leak, AlertType::NoFlow, AlertType::Blockage, AlertType::Over => self::Water,
            AlertType::Dry, AlertType::HeatStress, AlertType::NdviDrop => self::Plant,
            AlertType::Litter => self::Cleanliness,
            AlertType::Offline, AlertType::SensorFault, AlertType::LowBattery => self::Devices,
        };
    }
}
