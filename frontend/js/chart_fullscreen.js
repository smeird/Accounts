// Adds a fullscreen toggle button to Highcharts graphs.
// Buttons use Font Awesome icons and include aria-labels for accessibility.
document.addEventListener('DOMContentLoaded', () => {
    if (!window.Highcharts || !Highcharts.addEvent) return;

    Highcharts.addEvent(Highcharts.Chart, 'load', function () {
        const container = this.renderTo;
        if (!container || !container.dataset || !container.dataset.chartDesc) return;

        container.classList.add('relative');
        const btn = document.createElement('button');
        btn.className = 'absolute top-2 right-2 z-10 bg-white border border-gray-300 rounded p-1 text-gray-600 hover:bg-gray-50';
        btn.innerHTML = '<i class="fas fa-expand"></i>';
        btn.setAttribute('aria-label', 'View chart full screen');

        const update = () => {
            if (document.fullscreenElement === container) {
                btn.innerHTML = '<i class="fas fa-compress"></i>';
                btn.setAttribute('aria-label', 'Exit full screen');
            } else {
                btn.innerHTML = '<i class="fas fa-expand"></i>';
                btn.setAttribute('aria-label', 'View chart full screen');
            }
        };

        btn.addEventListener('click', () => {
            if (document.fullscreenElement === container) {
                document.exitFullscreen();
            } else if (container.requestFullscreen) {
                container.requestFullscreen();
            }
        });

        document.addEventListener('fullscreenchange', update);
        container.appendChild(btn);
    });
});
