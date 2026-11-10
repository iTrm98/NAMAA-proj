<?php

namespace App\Services;

use App\Models\Node;
use Illuminate\Support\Facades\File;

/**
 * Device tokens of nodes. regenerate() revokes the old ones, creates a new one and replaces
 * that node's line in the tokens file (config namaa.node_tokens_file). The token is
 * returned to the caller once and is never logged.
 */
class NodeTokens
{
    public function regenerate(Node $node): string
    {
        $node->tokens()->delete();
        $token = $node->createToken('device', ['device'])->plainTextToken;
        $this->writeLine($node, $token);

        return $token;
    }

    private function writeLine(Node $node, string $token): void
    {
        $file = (string) config('namaa.node_tokens_file');
        $node->loadMissing('park');
        $row = implode("\t", [$node->id, $node->name, $node->park->name_en, $node->source->value, $token]);

        $lines = is_file($file) ? preg_split('/\r\n|\n|\r/', rtrim((string) file_get_contents($file), "\r\n")) : [];
        $found = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^'.$node->id."\t/", $line)) {
                $lines[$i] = $row;
                $found = true;
            }
        }
        if (! $found) {
            $lines[] = $row;
        }

        File::ensureDirectoryExists(dirname($file));
        File::put($file, implode(PHP_EOL, $lines).PHP_EOL);
    }
}
