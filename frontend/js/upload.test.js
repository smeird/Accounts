const assert = require('assert');
const { isMacOS } = require('./upload.js');

assert.strictEqual(isMacOS('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)'), true);
assert.strictEqual(isMacOS('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'), false);

console.log('upload.js tests passed');
