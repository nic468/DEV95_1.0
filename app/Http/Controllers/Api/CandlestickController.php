<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardFilterResolver;
use App\Services\SnapshotCandlestickService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CandlestickController extends Controller
{
    public function __invoke(
        Request $request,
        DashboardFilterResolver $filters,
        SnapshotCandlestickService $candlesticks
    ): JsonResponse {
        $validated = Validator::make($request->query(), [
            'metric' => ['sometimes', 'string', Rule::in([
                'cost',
                'records',
                'distinct_work_orders',
                'scrap_m2',
            ])],
        ])->validate();
        $metric = $validated['metric'] ?? 'cost';
        $resolvedFilters = $filters->resolve($request->query());

        return response()->json([
            'metric' => $metric,
            'range' => [
                'start_date' => $resolvedFilters->startDate->toDateString(),
                'end_date' => $resolvedFilters->endDate->toDateString(),
            ],
            'filters' => [
                'cost_center' => $resolvedFilters->costCenter,
                'movement_type' => $resolvedFilters->movementType,
                'unit' => $resolvedFilters->unit,
            ],
            'timezone' => 'America/Sao_Paulo',
            'candles' => $candlesticks->candles(
                $metric,
                $resolvedFilters,
            ),
        ]);
    }
}
