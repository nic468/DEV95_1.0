<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Apontamento de sobra</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main class="dashboard-shell">
        @include('partials.app-navigation', [
            'title' => 'Apontamento de sobra',
            'subtitle' => 'Registre e consulte o histórico de sobras de chapa dos snapshots arquivados.',
            'activePage' => 'scraps',
        ])

        @if (session('status'))
            <p class="alert alert-success" role="status">{{ session('status') }}</p>
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

        <section class="content-panel" aria-labelledby="scrap-filter-title">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">CHAPAS E SOBRAS</p>
                    <h2 id="scrap-filter-title">Filtros</h2>
                </div>
                <span class="count-badge">{{ number_format($scraps->total(), 0, ',', '.') }} grupos</span>
            </div>
            <form class="archive-filter" method="get" action="{{ route('materials.scraps.index') }}">
                <div class="filter-field">
                    <label for="scrap-date">Data de referência</label>
                    <input id="scrap-date" name="date" type="date" value="{{ $selectedDate }}">
                </div>
                <div class="filter-field">
                    <label for="scrap-filename">Arquivo de origem</label>
                    <input
                        id="scrap-filename"
                        name="filename"
                        type="search"
                        maxlength="260"
                        value="{{ $selectedFilename }}"
                        placeholder="Parte do nome do arquivo"
                    >
                </div>
                <div class="filter-field">
                    <label for="scrap-search">Centro, OP, produto ou descrição</label>
                    <input id="scrap-search" name="search" type="search" maxlength="100" value="{{ $selectedSearch }}">
                </div>
                <button class="button button-quiet" type="submit">Filtrar</button>
                @if ($selectedDate !== '' || $selectedFilename !== '' || $selectedSearch !== '')
                    <a class="text-button" href="{{ route('materials.scraps.index') }}">Limpar filtros</a>
                @endif
            </form>
            <p class="form-help">
                Elegibilidade: UM M2, descrição contendo CHAPA sem diferenciar maiúsculas/minúsculas e sem GRADE ou DEGRAU.
                O limite é a soma líquida das quantidades elegíveis do grupo, nunca inferior a zero.
            </p>
        </section>

        <section class="content-panel" aria-label="Grupos de chapa elegíveis">
            <div class="table-wrap">
                <table class="materials-table scrap-table">
                    <thead>
                        <tr>
                            <th scope="col">Data / versão</th>
                            <th scope="col">Arquivo de origem</th>
                            <th scope="col">Centro / OP</th>
                            <th scope="col">Produto / descrição</th>
                            <th scope="col">Tipo</th>
                            <th scope="col">Máximo (m²)</th>
                            <th scope="col">Último apontamento (m²)</th>
                            <th scope="col">Apontado por / em</th>
                            <th scope="col">Novo apontamento</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($scraps as $scrap)
                            @php
                                $scrapKey = hash('sha256', json_encode([
                                    $scrap->snapshot_id,
                                    $scrap->cost_center_code,
                                    $scrap->work_order,
                                    $scrap->product_code,
                                ], JSON_THROW_ON_ERROR));
                                $isEditing = (string) old('scrap_key') === $scrapKey;
                            @endphp
                            <tr>
                                <td>{{ \Carbon\CarbonImmutable::parse($scrap->reference_date)->format('d/m/Y') }} / v{{ $scrap->version }}</td>
                                <td class="filename-cell" title="{{ $scrap->filename }}">{{ $scrap->filename }}</td>
                                <td>{{ $scrap->cost_center_code }} / {{ $scrap->work_order }}</td>
                                <td>{{ $scrap->product_code }} — {{ $scrap->description }}</td>
                                <td>{{ $scrap->movement_type }}</td>
                                <td>{{ $scrap->available_area }}</td>
                                <td>{{ $scrap->value_m2 ?? 'Não apontada' }}</td>
                                <td>
                                    @if ($scrap->recorded_by)
                                        {{ $scrap->recorded_by }}<br>
                                        <span class="form-help">{{ \Carbon\CarbonImmutable::parse($scrap->recorded_at, 'UTC')->setTimezone('America/Sao_Paulo')->format('d/m/Y H:i') }}</span>
                                    @else
                                        <span class="form-help">Sem apontamento manual</span>
                                    @endif
                                </td>
                                <td class="scrap-point-cell">
                                    <form
                                        class="scrap-point-form"
                                        method="post"
                                        action="{{ route('materials.scraps.store', request()->query()) }}"
                                    >
                                        @csrf
                                        <input type="hidden" name="snapshot_id" value="{{ $scrap->snapshot_id }}">
                                        <input type="hidden" name="cost_center_code" value="{{ $scrap->cost_center_code }}">
                                        <input type="hidden" name="work_order" value="{{ $scrap->work_order }}">
                                        <input type="hidden" name="product_code" value="{{ $scrap->product_code }}">
                                        <input type="hidden" name="scrap_key" value="{{ $scrapKey }}">
                                        <label class="visually-hidden" for="value-{{ $scrapKey }}">Sobra manual em m²</label>
                                        <input
                                            id="value-{{ $scrapKey }}"
                                            name="value_m2"
                                            type="number"
                                            min="0"
                                            max="{{ $scrap->available_area }}"
                                            step="0.000001"
                                            value="{{ $isEditing ? old('value_m2') : '' }}"
                                            required
                                        >
                                        <label class="scrap-operator-label">
                                            Nome de quem aponta
                                            <input
                                                name="recorded_by"
                                                type="text"
                                                maxlength="256"
                                                value="{{ $isEditing ? old('recorded_by') : '' }}"
                                                required
                                            >
                                        </label>
                                        <button class="button button-primary" type="submit">Registrar</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="table-empty" colspan="9">Nenhum grupo elegível de chapa corresponde aos filtros.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($scraps->hasPages())
                <div class="archive-pagination">{{ $scraps->links() }}</div>
            @endif
        </section>
    </main>
</body>
</html>
