// Utility functions for colour conversions and contrast checks using OKLCH.
// Functions are exported for use in palette generation and UI modules.

export function hexToOklch(hex) {
  const rgb = hexToRgb(hex);
  return rgbToOklch(rgb.r, rgb.g, rgb.b);
}

export function oklchToHex(l, c, h) {
  const rgb = oklchToRgb(l, c, h);
  return rgbToHex(rgb.r, rgb.g, rgb.b);
}

export function oklchToRgb(l, c, h) {
  // Convert OKLCH to sRGB (0-255) using OKLab formulas
  const hr = (h * Math.PI) / 180;
  const a = Math.cos(hr) * c;
  const b = Math.sin(hr) * c;
  const l_ = l + 0.3963377774 * a + 0.2158037573 * b;
  const m_ = l - 0.1055613458 * a - 0.0638541728 * b;
  const s_ = l - 0.0894841775 * a - 1.2914855480 * b;
  const l3 = l_ ** 3;
  const m3 = m_ ** 3;
  const s3 = s_ ** 3;
  let r = 4.0767416621 * l3 - 3.3077115913 * m3 + 0.2309699292 * s3;
  let g = -1.2684380046 * l3 + 2.6097574011 * m3 - 0.3413193965 * s3;
  let b2 = -0.0041960863 * l3 - 0.7034186147 * m3 + 1.7076147010 * s3;
  r = linearToSrgb(r);
  g = linearToSrgb(g);
  b2 = linearToSrgb(b2);
  return { r: Math.round(r * 255), g: Math.round(g * 255), b: Math.round(b2 * 255) };
}

export function rgbToOklch(r, g, b) {
  r /= 255; g /= 255; b /= 255;
  r = srgbToLinear(r);
  g = srgbToLinear(g);
  b = srgbToLinear(b);
  const l = 0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b;
  const m = 0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b;
  const s = 0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b;
  const l_ = Math.cbrt(l);
  const m_ = Math.cbrt(m);
  const s_ = Math.cbrt(s);
  const L = 0.2104542553 * l_ + 0.7936177850 * m_ - 0.0040720468 * s_;
  const a = 1.9779984951 * l_ - 2.4285922050 * m_ + 0.4505937099 * s_;
  const b2 = 0.0259040371 * l_ + 0.7827717662 * m_ - 0.8086757660 * s_;
  const C = Math.sqrt(a * a + b2 * b2);
  let h = Math.atan2(b2, a) * 180 / Math.PI;
  if (h < 0) h += 360;
  return { l: L * 100, c: C, h };
}

export function oklchToHsl(l, c, h) {
  const rgb = oklchToRgb(l, c, h);
  return rgbToHsl(rgb.r, rgb.g, rgb.b);
}

function rgbToHex(r, g, b) {
  return '#' + [r, g, b].map(x => x.toString(16).padStart(2, '0')).join('');
}

function hexToRgb(hex) {
  const m = /^#?([\da-f]{2})([\da-f]{2})([\da-f]{2})$/i.exec(hex);
  return m ? { r: parseInt(m[1], 16), g: parseInt(m[2], 16), b: parseInt(m[3], 16) } : { r: 0, g: 0, b: 0 };
}

function srgbToLinear(c) {
  return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
}

function linearToSrgb(c) {
  return c <= 0.0031308 ? 12.92 * c : 1.055 * c ** (1 / 2.4) - 0.055;
}

function rgbToHsl(r, g, b) {
  r /= 255; g /= 255; b /= 255;
  const max = Math.max(r, g, b), min = Math.min(r, g, b);
  let h, s, l = (max + min) / 2;
  if (max === min) {
    h = s = 0;
  } else {
    const d = max - min;
    s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
    switch (max) {
      case r: h = (g - b) / d + (g < b ? 6 : 0); break;
      case g: h = (b - r) / d + 2; break;
      case b: h = (r - g) / d + 4; break;
    }
    h /= 6;
  }
  return { h: h * 360, s: s * 100, l: l * 100 };
}

export function relativeLuminance({ r, g, b }) {
  const rs = srgbToLinear(r / 255);
  const gs = srgbToLinear(g / 255);
  const bs = srgbToLinear(b / 255);
  return 0.2126 * rs + 0.7152 * gs + 0.0722 * bs;
}

export function contrastRatio(rgb1, rgb2) {
  const L1 = relativeLuminance(rgb1) + 0.05;
  const L2 = relativeLuminance(rgb2) + 0.05;
  return L1 > L2 ? L1 / L2 : L2 / L1;
}
