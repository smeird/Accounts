const assert = require('assert');
const { normalizeCutoverPayload, taxonomyCutoverCanApply, taxonomyCutoverCanCleanLegacy } = require('./tag_taxonomy_cutover.js');

const normalized = normalizeCutoverPayload({ cutover: { schema_ready: true, runs: [{ id: 1 }], selected_run: { can_apply: true, run: { status: 'ready' } } } });
assert.strictEqual(normalized.schemaReady, true);
assert.strictEqual(normalized.runs.length, 1);
assert.strictEqual(taxonomyCutoverCanApply(normalized.selectedRun), true);
assert.strictEqual(taxonomyCutoverCanApply({ can_apply: true, run: { status: 'applied' } }), false);
assert.strictEqual(taxonomyCutoverCanCleanLegacy({ run: { status: 'applied' }, legacy_cleanup: { can_cleanup: true } }), true);
assert.strictEqual(taxonomyCutoverCanCleanLegacy({ run: { status: 'ready' }, legacy_cleanup: { can_cleanup: true } }), false);
assert.strictEqual(taxonomyCutoverCanCleanLegacy({ run: { status: 'applied' }, legacy_cleanup: { completed: true, can_cleanup: false } }), false);
assert.strictEqual(normalizeCutoverPayload({}), null);

console.log('tag_taxonomy_cutover.js tests passed');
