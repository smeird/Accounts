const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');

const context = {
    window: {},
    document: {
        getElementById: () => null,
        createElement: () => ({className:'',append(){},appendChild(){},setAttribute(){}})
    },
    Intl,
    Number,
    Math,
    fetch: async () => ({ok:true,json:async()=>[]})
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname, 'projects_common.js'), 'utf8'), context);

const UI = context.window.ProjectUI;
const critical = {id:2,benefit_risk:5,weight_risk:5,benefit_sustainability:5,benefit_financial:2,benefit_quality:0};
const cosmetic = {id:1,benefit_risk:0,weight_risk:1,benefit_sustainability:0,benefit_financial:0,benefit_quality:5};
const preservation = {id:3,benefit_risk:1,weight_risk:1,benefit_sustainability:5,benefit_financial:0,benefit_quality:0};

assert.strictEqual(UI.priorityScore(critical), 84, 'priority score uses the shared fixed weights');
assert.strictEqual(UI.priorityTier(critical).key, 'critical', 'critical override is consistent in the frontend');
assert.strictEqual(UI.priorityTier(cosmetic).key, 'nice', 'cosmetic benefit alone stays nice to have');
assert.strictEqual(UI.priorityTier(preservation).key, 'preventive', 'asset preservation receives a preventive guardrail');
assert.deepStrictEqual([cosmetic, critical].sort(UI.comparePriority).map(item => item.id), [2,1], 'priority sorting places critical work first');
console.log('projects_common.js tests passed');
