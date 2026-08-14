const assert = require('assert');
const { normaliseHealthPayload, repairableIssueIds } = require('./database_health.js');

const healthy = normaliseHealthPayload({
    status: 'healthy',
    healthy: true,
    checked_at: '2026-08-14T00:00:00Z',
    database: { name: 'accounts', driver: 'mysql' },
    summary: { issues: 0 },
    issues: []
});
assert.strictEqual(healthy.status, 'healthy');
assert.strictEqual(healthy.healthy, true);

const issues = normaliseHealthPayload({
    status: 'issues',
    healthy: false,
    summary: { issues: 2, repairable: 1 },
    issues: [
        { id: 'index:transactions.lookup', repairable: true },
        { id: 'column_definition:projects.name', repairable: false }
    ]
});
assert.strictEqual(issues.status, 'issues');
assert.deepStrictEqual(repairableIssueIds(issues), ['index:transactions.lookup']);
assert.strictEqual(normaliseHealthPayload(null), null);
assert.deepStrictEqual(repairableIssueIds(null), []);

console.log('database_health.js tests passed');
