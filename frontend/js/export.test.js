const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { defaultPeriod, validatePeriod, queryString, filenameFromResponse } = require('./export.js');

assert.deepStrictEqual(defaultPeriod(new Date(2026, 7, 24)), { start: '2026-08-01', end: '2026-08-24' }, 'export defaults to the current month using local calendar dates');
assert.deepStrictEqual(validatePeriod('2026-01-01', '2026-12-31'), { start: '2026-01-01', end: '2026-12-31' }, 'valid export period is retained');
assert.throws(() => validatePeriod('2026-12-31', '2026-01-01'), /start date/, 'reversed export period is rejected');
assert.strictEqual(queryString({ start: '2026-01-01', end: '2026-01-31' }), 'start=2026-01-01&end=2026-01-31', 'date range is encoded for export endpoints');
assert.strictEqual(filenameFromResponse({ headers: { get: () => 'attachment; filename="money.xlsx"' } }, 'fallback.xlsx'), 'money.xlsx', 'server workbook filename is honoured');

const page = fs.readFileSync(path.join(__dirname, '..', 'export.html'), 'utf8');
assert.match(page, /id="standard-export-form"/, 'portable exports retain their own form');
assert.match(page, /id="excel-export-form"/, 'Excel workbook has a separate export area');
assert.match(page, /Summary[\s\S]*Pivot Analysis[\s\S]*Transactions/, 'Excel area explains the three workbook sheets');
assert.doesNotMatch(page, /xlsx\.full\.min\.js/, 'the raw browser-side XLSX dump library is no longer loaded');

console.log('export.js tests passed');
