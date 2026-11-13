<?php

namespace App\Console\Commands;

use App\Enums\SiteType;
use App\Models\Node;
use App\Services\ActivityLogger;
use App\Services\NodeTokens;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('namaa:stand:rotate-token {--dry-run : print what would happen, change nothing}')]
#[Description('Rotate the device token of Bench A (the demo stand board) only. The new token is printed once.')]
class StandRotateToken extends Command
{
    public const BOARD = 'Bench A';

    public function handle(NodeTokens $tokens, ActivityLogger $logger): int
    {
        $nodes = Node::with('park')->where('name', self::BOARD)
            ->whereHas('park', fn ($q) => $q->where('site_type', SiteType::DemoStand))
            ->orderBy('id')->get();
        if ($nodes->count() !== 1) {
            $this->error($nodes->isEmpty() ? 'Bench A not found on the demo stand.' : 'More than one Bench A on the demo stand; refusing to guess.');

            return self::FAILURE;
        }
        $node = $nodes->first();

        $count = $node->tokens()->count();
        if ($this->option('dry-run')) {
            $this->line("Dry run: would revoke $count token(s) of node #{$node->id} ({$node->name}) and issue one new token. Nothing changed.");

            return self::SUCCESS;
        }

        $token = $tokens->regenerate($node);
        $logger->log('node.token_regenerated', $node, ['after' => ['name' => $node->name, 'reason' => 'stand_rotate']], companyId: $node->park->company_id);

        $this->line("Node #{$node->id} ({$node->name}): $count old token(s) revoked. New token (shown once):");
        $this->line($token);

        return self::SUCCESS;
    }
}
