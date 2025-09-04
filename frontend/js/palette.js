// Palette generation using OKLCH with HSL fallback.
// Requires colour.js utilities.
import {oklchToHsl, oklchToRgb, contrastRatio} from './colour.js';

const GOLDEN_ANGLE = 137.50776405;

export function supportsOklch() {
  return CSS && CSS.supports && CSS.supports('color', 'oklch(50% 0.1 40)');
}

export function generatePalette(segments, shadeCount = 7) {
  const startHue = 0;
  const used = new Set(
    segments
      .filter(s => s.locked && s.hue_deg !== null)
      .map(s => Math.round(s.hue_deg))
  );
  for (let i = 0; i < segments.length; i++) {
    const seg = segments[i];
    let h;
    if (seg.locked && seg.hue_deg !== null) {
      h = seg.hue_deg;
    } else {
      h = (startHue + i * GOLDEN_ANGLE) % 360;
      while (used.has(Math.round(h))) {
        h = (h + GOLDEN_ANGLE) % 360;
      }
      seg.hue_deg = h;
    }
    used.add(Math.round(seg.hue_deg));
    seg.base_l_pct = clamp(seg.base_l_pct ?? 67, 65, 70);
    seg.base_c = clamp(seg.base_c ?? 0.12, 0.1, 0.14);
  }
  const steps = Array.from({length: shadeCount}, (_, i) => 92 - (i * (92 - 38) / (shadeCount - 1)));
  segments.forEach(seg => {
    seg.shades = steps.map(l => {
      const c = seg.base_c * (l / seg.base_l_pct);
      const fg = l < 60 ? fgWhite : fgBlack;
      let rgb = oklchToRgb(l/100, c, seg.hue_deg);
      if (contrastRatio(rgb, fg) < 4.5) {
        l = seg.base_l_pct;
        rgb = oklchToRgb(l/100, c, seg.hue_deg);
      }
      const hsl = oklchToHsl(l/100, c, seg.hue_deg);
      return { l, c, h: seg.hue_deg, rgb, hsl, fg: fg === fgBlack ? '#000' : '#fff' };
    });
  });
  return segments;
}

const fgBlack = {r:0,g:0,b:0};
const fgWhite = {r:255,g:255,b:255};

export function applyCss(palette) {
  const root = document.documentElement;
  palette.forEach(seg => {
    const id = seg.id;
    const base = `oklch(${seg.base_l_pct}% ${seg.base_c} ${seg.hue_deg})`;
    const baseHsl = hslString(oklchToHsl(seg.base_l_pct/100, seg.base_c, seg.hue_deg));
    root.style.setProperty(`--segment-${id}-base`, base);
    root.style.setProperty(`--segment-${id}-base-hsl`, baseHsl);
    const fg = seg.base_l_pct < 60 ? '#fff' : '#000';
    root.style.setProperty(`--segment-${id}-fg`, fg);
    seg.shades.forEach((sh, idx) => {
      const i = idx + 1;
      const col = `oklch(${sh.l}% ${sh.c} ${sh.h})`;
      const hsl = hslString(sh.hsl);
      root.style.setProperty(`--segment-${id}-s${i}`, col);
      root.style.setProperty(`--segment-${id}-s${i}-hsl`, hsl);
      const fgc = sh.l < 60 ? '#fff' : '#000';
      root.style.setProperty(`--segment-${id}-s${i}-fg`, fgc);
    });
  });
}

function hslString(hsl) {
  return `hsl(${hsl.h.toFixed(0)} ${hsl.s.toFixed(1)}% ${hsl.l.toFixed(1)}%)`;
}

function clamp(v, min, max) { return Math.min(Math.max(v, min), max); }
