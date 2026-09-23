(function () {
    'use strict';
    const el = id => document.getElementById(id);
    const money = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' });
    let table, chart, controller;
    let requestNumber = 0;

    async function json(url, signal) {
        const response = await fetch(url, { signal, cache: 'no-store' });
        if (response.status === 401) throw new Error('Your session has expired. Please sign in again.');
        const data = await response.json();
        if (!response.ok || data.error) throw new Error(data.error || 'Unable to load analysis');
        return data;
    }

    function evidence(data, direction = 'all', tag) {
        const options = TransactionDrilldown.financial({
            group_id: data.group.id, start: data.start, end: data.end,
            direction, label: `${data.group.name}${tag ? ' · ' + tag.name : ''}`
        });
        if (tag) {
            options.dimension = 'tag';
            if (tag.id === null) options.unclassified = true;
            else options.dimension_id = tag.id;
        }
        return options;
    }

    function render(data) {
        const totals = data.summary;
        el('analysis-results').hidden = false;
        el('group-title').textContent = data.group.name;
        el('group-description').textContent = data.group.description || '';
        el('analysis-period').textContent = `${data.start || 'First record'} → ${data.end || 'Latest record'}${data.first_date ? ` · Recorded activity: ${data.first_date} to ${data.last_date}` : ''}`;
        [['total-spending', 'spending', 'spending'], ['total-income', 'income', 'income'], ['total-net', 'net_cost', 'all'], ['total-count', 'count', 'all']].forEach(([id, field, direction]) => {
            TransactionDrilldown.linkify(el(id), evidence(data, direction), field === 'count' ? String(totals[field]) : money.format(totals[field]), `View ${el(id).previousElementSibling.textContent.toLowerCase()} transactions for ${data.group.name}`);
        });
        el('exclusion-note').textContent = `Transfers and IGNORE-tagged entries are excluded from the figures above (${totals.excluded} excluded in this period). Money received includes any refunds or contributions recorded in this group.`;
        el('all-entries').href = TransactionDrilldown.url({ ...evidence(data), transfer_scope: 'include', ignored_scope: 'include' });
        el('analysis-state').textContent = totals.count ? '' : 'No transactions to analyse in this period. Try widening the dates or view all group entries.';
        if (table) table.destroy();
        const amountColumn = (title, field, direction) => ({ title, field, sorter: 'number', hozAlign: 'right', minWidth: 130,
            formatter(cell) {
                const link = document.createElement('a');
                const tag = cell.getRow().getData();
                link.textContent = field === 'count' ? String(cell.getValue()) : money.format(cell.getValue());
                link.href = TransactionDrilldown.url(evidence(data, direction, tag));
                link.className = 'transaction-drilldown-link';
                link.setAttribute('aria-label', `View ${title.toLowerCase()} transactions for ${tag.name}`);
                return link;
            }
        });
        table = tailwindTabulator(el('group-tags'), {
            data: data.tags, layout: 'fitColumns', pagination: 'local', paginationSize: 10,
            placeholder: 'No tags in the selected period',
            columns: [
                { title: 'Tag', field: 'name', minWidth: 180, formatter: badgeFormatter('bg-indigo-200 text-indigo-800') },
                amountColumn('Money spent', 'spending', 'spending'),
                { title: 'Share of spend', field: 'share', sorter: 'number', hozAlign: 'right', minWidth: 120, formatter: cell => `${Number(cell.getValue()).toFixed(1)}%` },
                amountColumn('Money received', 'income', 'income'),
                amountColumn('Net cost', 'net_cost', 'all'),
                amountColumn('Transactions', 'count', 'all')
            ]
        });
        if (chart) { chart.destroy(); chart = null; }
        const topTags = data.tags.filter(tag => tag.spending > 0).slice(0, 12);
        if (!topTags.length) { el('group-chart').textContent = 'No outgoing spending in this period.'; return; }
        if (!window.Highcharts) { el('group-chart').textContent = 'The chart could not load. All amounts are available in the table below.'; return; }
        chart = Highcharts.chart('group-chart', {
            chart: { type: 'bar', backgroundColor: 'transparent', height: Math.max(240, topTags.length * 38 + 90), animation: false, style: { fontFamily: getComputedStyle(document.documentElement).getPropertyValue('--chart-font').trim() || 'inherit' } },
            title: { text: null }, credits: { enabled: false }, legend: { enabled: false },
            xAxis: { categories: topTags.map(tag => tag.name) },
            yAxis: { min: 0, title: { text: 'Money spent (£)' } },
            tooltip: { valuePrefix: '£', valueDecimals: 2 },
            accessibility: { description: 'Top spending tags in the selected group and period. Each bar opens its transactions.' },
            plotOptions: { series: { ...TransactionDrilldown.highchartsPoint(point => evidence(data, 'spending', topTags[point.index])), animation: false } },
            series: [{ name: 'Money spent', color: getComputedStyle(document.documentElement).getPropertyValue('--brand-color-600').trim() || '#6366f1', data: topTags.map(tag => tag.spending) }]
        });
    }

    async function load() {
        const number = ++requestNumber;
        if (controller) controller.abort();
        controller = new AbortController();
        el('analysis-results').hidden = true;
        const group = el('analysis-group').value;
        if (!group) { el('analysis-state').textContent = 'Choose a group to explore its costs.'; return; }
        const start = el('analysis-start').value, end = el('analysis-end').value;
        if (start && end && start > end) { el('analysis-state').textContent = 'The start date must be on or before the end date.'; return; }
        el('analysis-state').textContent = 'Loading group analysis…';
        el('analysis-results').setAttribute('aria-busy', 'true');
        const params = new URLSearchParams({ group_id: group, start, end });
        try {
            const data = await json('../php_backend/public/group_analysis.php?' + params, controller.signal);
            if (number !== requestNumber) return;
            history.replaceState(null, '', '?' + params);
            render(data);
        } catch (error) {
            if (number !== requestNumber || error.name === 'AbortError') return;
            el('analysis-results').hidden = true;
            el('analysis-state').textContent = error.message + ' Use Update analysis to try again.';
            window.showMessage(error.message, 'error');
        } finally {
            if (number === requestNumber) el('analysis-results').setAttribute('aria-busy', 'false');
        }
    }

    async function init() {
        const params = new URLSearchParams(location.search);
        el('analysis-start').value = params.get('start') || '';
        el('analysis-end').value = params.get('end') || '';
        try {
            const groups = (await json('../php_backend/public/groups.php')).filter(group => Number(group.active) === 1).sort((a, b) => a.name.localeCompare(b.name));
            el('analysis-group').replaceChildren(new Option('Choose a group', ''));
            groups.forEach(group => el('analysis-group').add(new Option(group.name, group.id)));
            el('analysis-group').value = params.get('group_id') || '';
            el('analysis-state').textContent = groups.length ? 'Choose a group to explore its costs.' : 'No active groups yet. Create or reactivate a group in Organise → Groups and assign transactions to it.';
            if (el('analysis-group').value) await load();
        } catch (error) {
            el('analysis-group').replaceChildren(new Option('Groups unavailable', ''));
            el('analysis-state').textContent = error.message + ' Reload this page to try again.';
        }
    }
    el('group-filters').addEventListener('submit', event => { event.preventDefault(); load(); });
    el('analysis-group').addEventListener('change', load);
    el('all-dates').addEventListener('click', () => { el('analysis-start').value = ''; el('analysis-end').value = ''; load(); });
    init();
})();
