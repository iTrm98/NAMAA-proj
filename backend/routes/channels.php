<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Private channels (api.md, Realtime). Authorised at POST /api/broadcasting/auth
| with the user's Bearer token (auth:sanctum + user.token, no session).
*/

// Admins of that company.
Broadcast::channel('company.{companyId}', fn (User $user, int $companyId) => $user->isAdmin() && (int) $user->company_id === (int) $companyId);

// That user only (workers: their tasks and the parks of their tasks).
Broadcast::channel('user.{userId}', fn (User $user, int $userId) => (int) $user->id === (int) $userId);

// Municipality users: every company's events, read-only.
Broadcast::channel('municipality', fn (User $user) => $user->isMunicipality());
