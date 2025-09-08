const chartColors = [
    '#4F46E5', // indigo-600
    '#4338CA', // indigo-700
    '#3730A3', // indigo-800
    '#6366F1', // indigo-500
    '#818CF8', // indigo-400
    '#A5B4FC', // indigo-300
    '#C7D2FE', // indigo-200
    '#E0E7FF'  // indigo-100
];
const segmentColorMap = {};
const categoryColorMap = {};
const categorySegmentMap = {};
const tagColorMap = {};
let nextSegmentIndex = 0;

function hashString(str) {
    let h = 0;
    for (let i = 0; i < str.length; i++) {
        h = (h << 5) - h + str.charCodeAt(i);
        h |= 0;
    }
    return Math.abs(h);
}

// Load palette CSS and data synchronously so colours are available immediately
try {
    const cssReq = new XMLHttpRequest();
    cssReq.open('GET', '../php_backend/public/palette_css.php', false);
    cssReq.send(null);
    if (cssReq.status === 200) {
        const style = document.createElement('style');
        style.textContent = cssReq.responseText;
        document.head.appendChild(style);
    }
    const req = new XMLHttpRequest();
    req.open('GET', '../php_backend/public/palette.php', false);
    req.send(null);
    if (req.status === 200) {
        const data = JSON.parse(req.responseText);
        const styles = getComputedStyle(document.documentElement);
        (data.segments || []).forEach(seg => {
            const base = styles.getPropertyValue(`--segment-${seg.id}-base`).trim();
            if (base) {
                segmentColorMap[seg.name] = base;
            }
            (seg.categories || []).forEach(cat => {
                categorySegmentMap[cat.name] = seg.name;
            });
        });
    }
} catch (e) {
    console.error('Failed to load colour palette', e);
}

function getChartTheme(theme) {
    const dark = theme === 'dark';
    const text = dark ? '#f3f4f6' : '#000000';
    return {
        colors: chartColors,
        chart: { style: { fontFamily: 'Inter, sans-serif', color: text }, backgroundColor: dark ? '#1f2937' : '#ffffff' },
        credits: { enabled: false },
        legend: { enabled: true, itemStyle: { fontSize: '10px', color: text } },
        title: { style: { color: text } },
        xAxis: { labels: { style: { color: text } }, title: { style: { color: text } } },
        yAxis: { labels: { style: { color: text } }, title: { style: { color: text } } },
        plotOptions: {
            series: { showInLegend: true },
            pie: { showInLegend: true },
            sunburst: { showInLegend: true }
        }
    };
}

function applyChartTheme(theme) {
    const opts = getChartTheme(theme);
    Highcharts.setOptions(opts);
    const update = {
        colors: opts.colors,
        chart: { backgroundColor: opts.chart.backgroundColor },
        legend: { itemStyle: opts.legend.itemStyle },
        title: opts.title,
        xAxis: { labels: opts.xAxis.labels, title: opts.xAxis.title },
        yAxis: { labels: opts.yAxis.labels, title: opts.yAxis.title }
    };
    Highcharts.charts.forEach(c => {
        if (c) {
            c.update(update, false);
            c.redraw();
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    applyChartTheme(document.body.classList.contains('dark') ? 'dark' : 'light');
});

document.addEventListener('themechange', e => applyChartTheme(e.detail));

function getSegmentColor(name) {
    if (!name) name = 'Not Segmented';
    if (!segmentColorMap[name]) {
        segmentColorMap[name] = chartColors[nextSegmentIndex % chartColors.length];
        nextSegmentIndex++;
    }
    return segmentColorMap[name];
}

function getCategoryColor(name, segmentName = null) {
    if (categoryColorMap[name]) return categoryColorMap[name];
    const seg = segmentName || categorySegmentMap[name];
    if (seg) {
        const base = getSegmentColor(seg);
        const hash = hashString(name);
        const shift = ((hash % 40) - 20) / 100; // -0.20..0.19
        const color = Highcharts.color(base).brighten(shift).get();
        categoryColorMap[name] = color;
        return color;
    }
    return chartColors[0];
}

function getTagColor(name, categoryName, categoryColor = null) {
    const key = `${categoryName}|${name}`;
    if (tagColorMap[key]) return tagColorMap[key];
    const base = categoryColor || getCategoryColor(categoryName);
    const hash = hashString(key);
    const shift = ((hash % 40) - 20) / 100; // -0.20..0.19
    const color = Highcharts.color(base).brighten(shift).get();
    tagColorMap[key] = color;
    return color;
}

window.chartColors = chartColors;
window.getSegmentColor = getSegmentColor;
window.getCategoryColor = getCategoryColor;
window.getTagColor = getTagColor;

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
