<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Box\CreateBoxAdjustmentRequest;
use App\Models\Box;
use App\Models\BoxAdjustment;
use App\Models\User;
use App\Services\BoxAdjustmentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BoxAdjustmentController extends BaseApiController
{
    public function __construct(private readonly BoxAdjustmentService $boxAdjustmentService) {}

    public function store(CreateBoxAdjustmentRequest $request, Box $box): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! $this->canCreateAdjustment($user)) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $adjustment = $this->boxAdjustmentService->create($box, $user, $request->validated());

        return $this->sendResponse($adjustment, 'تم إنشاء تعديل رصيد الصندوق', 201);
    }

    public function boxAdjustments(Request $request, Box $box): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! $this->canViewBoxAdjustments($user, $box)) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse(
            $box->adjustments()
                ->with('creator:id,name,email')
                ->latest()
                ->paginate((int) $request->integer('per_page', 15))
        );
    }

    public function index(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! $this->canViewAdjustments($user)) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse(
            BoxAdjustment::query()
                ->with(['box:id,name,type,current_balance,currency,assigned_user_id', 'creator:id,name,email'])
                ->when(
                    $user->hasRole('operations_employee', 'sanctum') && ! $user->can('box.adjustment.view'),
                    fn (Builder $query): Builder => $query->whereHas(
                        'box',
                        fn (Builder $boxQuery): Builder => $boxQuery->where('assigned_user_id', $user->id)
                    )
                )
                ->latest()
                ->paginate((int) $request->integer('per_page', 15))
        );
    }

    private function canCreateAdjustment(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'admin'], 'sanctum')
            || $user->can('box.adjustment.create');
    }

    private function canViewAdjustments(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'admin'], 'sanctum')
            || $user->can('box.adjustment.view')
            || $user->hasRole('operations_employee', 'sanctum');
    }

    private function canViewBoxAdjustments(User $user, Box $box): bool
    {
        if (! $this->canViewAdjustments($user)) {
            return false;
        }

        if (! $user->hasRole('operations_employee', 'sanctum') || $user->can('box.adjustment.view')) {
            return true;
        }

        return (int) $box->assigned_user_id === $user->id;
    }
}
