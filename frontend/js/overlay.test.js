const assert = require('assert');
const { normaliseTone, inferTone, durationForTone } = require('./overlay.js');

assert.strictEqual(normaliseTone('success'), 'success');
assert.strictEqual(normaliseTone('danger'), 'error');
assert.strictEqual(normaliseTone('warn'), 'warning');
assert.strictEqual(normaliseTone('progress'), 'loading');
assert.strictEqual(normaliseTone('unknown'), 'success');
assert.strictEqual(inferTone('AI tagging started', undefined, false), 'info');
assert.strictEqual(inferTone('Transaction updated', undefined, false), 'success');
assert.strictEqual(inferTone('Process started', 'error', true), 'error');
assert.strictEqual(durationForTone('error'), 7000);
assert.strictEqual(durationForTone('loading'), 0);

console.log('overlay.js tests passed');
