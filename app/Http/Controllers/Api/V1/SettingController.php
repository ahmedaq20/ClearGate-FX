<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Setting\UpdateSettingsRequest;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Settings
 *
 * Read and manage application settings grouped by feature area.
 */
class SettingController extends BaseApiController
{
    public function __construct(
        private SettingsService $settingsService,
    ) {}

    /**
     * Public settings
     *
     * Return settings marked as public. This endpoint is available without authentication.
     *
     * @unauthenticated
     *
     * @response 200 {"success":true,"message":"Success","data":{"shop_name":"ClearGate FX","default_currency":"USD"}}
     */
    public function publicSettings(): JsonResponse
    {
        return $this->sendResponse($this->settingsService->publicSettings());
    }

    /**
     * List all settings
     *
     * Owner-only endpoint returning every setting sorted by group and key.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"Success","data":[{"key":"shop_name","value":"ClearGate FX","group_name":"general","is_public":true}]}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function index(Request $request): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        return $this->sendResponse(Setting::query()->orderBy('group_name')->orderBy('key')->get());
    }

    /**
     * Settings group
     *
     * Return settings for one group. Owners and users with settings.view may access it.
     *
     * @authenticated
     *
     * @urlParam group string required Settings group name. Example: general
     *
     * @response 200 {"success":true,"message":"Success","data":{"shop_name":"ClearGate FX","default_currency":"USD"}}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function group(Request $request, string $group): JsonResponse
    {
        if (! $this->isOwner($request->user()) && ! $request->user()?->can('settings.view')) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse($this->settingsService->group($group));
    }

    /**
     * Update settings
     *
     * Owner-only endpoint for updating settings by key/value pairs.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تحديث الإعدادات"}
     * @response 422 {"success":false,"message":"Validation Error"}
     */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        foreach ($request->validated('settings') as $key => $value) {
            $this->settingsService->set($key, $value);
        }

        return $this->sendResponse(null, 'تم تحديث الإعدادات');
    }

    /**
     * Reset settings group
     *
     * Owner-only endpoint that deletes all settings in a group so defaults can be recreated later.
     *
     * @authenticated
     *
     * @urlParam group string required Settings group name. Example: general
     *
     * @response 200 {"success":true,"message":"تمت إعادة ضبط الإعدادات"}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function reset(Request $request, string $group): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        Setting::query()->where('group_name', $group)->delete();

        return $this->sendResponse(null, 'تمت إعادة ضبط الإعدادات');
    }
}
