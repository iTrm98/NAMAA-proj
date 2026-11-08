<?php

namespace Database\Factories;

use App\Enums\DataSource;
use App\Enums\ParkStatus;
use App\Enums\ParkType;
use App\Models\Company;
use App\Models\Park;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Park>
 */
class ParkFactory extends Factory
{
    public function definition(): array
    {
        // Somewhere in Makkah, around 21.42 N, 39.82 E.
        $lat = round(21.42 + fake()->randomFloat(4, -0.04, 0.04), 7);
        $lng = round(39.82 + fake()->randomFloat(4, -0.05, 0.05), 7);
        $d = 0.0006; // about 60 m half-width

        return [
            'company_id' => Company::factory(),
            'name_ar' => 'حديقة '.fake()->randomElement(['العزيزية', 'الشوقية', 'النسيم', 'العوالي', 'الزاهر', 'الرصيفة']),
            'name_en' => fake()->randomElement(['Al Aziziyah', 'Ash Shawqiyah', 'An Naseem', 'Al Awali', 'Az Zahir', 'Ar Rusayfah']).' Park',
            'district' => fake()->randomElement(['Al Aziziyah', 'Ash Shawqiyah', 'An Naseem', 'Al Awali', 'Az Zahir']),
            'park_type' => fake()->randomElement(ParkType::cases()),
            'area_m2' => fake()->numberBetween(800, 20000),
            // GeoJSON Polygon, [lon, lat], closed ring.
            'polygon' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [$lng - $d, $lat - $d],
                    [$lng + $d, $lat - $d],
                    [$lng + $d, $lat + $d],
                    [$lng - $d, $lat + $d],
                    [$lng - $d, $lat - $d],
                ]],
            ],
            'lat' => $lat,
            'lng' => $lng,
            'moisture_min' => 25,
            'moisture_max' => 70,
            'baseline_l_per_day' => fake()->numberBetween(500, 5000),
            'status' => ParkStatus::Offline,
            'status_changed_at' => null,
            'last_reading_at' => null,
            'source' => DataSource::Real,
        ];
    }

    public function simulated(): static
    {
        return $this->state(fn () => ['source' => DataSource::Simulated]);
    }
}
