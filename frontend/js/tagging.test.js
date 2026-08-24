const assert = require('assert');
const fs = require('fs');
const path = require('path');

const script = fs.readFileSync(path.join(__dirname, 'tagging.js'), 'utf8');
const page = fs.readFileSync(path.join(__dirname, '..', 'tagging.html'), 'utf8');
const styles = fs.readFileSync(path.join(__dirname, '..', 'tagging.css'), 'utf8');
const menu = fs.readFileSync(path.join(__dirname, '..', 'menu.php'), 'utf8');
const aiEndpoint = fs.readFileSync(path.join(__dirname, '..', '..', 'php_backend', 'public', 'ai_tags.php'), 'utf8');

assert.match(page, /data-tagging-panel="inbox"/, 'unified tagging page includes an inbox');
assert.match(page, /data-tagging-panel="catalogue"/, 'unified tagging page includes the canonical catalogue');
assert.match(page, /data-tagging-panel="rules"/, 'unified tagging page includes deterministic rules');
assert.match(page, /data-tagging-panel="history"/, 'unified tagging page preserves rebuild history');
assert.match(styles, /#inbox-dialog\s*\{\s*width:min\(680px,calc\(100% - 2rem\)\);\s*\}/, 'resolve pattern dialog gets additional desktop width while retaining the mobile gutter');
assert.match(script, /action:\s*'merge_tag'/, 'catalogue UI uses guarded canonical merge');
assert.match(script, /action:\s*'retire_tag'/, 'catalogue UI uses non-destructive retirement');
assert.match(script, /requires_confirmation/, 'rule editor surfaces overlap confirmation');
assert.match(script, /review_required/, 'AI results surface unfamiliar suggestions for review');
assert.doesNotMatch(aiEndpoint, /Tag::create\(\$tagName/, 'AI tagging cannot silently create canonical tags');
assert.match(menu, /href="tagging\.html"/, 'sidebar exposes one permanent Tagging destination');
assert.doesNotMatch(menu, /href="tag_(?:migration|taxonomy_discovery|taxonomy_cutover)\.html"/, 'completed rebuild phases are absent from everyday navigation');

console.log('tagging.js tests passed');
