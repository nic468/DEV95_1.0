<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class MaterialWorkbookFactory
{
    public static function create(
        string $costCenter,
        string $workOrder,
        string $product,
        string $unit,
        string $description,
        string $cost,
        string $issueDate,
        string $document,
    ): string {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $headers = [
            'C Custo',
            'Ord Producao',
            'Tipo RE/DE',
            'Produto',
            'Descricao',
            'Quantidade',
            'UM',
            'Custo Unitario',
            'Custo',
            'Documento',
            'DT Emissao',
            'Custo Stand.',
            'TotalSTD',
        ];

        foreach ($headers as $index => $header) {
            $sheet->setCellValue([$index + 1, 2], $header);
        }

        $values = [
            $costCenter,
            $workOrder,
            'RE1',
            $product,
            $description,
            '2,500000',
            $unit,
            '5,000000',
            $cost,
            $document,
            $issueDate,
            ',   ,   ,   .',
            '0,000000',
        ];

        foreach ($values as $index => $value) {
            $sheet->setCellValue([$index + 1, 3], $value);
        }

        $sheet->setCellValue('I4', '='.$cost);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'materials-db-'.bin2hex(random_bytes(8)).'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return $path;
    }
}
