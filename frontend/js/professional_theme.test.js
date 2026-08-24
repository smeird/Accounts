const assert = require('assert');
const fs = require('fs');
const path = require('path');

const frontendDirectory = path.resolve(__dirname, '..');
const professionalTheme = fs.readFileSync(path.join(frontendDirectory, 'css', 'theme-professional.css'), 'utf8');
const menu = fs.readFileSync(path.join(__dirname, 'menu.js'), 'utf8');

assert.match(professionalTheme, /\.theme-professional :is\([\s\S]*\.cards,[\s\S]*\[class\$="-card"\][\s\S]*border:\s*0 !important/, 'Professional cards should use borderless paper surfaces');
assert.match(professionalTheme, /box-shadow:\s*none !important/, 'Professional cards should not retain elevation');
assert.match(professionalTheme, /@media \(min-width: 768px\)[\s\S]*\.theme-professional \.tabulator \.tabulator-row,[\s\S]*min-height:\s*2\.35rem !important/, 'desktop Professional tables should use compact rows');
assert.match(professionalTheme, /\.theme-professional table td[\s\S]*padding-top:\s*\.4rem !important/, 'native table cells should use compact vertical padding');
assert.doesNotMatch(professionalTheme, /@media \(max-width:[^)]+\)[\s\S]*min-height:\s*2\.35rem/, 'compact rows must not replace mobile touch spacing');
assert.match(menu, /theme-professional\.css\?v=20260824-paper-density/, 'the Professional stylesheet cache key should change with the theme update');

console.log('professional theme CSS tests passed');
