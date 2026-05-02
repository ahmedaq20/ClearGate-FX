<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\User\SetVaultBalanceRequest;
use App\Http\Requests\Vault\UpdateVaultRequest;
use App\Models\Vault;
use App\Services\BalanceService;
use App\Services\VaultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VaultController extends BaseApiController
{
    public function __construct(
        private BalanceService $balanceService,
        private VaultService $vaultService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Vault::query()->with('user')->latest();
        $query = $this->scopeToCurrentUser($query, $request);

        return $this->sendResponse($query->paginate($request->integer('per_page', 20)));
    }

    public function show(Request $request, Vault $vault): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $vault->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse($vault->load('user'));
    }

    public function update(UpdateVaultRequest $request, Vault $vault): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $vault->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $vault->update($request->safe()->only(['name', 'note', 'is_active']));

        return $this->sendResponse($vault->refresh(), 'تم تحديث الصندوق');
    }

    public function setBalance(SetVaultBalanceRequest $request, Vault $vault): JsonResponse
    {
        $vault->update(['initial_balance' => $request->float('initial_balance')]);
        $this->vaultService->recalculateBalance($vault);

        return $this->sendResponse($vault->refresh(), 'تم تحديث رصيد الصندوق');
    }

    public function transactions(Request $request, Vault $vault): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $vault->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse($vault->transactions()->latest('transaction_date')->paginate($request->integer('per_page', 20)));
    }

    public function summary(Request $request, Vault $vault): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $vault->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse($this->balanceService->getVaultBalance($vault->id));
    }
}
