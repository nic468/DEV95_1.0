<?php

namespace App\Data;

final readonly class MaterialImportPreview
{
    /**
     * @param  list<array<string, int|string|null>>  $movements
     * @param  list<array{cost_center_code: string, work_order: string, product_code: string, description: string, movement_type: string}>  $scraps
     * @param  list<string>  $newCostCenters
     * @param  list<string>  $newUnits
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $filename,
        public string $sha256,
        public int $sourceRowCount,
        public string $sourceTotal,
        public string $importedTotal,
        public array $movements,
        public array $scraps,
        public array $newCostCenters,
        public array $newUnits,
        public array $warnings,
    ) {}
}
