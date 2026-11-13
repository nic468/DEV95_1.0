import * as echarts from 'echarts/core';
import { BarChart, CandlestickChart, LineChart } from 'echarts/charts';
import {
    AxisPointerComponent,
    DataZoomComponent,
    GridComponent,
    LegendComponent,
    MarkPointComponent,
    TooltipComponent,
} from 'echarts/components';
import { CanvasRenderer } from 'echarts/renderers';

echarts.use([
    CandlestickChart,
    BarChart,
    LineChart,
    AxisPointerComponent,
    DataZoomComponent,
    GridComponent,
    LegendComponent,
    MarkPointComponent,
    TooltipComponent,
    CanvasRenderer,
]);

const root = document.querySelector('[data-dashboard]');

if (root) {
    const form = document.querySelector('#dashboard-filters');
    const periodMode = document.querySelector('#period-mode');
    const periodPreset = document.querySelector('#period-preset');
    const monthPicker = document.querySelector('#month-picker');
    const startDate = document.querySelector('#start-date');
    const endDate = document.querySelector('#end-date');
    const costCenter = document.querySelector('#cost-center');
    const movementType = document.querySelector('#movement-type');
    const unit = document.querySelector('#unit');
    const metric = document.querySelector('#metric');
    const palette = document.querySelector('#palette');
    const status = document.querySelector('#dashboard-status');
    const errorBanner = document.querySelector('#dashboard-error');
    const chartElement = document.querySelector('#candlestick-chart');
    const chartEmpty = document.querySelector('#chart-empty');
    const unitChartStatus = document.querySelector('#unit-chart-status');
    const unitChartGrid = document.querySelector('#unit-chart-grid');
    const unitChartEmpty = document.querySelector('#unit-chart-empty');
    const clearUnitFilter = document.querySelector('#clear-unit-filter');
    const snapshotRows = document.querySelector('#snapshot-rows');
    const snapshotCount = document.querySelector('#snapshot-count');
    const pageLabel = document.querySelector('#page-label');
    const previousPage = document.querySelector('#previous-page');
    const nextPage = document.querySelector('#next-page');
    const drawer = document.querySelector('#snapshot-drawer');
    const drawerBackdrop = document.querySelector('#drawer-backdrop');
    const drawerTitle = document.querySelector('#drawer-title');
    const drawerStatus = document.querySelector('#drawer-status');
    const drawerSnapshots = document.querySelector('#drawer-snapshots');
    const drawerExportLatest = document.querySelector('#drawer-export-latest');
    const queryAtLoad = new URLSearchParams(window.location.search);
    const state = {
        page: Math.max(1, Number.parseInt(queryAtLoad.get('page') ?? '1', 10) || 1),
        candles: [],
        chart: null,
        chartResizeObserver: null,
        unitCharts: [],
        unitChartResizeObservers: [],
        returnFocus: null,
    };

    const numberFormat = new Intl.NumberFormat('pt-BR');
    const quantityFormat = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 3 });
    const currencyFormat = new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    });
    const squareMeterFormat = new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 3,
        maximumFractionDigits: 3,
    });
    const dateTimeFormat = new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'short',
        timeZone: 'America/Sao_Paulo',
    });
    const metricLabels = {
        cost: 'Custo total (R$)',
        records: 'Registros',
        distinct_work_orders: 'OPs distintas',
        scrap_m2: 'Sobra apontada (m²)',
    };

    function setControlsFromParams(params) {
        if (params.has('start_date') || params.has('end_date')) {
            periodMode.value = 'custom';
        } else if (params.has('year') || params.has('month')) {
            periodMode.value = 'month';
        } else {
            periodMode.value = 'preset';
        }

        periodPreset.value = params.get('period') ?? 'current_month';
        const year = params.get('year');
        const month = params.get('month');
        if (year && month) {
            monthPicker.value = `${year}-${month.padStart(2, '0')}`;
        }
        startDate.value = params.get('start_date') ?? '';
        endDate.value = params.get('end_date') ?? '';
        metric.value = params.get('metric') ?? 'cost';
        palette.value = params.get('palette') ?? 'standard';
        costCenter.value = params.get('cost_center') ?? '';
        movementType.value = params.get('movement_type') ?? '';
        unit.value = params.get('unit') ?? '';
        state.page = Math.max(1, Number.parseInt(params.get('page') ?? '1', 10) || 1);
        updatePeriodFields();
    }

    function updatePeriodFields() {
        document.querySelectorAll('[data-period-field]').forEach((field) => {
            field.hidden = field.dataset.periodField !== periodMode.value;
        });
    }

    function addOptions(select, items, labelFor, placeholder) {
        select.replaceChildren(new Option(placeholder, ''));
        items.forEach((item) => {
            select.add(new Option(labelFor(item), item.value));
        });
    }

    async function fetchJson(url) {
        const response = await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (response.status === 401) {
            throw new Error('É necessário estar autenticado para acessar o painel.');
        }
        if (!(response.headers.get('content-type') ?? '').includes('application/json')) {
            throw new Error('O servidor retornou uma resposta inválida para o painel.');
        }
        const payload = await response.json();

        if (!response.ok) {
            const validationMessage = payload.errors
                ? Object.values(payload.errors).flat().join(' ')
                : '';
            throw new Error(validationMessage || payload.message || `Falha HTTP ${response.status}.`);
        }

        return payload;
    }

    function urlFor(endpoint, params) {
        const url = new URL(endpoint, window.location.origin);
        url.search = params.toString();
        return url;
    }

    async function loadOptions() {
        const options = await fetchJson(root.dataset.optionsUrl);
        addOptions(
            costCenter,
            options.cost_centers.map((center) => ({
                value: center.code,
                label: center.name,
            })),
            (item) => `${item.label} (${item.value})`,
            'Todos',
        );
        addOptions(
            movementType,
            options.movement_types.map((value) => ({ value, label: value })),
            (item) => item.label,
            'Todos',
        );
        addOptions(
            unit,
            options.units.map((value) => ({ value, label: value })),
            (item) => item.label,
            'Todas',
        );
        setControlsFromParams(queryAtLoad);
    }

    function buildParams() {
        const params = new URLSearchParams();

        if (periodMode.value === 'preset') {
            params.set('period', periodPreset.value);
        } else if (periodMode.value === 'month') {
            const [year, month] = monthPicker.value.split('-');
            params.set('year', year);
            params.set('month', String(Number.parseInt(month, 10)));
        } else {
            params.set('start_date', startDate.value);
            params.set('end_date', endDate.value);
        }

        if (costCenter.value) params.set('cost_center', costCenter.value);
        if (movementType.value) params.set('movement_type', movementType.value);
        if (unit.value) params.set('unit', unit.value);
        params.set('metric', metric.value);
        params.set('palette', palette.value);
        params.set('page', String(state.page));

        return params;
    }

    function updateUrl(params) {
        const query = params.toString();
        window.history.pushState({}, '', query ? `${window.location.pathname}?${query}` : window.location.pathname);
    }

    function validatePeriod() {
        if (periodMode.value === 'month' && !monthPicker.value) {
            monthPicker.focus();
            return 'Selecione um mês e ano.';
        }
        if (periodMode.value === 'custom') {
            if (!startDate.value || !endDate.value) {
                (startDate.value ? endDate : startDate).focus();
                return 'Informe as datas inicial e final.';
            }
            if (startDate.value > endDate.value) {
                endDate.focus();
                return 'A data final deve ser igual ou posterior à inicial.';
            }
        }

        return null;
    }

    function setError(message) {
        errorBanner.textContent = message;
        errorBanner.hidden = false;
        status.textContent = 'Não foi possível carregar o painel.';
    }

    function clearError() {
        errorBanner.textContent = '';
        errorBanner.hidden = true;
    }

    async function refreshDashboard(updateHistory = false) {
        const validationError = validatePeriod();
        if (validationError) {
            setError(validationError);
            return;
        }

        const params = buildParams();
        if (updateHistory) updateUrl(params);
        clearError();
        status.textContent = 'Atualizando dados…';
        chartElement.setAttribute('aria-busy', 'true');
        if (state.chart) state.chart.showLoading('default', { text: 'Carregando…' });

        try {
            const [summary, candlestick, snapshots, unitCharts] = await Promise.all([
                fetchJson(urlFor(root.dataset.summaryUrl, params)),
                fetchJson(urlFor(root.dataset.candlestickUrl, params)),
                fetchJson(urlFor(root.dataset.snapshotsUrl, params)),
                fetchJson(urlFor(root.dataset.unitChartsUrl, params)),
            ]);

            renderKpis(summary);
            renderCandlestick(candlestick.candles);
            renderSnapshotRows(snapshots);
            renderUnitCharts(unitCharts);
            status.textContent = `${snapshots.pagination.total} snapshot(s) no período selecionado.`;
        } catch (error) {
            setError(error instanceof Error ? error.message : 'Erro inesperado ao carregar o painel.');
            if (state.chart) state.chart.hideLoading();
        } finally {
            chartElement.removeAttribute('aria-busy');
        }
    }

    function renderKpis(result) {
        const kpis = result.kpis;
        document.querySelector('#kpi-cost').textContent = kpis
            ? currencyFormat.format(Number(kpis.total_cost))
            : '—';
        document.querySelector('#kpi-records').textContent = kpis
            ? numberFormat.format(Number(kpis.record_count))
            : '—';
        document.querySelector('#kpi-work-orders').textContent = kpis
            ? numberFormat.format(Number(kpis.distinct_work_orders))
            : '—';
        document.querySelector('#kpi-scrap').textContent = kpis
            ? `${squareMeterFormat.format(Number(kpis.scrap_total_m2))} m²`
            : '—';

        const note = document.querySelector('#kpi-snapshot-note');
        if (result.snapshot) {
            const importedAt = dateTimeFormat.format(new Date(result.snapshot.imported_at));
            note.textContent = `Indicadores do snapshot ${result.snapshot.reference_date}, versão ${result.snapshot.version}, importado em ${importedAt}.`;
        } else {
            note.textContent = 'Nenhum snapshot disponível para calcular os indicadores deste período.';
        }
    }

    function formatMetric(value) {
        if (metric.value === 'cost') return currencyFormat.format(value);
        if (metric.value === 'scrap_m2') return `${squareMeterFormat.format(value)} m²`;
        return numberFormat.format(value);
    }

    function formatShortDate(value) {
        const [year, month, day] = value.split('-').map(Number);
        return new Intl.DateTimeFormat('pt-BR').format(new Date(year, month - 1, day));
    }

    function renderCandlestick(candles) {
        state.candles = candles;
        if (!state.chart) {
            state.chart = echarts.init(chartElement);
            state.chartResizeObserver = observeChartResize(state.chart, chartElement);
            state.chart.on('click', (event) => {
                if (event.seriesType === 'candlestick' && state.candles[event.dataIndex]) {
                    openSnapshotDay(state.candles[event.dataIndex].date);
                }
            });
        }
        state.chart.hideLoading();
        chartEmpty.hidden = candles.length > 0;
        chartElement.hidden = candles.length === 0;

        if (candles.length === 0) {
            document.querySelector('.chart-hint').textContent = 'Nenhum candle disponível para o período selecionado.';
            state.chart.clear();
            return;
        }

        const colors = palette.value === 'accessible'
            ? { up: '#0072B2', down: '#D55E00' }
            : { up: '#23835a', down: '#c84343' };
        const zoomEnabled = candles.length > 1;
        const dojiMarks = candles
            .filter((candle) => candle.doji)
            .map((candle) => ({
                name: 'Doji',
                coord: [candle.date, Number(candle.close)],
            }));
        document.querySelector('.chart-hint').textContent = zoomEnabled
            ? 'Arraste para ampliar; use a rolagem para navegar pelas datas.'
            : dojiMarks.length > 0
                ? 'Doji: abertura, máxima, mínima e fechamento coincidem; ampliação desativada para este ponto.'
                : 'Um candle disponível; ampliação de datas desativada.';
        document.querySelector('.legend-up').style.backgroundColor = colors.up;
        document.querySelector('.legend-down').style.backgroundColor = colors.down;
        document.querySelector('.legend-doji').style.borderColor = colors.up;

        state.chart.setOption({
            animation: false,
            grid: { left: 72, right: 28, top: 24, bottom: zoomEnabled ? 72 : 42, containLabel: true },
            tooltip: {
                trigger: 'axis',
                axisPointer: { type: 'cross' },
                formatter: (items) => {
                    const candleItem = items.find((item) => item.seriesType === 'candlestick');
                    if (!candleItem) return '';
                    const candle = candles[candleItem.dataIndex];
                    const lines = [
                        `<strong>${formatShortDate(candle.date)}</strong>`,
                        `Abertura: ${formatMetric(Number(candle.open))}`,
                        `Máxima: ${formatMetric(Number(candle.high))}`,
                        `Mínima: ${formatMetric(Number(candle.low))}`,
                        `Fechamento: ${formatMetric(Number(candle.close))}`,
                        `Variação: ${formatMetric(Number(candle.variation))}${candle.variation_percent === null ? '' : ` (${numberFormat.format(Number(candle.variation_percent))}%)`}`,
                        `Atualizações: ${numberFormat.format(candle.update_count)}`,
                    ];
                    if (candle.derived) lines.push('Abertura derivada do fechamento anterior');
                    if (candle.doji) lines.push('Doji: primeiro snapshot sem fechamento anterior');
                    return lines.join('<br>');
                },
            },
            xAxis: {
                type: 'category',
                data: candles.map((candle) => candle.date),
                boundaryGap: true,
                axisLabel: {
                    rotate: candles.length > 7 ? 35 : 0,
                    hideOverlap: true,
                    formatter: (value) => formatShortDate(value),
                },
                axisTick: { alignWithLabel: true },
            },
            yAxis: {
                scale: true,
                name: metricLabels[metric.value],
                nameLocation: 'middle',
                nameGap: 52,
                axisLabel: {
                    formatter: (value) => metric.value === 'cost'
                        ? new Intl.NumberFormat('pt-BR', { notation: 'compact', maximumFractionDigits: 1 }).format(value)
                        : numberFormat.format(value),
                },
                splitLine: { lineStyle: { color: '#edf1f5' } },
            },
            dataZoom: zoomEnabled
                ? [
                    { type: 'inside', filterMode: 'none' },
                    { type: 'slider', height: 18, bottom: 8, borderColor: '#dce3eb' },
                ]
                : [],
            series: [{
                name: metricLabels[metric.value],
                type: 'candlestick',
                barWidth: zoomEnabled ? '42%' : 16,
                data: candles.map((candle) => [
                    Number(candle.open),
                    Number(candle.close),
                    Number(candle.low),
                    Number(candle.high),
                ]),
                itemStyle: {
                    color: colors.up,
                    color0: colors.down,
                    borderColor: colors.up,
                    borderColor0: colors.down,
                },
                markPoint: dojiMarks.length > 0
                    ? {
                        symbol: 'circle',
                        symbolSize: 9,
                        silent: true,
                        label: { show: false },
                        itemStyle: {
                            color: '#fff',
                            borderColor: colors.up,
                            borderWidth: 2,
                        },
                        data: dojiMarks,
                    }
                    : undefined,
            }],
        }, { notMerge: true });
    }

    function unitChartColors() {
        return palette.value === 'accessible'
            ? ['#0072B2', '#E69F00', '#009E73', '#CC79A7', '#D55E00', '#56B4E9']
            : ['#205f91', '#1e9b77', '#e1a73d', '#735da6', '#cf6c52', '#537b83'];
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        })[character]);
    }

    function createUnitChartSection(card, title, unitCode) {
        const section = document.createElement('section');
        section.className = 'unit-chart-block';
        const heading = document.createElement('h4');
        heading.textContent = title;
        const chartElement = document.createElement('div');
        chartElement.className = 'unit-chart';
        chartElement.setAttribute('role', 'img');
        chartElement.setAttribute('aria-label', `${title} para a unidade ${unitCode}`);
        section.append(heading, chartElement);
        card.append(section);

        return chartElement;
    }

    function attachUnitChart(element, option, unitCode) {
        const chart = echarts.init(element);
        chart.setOption(option);
        chart.on('click', () => selectUnit(unitCode));
        state.unitCharts.push(chart);
        const observer = observeChartResize(chart, element);
        if (observer) state.unitChartResizeObservers.push(observer);
    }

    function observeChartResize(chart, element) {
        if (typeof ResizeObserver === 'undefined') return null;

        const observer = new ResizeObserver(() => chart.resize());
        observer.observe(element);

        return observer;
    }

    function selectUnit(unitCode) {
        unit.value = unit.value === unitCode ? '' : unitCode;
        state.page = 1;
        refreshDashboard(true);
    }

    function dailyPointsWithGaps(daily) {
        const points = [];

        daily.forEach((point, index) => {
            if (index > 0) {
                const previous = daily[index - 1];
                const cursor = new Date(`${previous.date}T00:00:00Z`);
                const current = new Date(`${point.date}T00:00:00Z`);
                cursor.setUTCDate(cursor.getUTCDate() + 1);

                if (cursor < current) {
                    points.push({
                        date: cursor.toISOString().slice(0, 10),
                        quantity_total: null,
                        total_cost: null,
                        snapshot_version: null,
                    });
                }
            }
            points.push(point);
        });

        return points;
    }

    function renderUnitCharts(result) {
        state.unitChartResizeObservers.forEach((observer) => observer.disconnect());
        state.unitChartResizeObservers = [];
        state.unitCharts.forEach((chart) => chart.dispose());
        state.unitCharts = [];
        unitChartGrid.replaceChildren();
        clearUnitFilter.hidden = !unit.value;
        unitChartEmpty.hidden = result.units.length > 0;

        if (!result.latest_snapshot) {
            unitChartStatus.textContent = 'Nenhum snapshot disponível para este período.';
            return;
        }

        unitChartStatus.textContent = `Barras e composição usam o snapshot ${result.latest_snapshot.reference_date}, versão ${result.latest_snapshot.version}. A linha mostra a última versão de cada dia com snapshot; dias sem snapshot ficam sem ponto. Clique em uma UM para filtrar o painel.`;

        result.units.forEach((unitData) => {
            const card = document.createElement('article');
            card.className = 'unit-card';
            const header = document.createElement('div');
            header.className = 'unit-card-header';
            const selectButton = document.createElement('button');
            selectButton.className = 'unit-select-button';
            selectButton.type = 'button';
            selectButton.textContent = `UM ${unitData.code}`;
            selectButton.setAttribute('aria-pressed', String(unit.value === unitData.code));
            selectButton.addEventListener('click', () => selectUnit(unitData.code));
            const filterHint = document.createElement('span');
            filterHint.className = 'unit-filter-hint';
            filterHint.textContent = unit.value === unitData.code
                ? 'Filtro ativo · clique para limpar'
                : 'Clique para filtrar o painel';
            header.append(selectButton, filterHint);
            card.append(header);
            unitChartGrid.append(card);

            const totals = document.createElement('div');
            totals.className = 'unit-total-summary';
            const quantity = document.createElement('div');
            const quantityLabel = document.createElement('span');
            quantityLabel.textContent = 'Quantidade';
            const quantityValue = document.createElement('strong');
            quantityValue.textContent = `${quantityFormat.format(Number(unitData.quantity_total))} ${unitData.code}`;
            quantity.append(quantityLabel, quantityValue);
            const cost = document.createElement('div');
            const costLabel = document.createElement('span');
            costLabel.textContent = 'Custo';
            const costValue = document.createElement('strong');
            costValue.textContent = currencyFormat.format(Number(unitData.total_cost));
            cost.append(costLabel, costValue);
            totals.append(quantity, cost);
            card.append(totals);

            const totalChart = createUnitChartSection(card, 'Quantidade e custo no último snapshot', unitData.code);
            const dailyChart = createUnitChartSection(card, 'Evolução diária', unitData.code);
            const centerChart = createUnitChartSection(card, 'Composição por centro de custo', unitData.code);
            const colors = unitChartColors();
            const dailyPoints = dailyPointsWithGaps(unitData.daily);

            attachUnitChart(totalChart, {
                animation: false,
                grid: { left: 76, right: 80, top: 48, bottom: 38, containLabel: true },
                legend: {
                    top: 2,
                    data: ['Quantidade', 'Custo (R$)'],
                    itemWidth: 11,
                    itemHeight: 8,
                    textStyle: { fontSize: 10 },
                },
                tooltip: {
                    trigger: 'axis',
                    formatter: (items) => items.map((item) => {
                        const value = Number(item.value);
                        const formatted = item.seriesName === 'Custo (R$)'
                            ? currencyFormat.format(value)
                            : `${quantityFormat.format(value)} ${escapeHtml(unitData.code)}`;

                        return `${escapeHtml(item.seriesName)}: ${formatted}`;
                    }).join('<br>'),
                },
                xAxis: {
                    type: 'category',
                    data: [unitData.code],
                    axisLabel: { hideOverlap: true, fontSize: 10 },
                },
                yAxis: [
                    {
                        type: 'value',
                        name: 'Quantidade',
                        nameLocation: 'middle',
                        nameGap: 48,
                        nameTextStyle: { fontSize: 10 },
                        axisLabel: {
                            fontSize: 10,
                            formatter: (value) => quantityFormat.format(value),
                        },
                    },
                    {
                        type: 'value',
                        name: 'Custo (R$)',
                        position: 'right',
                        nameLocation: 'middle',
                        nameGap: 50,
                        nameTextStyle: { fontSize: 10 },
                        axisLabel: {
                            fontSize: 10,
                            formatter: (value) => new Intl.NumberFormat('pt-BR', {
                                notation: 'compact',
                                maximumFractionDigits: 1,
                            }).format(value),
                        },
                    },
                ],
                series: [
                    {
                        name: 'Quantidade',
                        type: 'bar',
                        data: [Number(unitData.quantity_total)],
                        itemStyle: { color: colors[0] },
                    },
                    {
                        name: 'Custo (R$)',
                        type: 'bar',
                        yAxisIndex: 1,
                        data: [Number(unitData.total_cost)],
                        itemStyle: { color: colors[1] },
                    },
                ],
            }, unitData.code);

            attachUnitChart(dailyChart, {
                animation: false,
                grid: {
                    left: 76,
                    right: 80,
                    top: 48,
                    bottom: dailyPoints.length > 8 ? 56 : 40,
                    containLabel: true,
                },
                legend: {
                    top: 2,
                    data: ['Quantidade', 'Custo (R$)'],
                    itemWidth: 11,
                    itemHeight: 8,
                    textStyle: { fontSize: 10 },
                },
                tooltip: {
                    trigger: 'axis',
                    formatter: (items) => {
                        if (!items.length) return '';
                        const point = dailyPoints[items[0].dataIndex];
                        if (point.snapshot_version === null) {
                            return `${formatShortDate(point.date)}<br>Sem snapshot`;
                        }
                        return [
                            `<strong>${formatShortDate(point.date)}</strong> · versão ${point.snapshot_version}`,
                            `Quantidade: ${quantityFormat.format(Number(point.quantity_total))} ${escapeHtml(unitData.code)}`,
                            `Custo: ${currencyFormat.format(Number(point.total_cost))}`,
                        ].join('<br>');
                    },
                },
                xAxis: {
                    type: 'category',
                    data: dailyPoints.map((point) => point.date),
                    axisLabel: {
                        rotate: dailyPoints.length > 8 ? 35 : 0,
                        hideOverlap: true,
                        fontSize: 10,
                        formatter: (value) => formatShortDate(value),
                    },
                },
                yAxis: [
                    {
                        type: 'value',
                        name: 'Quantidade',
                        nameLocation: 'middle',
                        nameGap: 48,
                        nameTextStyle: { fontSize: 10 },
                        axisLabel: {
                            fontSize: 10,
                            formatter: (value) => quantityFormat.format(value),
                        },
                    },
                    {
                        type: 'value',
                        name: 'Custo (R$)',
                        position: 'right',
                        nameLocation: 'middle',
                        nameGap: 50,
                        nameTextStyle: { fontSize: 10 },
                        axisLabel: {
                            fontSize: 10,
                            formatter: (value) => new Intl.NumberFormat('pt-BR', {
                                notation: 'compact',
                                maximumFractionDigits: 1,
                            }).format(value),
                        },
                    },
                ],
                series: [
                    {
                        name: 'Quantidade',
                        type: 'line',
                        yAxisIndex: 0,
                        data: dailyPoints.map((point) => (
                            point.quantity_total === null ? null : Number(point.quantity_total)
                        )),
                        smooth: true,
                        symbolSize: 6,
                        lineStyle: { color: colors[0] },
                        itemStyle: { color: colors[0] },
                    },
                    {
                        name: 'Custo (R$)',
                        type: 'line',
                        yAxisIndex: 1,
                        data: dailyPoints.map((point) => (
                            point.total_cost === null ? null : Number(point.total_cost)
                        )),
                        smooth: true,
                        symbolSize: 6,
                        lineStyle: { color: colors[1] },
                        itemStyle: { color: colors[1] },
                    },
                ],
            }, unitData.code);

            if (unitData.cost_centers.length === 0) {
                centerChart.hidden = true;
                const empty = document.createElement('p');
                empty.className = 'unit-chart-empty';
                empty.textContent = 'Sem dados por centro de custo no último snapshot.';
                centerChart.parentElement.append(empty);
            } else {
                const centerSeries = unitData.cost_centers.map((center, index) => ({
                    ...center,
                    label: `${center.code} · ${center.name}`,
                    color: colors[index % colors.length],
                }));
                attachUnitChart(centerChart, {
                    animation: false,
                    grid: { left: 80, right: 20, top: 46, bottom: 34, containLabel: true },
                    legend: {
                        type: 'scroll',
                        top: 2,
                        left: 8,
                        right: 8,
                        data: centerSeries.map((center) => center.label),
                        itemWidth: 10,
                        itemHeight: 8,
                        textStyle: { fontSize: 10 },
                    },
                    tooltip: {
                        trigger: 'axis',
                        formatter: (items) => items.map((item) => (
                            `${escapeHtml(item.seriesName)}: ${currencyFormat.format(Number(item.value))}`
                        )).join('<br>'),
                    },
                    xAxis: {
                        type: 'value',
                        axisLabel: {
                            fontSize: 10,
                            formatter: (value) => new Intl.NumberFormat('pt-BR', {
                                notation: 'compact',
                                maximumFractionDigits: 1,
                            }).format(value),
                        },
                    },
                    yAxis: {
                        type: 'category',
                        data: [`Total ${unitData.code}`],
                        axisLabel: { fontSize: 10 },
                    },
                    series: centerSeries.map((center) => ({
                        name: center.label,
                        type: 'bar',
                        stack: 'cost-centers',
                        data: [Number(center.total_cost)],
                        itemStyle: { color: center.color },
                    })),
                }, unitData.code);
            }

        });
    }

    function createCell(row, value, className = '') {
        const cell = document.createElement('td');
        cell.textContent = value;
        if (className) cell.className = className;
        row.append(cell);
        return cell;
    }

    function renderSnapshotRows(result) {
        snapshotRows.replaceChildren();
        snapshotCount.textContent = `${numberFormat.format(result.pagination.total)} snapshot(s)`;
        pageLabel.textContent = `Página ${result.pagination.current_page} de ${result.pagination.last_page}`;
        previousPage.disabled = result.pagination.current_page <= 1;
        nextPage.disabled = result.pagination.current_page >= result.pagination.last_page;

        if (result.data.length === 0) {
            const row = document.createElement('tr');
            createCell(row, 'Nenhum snapshot encontrado com estes filtros.', 'table-empty');
            row.firstElementChild.colSpan = 7;
            snapshotRows.append(row);
            return;
        }

        result.data.forEach((snapshot) => {
            const row = document.createElement('tr');
            createCell(row, formatShortDate(snapshot.reference_date));
            createCell(row, String(snapshot.version));
            createCell(row, dateTimeFormat.format(new Date(snapshot.imported_at)));
            createCell(row, snapshot.filename, 'filename-cell');
            createCell(row, numberFormat.format(snapshot.source_row_count));
            createCell(row, currencyFormat.format(Number(snapshot.total_cost)));
            const actionCell = document.createElement('td');
            const detailsButton = document.createElement('button');
            detailsButton.type = 'button';
            detailsButton.className = 'text-button';
            detailsButton.dataset.snapshotDate = snapshot.reference_date;
            detailsButton.textContent = 'Ver dia';
            actionCell.append(detailsButton);
            row.append(actionCell);
            snapshotRows.append(row);
        });
    }

    function addDrawerSnapshot(snapshot) {
        const card = document.createElement('article');
        card.className = 'drawer-snapshot-card';
        const heading = document.createElement('h3');
        heading.textContent = `Versão ${snapshot.version}`;
        card.append(heading);
        const details = document.createElement('dl');
        [
            ['Horário', dateTimeFormat.format(new Date(snapshot.imported_at))],
            ['Arquivo', snapshot.filename],
            ['Linhas', numberFormat.format(snapshot.row_count)],
            ['Custo total', currencyFormat.format(Number(snapshot.total_cost))],
        ].forEach(([label, value]) => {
            const term = document.createElement('dt');
            term.textContent = label;
            const description = document.createElement('dd');
            description.textContent = value;
            details.append(term, description);
        });
        card.append(details);
        const exportLink = document.createElement('a');
        exportLink.className = 'button button-primary drawer-export-link';
        exportLink.href = snapshotExportUrl(snapshot.id);
        exportLink.textContent = 'Exportar este snapshot';
        card.append(exportLink);
        drawerSnapshots.append(card);
    }

    function snapshotExportUrl(snapshotId) {
        const baseUrl = root.dataset.snapshotExportBase;
        const url = new URL(
            `${baseUrl}/${encodeURIComponent(String(snapshotId))}/export`,
            window.location.origin,
        );
        const activeFilters = new URLSearchParams(window.location.search);

        ['cost_center', 'movement_type', 'unit'].forEach((key) => {
            const value = activeFilters.get(key);
            if (value) url.searchParams.set(key, value);
        });

        return url.toString();
    }

    async function openSnapshotDay(date) {
        state.returnFocus = document.activeElement;
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        drawerBackdrop.hidden = false;
        document.body.classList.add('drawer-open');
        drawerTitle.textContent = `Snapshots de ${formatShortDate(date)}`;
        drawerStatus.textContent = 'Carregando snapshots…';
        drawerSnapshots.replaceChildren();
        drawerExportLatest.hidden = true;
        document.querySelector('#close-drawer').focus();

        try {
            const url = new URL(root.dataset.snapshotDayUrl, window.location.origin);
            url.searchParams.set('date', date);
            const result = await fetchJson(url);
            drawerSnapshots.replaceChildren();
            result.snapshots.forEach(addDrawerSnapshot);
            if (result.snapshots.length > 0) {
                const latestSnapshot = result.snapshots[result.snapshots.length - 1];
                drawerExportLatest.href = snapshotExportUrl(latestSnapshot.id);
                drawerExportLatest.hidden = false;
            }
            drawerStatus.textContent = result.snapshots.length
                ? `${numberFormat.format(result.snapshots.length)} snapshot(s) arquivado(s) neste dia.`
                : 'Nenhum snapshot arquivado neste dia.';
        } catch (error) {
            drawerStatus.textContent = error instanceof Error
                ? error.message
                : 'Erro inesperado ao carregar os detalhes.';
        }
    }

    function closeDrawer() {
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        drawerBackdrop.hidden = true;
        document.body.classList.remove('drawer-open');
        if (state.returnFocus instanceof HTMLElement) state.returnFocus.focus();
    }

    periodMode.addEventListener('change', updatePeriodFields);
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        state.page = 1;
        refreshDashboard(true);
    });
    document.querySelector('#clear-filters').addEventListener('click', () => {
        window.history.pushState({}, '', window.location.pathname);
        window.location.reload();
    });
    clearUnitFilter.addEventListener('click', () => selectUnit(unit.value));
    previousPage.addEventListener('click', () => {
        state.page = Math.max(1, state.page - 1);
        refreshDashboard(true);
    });
    nextPage.addEventListener('click', () => {
        state.page += 1;
        refreshDashboard(true);
    });
    snapshotRows.addEventListener('click', (event) => {
        const button = event.target.closest('[data-snapshot-date]');
        if (button) openSnapshotDay(button.dataset.snapshotDate);
    });
    document.querySelector('#close-drawer').addEventListener('click', closeDrawer);
    drawerBackdrop.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && drawer.classList.contains('is-open')) closeDrawer();
    });
    palette.addEventListener('change', () => {
        if (state.candles.length) renderCandlestick(state.candles);
    });
    window.addEventListener('popstate', () => {
        setControlsFromParams(new URLSearchParams(window.location.search));
        refreshDashboard();
    });
    window.addEventListener('resize', () => {
        state.chart?.resize();
        state.unitCharts.forEach((chart) => chart.resize());
    });

    loadOptions()
        .then(() => refreshDashboard())
        .catch((error) => {
            setError(error instanceof Error ? error.message : 'Erro inesperado ao iniciar o painel.');
            snapshotRows.replaceChildren();
        });
}
