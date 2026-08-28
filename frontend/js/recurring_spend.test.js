const assert = require('assert');
const fs = require('fs');
const path = require('path');
const {
    normaliseRecurringPayload,
    buildRecurringSummary,
    buildRecurringSelectionSummary,
    recurringTablePaging,
    ordinal,
    formatSchedule,
    formatCurrency
} = require('./recurring_spend.js');

const payload = normaliseRecurringPayload({
    outgoings: { results: [{ description: 'Broadband', day: '21', occurrences: '12', average: '-30', last_amount: '-32', total: '-360' }], total: '360', next_month: '32' },
    income: { results: [{ description: 'Salary', day: 28, occurrences: 12, average: 2000, last_amount: 2050, total: 24000 }], total: 24000, next_month: 2050 }
});

assert.strictEqual(payload.outgoings.results[0].last_amount, 32);
assert.strictEqual(payload.outgoings.results[0].total, 360);
assert.strictEqual(payload.income.results[0].occurrences, 12);
assert.deepStrictEqual(buildRecurringSummary(payload), {
    outgoingNext: 32,
    incomeNext: 2050,
    netNext: 2018,
    patterns: 2,
    outgoingPatterns: 1,
    incomePatterns: 1
});
assert.deepStrictEqual(buildRecurringSelectionSummary([
    { kind: 'outgoings', amount: -32 },
    { kind: 'outgoings', amount: 18 },
    { kind: 'income', amount: '2050' },
    { kind: 'unknown', amount: 999 }
]), {
    count: 3,
    outgoings: 50,
    income: 2050,
    net: 2000
});
assert.deepStrictEqual(buildRecurringSelectionSummary(null), {
    count: 0,
    outgoings: 0,
    income: 0,
    net: 0
});
assert.deepStrictEqual(recurringTablePaging(10), { pagination: false, paginationSize: 10 });
assert.deepStrictEqual(recurringTablePaging(11), { pagination: true, paginationSize: 10 });
assert.deepStrictEqual(recurringTablePaging(73), { pagination: true, paginationSize: 10 });
assert.strictEqual(ordinal(1), '1st');
assert.strictEqual(ordinal(12), '12th');
assert.strictEqual(ordinal(23), '23rd');
assert.strictEqual(formatSchedule(21), 'Around the 21st');
assert.strictEqual(formatCurrency(12.5), '£12.50');

const recurringCss = fs.readFileSync(path.resolve(__dirname, '..', 'recurring_spend.css'), 'utf8');
assert.match(recurringCss, /\.recurring-panel \.modern-table-search/, 'table searches should be scoped through the recurring panel');
assert.match(recurringCss, /\.recurring-table\.modern-table\.tabulator/, 'table states should target the classes Tabulator applies to the same element');
assert.doesNotMatch(recurringCss, /\.recurring-table \.modern-table\.tabulator/, 'recurring table rules must not assume a nested Tabulator element');

console.log('recurring_spend.js tests passed');
