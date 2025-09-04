import {generatePalette, applyCss, supportsOklch} from './palette.js';
import {hexToOklch, oklchToHex} from './colour.js';

const state = { segments: [] };

document.addEventListener('DOMContentLoaded', async () => {
  const res = await fetch('../php_backend/public/palette.php', {cache: 'no-store'});
  const data = await res.json();
  state.segments = data.segments;
  document.getElementById('segment-count').textContent = state.segments.length;
  buildSegmentInputs();
  update();
  document.getElementById('apply').addEventListener('click', save);
  document.getElementById('refresh').addEventListener('click', update);
});

function buildSegmentInputs() {
  const container = document.getElementById('segments');
  container.innerHTML = '';
  state.segments.forEach((seg, idx) => {
    const div = document.createElement('div');
    div.className = 'mb-4';
    const color = oklchToHex((seg.base_l_pct || 67)/100, seg.base_c || 0.12, seg.hue_deg || 0);
    div.innerHTML = `
      <div class="flex items-center gap-2">
        <span class="w-32">${seg.name}</span>
        <input type="color" class="hue" value="${color}" data-help="Select a hue for this segment">
        <label class="text-sm"><input type="checkbox" class="lock" ${seg.locked ? 'checked' : ''} data-help="Keep this colour when refreshing"> Lock</label>
      </div>
      <div class="preview flex mt-2 gap-1"></div>
    `;
    const colorInput = div.querySelector('.hue');
    const lockInput = div.querySelector('.lock');
    colorInput.addEventListener('input', e => {
      const o = hexToOklch(e.target.value);
      seg.hue_deg = o.h;
      seg.locked = true;
      lockInput.checked = true;
      update();
    });
    lockInput.addEventListener('change', e => {
      seg.locked = e.target.checked;
      update();
    });
    container.appendChild(div);
    seg._preview = div.querySelector('.preview');
    if (window.initInputHelp) window.initInputHelp(div);
  });
}

function update() {
  generatePalette(state.segments);
  applyCss(state.segments);
  renderPreview();
}

function renderPreview() {
  state.segments.forEach(seg => {
    const preview = seg._preview;
    preview.innerHTML = '';
    const base = document.createElement('div');
    base.className = 'w-10 h-10 rounded';
    base.style.background = supportsOklch() ? `var(--segment-${seg.id}-base)` : `var(--segment-${seg.id}-base-hsl)`;
    preview.appendChild(base);
    seg.shades.forEach((sh, idx) => {
      const sw = document.createElement('div');
      sw.className = 'w-8 h-8 rounded';
      sw.style.background = supportsOklch() ? `var(--segment-${seg.id}-s${idx+1})` : `var(--segment-${seg.id}-s${idx+1}-hsl)`;
      preview.appendChild(sw);
    });
  });
}

async function save() {
  const payload = {
    segments: state.segments.map(s => ({
      id: s.id,
      hue_deg: s.hue_deg,
      base_l_pct: s.base_l_pct,
      base_c: s.base_c,
      locked: s.locked
    }))
  };
  await fetch('../php_backend/public/palette.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify(payload)
  });
  alert('Palette saved');
}
