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

class ExchangeRateController extends BaseApiController
{
    public function __construct(
        private ExchangeRateService $exchangeRateService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ExchangeRate::query()->with(['currency', 'createdBy'])->latest('date');

        $query
            ->when($request->filled('currency'), fn ($query) => $query->where('currency_code', $request->string('currency')))
            ->when($request->filled('date_from'), fn ($query) => $query->whereDate('date', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($query) => $query->whereDate('date', '<=', $request->date('date_to')));

        return $this->sendResponse($query->paginate($request->integer('per_page', 20)));
    }

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
