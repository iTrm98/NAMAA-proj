<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RunScenarioRequest;
use App\Http\Requests\Demo\DemoLoginRequest;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ScenarioService;
use App\Support\Namaa;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Demo helpers. Everything except the status answers 404 when DEMO_MODE is
 * false, as if the routes did not exist.
 */
class DemoController extends Controller
{
    /** GET /api/demo/status (public). */
    public function status(): JsonResponse
    {
        return response()->json(['demo_mode' => Namaa::demoMode()]);
    }

    /**
     * POST /api/auth/demo-login { role: admin | worker } (public, 10/min per IP).
     * The account comes from config, never from the request. Same response as
     * POST /api/auth/login.
     */
    public function login(DemoLoginRequest $request, ActivityLogger $logger): JsonResponse
    {
        $email = config('namaa.demo_accounts')[$request->validated('role')];
        $user = User::where('email', $email)->first();
        abort_if($user === null, 404);

        $logger->log('auth.demo_login', $user, ['after' => ['role' => $user->role->value]], user: $user);

        return AuthController::tokenResponse($user);
    }

    /** POST /api/admin/demo/scenario { name }: the admin's company's simulated parks only. */
    public function scenario(RunScenarioRequest $request, ScenarioService $scenarios): JsonResponse
    {
        try {
            $summary = $scenarios->run($request->validated('name'), $request->user(), $request->user()->company_id);
        } catch (InvalidArgumentException $e) {
            abort(422, $e->getMessage()); // e.g. this company has no simulated node for the scenario
        }

        return response()->json(['data' => $summary]);
    }
}
