const chartColors = Highcharts.getOptions().colors;
const segmentColorMap = {};
let nextSegmentIndex = 0;

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
