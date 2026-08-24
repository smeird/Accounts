const assert = require('assert');
const fs = require('fs');
const path = require('path');

const frontendDirectory = path.resolve(__dirname, '..');
const typography = fs.readFileSync(path.join(frontendDirectory, 'typography.css'), 'utf8');
const menu = fs.readFileSync(path.join(__dirname, 'menu.js'), 'utf8');

assert.match(typography, /--type-table-secondary:\s*\.6875rem/, 'secondary table metadata should use the shared 11px scale');
assert.match(typography, /--type-table-secondary-weight:\s*400/, 'secondary table metadata should remain regular weight');
assert.match(typography, /\.statement-date small,[\s\S]*\.statement-memo,[\s\S]*\.account-type,/, 'native reference tables should share the metadata treatment');
assert.match(typography, /\.modern-table\.tabulator \.tabulator-cell small/, 'Tabulator cell metadata should share the metadata treatment');
assert.doesNotMatch(typography, /\.statement-description,[\s\S]{0,180}font-weight:\s*var\(--type-table-secondary-weight\)/, 'primary statement descriptions must stay prominent');
assert.match(menu, /typography\.css\?v=20260824-table-hierarchy/, 'the shared stylesheet cache key should change with the typography update');

console.log('typography CSS tests passed');
