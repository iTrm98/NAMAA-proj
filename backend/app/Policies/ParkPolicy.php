<?php

namespace App\Policies;

use App\Models\Park;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Admins see and change only their own company's parks. Another company's
 * park answers 404 (not 403) so its existence does not leak.
 * Municipality users read every park through /api/municipality (viewAny only).
 */
class ParkPolicy
{
    public function view(User $user, Park $park): Response
    {
        return $this->sameCompany($user, $park);
    }

    public function update(User $user, Park $park): Response
    {
        return $user->isAdmin() ? $this->sameCompany($user, $park) : Response::deny();
    }

    private function sameCompany(User $user, Park $park): Response
    {
        return $user->company_id !== null && $user->company_id === $park->company_id
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
