<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Currency\StoreCurrencyRequest;
use App\Http\Requests\Currency\UpdateCurrencyRequest;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Currencies
 *
 * Manage supported currencies and their current USD exchange rates.
 */
class CurrencyController extends BaseApiController
{
    /**
     * List currencies
     *
     * Return paginated currencies with optional search and active status filters.
     *
     * @authenticated
     *
     * @queryParam search string Search by code, English name, or Arabic name. Example: USD
     * @queryParam is_active boolean Filter by active status. Example: true
     * @queryParam per_page integer Results per page. Example: 20
     *
     * @response 200 {"success":true,"message":"Success","data":[{"id":1,"code":"USD","name":"US Dollar","name_ar":"دولار أمريكي","symbol":"$","rate_to_usd":"1.000000","is_active":true}]}
     */
    public function index(Request $request): JsonResponse
    {
        $query = Currency::query()->latest();

        $query
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), fn ($query) => $query->where(function ($query) use ($request): void {
                $search = '%'.$request->string('search')->toString().'%';

                $query
                    ->where('code', 'like', $search)
                    ->orWhere('name', 'like', $search)
                    ->orWhere('name_ar', 'like', $search);
            }));

        return $this->sendResponse($query->paginate($request->integer('per_page', 20)));
    }

    /**
     * Create currency
     *
     * Create a new currency with its initial exchange rate to USD.
     *
     * @authenticated
     *
     * @response 201 {"success":true,"message":"تم إنشاء العملة","data":{"id":1,"code":"USD","name":"US Dollar","rate_to_usd":"1.000000","is_active":true}}
     * @response 422 {"success":false,"message":"Validation Error"}
     */
    public function store(StoreCurrencyRequest $request): JsonResponse
    {
        return $this->sendResponse(Currency::query()->create($request->validated()), 'تم إنشاء العملة', 201);
    }

    /**
     * Show currency
     *
     * Return one currency and its exchange rate history.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"Success","data":{"id":1,"code":"USD","name":"US Dollar","exchange_rates":[]}}
     */
    public function show(Currency $currency): JsonResponse
    {
        return $this->sendResponse($currency->load('exchangeRates'));
    }

    /**
     * Update currency
     *
     * Update currency metadata or its active status.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تحديث العملة","data":{"id":1,"code":"USD","name":"US Dollar","is_active":true}}
     * @response 422 {"success":false,"message":"Validation Error"}
     */
    public function update(UpdateCurrencyRequest $request, Currency $currency): JsonResponse
    {
        $currency->update($request->validated());

        return $this->sendResponse($currency->refresh(), 'تم تحديث العملة');
    }

    /**
     * Disable currency
     *
     * Disable a currency by setting it inactive instead of deleting it.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تعطيل العملة"}
     */
    public function destroy(Currency $currency): JsonResponse
    {
        $currency->update(['is_active' => false]);

        return $this->sendResponse(null, 'تم تعطيل العملة');
    }
}
