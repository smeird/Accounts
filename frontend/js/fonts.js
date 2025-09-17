(function(){
  const systemFonts = new Set([
    '', 'Arial', 'Helvetica', 'Times New Roman', 'Georgia', 'Courier New',
    'Verdana', 'Trebuchet MS', 'Garamond', 'Comic Sans MS', 'serif',
    'sans-serif', 'monospace', 'inherit', 'system-ui'
  ]);

  function loadFont(font) {
    if (!font || systemFonts.has(font)) return;
    const id = 'font-' + font.replace(/\s+/g, '-');
    if (document.getElementById(id)) return;
    const link = document.createElement('link');
    link.id = id;
    link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=' +
      encodeURIComponent(font).replace(/%20/g, '+') + '&display=swap';
    document.head.appendChild(link);
  }

  function ensureStyle() {
    if (document.getElementById('font-overrides')) return;
    const style = document.createElement('style');
    style.id = 'font-overrides';
    style.textContent = `
      body { font-family: var(--body-font, inherit); }
      h1, h2, h3, h4, h5, h6 { font-family: var(--heading-font, inherit); }
      table, .tabulator, .tabulator * { font-family: var(--table-font, inherit); }
      .accent { font-family: var(--accent-font, inherit); font-weight: var(--accent-font-weight, 300); }
    `;
    document.head.appendChild(style);
  }

  window.applyFonts = function(opts){
    opts = opts || {};
    const heading = opts.heading_font || opts.font_heading || '';
    const body    = opts.body_font    || opts.font_body    || '';
    const table   = opts.table_font   || opts.font_table   || '';
    const chart   = opts.chart_font   || opts.font_chart   || '';
    const accentW = opts.accent_font_weight || opts.font_accent_weight || '';

    [heading, body, table, chart].forEach(loadFont);

    const root = document.documentElement;
    if (heading) root.style.setProperty('--heading-font', heading);
    if (body) root.style.setProperty('--body-font', body);
    if (table) {
      root.style.setProperty('--table-font', table);
      root.style.setProperty('--accent-font', table);
      root.style.setProperty('--tabulator-font-family', table);
      root.style.setProperty('--tabulator-header-font-family', table);
    }
    if (chart) root.style.setProperty('--chart-font', chart);
    if (accentW) {
      root.style.setProperty('--accent-font-weight', accentW);
    } else {
      root.style.removeProperty('--accent-font-weight');
    }

    ensureStyle();
    document.dispatchEvent(new Event('fonts-applied'));
  };
  window.loadFont = loadFont;
})();
