<?php

namespace App\Services;

use App\Data\DashboardFilters;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DashboardFilterResolver
{
    private const TIMEZONE = 'America/Sao_Paulo';

    /**
     * @param  array<string, mixed>  $query
     */
    public function resolve(array $query): DashboardFilters
    {
        $validated = Validator::make($query, [
            'period' => ['sometimes', 'string', Rule::in([
                'current_month',
                'previous_month',
                'last_30_days',
            ])],
            'year' => ['nullable', 'integer', 'between:1900,9999'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'start_date' => ['nullable', 'required_with:end_date', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'required_with:start_date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'cost_center' => ['nullable', 'string', 'max:20'],
            'movement_type' => ['nullable', 'string', 'max:12'],
            'unit' => ['nullable', 'string', 'max:10'],
        ])->validate();

        $hasCustomRange = isset($validated['start_date']) || isset($validated['end_date']);
        $hasMonth = isset($validated['year']) || isset($validated['month']);
        $hasPreset = isset($validated['period']);

        if (
            ($hasCustomRange && ($hasMonth || $hasPreset))
            || ($hasMonth && $hasPreset)
            || ($hasMonth && (! isset($validated['year']) || ! isset($validated['month'])))
        ) {
            throw ValidationException::withMessages([
                'period' => 'Use apenas um intervalo personalizado, um preset ou mes/ano.',
            ]);
        }

        [$startDate, $endDate] = $this->dateRange($validated);

        return new DashboardFilters(
            startDate: $startDate,
            endDate: $endDate,
            costCenter: $validated['cost_center'] ?? null,
            movementType: $validated['movement_type'] ?? null,
            unit: $validated['unit'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function dateRange(array $validated): array
    {
        if (isset($validated['start_date'], $validated['end_date'])) {
            return [
                CarbonImmutable::createFromFormat('!Y-m-d', $validated['start_date'], self::TIMEZONE),
                CarbonImmutable::createFromFormat('!Y-m-d', $validated['end_date'], self::TIMEZONE),
            ];
        }

        if (isset($validated['year'], $validated['month'])) {
            $start = CarbonImmutable::createFromDate(
                (int) $validated['year'],
                (int) $validated['month'],
                1,
                self::TIMEZONE
            )->startOfMonth();

            return [$start, $start->endOfMonth()];
        }

        $today = CarbonImmutable::now(self::TIMEZONE);

        return match ($validated['period'] ?? 'current_month') {
            'previous_month' => [
                $today->subMonthNoOverflow()->startOfMonth(),
                $today->subMonthNoOverflow()->endOfMonth(),
            ],
            'last_30_days' => [
                $today->subDays(29)->startOfDay(),
                $today->endOfDay(),
            ],
            default => [$today->startOfMonth(), $today->endOfMonth()],
        };
    }
}
