const assert = require('assert');
const { normalizeDiscoveryPayload, taxonomyCanMarkReady, taxonomyCanFinishEarly, taxonomyProposalValid } = require('./tag_taxonomy_discovery.js');

const view = normalizeDiscoveryPayload({ discovery: {
    schema_ready: true,
    runs: [{ id: 4 }],
    selected_run: { id: 4, status: 'staging' },
    categories: [],
    metrics: { pending_patterns: 0, pending_proposals: 0, approved_proposals: 2 },
    proposals: []
} });
assert.strictEqual(view.schemaReady, true);
assert.strictEqual(view.selectedRun.id, 4);
assert.strictEqual(taxonomyCanMarkReady(view), true);
assert.strictEqual(taxonomyCanMarkReady({ selectedRun: { status: 'staging' }, metrics: { pending_patterns: 1, pending_proposals: 0, approved_proposals: 2 } }), false);
assert.strictEqual(taxonomyCanFinishEarly({ selectedRun: { status: 'staging' }, metrics: { coverage_percent: 95, pending_patterns: 4, pending_proposals: 0, approved_proposals: 2 } }), true);
assert.strictEqual(taxonomyCanFinishEarly({ selectedRun: { status: 'staging' }, metrics: { coverage_percent: 94.9, pending_patterns: 4, pending_proposals: 0, approved_proposals: 2 } }), false);
assert.strictEqual(taxonomyCanFinishEarly({ selectedRun: { status: 'staging' }, metrics: { coverage_percent: 99, pending_patterns: 4, pending_proposals: 1, approved_proposals: 2 } }), false);
assert.strictEqual(taxonomyCanFinishEarly({ selectedRun: { status: 'ready' }, metrics: { coverage_percent: 95, pending_patterns: 4, pending_proposals: 0, approved_proposals: 2 } }), false);
assert.strictEqual(taxonomyProposalValid('Groceries'), true);
assert.strictEqual(taxonomyProposalValid('IGNORE'), false);
assert.strictEqual(taxonomyProposalValid(''), false);
console.log('tag_taxonomy_discovery.js tests passed');
