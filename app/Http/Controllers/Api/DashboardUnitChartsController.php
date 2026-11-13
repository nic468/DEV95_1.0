<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardFilterResolver;
use App\Services\SnapshotUnitChartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardUnitChartsController extends Controller
{
    public function __invoke(
        Request $request,
        DashboardFilterResolver $filters,
        SnapshotUnitChartService $charts
    ): JsonResponse {
        return response()->json(
            $charts->charts($filters->resolve($request->query()))
        );
    }
}
