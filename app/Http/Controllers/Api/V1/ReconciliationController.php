<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\ReconciliationSnapshot;
use App\Models\User;
use App\Services\ReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReconciliationController extends BaseApiController
{
    public function __construct(private readonly ReconciliationService $reconciliationService) {}

    public function show(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! $this->canViewReconciliation($user)) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse($this->reconciliationService->calculate($this->ownerForReconciliation($user)));
    }

    public function run(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! $this->canRunReconciliation($user)) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $snapshot = $this->reconciliationService->run($this->ownerForReconciliation($user), $user);

        return $this->sendResponse($snapshot, 'تم تنفيذ المطابقة المالية', 201);
    }

    public function history(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        if (! $this->canViewReconciliation($user)) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse(
            ReconciliationSnapshot::query()
                ->with('creator:id,name,email')
                ->latest()
                ->paginate((int) $request->integer('per_page', 15))
        );
    }

    private function ownerForReconciliation(User $user): User
    {
        if ($user->hasRole('owner', 'sanctum')) {
            return $user;
        }

        return User::role('owner', 'sanctum')->oldest('id')->first() ?? $user;
    }

    private function canViewReconciliation(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'admin'], 'sanctum')
            || $user->can('reconciliation.view')
            || $user->hasRole('operations_employee', 'sanctum');
    }

    private function canRunReconciliation(User $user): bool
    {
        return $user->hasAnyRole(['owner', 'admin'], 'sanctum')
            || $user->can('reconciliation.run');
    }
}
