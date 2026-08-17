const assert = require('assert');
const { normaliseRecurringPayload, buildRecurringSummary, ordinal, formatSchedule, formatCurrency } = require('./recurring_spend.js');

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
assert.strictEqual(ordinal(1), '1st');
assert.strictEqual(ordinal(12), '12th');
assert.strictEqual(ordinal(23), '23rd');
assert.strictEqual(formatSchedule(21), 'Around the 21st');
assert.strictEqual(formatCurrency(12.5), '£12.50');

console.log('recurring_spend.js tests passed');
