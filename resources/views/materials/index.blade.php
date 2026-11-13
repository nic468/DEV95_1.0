<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Painel de materiais</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="dashboard-shell">
        @include('partials.app-navigation', [
            'title' => 'Painel de materiais',
            'subtitle' => 'Importe planilhas e consulte os snapshots arquivados por data.',
            'activePage' => 'materials',
        ])

        @if (session('status'))
            <p class="alert alert-success" role="status">{{ session('status') }}</p>
        @endif
        @if (session('error'))
            <p class="alert alert-error" role="alert">{{ session('error') }}</p>
        @endif
        @if (session('import_warnings'))
            <div class="alert alert-warning" role="status">
                <strong>Atenção:</strong>
                <ul>
                    @foreach (session('import_warnings') as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error" role="alert">
                <ul>
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <section class="content-panel upload-panel" aria-labelledby="upload-title">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">NOVA IMPORTAÇÃO</p>
                    <h2 id="upload-title">Importar planilha Excel</h2>
                </div>
            </div>
            <form class="upload-form" method="post" action="{{ route('materials.import') }}" enctype="multipart/form-data">
                @csrf
                <div class="upload-field">
                    <label for="material-file">Arquivo .xlsx</label>
                    <input id="material-file" name="file" type="file" accept=".xlsx" required>
                    <span class="form-help">
                        Apenas XLSX; limite de {{ number_format(config('materials.import_max_kilobytes', 10240) / 1024, 0, ',', '.') }} MiB.
                    </span>
                    @error('file')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>
                <div class="upload-field">
                    <label for="reference-date">Data de referência</label>
                    <input
                        id="reference-date"
                        name="reference_date"
                        type="date"
                        value="{{ old('reference_date', now('America/Sao_Paulo')->toDateString()) }}"
                        required
                    >
                    @error('reference_date')
                        <span class="field-error">{{ $message }}</span>
                    @enderror
                </div>
                <div class="upload-submit">
                    <button class="button button-primary" type="submit">Validar e importar</button>
                </div>
            </form>
            <p class="form-help">
                O snapshot só é gravado após validar a estrutura e reconciliar o custo com o TOTAL GERAL.
                O arquivo original é guardado em armazenamento privado.
            </p>
        </section>

        <section class="content-panel snapshots-panel" aria-labelledby="archive-title">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">ARQUIVO DE IMPORTAÇÕES</p>
                    <h2 id="archive-title">Planilhas arquivadas</h2>
                </div>
                <span class="count-badge">{{ number_format($snapshots->total(), 0, ',', '.') }} snapshots</span>
            </div>
            <form class="archive-filter" method="get" action="{{ route('materials.index') }}">
                <div class="filter-field">
                    <label for="archive-date">Filtrar por data de referência</label>
                    <input id="archive-date" name="date" type="date" value="{{ $selectedDate }}">
                </div>
                <div class="filter-field">
                    <label for="archive-filename">Arquivo de origem</label>
                    <input
                        id="archive-filename"
                        name="filename"
                        type="search"
                        maxlength="260"
                        value="{{ $selectedFilename }}"
                        placeholder="Parte do nome do arquivo"
                    >
                </div>
                <button class="button button-quiet" type="submit">Filtrar</button>
                @if ($selectedDate !== '' || $selectedFilename !== '')
                    <a class="text-button" href="{{ route('materials.index') }}">Limpar filtro</a>
                @endif
            </form>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Data de referência</th>
                            <th scope="col">Versão</th>
                            <th scope="col">Importado em</th>
                            <th scope="col">Arquivo de origem</th>
                            <th scope="col">Linhas</th>
                            <th scope="col">Custo importado</th>
                            <th scope="col">Original XLSX</th>
                            <th scope="col"><span class="visually-hidden">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($snapshots as $snapshot)
                            <tr>
                                <td>{{ $snapshot->reference_date->format('d/m/Y') }}</td>
                                <td>{{ $snapshot->version }}</td>
                                <td>{{ $snapshot->imported_at->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</td>
                                <td class="filename-cell" title="{{ $snapshot->filename }}">{{ $snapshot->filename }}</td>
                                <td>{{ number_format($snapshot->row_count, 0, ',', '.') }}</td>
                                <td>R$ {{ number_format((float) $snapshot->imported_total, 2, ',', '.') }}</td>
                                <td>
                                    @if ($snapshot->source_file_path)
                                        <a class="text-button"
                                           href="{{ route('materials.source', ['snapshot' => $snapshot->id]) }}">
                                            Baixar original
                                        </a>
                                    @else
                                        <span class="form-help">Não armazenado</span>
                                    @endif
                                </td>
                                <td>
                                    <a class="button button-quiet archive-action"
                                       href="{{ route('materials.index', array_merge(request()->query(), [
                                            'open_snapshot_id' => $snapshot->id,
                                            'materials_page' => 1,
                                       ])) }}">
                                        Consultar materiais
                                    </a>
                                    <a class="button button-quiet archive-export-link"
                                       href="{{ route('api.snapshots.export', ['snapshot' => $snapshot->id]) }}">
                                        Exportar dados
                                    </a>
                                    <form
                                        class="archive-delete-form"
                                        method="post"
                                        action="{{ route('materials.destroy', ['snapshot' => $snapshot->id]) }}"
                                        onsubmit="return window.confirm('Excluir definitivamente este arquivamento, seus materiais, revisões, apontamentos, métricas e o XLSX original? Esta ação não pode ser desfeita.');"
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <label class="delete-confirm-label">
                                            <input name="confirm_delete" type="checkbox" value="1" required>
                                            Confirmo a exclusão definitiva
                                        </label>
                                        <button class="text-button text-button-danger" type="submit">Excluir arquivamento</button>
                                    </form>
                                </td>
                            </tr>
                            @if ($openSnapshotId === (int) $snapshot->id)
                                <tr class="archive-materials-row">
                                    <td colspan="8">
                                        <details id="snapshot-materials-{{ $snapshot->id }}" class="archive-materials-disclosure" open>
                                            <summary>
                                                Materiais desta planilha — {{ number_format($materials->total(), 0, ',', '.') }} registros
                                            </summary>
                                            <div class="archive-materials-panel">
                                                <p class="form-help">
                                                    {{ $snapshot->filename }} · {{ $snapshot->reference_date->format('d/m/Y') }} · versão {{ $snapshot->version }}
                                                </p>
                                                @include('materials.partials.movements-table')
                                            </div>
                                        </details>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td class="table-empty" colspan="8">Ainda não há planilhas arquivadas.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($snapshots->hasPages())
                <div class="archive-pagination">{{ $snapshots->links() }}</div>
            @endif
        </section>
    </main>
</body>
</html>
