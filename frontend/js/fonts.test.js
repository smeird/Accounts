const assert = require('assert');

const elements = new Map();
const appended = [];
const styleValues = new Map();
const root = {
    dataset: {},
    style: {
        setProperty(name, value) { styleValues.set(name, value); },
        removeProperty(name) { styleValues.delete(name); }
    }
};

global.window = {};
global.Event = class Event { constructor(type) { this.type = type; } };
global.document = {
    documentElement: root,
    head: {
        appendChild(element) {
            appended.push(element);
            if (element.id) elements.set(element.id, element);
        }
    },
    createElement(tagName) { return { tagName, id: '', rel: '', href: '', textContent: '' }; },
    getElementById(id) { return elements.get(id) || null; },
    dispatchEvent() {}
};

require('./fonts.js');

window.applyFonts({
    heading_font: 'Caveat',
    body_font: 'Fredoka',
    table_font: 'Roboto Mono',
    chart_font: 'Pacifico',
    accent_font_weight: '300'
});

assert.strictEqual(root.dataset.headingFont, 'Caveat');
assert.strictEqual(root.dataset.bodyFont, 'Fredoka');
assert.strictEqual(root.dataset.tableFont, 'Roboto Mono');
assert.strictEqual(root.dataset.accentFontWeight, '300');
assert.strictEqual(styleValues.get('--heading-font'), 'Caveat');
assert.strictEqual(styleValues.get('--chart-font'), 'Pacifico');
assert.match(elements.get('font-overrides').textContent, /:root\[data-body-font\] body/);
assert.match(elements.get('font-overrides').textContent, /:root\[data-table-font\] \.tabulator/);

window.applyFonts({});

assert.strictEqual(root.dataset.headingFont, undefined);
assert.strictEqual(root.dataset.bodyFont, undefined);
assert.strictEqual(root.dataset.tableFont, undefined);
assert.strictEqual(root.dataset.accentFontWeight, undefined);
assert.strictEqual(styleValues.has('--heading-font'), false);
assert.strictEqual(styleValues.has('--body-font'), false);
assert.strictEqual(styleValues.has('--table-font'), false);
assert.strictEqual(styleValues.has('--chart-font'), false);

console.log('fonts.js tests passed');
