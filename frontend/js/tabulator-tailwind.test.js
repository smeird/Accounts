const assert = require('assert');
const fs = require('fs');
const path = require('path');

const frontendDirectory = path.resolve(__dirname, '..');
const adapterPath = path.join(__dirname, 'tabulator-tailwind.js');
const adapter = fs.readFileSync(adapterPath, 'utf8');

assert.match(adapter, /getDataCount\('active'\)/, 'row counts should use Tabulator metadata');
assert.doesNotMatch(adapter, /getRows\('active'\)\.forEach\(decorateModernRow\)/, 'processed data must not trigger a second full row pass');
assert.match(adapter, /if \(modernFreezeFirst/, 'frozen columns should be opt-in');
assert.doesNotMatch(adapter, /redraw\(true\)/, 'viewport changes must not force a full table redraw');
assert.match(adapter, /options\.paginationMode = options\.paginationMode \|\| 'local'/, 'pagination should use the Tabulator 6 option shape');
assert.doesNotMatch(adapter, /modern-table-pill-label/, 'classification pills should not repeat their type');
assert.match(adapter, /createClassificationKey\(classificationKinds\)/, 'classification tables should include a compact colour key');

const monthlyStatement = fs.readFileSync(path.join(frontendDirectory, 'monthly_statement.html'), 'utf8');
assert.match(monthlyStatement, /statement-classification-key/, 'monthly statement should include the classification colour key');

const tablePages = fs.readdirSync(frontendDirectory)
    .filter(file => file.endsWith('.html'))
    .map(file => ({ file, contents:fs.readFileSync(path.join(frontendDirectory, file), 'utf8') }))
    .filter(page => page.contents.includes('tabulator-tailwind.js'));

assert.ok(tablePages.length > 0, 'at least one table page should be covered');
tablePages.forEach(page => {
    assert.ok(page.contents.includes('vendor/tabulator/6.5.0/tabulator.min.js'), `${page.file} should use the pinned local script`);
    assert.ok(page.contents.includes('vendor/tabulator/6.5.0/tabulator_simple.min.css'), `${page.file} should use the pinned local stylesheet`);
    assert.ok(!page.contents.includes('unpkg.com/tabulator-tables'), `${page.file} should not depend on the Tabulator CDN`);
});

['tabulator.min.js', 'tabulator_simple.min.css', 'LICENSE'].forEach(file => {
    assert.ok(fs.existsSync(path.join(frontendDirectory, 'vendor', 'tabulator', '6.5.0', file)), `${file} should be vendored`);
});

console.log('tabulator-tailwind tests passed');
