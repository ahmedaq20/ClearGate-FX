<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * @group Auth
 *
 * Endpoints for API token login, logout, profile lookup, and password changes.
 */
class AuthController extends BaseApiController
{
    /**
     * Login
     *
     * Authenticate an active user and return a Sanctum bearer token.
     *
     * @unauthenticated
     *
     * @response 200 {"success":true,"message":"تم تسجيل الدخول","data":{"user":{"id":1,"name":"Owner","email":"owner@example.com"},"token":"1|example-token"}}
     * @response 401 {"success":false,"message":"بيانات الدخول غير صحيحة"}
     * @response 403 {"success":false,"message":"الحساب موقوف"}
     */
    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::attempt($request->validated())) {
            return $this->sendError('بيانات الدخول غير صحيحة', [], 401);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->is_active) {
            Auth::logout();

            return $this->sendError('الحساب موقوف', [], 403);
        }

        $user->update(['last_login_at' => now()]);

        $token = $user->createToken('api')->plainTextToken;

        return $this->sendResponse([
            'user' => $user,
            'token' => $token,
        ], 'تم تسجيل الدخول');
    }

    /**
     * Logout
     *
     * Revoke the current Sanctum token for the authenticated user.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تسجيل الخروج"}
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user !== null && method_exists($user, 'currentAccessToken') && $user->currentAccessToken() !== null) {
            $user->currentAccessToken()->delete();
        }

        Auth::logout();

        return $this->sendResponse(null, 'تم تسجيل الخروج');
    }

    /**
     * Current user
     *
     * Return the authenticated user profile.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"Success","data":{"id":1,"name":"Owner","email":"owner@example.com"}}
     */
    public function me(Request $request): JsonResponse
    {
        return $this->sendResponse($request->user());
    }

    /**
     * Change password
     *
     * Update the authenticated user's password after verifying the current password.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تغيير كلمة المرور"}
     * @response 422 {"success":false,"message":"كلمة المرور الحالية غير صحيحة"}
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->string('current_password')->toString(), $user->password)) {
            return $this->sendError('كلمة المرور الحالية غير صحيحة', [], 422);
        }

        $user->update(['password' => $request->string('password')->toString()]);

        return $this->sendResponse(null, 'تم تغيير كلمة المرور');
    }
}
