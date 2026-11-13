<div class="table-wrap archive-materials-table-wrap">
    <table class="materials-table">
        <caption class="visually-hidden">Materiais e revisões da planilha selecionada</caption>
        <thead>
            <tr>
                <th scope="col">C Custo</th>
                <th scope="col">Ord Producao</th>
                <th scope="col">Tipo RE/DE</th>
                <th scope="col">Produto</th>
                <th scope="col">Descricao</th>
                <th scope="col">Quantidade</th>
                <th scope="col">UM</th>
                <th scope="col">Custo Unitario</th>
                <th scope="col">Custo</th>
                <th scope="col">Documento</th>
                <th scope="col">DT Emissao</th>
                <th scope="col">Custo Stand.</th>
                <th scope="col">TotalSTD</th>
                <th scope="col">Última revisão</th>
                <th scope="col">Correção</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($materials as $material)
                @php($isEditing = (string) $editingMovementId === (string) $material->id)
                <tr>
                    <td>{{ $material->cost_center_code }}</td>
                    <td>{{ $material->work_order }}</td>
                    <td>{{ $material->movement_type }}</td>
                    <td>{{ $material->product_code }}</td>
                    <td>{{ $material->description }}</td>
                    <td>{{ $material->quantity }}</td>
                    <td>{{ $material->unit_code }}</td>
                    <td>{{ $material->unit_cost }}</td>
                    <td>{{ $material->cost }}</td>
                    <td>{{ $material->document }}</td>
                    <td>{{ \Carbon\CarbonImmutable::parse($material->issue_date)->format('d/m/Y') }}</td>
                    <td>{{ $material->standard_cost ?? '—' }}</td>
                    <td>{{ $material->standard_total }}</td>
                    <td>
                        @if ($material->correction_revision)
                            #{{ $material->correction_revision }} — {{ $material->correction_recorded_by }}<br>
                            <span class="form-help">{{ \Carbon\CarbonImmutable::parse($material->correction_recorded_at, 'UTC')->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</span>
                        @else
                            <span class="form-help">Importação original</span>
                        @endif
                    </td>
                    <td class="material-edit-cell">
                        <details class="material-editor" @if ($isEditing) open @endif>
                            <summary class="button button-quiet">Editar</summary>
                            <form
                                class="material-editor-form"
                                method="post"
                                action="{{ route('materials.movements.update', ['movement' => $material->id] + request()->query()) }}"
                            >
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="movement_id" value="{{ $material->id }}">
                                <div class="material-editor-grid">
                                    <label>
                                        C Custo
                                        <select name="cost_center_code" required>
                                            @foreach ($costCenters as $center)
                                                <option
                                                    value="{{ $center->code }}"
                                                    @selected(($isEditing ? old('cost_center_code', $material->cost_center_code) : $material->cost_center_code) === $center->code)
                                                >{{ $center->code }}{{ $center->name ? ' — '.$center->name : '' }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>Ord Producao<input name="work_order" maxlength="40" value="{{ $isEditing ? old('work_order', $material->work_order) : $material->work_order }}" required></label>
                                    <label>Tipo RE/DE<input name="movement_type" maxlength="12" value="{{ $isEditing ? old('movement_type', $material->movement_type) : $material->movement_type }}" required></label>
                                    <label>Produto<input name="product_code" maxlength="60" value="{{ $isEditing ? old('product_code', $material->product_code) : $material->product_code }}" required></label>
                                    <label class="editor-wide">Descricao<input name="description" maxlength="500" value="{{ $isEditing ? old('description', $material->description) : $material->description }}" required></label>
                                    <label>Quantidade<input name="quantity" inputmode="decimal" value="{{ $isEditing ? old('quantity', $material->quantity) : $material->quantity }}" required></label>
                                    <label>UM
                                        <select name="unit_code" required>
                                            @foreach ($units as $unit)
                                                <option value="{{ $unit }}" @selected(($isEditing ? old('unit_code', $material->unit_code) : $material->unit_code) === $unit)>{{ $unit }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>Custo Unitario<input name="unit_cost" inputmode="decimal" value="{{ $isEditing ? old('unit_cost', $material->unit_cost) : $material->unit_cost }}" required></label>
                                    <label>Custo<input name="cost" inputmode="decimal" value="{{ $isEditing ? old('cost', $material->cost) : $material->cost }}" required></label>
                                    <label>Documento<input name="document" maxlength="40" value="{{ $isEditing ? old('document', $material->document) : $material->document }}" required></label>
                                    <label>DT Emissao<input name="issue_date" type="date" value="{{ $isEditing ? old('issue_date', $material->issue_date) : $material->issue_date }}" required></label>
                                    <label>Custo Stand.<input name="standard_cost" inputmode="decimal" value="{{ $isEditing ? old('standard_cost', $material->standard_cost) : $material->standard_cost }}"></label>
                                    <label>TotalSTD<input name="standard_total" inputmode="decimal" value="{{ $isEditing ? old('standard_total', $material->standard_total) : $material->standard_total }}" required></label>
                                    <label class="editor-wide">Nome de quem corrige<input name="recorded_by" maxlength="256" value="{{ $isEditing ? old('recorded_by') : '' }}" required></label>
                                </div>
                                <button class="button button-primary" type="submit">Salvar como nova revisão</button>
                            </form>
                        </details>
                    </td>
                </tr>
            @empty
                <tr>
                    <td class="table-empty" colspan="15">Nenhum material nesta planilha.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
@if ($materials->hasPages())
    <div class="archive-pagination">{{ $materials->links() }}</div>
@endif
