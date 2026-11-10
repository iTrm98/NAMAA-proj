<?php

namespace App\Console\Commands;

use App\Enums\DataSource;
use App\Enums\NodeType;
use App\Models\Node;
use App\Models\Park;
use App\Services\ActivityLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * "Sim Cam": a simulated camera on Al Adel Park for the demo page (webcam,
 * upload) and the litter scenario, so test frames never land on a real park's
 * camera. Safe to run again. Its device token is created once and appended to
 * storage/app/private/node-tokens.txt (existing lines are never changed); the
 * token is never printed.
 */
#[Signature('namaa:sim:camera')]
#[Description('Create the simulated camera "Sim Cam" on Al Adel Park (idempotent).')]
class SimCamera extends Command
{
    public const NAME = 'Sim Cam';

    public const PARK_NAME_EN = 'Al Adel Park';

    public const TOKEN_FILE = 'app/private/node-tokens.txt';

    public function handle(ActivityLogger $logger): int
    {
        $park = Park::where('name_en', self::PARK_NAME_EN)->first();
        if (! $park) {
            $this->error(self::PARK_NAME_EN.' not found.');

            return self::FAILURE;
        }
        if ($park->source !== DataSource::Simulated) {
            $this->error(self::PARK_NAME_EN.' is a real park: Sim Cam goes on a simulated park only.');

            return self::FAILURE;
        }

        $node = Node::where('park_id', $park->id)->where('name', self::NAME)->first();
        if ($node) {
            $this->info(sprintf('Nothing to do: %s (node #%d) is already on %s.', self::NAME, $node->id, $park->name_en));

            return self::SUCCESS;
        }

        $node = Node::create([
            'park_id' => $park->id, 'type' => NodeType::Camera, 'name' => self::NAME,
            'has_pump' => false, 'pump_target' => false, 'source' => DataSource::Simulated,
        ]);
        $logger->log('node.created', $node, ['after' => [
            'name' => self::NAME, 'type' => 'camera', 'source' => 'simulated', 'park_id' => $park->id,
        ]], companyId: $park->company_id);

        $token = $node->createToken('device', ['device'])->plainTextToken;
        // Tests use throwaway tokens: never touch the real file.
        if (! app()->runningUnitTests()) {
            self::appendTokenLine([$node->id, $node->name, $park->name_en, 'simulated', $token]);
        }

        $this->info(sprintf('Created %s (node #%d, camera, simulated) on %s. Its token was appended to storage/%s.',
            self::NAME, $node->id, $park->name_en, self::TOKEN_FILE));

        return self::SUCCESS;
    }

    /** Appends one tab-separated line; the lines already in the file are left as they are. @param list<int|string> $row */
    public static function appendTokenLine(array $row, ?string $file = null): void
    {
        $file ??= storage_path(self::TOKEN_FILE);
        $prefix = is_file($file) && filesize($file) > 0 && ! str_ends_with((string) file_get_contents($file, offset: filesize($file) - 1), "\n")
            ? PHP_EOL : '';
        File::append($file, $prefix.implode("\t", $row).PHP_EOL);
    }
}
