(function(){
  const systemFonts = new Set([
    '', 'Arial', 'Helvetica', 'Times New Roman', 'Georgia', 'Courier New',
    'Verdana', 'Trebuchet MS', 'Garamond', 'Comic Sans MS', 'serif',
    'sans-serif', 'monospace', 'inherit', 'system-ui'
  ]);

  const FONT_WEIGHTS = '100;300;400;700';
  const FONT_WEIGHT_SETS = {
    'Atkinson Hyperlegible': '400;700',
    'Bangers': '400',
    'Caveat': '400;700',
    'Dancing Script': '400;700',
    'Fjalla One': '400',
    'Fredoka': '300;400;700',
    'Libre Baskerville': '400;700',
    'Lora': '400;700',
    'Merriweather': '300;400;700',
    'Oswald': '300;400;700',
    'Pacifico': '400',
    'Playfair Display': '400;700',
    'Source Sans 3': '300;400;700',
    'Source Serif Pro': '300;400;700',
    'Source Serif 4': '300;400;700',
    'Space Grotesk': '300;400;700'
  };

  function loadFont(font, includeWeights) {
    if (!font || systemFonts.has(font)) return;
    const baseId = 'font-' + font.replace(/\s+/g, '-');
    const weightedId = baseId + '-configured-weights';
    const id = includeWeights ? weightedId : baseId;
    if (!includeWeights && document.getElementById(weightedId)) return;
    if (document.getElementById(id)) return;
    const link = document.createElement('link');
    link.id = id;
    link.rel = 'stylesheet';
    link.href = 'https://fonts.googleapis.com/css2?family=' +
      encodeURIComponent(font).replace(/%20/g, '+') +
      (includeWeights ? ':wght@' + (FONT_WEIGHT_SETS[font] || FONT_WEIGHTS) : '') +
      '&display=swap';
    document.head.appendChild(link);
  }

  function ensureStyle() {
    if (document.getElementById('font-overrides')) return;
    const style = document.createElement('style');
    style.id = 'font-overrides';
    style.textContent = `
      :root[data-body-font] body { font-family: var(--body-font); }
      :root[data-body-font] button,
      :root[data-body-font] input,
      :root[data-body-font] select,
      :root[data-body-font] textarea { font-family: inherit; }
      :root[data-heading-font] h1,
      :root[data-heading-font] h2,
      :root[data-heading-font] h3,
      :root[data-heading-font] h4,
      :root[data-heading-font] h5,
      :root[data-heading-font] h6 { font-family: var(--heading-font); }
      :root[data-table-font] table,
      :root[data-table-font] .tabulator { font-family: var(--table-font); }
      :root[data-table-font] table :is(button, input, select, textarea):not(.fa):not(.fas):not(.far):not(.fab):not(.fa-solid):not(.fa-regular):not(.fa-brands),
      :root[data-table-font] .tabulator :is(button, input, select, textarea):not(.fa):not(.fas):not(.far):not(.fab):not(.fa-solid):not(.fa-regular):not(.fa-brands) { font-family: inherit; }
      :root[data-table-font] .accent { font-family: var(--accent-font); }
      :root[data-accent-font-weight] .accent,
      :root[data-accent-font-weight] .page-title,
      :root[data-accent-font-weight] input:not([type="checkbox"]):not([type="radio"]):not([type="range"]),
      :root[data-accent-font-weight] select,
      :root[data-accent-font-weight] textarea,
      :root[data-accent-font-weight] .tabulator .tabulator-header-filter input {
        font-weight: var(--accent-font-weight) !important;
      }
    `;
    document.head.appendChild(style);
  }

  function setOrRemove(root, property, value) {
    if (value) root.style.setProperty(property, value);
    else root.style.removeProperty(property);
  }

  function setDataFlag(root, name, value) {
    if (value) root.dataset[name] = value;
    else delete root.dataset[name];
  }

  window.applyFonts = function(opts){
    opts = opts || {};
    const heading = opts.heading_font || opts.font_heading || '';
    const body    = opts.body_font    || opts.font_body    || '';
    const table   = opts.table_font   || opts.font_table   || '';
    const chart   = opts.chart_font   || opts.font_chart   || '';
    const accentW = opts.accent_font_weight || opts.font_accent_weight || '';

    [heading, body, table, chart].forEach(font => loadFont(font, true));

    const root = document.documentElement;
    setOrRemove(root, '--heading-font', heading);
    setOrRemove(root, '--body-font', body);
    setOrRemove(root, '--table-font', table);
    setOrRemove(root, '--accent-font', table);
    setOrRemove(root, '--tabulator-font-family', table);
    setOrRemove(root, '--tabulator-header-font-family', table);
    setOrRemove(root, '--chart-font', chart);
    setDataFlag(root, 'headingFont', heading);
    setDataFlag(root, 'bodyFont', body);
    setDataFlag(root, 'tableFont', table);
    if (accentW) {
      root.style.setProperty('--accent-font-weight', accentW);
      root.dataset.accentFontWeight = accentW;
    } else {
      root.style.removeProperty('--accent-font-weight');
      delete root.dataset.accentFontWeight;
    }

    ensureStyle();
    document.dispatchEvent(new Event('fonts-applied'));
  };
  window.loadFont = loadFont;
})();
