'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const drilldown = require('./transaction_drilldown.js');

function element() {
    return {
        value: '12', options: [{value:'12'}], hidden:true, textContent:'', children:[],
        style:{setProperty() {}}, classList:{add() {}, remove() {}},
        addEventListener() {}, setAttribute() {},
        append(...nodes) { this.children.push(...nodes); },
        appendChild(node) { this.children.push(node); },
        replaceChildren(...nodes) { this.children = nodes; }
    };
}

async function render(spending) {
    const elements = new Map();
    const byId = id => {
        if (!elements.has(id)) elements.set(id, element());
        return elements.get(id);
    };
    const charts = [];
    const months = [{key:'2026-09',label:'Sep 2026',spending,segments:[{id:1,name:'Household',daily_burn:spending/30}]}];
    const snapshot = {
        period:{start:'2026-09-01',end:'2026-09-30',months:1}, method:'Calendar-normalised expenditure.',
        metrics:{latest_daily_burn:spending/30,average_daily_burn:spending/30,monthly_equivalent:spending,total_spending:spending,transaction_count:spending?1:0,peak_day:{amount:spending,date:spending?'2026-09-12':null}},
        months,
        segments:[{id:1,name:'Household',average_daily_burn:spending/30,latest_daily_burn:spending/30,share:100},{id:2,name:'No spending',average_daily_burn:0,latest_daily_burn:0,share:0}],
        daily:[{date:'2026-09-12',actual_spending:spending,rolling_average:spending/14}]
    };
    const window = {location:{href:'https://accounts.test/frontend/daily_burn.html'},history:{replaceState() {}}};
    vm.runInNewContext(fs.readFileSync(path.join(__dirname,'daily_burn.js'),'utf8'), {
        document:{getElementById:byId,createElement:element},window,
        URL,URLSearchParams,AbortController,Intl,Date,
        TransactionDrilldown:{...drilldown,linkify() {}},
        Highcharts:{chart:(id,options)=>charts.push({id,options})},
        fetch:async url=>({ok:true,json:async()=>url.includes('transaction_months.php')?[{year:2026,month:9}]:snapshot})
    });
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(byId('burn-error').hidden,true,byId('burn-error').textContent);
    return {charts,elements,window};
}

(async()=>{
    const populated = await render(300);
    const chart = populated.charts.find(chart=>chart.id==='burn-segment-chart');
    assert.ok(chart,'spending history renders the segment chart');
    assert.equal(chart.options.series.length,1,'retain positive object-valued series and omit empty segments');
    assert.equal(chart.options.series[0].name,'Household');
    const point=chart.options.series[0].data[0];
    assert.equal(point.y,10,'chart keeps calendar-normalised daily values');
    const evidence=new URL(point.drilldown,'https://accounts.test/frontend/').searchParams;
    assert.equal(evidence.get('start'),'2026-09-01');
    assert.equal(evidence.get('end'),'2026-09-30');
    assert.equal(evidence.get('dimension'),'segment');
    assert.equal(evidence.get('dimension_id'),'1');
    chart.options.plotOptions.series.point.events.click.call({options:point});
    assert.equal(populated.window.location.href,point.drilldown,'clicking a point retains transaction evidence navigation');
    assert.ok(populated.charts.some(chart=>chart.id==='burn-actual-chart'),'actual-day spending chart still renders');

    const empty=await render(0);
    assert.equal(empty.charts.length,0,'empty history does not render misleading chart series');
    assert.match(empty.elements.get('burn-segment-chart').children[0].textContent,/No expenditure/);
    assert.match(empty.elements.get('burn-actual-chart').children[0].textContent,/No transaction-day expenditure/);
    console.log('PASS: Daily Burn populated/empty charts and segment transaction evidence');
})().catch(error=>{console.error(error);process.exitCode=1;});
