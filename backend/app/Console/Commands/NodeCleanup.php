<?php

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

#[Signature('namaa:node-cleanup {--dry-run : list what would change}')]
#[Description('Detach and mark as placeholder the leftover boards (Model A/B, Camera 1, Bench B, Sim 3, nodes on archived parks).')]
class NodeCleanup extends Command
{
    public function handle(): int
    {
        $nodes = Node::query()->with('park:id,name_en,archived_at')
            ->whereNull('detached_at')
            ->when(Node::hasZoneColumn(), fn ($q) => $q->whereNull('zone'))
            ->where(fn ($q) => $q->whereIn('name', Node::PLACEHOLDER_NAMES)
                ->orWhereHas('park', fn ($p) => $p->whereNotNull('archived_at')))
            ->orderBy('id')->get();

        $this->table(['Node', 'Name', 'Park'], $nodes->map(fn (Node $n) => [$n->id, $n->name, $n->park?->name_en])->all());

        if ($this->option('dry-run')) {
            $this->line('DRY RUN, nothing changed: '.$nodes->count().' node(s).');

            return self::SUCCESS;
        }

        foreach ($nodes as $node) {
            $node->update(['detached_at' => now()] + (Schema::hasColumn('nodes', 'placeholder') ? ['placeholder' => true] : []));
        }
        $this->info('Detached '.$nodes->count().' node(s).');

        return self::SUCCESS;
    }
}
