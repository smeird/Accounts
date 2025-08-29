const chartColors = Highcharts.getOptions().colors;
const segmentColorMap = {};
let nextSegmentIndex = 0;

Highcharts.setOptions({
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
