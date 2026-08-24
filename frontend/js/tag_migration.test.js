const assert = require('assert');
const { normaliseMigrationPayload, restoreConfirmationValid } = require('./tag_migration.js');

const view = normaliseMigrationPayload({
    schema_ready: true,
    schema_message: 'Ready',
    contract: { version: 'v1' },
    current: { transaction_count: 12 },
    runs: [{ id: 1 }]
});

assert.strictEqual(view.schemaReady, true);
assert.strictEqual(view.current.transaction_count, 12);
assert.strictEqual(view.runs.length, 1);
assert.strictEqual(normaliseMigrationPayload([]), null);
assert.strictEqual(restoreConfirmationValid(' RESTORE '), true);
assert.strictEqual(restoreConfirmationValid('restore now'), false);

console.log('tag_migration.js tests passed');
