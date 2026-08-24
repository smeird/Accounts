const assert = require('assert');
const { normalizeDiscoveryPayload, taxonomyCanMarkReady, taxonomyProposalValid } = require('./tag_taxonomy_discovery.js');

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
assert.strictEqual(taxonomyProposalValid('Groceries'), true);
assert.strictEqual(taxonomyProposalValid('IGNORE'), false);
assert.strictEqual(taxonomyProposalValid(''), false);
console.log('tag_taxonomy_discovery.js tests passed');
