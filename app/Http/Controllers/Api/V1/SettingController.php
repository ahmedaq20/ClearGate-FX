<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Setting\UpdateSettingsRequest;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingController extends BaseApiController
{
    public function __construct(
        private SettingsService $settingsService,
    ) {}

    public function publicSettings(): JsonResponse
    {
        return $this->sendResponse($this->settingsService->publicSettings());
    }

    public function index(Request $request): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        return $this->sendResponse(Setting::query()->orderBy('group_name')->orderBy('key')->get());
    }

    public function group(Request $request, string $group): JsonResponse
    {
        if (! $this->isOwner($request->user()) && ! $request->user()?->can('settings.view')) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse($this->settingsService->group($group));
    }

    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        foreach ($request->validated('settings') as $key => $value) {
            $this->settingsService->set($key, $value);
        }

        return $this->sendResponse(null, 'تم تحديث الإعدادات');
    }

    public function reset(Request $request, string $group): JsonResponse
    {
        if ($error = $this->abortUnlessOwner($request)) {
            return $error;
        }

        Setting::query()->where('group_name', $group)->delete();

        return $this->sendResponse(null, 'تمت إعادة ضبط الإعدادات');
    }
}
