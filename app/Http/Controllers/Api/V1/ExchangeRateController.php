<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Currency\BulkUpdateExchangeRatesRequest;
use App\Http\Requests\Currency\UpdateExchangeRateRequest;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Exchange Rates
 *
 * View exchange-rate history and update current currency rates.
 */
class ExchangeRateController extends BaseApiController
{
    public function __construct(
        private ExchangeRateService $exchangeRateService,
    ) {}

    /**
     * List exchange rates
     *
     * Return paginated exchange-rate history with optional currency and date filters.
     *
     * @authenticated
     *
     * @queryParam currency string Filter by currency code. Example: USD
     * @queryParam date_from date Filter from rate date. Example: 2026-05-01
     * @queryParam date_to date Filter to rate date. Example: 2026-05-31
     * @queryParam per_page integer Results per page. Example: 20
     *
     * @response 200 {"success":true,"message":"Success","data":[{"id":1,"currency_code":"USD","rate":"1.000000","source":"manual","date":"2026-05-03"}]}
     */
    public function index(Request $request): JsonResponse
    {
        $query = ExchangeRate::query()->with(['currency', 'createdBy'])->latest('date');

        $query
            ->when($request->filled('currency'), fn ($query) => $query->where('currency_code', $request->string('currency')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('date', '<=', $request->date('date_to')));

        return $this->sendResponse($query->paginate($request->integer('per_page', 20)));
    }

    /**
     * Update currency rate
     *
     * Update the current exchange rate for a currency and append a historical exchange-rate row.
     *
     * @authenticated
     *
     * @urlParam code string required Currency code. Example: USD
     *
     * @response 200 {"success":true,"message":"تم تحديث سعر الصرف","data":{"code":"USD","rate_to_usd":"1.000000"}}
     * @response 422 {"success":false,"message":"Validation Error"}
     */
    public function update(UpdateExchangeRateRequest $request, string $code): JsonResponse
    {
        $this->exchangeRateService->updateRate(
            $code,
            $request->float('rate'),
            (int) $request->user()?->id,
            $request->filled('date') ? $request->date('date')?->toDateString() : null
        );

        return $this->sendResponse(Currency::query()->where('code', $code)->firstOrFail(), 'تم تحديث سعر الصرف');
    }

    /**
     * Bulk update exchange rates
     *
     * Update multiple currency rates in one request. Each item creates a historical exchange-rate row.
     *
     * @authenticated
     *
     * @response 200 {"success":true,"message":"تم تحديث أسعار الصرف"}
     * @response 422 {"success":false,"message":"Validation Error"}
     */
    public function bulkUpdate(BulkUpdateExchangeRatesRequest $request): JsonResponse
    {
        foreach ($request->validated('rates') as $rate) {
            $this->exchangeRateService->updateRate(
                $rate['code'],
                (float) $rate['rate'],
                (int) $request->user()?->id
            );
        }

        return $this->sendResponse(null, 'تم تحديث أسعار الصرف');
    }
}
