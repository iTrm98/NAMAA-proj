<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request, ActivityLogger $logger): JsonResponse
    {
        $user = User::where('email', $request->string('email')->lower()->toString())->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            // One generic message: never say whether the email or the password was wrong.
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $logger->log('auth.login', $user, user: $user);

        return self::tokenResponse($user);
    }

    /** A new user token (ability `user`) and the user: the login response, also used by the demo login. */
    public static function tokenResponse(User $user): JsonResponse
    {
        $token = $user->createToken('dashboard', ['user'])->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->load('company')),
        ]);
    }

    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user()->load('company'))]);
    }
}
