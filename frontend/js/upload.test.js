const assert = require('assert');
const {
    MAX_UPLOAD_FILES,
    balanceStatusText,
    formatFileSize,
    isSupportedStatementFile,
    normaliseUploadPayload
} = require('./upload.js');

assert.strictEqual(MAX_UPLOAD_FILES, 20);
assert.strictEqual(isSupportedStatementFile({ name: 'statement.OFX' }), true);
assert.strictEqual(isSupportedStatementFile({ name: 'credit-card.qfx' }), true);
assert.strictEqual(isSupportedStatementFile({ name: 'transactions.csv' }), false);
assert.strictEqual(formatFileSize(2048), '2.0 KB');
assert.strictEqual(balanceStatusText({ balance_status: 'updated' }), 'balance refreshed');
assert.strictEqual(balanceStatusText({ balance_status: 'recovered' }), 'balance repaired');
assert.strictEqual(balanceStatusText({ balance_status: 'protected' }), 'empty zero balance ignored');
assert.strictEqual(balanceStatusText({}), '');

const success = normaliseUploadPayload(JSON.stringify({
    status: 'success',
    message: 'Import complete',
    totals: { inserted: 3 },
    files: [{ file: 'statement.ofx', status: 'success' }]
}), 200);
assert.strictEqual(success.status, 'success');
assert.strictEqual(success.totals.inserted, 3);
assert.strictEqual(success.files.length, 1);

const serverError = normaliseUploadPayload(JSON.stringify({ error: 'Authentication required' }), 401);
assert.strictEqual(serverError.status, 'error');
assert.strictEqual(serverError.message, 'Authentication required');

const unreadable = normaliseUploadPayload('plain text response', 200);
assert.strictEqual(unreadable.status, 'error');

const incomplete = normaliseUploadPayload('{}', 200);
assert.strictEqual(incomplete.status, 'error');

console.log('upload.js tests passed');
