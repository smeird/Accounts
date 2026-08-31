'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const drilldown = require('./transaction_drilldown.js');

function paramsFor(options) {
    return new URL(drilldown.url(options), 'https://accounts.test/').searchParams;
}

assert.deepStrictEqual(
    drilldown.monthRange(2026, 2),
    { start: '2026-02-01', end: '2026-02-28' },
    'month ranges use inclusive calendar boundaries'
);
assert.deepStrictEqual(
    drilldown.yearRange(2024),
    { start: '2024-01-01', end: '2024-12-31' },
    'year ranges use inclusive calendar boundaries'
);

const exact = paramsFor(drilldown.financial({
    start: '2026-08-01',
    end: '2026-08-31',
    direction: 'spending',
    dimension: 'category',
    dimension_ids: [12, 19],
    transaction_ids: [41, 42],
    label: 'August household spending'
}));
assert.strictEqual(exact.get('start'), '2026-08-01');
assert.strictEqual(exact.get('end'), '2026-08-31');
assert.strictEqual(exact.get('direction'), 'spending');
assert.strictEqual(exact.get('transfer_scope'), 'exclude');
assert.strictEqual(exact.get('ignored_scope'), 'exclude');
assert.strictEqual(exact.get('dimension_ids'), '12,19');
assert.strictEqual(exact.get('transaction_ids'), '41,42');
assert.strictEqual(exact.get('label'), 'August household spending');

const comparison = paramsFor({
    start: '2026-01-01', end: '2026-03-31',
    compare_start: '2025-01-01', compare_end: '2025-03-31',
    direction: 'all'
});
assert.strictEqual(comparison.get('compare_start'), '2025-01-01');
assert.strictEqual(comparison.get('compare_end'), '2025-03-31');

const legacy = paramsFor({ value: 'Coffee shop', min_amount: '-20', max_amount: '-1' });
assert.strictEqual(legacy.get('value'), 'Coffee shop');
assert.strictEqual(legacy.get('min_amount'), '-20');
assert.strictEqual(legacy.get('max_amount'), '-1');

const createdLinks = [];
const fakeDocument = { createElement(tag) { const node={tagName:tag.toUpperCase(),attributes:{},setAttribute(name,value){this.attributes[name]=value;}}; createdLinks.push(node); return node; } };
const fakeTarget = {
    ownerDocument:fakeDocument,
    textContent:'£125.00',
    classList:{ values:[], add(value){this.values.push(value);} },
    replaceChildren(child){this.child=child;}
};
const link = drilldown.linkify(fakeTarget, { start:'2026-08-01', end:'2026-08-31', direction:'spending', label:'August spend' });
assert.strictEqual(link.tagName, 'A', 'drillable values are genuine links');
assert.match(link.href, /^search\.html\?/);
assert.match(link.attributes['aria-label'], /August spend/, 'drillable values have descriptive accessible names');
assert.strictEqual(fakeTarget.child, link, 'the visible value is replaced by its evidence link');

const sourceRoot = __dirname;
[
    'instant_dashboard.js', 'monthly_statement.js', 'financial_trends.js',
    'daily_burn.js', 'yearly_dashboard.js', 'graphs_dashboard.js',
    'budgets.js', 'recurring_spend.js', 'forecast_dashboard.js'
].forEach(file => {
    const source = fs.readFileSync(path.join(sourceRoot, file), 'utf8');
    assert.match(source, /TransactionDrilldown/, `${file} uses the shared drill-down convention`);
});

const projects = ['projects_common.js','projects_board.js','projects_archived.js','projects_overview.js']
    .map(file => fs.readFileSync(path.join(sourceRoot,file),'utf8')).join('\n');
assert.match(projects, /dimension:'group'/, 'project spending links use exact group evidence');
assert.match(projects, /direction:'spending'/, 'project spending links exclude incoming activity');
assert.match(projects, /transfer_scope:'exclude'/, 'project spending links exclude transfers');

const recurring = fs.readFileSync(path.join(sourceRoot,'recurring_spend.js'),'utf8');
assert.match(recurring, /transaction_ids:row\.transaction_ids/, 'recurring history uses exact detector evidence');

const graphs = fs.readFileSync(path.join(sourceRoot,'graphs_dashboard.js'),'utf8');
assert.match(graphs, /dimension_ids:memberIds\.length\?memberIds/, 'Other aggregates carry their complete member IDs');
assert.match(graphs, /include_unclassified:!!\(row\.is_other&&row\.includes_unclassified&&memberIds\.length\)/, 'Other aggregates retain unclassified evidence');

const trends = fs.readFileSync(path.join(sourceRoot,'financial_trends.js'),'utf8');
assert.match(trends, /compare_start/, 'comparison links carry a distinct comparison period');
assert.match(trends, /compare_end/, 'comparison links carry both comparison boundaries');

const forecast = fs.readFileSync(path.join(sourceRoot,'forecast_dashboard.js'),'utf8');
assert.doesNotMatch(forecast, /search\.html\?value=/, 'projected forecast categories have no misleading text-search link');

console.log('PASS: transaction drill-down URL generation and page integration');
