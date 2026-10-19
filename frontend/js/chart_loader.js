// Secondary charts load only when visible; ledger data never waits for the CDN.
(function () {
    'use strict';
    let pending;
    const jobs = new WeakMap();
    function script(src) {
        return new Promise((resolve, reject) => {
            const tag = document.createElement('script');
            const timer = setTimeout(() => { tag.remove(); reject(new Error('Chart loading timed out.')); }, 10000);
            tag.src = src; tag.async = true;
            tag.onload = () => { clearTimeout(timer); resolve(); };
            tag.onerror = () => { clearTimeout(timer); tag.remove(); reject(new Error('Chart unavailable.')); };
            document.head.append(tag);
        });
    }
    function applyFonts() {
        if (!window.Highcharts) return;
        const font = getComputedStyle(document.documentElement).getPropertyValue('--chart-font').trim() || getComputedStyle(document.body).fontFamily;
        const options = { chart: { style: { fontFamily: font } } };
        window.Highcharts.setOptions(options);
        window.Highcharts.charts.forEach(chart => chart?.update(options, true));
    }
    document.addEventListener('fonts-applied', applyFonts);
    function load() {
        if (window.Highcharts?.AccessibilityComponent) return Promise.resolve();
        if (!pending) pending = (async () => {
            if (!window.Highcharts) await script('https://code.highcharts.com/highcharts.js');
            if (!window.Highcharts.AccessibilityComponent) await script('https://code.highcharts.com/modules/accessibility.js');
            window.applyChartTheme?.();
            applyFonts();
        })().catch(error => { pending = null; throw error; });
        return pending;
    }
    function whenVisible(container, render) {
        if (!container) return;
        jobs.get(container)?.disconnect?.();
        const token = {};
        const run = async () => {
            try {
                await load();
                if (jobs.get(container) === token) render();
            } catch (_) {
                if (jobs.get(container) === token) {
                    container.textContent = 'Chart unavailable. Your financial figures and transaction evidence remain available.';
                }
            }
        };
        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(entries => {
                if (!entries.some(entry => entry.isIntersecting)) return;
                observer.disconnect(); run();
            }, { rootMargin: '100px' });
            token.disconnect = () => observer.disconnect();
            jobs.set(container, token); observer.observe(container);
        } else { jobs.set(container, token); run(); }
    }
    function cancel(container) {
        jobs.get(container)?.disconnect?.();
        jobs.delete(container);
    }
    window.AppCharts = { load, whenVisible, cancel };
})();
