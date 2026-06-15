<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BoxBalanceOperationType;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Box\AdjustBoxBalanceRequest;
use App\Http\Requests\Box\StoreBoxRequest;
use App\Http\Requests\Box\UpdateBoxRequest;
use App\Http\Resources\BoxBalanceLogResource;
use App\Http\Resources\BoxResource;
use App\Models\Box;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @group Boxes
 *
 * Manage operational boxes and their balance adjustment audit logs.
 */
class BoxController extends BaseApiController
{
    /**
     * List boxes
     *
     * Owners can see all boxes. Managers need box.viewAny. Operations employees only see assigned boxes.
     *
     * @authenticated
     *
     * @queryParam type string Filter by box type. Example: turkish
     * @queryParam per_page integer Results per page. Example: 20
     *
     * @response 200 {"success":true,"message":"Success","data":[{"id":1,"name":"Turkish Main","type":"turkish","current_balance":"1000.0000"}]}
     */
    public function index(Request $request): JsonResponse
    {
        if (! $this->canViewAnyBoxes($request->user())) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $query = Box::query()->with('assignedUser')->latest();
        $query = $this->scopeBoxesForUser($query, $this->currentUser($request));

        $query->when($request->filled('type'), fn (Builder $query) => $query->where('type', $request->string('type')));

        return $this->sendResponse(BoxResource::collection($query->paginate($request->integer('per_page', 20))));
    }

    /**
     * Create box
     *
     * Owner users and managers with box.create can create boxes.
     *
     * @authenticated
     *
     * @response 201 {"success":true,"message":"تم إنشاء الصندوق","data":{"id":1,"name":"Turkish Main","type":"turkish"}}
     */
    public function store(StoreBoxRequest $request): JsonResponse
    {
        if (! $this->canManageBoxes($request->user(), 'box.create')) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $box = Box::query()->create($request->validated());

        return $this->sendResponse(BoxResource::make($box->load('assignedUser')), 'تم إنشاء الصندوق', 201);
    }

    /**
     * Show box
     *
     * Return one box if the current user can access it.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"Success","data":{"id":1,"name":"Turkish Main","type":"turkish"}}
     * @response 403 {"success":false,"message":"غير مصرح"}
     */
    public function show(Request $request, Box $box): JsonResponse
    {
        if (! $this->canUseBox($request->user(), $box, 'box.view')) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse(BoxResource::make($box->load('assignedUser')));
    }

    /**
     * Update box
     *
     * Update editable box metadata. Balance changes must use the balance endpoint.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تحديث الصندوق"}
     */
    public function update(UpdateBoxRequest $request, Box $box): JsonResponse
    {
        if (! $this->canManageBoxes($request->user(), 'box.update')) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $box->update($request->validated());

        return $this->sendResponse(BoxResource::make($box->refresh()->load('assignedUser')), 'تم تحديث الصندوق');
    }

    /**
     * Delete box
     *
     * Delete a box and its balance logs.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم حذف الصندوق"}
     */
    public function destroy(Request $request, Box $box): JsonResponse
    {
        if (! $this->canManageBoxes($request->user(), 'box.delete')) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $box->delete();

        return $this->sendResponse(null, 'تم حذف الصندوق');
    }

    /**
     * Adjust box balance
     *
     * Adjust the current balance and save a full audit log entry.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تحديث رصيد الصندوق","data":{"id":1,"current_balance":"1250.0000"}}
     */
    public function balance(AdjustBoxBalanceRequest $request, Box $box): JsonResponse
    {
        if (! $this->canUseBox($request->user(), $box, 'box.adjustBalance')) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $data = $request->validated();
        $user = $this->currentUser($request);

        $box = DB::transaction(function () use ($box, $data, $user): Box {
            $lockedBox = Box::query()->lockForUpdate()->findOrFail($box->id);
            $balanceBefore = (float) $lockedBox->current_balance;
            $amount = (float) $data['amount'];
            $balanceAfter = $data['operation_type'] === BoxBalanceOperationType::Add->value
                ? $balanceBefore + $amount
                : $balanceBefore - $amount;

            if ($balanceAfter < 0) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'لا يمكن أن يصبح رصيد الصندوق سالباً',
                ], 422));
            }

            $lockedBox->update(['current_balance' => round($balanceAfter, 4)]);
            $lockedBox->balanceLogs()->create([
                'operation_type' => $data['operation_type'],
                'amount' => $amount,
                'balance_before' => $balanceBefore,
                'balance_after' => round($balanceAfter, 4),
                'notes' => $data['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            return $lockedBox;
        });

        return $this->sendResponse(BoxResource::make($box->refresh()->load('assignedUser')), 'تم تحديث رصيد الصندوق');
    }

    /**
     * Box balance logs
     *
     * Return the balance adjustment audit trail for one box.
     *
     * @authenticated
     *
     * @queryParam per_page integer Results per page. Example: 20
     *
     * @response 200 {"success":true,"message":"Success","data":[{"operation_type":"add","amount":"250.0000"}]}
     */
    public function logs(Request $request, Box $box): JsonResponse
    {
        if (! $this->canUseBox($request->user(), $box, 'box.viewLogs')) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse(BoxBalanceLogResource::collection(
            $box->balanceLogs()->with('creator')->latest()->paginate($request->integer('per_page', 20))
        ));
    }

    private function canViewAnyBoxes(?User $user): bool
    {
        return $user !== null && (
            $this->isOwnerOrAdmin($user)
            || $user->can('box.viewAny')
            || $user->hasRole('operations_employee', 'sanctum')
        );
    }

    private function canManageBoxes(?User $user, string $permission): bool
    {
        return $user !== null && (
            $this->isOwnerOrAdmin($user)
            || ($user->hasRole('manager', 'sanctum') && $user->can($permission))
        );
    }

    private function canUseBox(?User $user, Box $box, string $permission): bool
    {
        if ($user === null) {
            return false;
        }

        if ($this->isOwnerOrAdmin($user)) {
            return true;
        }

        if ($user->hasRole('manager', 'sanctum') && $user->can($permission)) {
            return true;
        }

        return $user->hasRole('operations_employee', 'sanctum')
            && $box->assigned_user_id === $user->id;
    }

    /**
     * @param  Builder<Box>  $query
     * @return Builder<Box>
     */
    private function scopeBoxesForUser(Builder $query, User $user): Builder
    {
        if ($this->isOwnerOrAdmin($user) || $user->can('box.viewAny')) {
            return $query;
        }

        return $query->whereBelongsTo($user, 'assignedUser');
    }

    private function isOwnerOrAdmin(User $user): bool
    {
        return $user->isOwner() || $user->hasRole('admin', 'sanctum');
    }
}
