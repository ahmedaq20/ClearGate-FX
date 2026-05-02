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

class AuthController extends BaseApiController
{
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

        $token = method_exists($user, 'createToken')
            ? $user->createToken('api')->plainTextToken
            : null;

        return $this->sendResponse([
            'user' => $user,
            'token' => $token,
        ], 'تم تسجيل الدخول');
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user !== null && method_exists($user, 'currentAccessToken') && $user->currentAccessToken() !== null) {
            $user->currentAccessToken()->delete();
        }

        Auth::logout();

        return $this->sendResponse(null, 'تم تسجيل الخروج');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->sendResponse($request->user());
    }

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
