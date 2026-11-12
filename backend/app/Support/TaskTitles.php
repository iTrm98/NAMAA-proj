<?php

namespace App\Support;

/**
 * System task titles (Sprint 6, B5). A system title is stored as a key
 * (`taskTitle.dry`) plus the park id; the frontend renders it per language
 * with the same keys. `tasks.title` keeps the English text for compatibility.
 */
class TaskTitles
{
    /** Key => English template (the frontend's taskTitle.* texts). @var array<string, string> */
    public const TEMPLATES = [
        'taskTitle.dry' => 'Irrigate {park}',
        'taskTitle.over' => 'Reduce irrigation at {park}',
        'taskTitle.no_flow' => 'Check the pump at {park}',
        'taskTitle.leak' => 'Fix the leak at {park}',
        'taskTitle.offline' => 'Restart the sensor at {park}',
        'taskTitle.sensor_fault' => 'Check the sensor at {park}',
        'taskTitle.litter' => 'Collect litter at {park}',
        'taskTitle.ndvi_drop' => 'Inspect the green cover at {park}',
        'taskTitle.manual' => 'Check {park}',
        'taskTitle.heat_stress' => 'Water {park} now (heat stress)',
        'taskTitle.blockage' => 'Clear the blockage at {park}',
        'taskTitle.low_battery' => 'Check the battery at {park}',
        'taskTitle.litter_repeated' => 'Clean {park} (repeated litter)',
    ];

    /** Older English wordings found in existing data (seeders): template => key. @var array<string, string> */
    public const LEGACY = [
        'Fix leak at {park}' => 'taskTitle.leak',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    public static function english(string $key, string $parkName): string
    {
        return str_replace('{park}', $parkName, self::TEMPLATES[$key]);
    }

    /** The key whose English text (with this park name) equals $title, or null. */
    public static function keyFor(string $title, string $parkName): ?string
    {
        foreach (self::TEMPLATES as $key => $template) {
            if ($title === str_replace('{park}', $parkName, $template)) {
                return $key;
            }
        }
        foreach (self::LEGACY as $template => $key) {
            if ($title === str_replace('{park}', $parkName, $template)) {
                return $key;
            }
        }

        return null;
    }
}
