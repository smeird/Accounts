const assert = require('assert');
const fs = require('fs');
const path = require('path');

const frontendDirectory = path.resolve(__dirname, '..');
const page = fs.readFileSync(path.join(frontendDirectory, 'transaction.html'), 'utf8');
const script = fs.readFileSync(path.join(__dirname, 'transaction_detail.js'), 'utf8');
const styles = fs.readFileSync(path.join(frontendDirectory, 'transaction_detail.css'), 'utf8');

assert.match(page, /id="tag-search"[\s\S]*role="combobox"/, 'transaction tags should use an accessible searchable picker');
assert.match(page, /id="tag-results"[\s\S]*role="listbox"/, 'tag matches should be exposed as a listbox');
assert.match(script, /URLSearchParams\(\{options:'1',q:query,limit:'20'\}\)/, 'tag searches should use the bounded options endpoint');
assert.doesNotMatch(script, /requestJson\('\.\.\/php_backend\/public\/tags\.php'\)/, 'transaction loading must not download the full tag catalogue');
assert.match(script, /searchExistingTags\(selectedTag\?selectedTag\.name:search\.value\.trim\(\)\)/, 'focusing the picker should reveal a short relevant list');
assert.match(styles, /max-height:14rem;[\s\S]*overflow-y:auto/, 'tag results should remain visually bounded');
assert.match(page, /transaction_detail\.js\?v=20260824-tag-picker/, 'the transaction script cache key should change with the picker update');

console.log('transaction tag picker tests passed');
