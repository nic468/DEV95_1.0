<?php

namespace App\Http\Controllers;

use App\Models\SnapshotMovement;
use App\Services\SnapshotMovementCorrectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class MaterialMovementCorrectionController extends Controller
{
    public function update(
        Request $request,
        SnapshotMovement $movement,
        SnapshotMovementCorrectionService $corrections
    ): RedirectResponse {
        $validated = $request->validate([
            'movement_id' => ['required', 'integer', 'in:'.$movement->id],
            'cost_center_code' => ['required', 'string', 'max:20', 'exists:cost_centers,code'],
            'work_order' => ['required', 'string', 'max:40'],
            'movement_type' => ['required', 'string', 'max:12'],
            'product_code' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string', 'max:500'],
            'quantity' => ['required', 'regex:/^-?\d{1,13}(?:\.\d{1,6})?$/'],
            'unit_code' => ['required', 'string', 'max:10', 'exists:units_of_measure,code'],
            'unit_cost' => ['required', 'regex:/^-?\d{1,13}(?:\.\d{1,6})?$/'],
            'cost' => ['required', 'regex:/^-?\d{1,17}(?:\.\d{1,2})?$/'],
            'document' => ['required', 'string', 'max:40'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'standard_cost' => ['nullable', 'regex:/^-?\d{1,13}(?:\.\d{1,6})?$/'],
            'standard_total' => ['required', 'regex:/^-?\d{1,13}(?:\.\d{1,6})?$/'],
            'recorded_by' => ['required', 'string', 'max:256'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'filename' => ['nullable', 'string', 'max:260'],
            'open_snapshot_id' => ['nullable', 'integer', 'min:1', 'exists:upload_snapshots,id'],
            'page' => ['nullable', 'integer', 'min:1'],
            'materials_page' => ['nullable', 'integer', 'min:1'],
        ], [
            'required' => 'O campo :attribute é obrigatório.',
            'string' => 'O campo :attribute deve ser texto.',
            'max.string' => 'O campo :attribute não pode exceder :max caracteres.',
            'integer' => 'O campo :attribute deve ser um número inteiro.',
            'date_format' => 'O campo :attribute deve estar no formato :format.',
            'exists' => 'Selecione um valor cadastrado para :attribute.',
            'in' => 'O valor enviado para :attribute é inválido.',
            'open_snapshot_id.min' => 'O identificador do snapshot deve ser positivo.',
            'open_snapshot_id.exists' => 'O snapshot selecionado não foi encontrado.',
            'page.integer' => 'A página do arquivo deve ser um número inteiro.',
            'page.min' => 'A página do arquivo deve ser positiva.',
            'materials_page.integer' => 'A página dos materiais deve ser um número inteiro.',
            'materials_page.min' => 'A página dos materiais deve ser positiva.',
            'movement_id.in' => 'O material enviado não corresponde à linha selecionada.',
            'cost_center_code.exists' => 'Selecione um centro de custo cadastrado.',
            'unit_code.exists' => 'Selecione uma unidade de medida cadastrada.',
            'quantity.regex' => 'Informe uma quantidade numérica com até 6 casas decimais.',
            'unit_cost.regex' => 'Informe um custo unitário numérico com até 6 casas decimais.',
            'cost.regex' => 'Informe um custo numérico com até 2 casas decimais.',
            'standard_cost.regex' => 'Informe um custo padrão válido ou deixe o campo vazio.',
            'standard_total.regex' => 'Informe um total padrão numérico com até 6 casas decimais.',
            'recorded_by.required' => 'Informe o nome de quem realizou a correção.',
        ], [
            'movement_id' => 'material',
            'cost_center_code' => 'C Custo',
            'work_order' => 'Ord Producao',
            'movement_type' => 'Tipo RE/DE',
            'product_code' => 'Produto',
            'description' => 'Descricao',
            'quantity' => 'Quantidade',
            'unit_code' => 'UM',
            'unit_cost' => 'Custo Unitario',
            'cost' => 'Custo',
            'document' => 'Documento',
            'issue_date' => 'DT Emissao',
            'standard_cost' => 'Custo Stand.',
            'standard_total' => 'TotalSTD',
            'recorded_by' => 'Nome de quem corrige',
            'date' => 'data de referência',
            'filename' => 'arquivo de origem',
            'open_snapshot_id' => 'snapshot',
            'page' => 'página do arquivo',
            'materials_page' => 'página dos materiais',
        ]);

        $revision = $corrections->correct(
            $movement,
            Arr::only($validated, [
                'cost_center_code',
                'work_order',
                'movement_type',
                'product_code',
                'description',
                'quantity',
                'unit_code',
                'unit_cost',
                'cost',
                'document',
                'issue_date',
                'standard_cost',
                'standard_total',
            ]),
            $validated['recorded_by']
        );

        return redirect()
            ->route(
                'materials.index',
                Arr::only($validated, ['date', 'filename', 'open_snapshot_id', 'page', 'materials_page'])
            )
            ->with('status', "Correção registrada como revisão {$revision->revision}.");
    }
}
