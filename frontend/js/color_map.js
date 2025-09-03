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
let nextSegmentIndex = 0;

Highcharts.setOptions({
    colors: chartColors,
    chart: { style: { fontFamily: 'Inter, sans-serif' } },
    credits: { enabled: false },
    legend: { enabled: true, itemStyle: { fontSize: '10px' } },
    plotOptions: {
        series: { showInLegend: true },
        pie: { showInLegend: true },
        sunburst: { showInLegend: true }
    }
});

function getSegmentColor(name) {
    if (!name) name = 'Not Segmented';
    if (!segmentColorMap[name]) {
        segmentColorMap[name] = chartColors[nextSegmentIndex % chartColors.length];
        nextSegmentIndex++;
    }
    return segmentColorMap[name];
}

function getCategoryColor(segmentName, index = 0) {
    const base = getSegmentColor(segmentName);
    const steps = [0, 0.2, -0.2, 0.4, -0.4];
    const bright = steps[index % steps.length];
    return Highcharts.color(base).brighten(bright).get();
}

window.chartColors = chartColors;
window.getSegmentColor = getSegmentColor;
window.getCategoryColor = getCategoryColor;

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
