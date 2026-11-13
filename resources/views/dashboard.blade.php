<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dashboard de materiais</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <main
        class="dashboard-shell"
        data-dashboard
        data-options-url="{{ route('api.dashboard.options') }}"
        data-summary-url="{{ route('api.dashboard.summary') }}"
        data-candlestick-url="{{ route('api.dashboard.candlestick') }}"
        data-unit-charts-url="{{ route('api.dashboard.units') }}"
        data-snapshots-url="{{ route('api.dashboard.snapshots') }}"
        data-snapshot-day-url="{{ route('api.snapshots.index') }}"
        data-snapshot-export-base="{{ url('/api/snapshots') }}"
    >
        @include('partials.app-navigation', [
            'title' => 'Dashboard de materiais',
            'subtitle' => 'Acompanhe os snapshots sem misturar unidades de medida.',
            'activePage' => 'dashboard',
        ])

        <form id="dashboard-filters" class="filter-panel" novalidate>
            <div class="filter-field">
                <label for="period-mode">Período</label>
                <select id="period-mode" name="period_mode">
                    <option value="preset">Atalho</option>
                    <option value="month">Mês / ano</option>
                    <option value="custom">Personalizado</option>
                </select>
            </div>
            <div class="filter-field" data-period-field="preset">
                <label for="period-preset">Atalho de período</label>
                <select id="period-preset" name="period">
                    <option value="current_month">Mês atual</option>
                    <option value="previous_month">Mês anterior</option>
                    <option value="last_30_days">Últimos 30 dias</option>
                </select>
            </div>
            <div class="filter-field" data-period-field="month" hidden>
                <label for="month-picker">Mês e ano</label>
                <input id="month-picker" name="month" type="month">
            </div>
            <div class="filter-field" data-period-field="custom" hidden>
                <label for="start-date">Data inicial</label>
                <input id="start-date" name="start_date" type="date">
            </div>
            <div class="filter-field" data-period-field="custom" hidden>
                <label for="end-date">Data final</label>
                <input id="end-date" name="end_date" type="date">
            </div>
            <div class="filter-field">
                <label for="cost-center">Centro de custo</label>
                <select id="cost-center" name="cost_center">
                    <option value="">Todos</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="movement-type">Tipo RE/DE</label>
                <select id="movement-type" name="movement_type">
                    <option value="">Todos</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="unit">Unidade de medida</label>
                <select id="unit" name="unit">
                    <option value="">Todas</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="metric">Métrica do candlestick</label>
                <select id="metric" name="metric">
                    <option value="cost">Custo total (R$)</option>
                    <option value="records">Registros</option>
                    <option value="distinct_work_orders">OPs distintas</option>
                    <option value="scrap_m2">Sobra apontada (m²)</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="palette">Paleta do gráfico</label>
                <select id="palette" name="palette">
                    <option value="standard">Padrão</option>
                    <option value="accessible">Acessível</option>
                </select>
            </div>
            <div class="filter-actions">
                <button class="button button-primary" type="submit">Aplicar filtros</button>
                <button class="button button-quiet" id="clear-filters" type="button">Limpar</button>
            </div>
        </form>

        <p id="dashboard-status" class="status-line" role="status" aria-live="polite">Carregando dados…</p>
        <div id="dashboard-error" class="alert alert-error" role="alert" hidden></div>

        <section class="kpi-grid" aria-label="Indicadores do período">
            <article class="kpi-card">
                <span class="kpi-label">Custo total</span>
                <strong id="kpi-cost" class="kpi-value">—</strong>
                <span class="kpi-note">Último snapshot do período</span>
            </article>
            <article class="kpi-card">
                <span class="kpi-label">Registros</span>
                <strong id="kpi-records" class="kpi-value">—</strong>
                <span class="kpi-note">Linhas consideradas nos filtros</span>
            </article>
            <article class="kpi-card">
                <span class="kpi-label">OPs distintas</span>
                <strong id="kpi-work-orders" class="kpi-value">—</strong>
                <span class="kpi-note">No último snapshot do período</span>
            </article>
            <article class="kpi-card kpi-card-accent">
                <span class="kpi-label">Sobra apontada</span>
                <strong id="kpi-scrap" class="kpi-value">—</strong>
                <span class="kpi-note">m², sem somar outras UMs</span>
            </article>
        </section>
        <p id="kpi-snapshot-note" class="snapshot-note"></p>

        <section class="content-panel chart-panel" aria-labelledby="chart-title">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">EVOLUÇÃO DIÁRIA</p>
                    <h2 id="chart-title">Candlestick</h2>
                </div>
                <span class="chart-hint">Zoom e rolagem aparecem quando há várias datas.</span>
            </div>
            <div id="candlestick-chart" class="candlestick-chart" role="img" aria-label="Candlestick diário do período"></div>
            <p id="chart-empty" class="empty-state" hidden>Nenhum snapshot encontrado neste período.</p>
            <div class="chart-legend">
                <span><i class="legend-swatch legend-up"></i> Alta</span>
                <span><i class="legend-swatch legend-down"></i> Baixa</span>
                <span><i class="legend-swatch legend-doji"></i> Doji sem fechamento anterior</span>
                <span>Candle derivado indica abertura calculada pelo fechamento anterior.</span>
            </div>
        </section>

        <section class="content-panel unit-charts-panel" aria-labelledby="unit-charts-title">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">COMPARAÇÃO POR UM</p>
                    <h2 id="unit-charts-title">Gráficos por unidade de medida</h2>
                </div>
                <button id="clear-unit-filter" class="button button-quiet" type="button" hidden>
                    Mostrar todas as UMs
                </button>
            </div>
            <p id="unit-chart-status" class="unit-chart-note" role="status" aria-live="polite">
                Carregando métricas por unidade…
            </p>
            <div id="unit-chart-grid" class="unit-chart-grid"></div>
            <p id="unit-chart-empty" class="empty-state" hidden>
                Não há dados de unidades de medida para este período e filtros.
            </p>
        </section>

        <section class="content-panel snapshots-panel" aria-labelledby="snapshots-title">
            <div class="panel-heading">
                <div>
                    <p class="eyebrow">ARQUIVO DIÁRIO</p>
                    <h2 id="snapshots-title">Snapshots do período</h2>
                </div>
                <span id="snapshot-count" class="count-badge">0 snapshots</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">Data</th>
                            <th scope="col">Versão</th>
                            <th scope="col">Horário</th>
                            <th scope="col">Arquivo</th>
                            <th scope="col">Linhas no arquivo</th>
                            <th scope="col">Custo sob filtros (R$)</th>
                            <th scope="col"><span class="visually-hidden">Ações</span></th>
                        </tr>
                    </thead>
                    <tbody id="snapshot-rows">
                        <tr><td class="table-empty" colspan="7">Carregando snapshots…</td></tr>
                    </tbody>
                </table>
            </div>
            <div class="pagination-controls">
                <span id="page-label">Página 1</span>
                <div>
                    <button class="button button-quiet" id="previous-page" type="button" disabled>Anterior</button>
                    <button class="button button-quiet" id="next-page" type="button" disabled>Próxima</button>
                </div>
            </div>
        </section>

        <aside id="snapshot-drawer" class="snapshot-drawer" aria-labelledby="drawer-title" aria-hidden="true">
            <div class="drawer-header">
                <div>
                    <p class="eyebrow">DETALHES DO DIA</p>
                    <h2 id="drawer-title">Snapshots</h2>
                </div>
                <button id="close-drawer" class="icon-button" type="button" aria-label="Fechar detalhes">×</button>
            </div>
            <p id="drawer-status" class="drawer-status" role="status" aria-live="polite"></p>
            <div id="drawer-snapshots" class="drawer-snapshots"></div>
            <a id="drawer-export-latest" class="button button-primary drawer-export" hidden>
                Exportar última versão
            </a>
        </aside>
        <button id="drawer-backdrop" class="drawer-backdrop" type="button" aria-label="Fechar detalhes" hidden></button>
    </main>
</body>
</html>
