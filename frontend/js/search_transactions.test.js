const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

// Execute the actual search lifecycle with isolated API/DOM collaborators.
// Existing table classes stand for the live instance that setData reuses.
const source=fs.readFileSync(__dirname+'/search_transactions.js','utf8');
const lifecycle=source.slice(source.indexOf('    async function runSearch() {'),source.indexOf("    byId('search-form').addEventListener"));
const classes=new Set(['transaction-table','tabulator','modern-table','modern-table--cards','ops-standard-table']);
const elements=new Map();
function byId(id){
    if(!elements.has(id))elements.set(id,{value:'',hidden:true,textContent:'',disabled:false,classList:{add:name=>classes.add(name),remove:name=>classes.delete(name)},querySelector:()=>({textContent:''}),replaceChildren(){}});
    return elements.get(id);
}
// Model a real className assignment too, so the original bug fails this test.
Object.defineProperty(byId('results-grid'),'className',{get:()=>[...classes].join(' '),set:value=>{classes.clear();value.split(/\s+/).forEach(name=>classes.add(name));}});
let resolveResponse, rejectResponse, updates=0;
const context={URLSearchParams,Number,Object,Array,byId,filterFields:{},structuredKeys:['all'],location:{search:'?all=1',pathname:'/frontend/search.html'},history:{state:null,replaceState(){}},searchSequence:0,latestRows:[],resultTableHasComparison:false,
    resultTable:{destroy(){classes.delete('tabulator');}},
    fetch:()=>new Promise((resolve,reject)=>{resolveResponse=resolve;rejectResponse=reject;}),
    renderFilterContext(){},setText(){},renderEvidencePages(){},renderComparison:rows=>rows,
    renderSummary(){},renderTable(){updates++;},renderChart(){},queryLabel:()=>'',updateTableTotal(){},resetSummary(){},emptyState(){return {};}
};
vm.createContext(context);vm.runInContext(lifecycle+'\nthis.search=runSearch;',context);
function assertStructure(stage){
    for(const name of ['transaction-table','tabulator','modern-table','modern-table--cards','ops-standard-table'])assert.ok(classes.has(name),`${stage}: retain ${name}`);
}
async function complete(rows){resolveResponse({ok:true,json:async()=>({results:rows})});}
(async()=>{
    for(let attempt=0;attempt<3;attempt++){
        const pending=context.search();assertStructure('While searching');assert.ok(classes.has('transaction-loading'));
        await complete([{id:attempt+1,amount:-5,tag_id:1,category_id:1}]);await pending;
        assertStructure('After repeated search');assert.equal(classes.has('transaction-loading'),false);assert.equal(byId('search-submit').disabled,false);
    }
    assert.equal(updates,3,'Repeated searches update the existing table');
    // An older response must not clear a newer request's loading state.
    const earlier=context.search(),resolveEarlier=resolveResponse;
    const later=context.search();
    resolveEarlier({ok:true,json:async()=>({results:[]})});await earlier;
    assertStructure('After stale response');assert.ok(classes.has('transaction-loading'));
    await complete([{id:9,tag_id:1,category_id:1}]);await later;assertStructure('After latest response');
    const failed=context.search();rejectResponse(new Error('Connection failed'));await failed;
    assert.equal(classes.has('transaction-loading'),false,'Failure clears loading state');
    assert.equal(byId('search-error').hidden,false,'Failure remains visible');
    assert.equal(context.resultTable,null,'Failure destroys the old table before showing the error');
    console.log('PASS: Search preserves live table layout during repeated requests, stale responses and failure recovery');
})().catch(error=>{console.error(error);process.exitCode=1;});
