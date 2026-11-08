<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Writes the audit trail (activity_logs). Use it for every user or system action:
 *
 *   $logger->log('park.updated', $park, ['before' => [...], 'after' => [...]]);
 *
 * The user defaults to the authenticated User of the current request (null for
 * nodes and the system); company_id defaults to the user's company, then the
 * subject's company_id.
 */
class ActivityLogger
{
    public function __construct(private readonly Request $request) {}

    public function log(
        string $action,
        ?Model $subject = null,
        ?array $diff = null,
        ?User $user = null,
        ?int $companyId = null,
    ): ActivityLog {
        $user ??= $this->request->user() instanceof User ? $this->request->user() : null;
        $companyId ??= $user?->company_id ?? $subject?->getAttribute('company_id');

        return ActivityLog::create([
            'company_id' => $companyId,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'diff' => $diff,
            'ip' => $this->request->ip(),
        ]);
    }
}
