const chartColors = [
    '#4F46E5', // indigo-600
    '#0F766E', // teal-700
    '#D97706', // amber-600
    '#E11D48', // rose-600
    '#0284C7', // sky-600
    '#7C3AED', // violet-600
    '#059669', // emerald-600
    '#EA580C', // orange-600
    '#C026D3', // fuchsia-600
    '#475569'  // slate-600
];
const segmentColorMap = {};
const categoryColorMap = {};
const tagColorMap = {};

function hashString(str) {
    let h = 0;
    for (let i = 0; i < str.length; i++) {
        h = (h << 5) - h + str.charCodeAt(i);
        h |= 0;
    }
    return Math.abs(h);
}

function colourForKey(type, name) {
    const key = `${type}:${name || ''}`;
    return chartColors[hashString(key) % chartColors.length];
}

function getChartTheme() {
    const isProfessionalTheme = document.body.classList.contains('theme-professional');
    const text = '#0f172a';
    const styles = getComputedStyle(document.documentElement);
    const chartFont = styles.getPropertyValue('--chart-font').trim() || 'Inter, sans-serif';
    const background = 'rgba(255, 255, 255, 0)';
    const plotBackground = 'rgba(255, 255, 255, 0)';
    return {
        colors: chartColors,
        chart: {
            style: { fontFamily: chartFont, color: text },
            backgroundColor: background,
            plotBackgroundColor: plotBackground,
            borderColor: 'transparent',
            plotBorderColor: 'transparent',
            plotBorderWidth: 0,
            borderRadius: 12,
            borderWidth: 0,
            className: isProfessionalTheme ? 'glass-chart-professional' : 'glass-chart',
            shadow: false
        },
        credits: { enabled: false },
        legend: {
            enabled: true,
            backgroundColor: 'rgba(255, 255, 255, 0.08)',
            borderWidth: 0,
            borderRadius: 12,
            itemStyle: { fontSize: '10px', color: text, fontFamily: chartFont }
        },
        title: { style: { color: text, fontFamily: chartFont } },
        xAxis: { labels: { style: { color: text, fontFamily: chartFont } }, title: { style: { color: text, fontFamily: chartFont } } },
        yAxis: { labels: { style: { color: text, fontFamily: chartFont } }, title: { style: { color: text, fontFamily: chartFont } } },
        tooltip: {
            backgroundColor: 'rgba(15, 23, 42, 0.92)',
            borderColor: 'rgba(148, 163, 184, 0.4)',
            style: { color: '#F8FAFC', fontFamily: chartFont }
        },
        plotOptions: {
            series: { showInLegend: true, shadow: false, borderWidth: 0 },
            pie: { showInLegend: true, shadow: false, borderWidth: 0 },
            sunburst: { showInLegend: true, shadow: false }
        }
    };
}

function applyChartTheme() {
    const opts = getChartTheme();
    Highcharts.setOptions(opts);
    const update = {
        colors: opts.colors,
        chart: {
            backgroundColor: opts.chart.backgroundColor,
            plotBackgroundColor: opts.chart.plotBackgroundColor,
            className: opts.chart.className,
            borderColor: opts.chart.borderColor,
            plotBorderColor: opts.chart.plotBorderColor,
            plotBorderWidth: opts.chart.plotBorderWidth,
            borderRadius: opts.chart.borderRadius,
            borderWidth: opts.chart.borderWidth,
            shadow: opts.chart.shadow
        },
        legend: {
            itemStyle: opts.legend.itemStyle,
            backgroundColor: opts.legend.backgroundColor,
            borderWidth: opts.legend.borderWidth,
            borderRadius: opts.legend.borderRadius
        },
        title: opts.title,
        xAxis: { labels: opts.xAxis.labels, title: opts.xAxis.title },
        yAxis: { labels: opts.yAxis.labels, title: opts.yAxis.title },
        tooltip: opts.tooltip,
        plotOptions: {
            series: { shadow: opts.plotOptions.series.shadow, borderWidth: opts.plotOptions.series.borderWidth },
            pie: { shadow: opts.plotOptions.pie.shadow, borderWidth: opts.plotOptions.pie.borderWidth },
            sunburst: { shadow: opts.plotOptions.sunburst.shadow }
        }
    };
    Highcharts.charts.forEach(c => {
        if (c) {
            c.update(update, false);
            if (opts.chart.className && c.container) {
                c.container.classList.add(opts.chart.className);
            }
            c.redraw();
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    applyChartTheme();
});

document.addEventListener('fonts-applied', () => {
    applyChartTheme();
});

document.addEventListener('theme-changed', () => {
    applyChartTheme();
});

function getSegmentColor(name) {
    if (!name) name = 'Not Segmented';
    if (!segmentColorMap[name]) {
        segmentColorMap[name] = colourForKey('segment', name);
    }
    return segmentColorMap[name];
}

function getCategoryColor(name, segmentName = null) {
    if (!name) name = 'Unspecified';
    const key = `${segmentName || ''}|${name}`;
    if (categoryColorMap[key]) return categoryColorMap[key];
    if (segmentName) {
        const base = getSegmentColor(segmentName);
        const hash = hashString(name);
        const shift = ((hash % 31) - 15) / 100; // -0.15..0.15
        const color = Highcharts.color(base).brighten(shift).get();
        categoryColorMap[key] = color;
        return color;
    }
    categoryColorMap[key] = colourForKey('category', name);
    return categoryColorMap[key];
}

function getTagColor(name, categoryName, categoryColor = null) {
    const key = `${categoryName}|${name}`;
    if (tagColorMap[key]) return tagColorMap[key];
    const base = categoryColor || getCategoryColor(categoryName);
    const hash = hashString(key);
    const shift = ((hash % 25) - 12) / 100; // -0.12..0.12
    const color = Highcharts.color(base).brighten(shift).get();
    tagColorMap[key] = color;
    return color;
}

window.chartColors = chartColors;
window.getSegmentColor = getSegmentColor;
window.getCategoryColor = getCategoryColor;
window.getTagColor = getTagColor;
window.applyChartTheme = applyChartTheme;

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-chart-desc]').forEach(el => {
        const wrapper = document.createElement('div');
        el.parentNode.insertBefore(wrapper, el);
        wrapper.appendChild(el);

        const p = document.createElement('p');
        p.className = 'text-xs text-gray-600 mt-2';
        p.textContent = el.dataset.chartDesc;
        wrapper.appendChild(p);
    });
});
