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

/**
 * @group Vaults
 *
 * View and manage user vault information. Balance changes remain service-driven.
 */
class VaultController extends BaseApiController
{
    public function __construct(
        private BalanceService $balanceService,
        private VaultService $vaultService,
    ) {}

    /**
     * List vaults
     *
     * Owner users can see all vaults. Managers are scoped to their own vault.
     *
     * @authenticated
     *
     * @queryParam per_page integer Results per page. Example: 20
     */
    public function index(Request $request): JsonResponse
    {
        $query = Vault::query()->with('user')->latest();
        $query = $this->scopeToCurrentUser($query, $request);

        return $this->sendResponse($query->paginate($request->integer('per_page', 20)));
    }

    /**
     * Show vault
     *
     * Return one vault if the current user is allowed to view it.
     *
     * @authenticated
     */
    public function show(Request $request, Vault $vault): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $vault->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse($vault->load('user'));
    }

    /**
     * Update vault
     *
     * Update editable vault metadata. Managers cannot update initial balance here.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تحديث الصندوق"}
     */
    public function update(UpdateVaultRequest $request, Vault $vault): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $vault->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        $vault->update($request->safe()->only(['name', 'note', 'is_active']));

        return $this->sendResponse($vault->refresh(), 'تم تحديث الصندوق');
    }

    /**
     * Set vault initial balance
     *
     * Owner-only endpoint that updates the vault initial balance and recalculates current balance.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تحديث رصيد الصندوق"}
     */
    public function setBalance(SetVaultBalanceRequest $request, Vault $vault): JsonResponse
    {
        $vault->update(['initial_balance' => $request->float('initial_balance')]);
        $this->vaultService->recalculateBalance($vault);

        return $this->sendResponse($vault->refresh(), 'تم تحديث رصيد الصندوق');
    }

    /**
     * Vault transactions
     *
     * List transactions linked to a vault.
     *
     * @authenticated
     *
     * @queryParam per_page integer Results per page. Example: 20
     */
    public function transactions(Request $request, Vault $vault): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $vault->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse($vault->transactions()->latest('transaction_date')->paginate($request->integer('per_page', 20)));
    }

    /**
     * Vault summary
     *
     * Return initial balance, receive totals, send totals, and current USD balance.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"Success","data":{"initial_balance":1000,"total_receive":500,"total_send":100,"balance_usd":1400}}
     */
    public function summary(Request $request, Vault $vault): JsonResponse
    {
        if (! $this->isOwner($request->user()) && $vault->user_id !== $request->user()?->id) {
            return $this->sendError('غير مصرح', [], 403);
        }

        return $this->sendResponse($this->balanceService->getVaultBalance($vault->id));
    }
}
