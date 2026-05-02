<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Currency\StoreCurrencyRequest;
use App\Http\Requests\Currency\UpdateCurrencyRequest;
use App\Models\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrencyController extends BaseApiController
{
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

    public function store(StoreCurrencyRequest $request): JsonResponse
    {
        return $this->sendResponse(Currency::query()->create($request->validated()), 'تم إنشاء العملة', 201);
    }

    public function show(Currency $currency): JsonResponse
    {
        return $this->sendResponse($currency->load('exchangeRates'));
    }

    public function update(UpdateCurrencyRequest $request, Currency $currency): JsonResponse
    {
        $currency->update($request->validated());

        return $this->sendResponse($currency->refresh(), 'تم تحديث العملة');
    }

    public function destroy(Currency $currency): JsonResponse
    {
        $currency->update(['is_active' => false]);

        return $this->sendResponse(null, 'تم تعطيل العملة');
    }
}
