<?php

namespace App\Policies;

use App\Models\Node;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Hardware page: an admin manages the nodes of their own company's parks.
 * Another company's node answers 404 so its existence does not leak.
 */
class NodePolicy
{
    public function manage(User $user, Node $node): Response
    {
        return $user->isAdmin() && $user->company_id !== null && $user->company_id === $node->park->company_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
