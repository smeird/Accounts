(function () {
    const UI = window.ProjectUI;
    const errorEl = document.getElementById('project-error');
    const axisOptions = {
        cost_low:{label:'Low cost',prefix:'£'}, cost_medium:{label:'Mid cost',prefix:'£'}, cost_high:{label:'High cost',prefix:'£'}, spent:{label:'Spent',prefix:'£'},
        benefit_risk:{label:'Consequence of delay'}, weight_risk:{label:'Urgency'}, benefit_sustainability:{label:'Asset preservation'}, benefit_financial:{label:'Financial impact'}, benefit_quality:{label:'Daily-life impact'}
    };
    let projects = [];
    let visibleProjects = [];
    let table;

    function editButton(project) {
        const button = UI.actionButton('fa-pen', `Edit ${project.name}`);
        button.addEventListener('click', event => { event.stopPropagation(); window.location.href = `project_add.html?id=${encodeURIComponent(project.id)}`; });
        return button;
    }
    function archiveButton(project) {
        const button = UI.actionButton('fa-box-archive', `Archive ${project.name}`, 'archive');
        button.addEventListener('click', async event => { event.stopPropagation(); button.disabled = true; try { await UI.request({method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:project.id,archived:1})}); await load(); UI.announce('Project archived'); } catch (error) { UI.announce(error.message,'error'); } finally { button.disabled = false; } });
        return button;
    }
    function deleteButton(project) {
        const button = UI.actionButton('fa-trash', `Delete ${project.name}`, 'delete');
        button.addEventListener('click', async event => { event.stopPropagation(); if (!confirm(`Permanently delete ${project.name}? This cannot be undone.`)) return; button.disabled = true; try { await UI.request({method:'DELETE',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:project.id})}); await load(); UI.announce('Project deleted'); } catch (error) { UI.announce(error.message,'error'); } finally { button.disabled = false; } });
        return button;
    }
    function actions(project) { const wrap=document.createElement('div'); wrap.className='project-actions'; wrap.append(editButton(project),archiveButton(project),deleteButton(project)); return wrap; }
    function priorityFormatter(cell) { const tier=UI.priorityTier(cell.getRow().getData()); const badge=document.createElement('span'); badge.className=`project-priority-badge project-priority-badge--${tier.key}`; badge.textContent=tier.label; return badge; }

    function drawChart() {
        const xKey=document.getElementById('x-axis').value; const yKey=document.getElementById('y-axis').value; const xOpt=axisOptions[xKey]; const yOpt=axisOptions[yKey];
        UI.text('comparison-count',String(visibleProjects.length));
        Highcharts.chart('project-bubble-chart', {
            chart:{type:'bubble',backgroundColor:'transparent',plotBorderWidth:0,height:380,spacing:[10,6,4,6]}, title:{text:null}, credits:{enabled:false}, legend:{enabled:false},
            xAxis:{title:{text:xOpt.label,style:{color:'#64748b',fontSize:'10px'}},gridLineColor:'rgba(148,163,184,.16)',lineColor:'#e2e8f0',labels:{formatter:function(){return xOpt.prefix?xOpt.prefix+Highcharts.numberFormat(this.value,0):this.value},style:{color:'#64748b',fontSize:'9px'}}},
            yAxis:{title:{text:yOpt.label,style:{color:'#64748b',fontSize:'10px'}},gridLineColor:'rgba(148,163,184,.16)',labels:{formatter:function(){return yOpt.prefix?yOpt.prefix+Highcharts.numberFormat(this.value,0):this.value},style:{color:'#64748b',fontSize:'9px'}}},
            tooltip:{useHTML:false,pointFormatter:function(){return `${xOpt.label}: ${xOpt.prefix||''}${Highcharts.numberFormat(this.x,0)}<br/>${yOpt.label}: ${yOpt.prefix||''}${Highcharts.numberFormat(this.y,0)}<br/>Priority score: ${Highcharts.numberFormat(this.z,0)}/100`}},
            plotOptions:{bubble:{minSize:12,maxSize:48,marker:{lineWidth:1,lineColor:'rgba(255,255,255,.7)'}},series:{animation:{duration:350}}},
            series:visibleProjects.map(project=>{const key=UI.priorityTier(project).key;const colors={critical:'#e11d48',important:'#f97316',preventive:'#eab308',improvement:'#06b6d4',nice:'#94a3b8'};return {name:project.name,color:colors[key],data:[{x:UI.number(project[xKey]),y:UI.number(project[yKey]),z:Math.max(UI.priorityScore(project),1)}]};}), accessibility:{enabled:false}
        });
    }

    function renderTable() {
        if (table) { table.setData(visibleProjects); return; }
        table=tailwindTabulator('#projects-table',{data:visibleProjects,layout:'fitDataStretch',placeholder:'No active projects match this shortlist.',rowClick:(event,row)=>{window.location.href=`project_add.html?id=${encodeURIComponent(row.getData().id)}`;},columns:[
            {title:'Project',field:'name',minWidth:170},{title:'Priority',field:'priority_label',formatter:priorityFormatter,minWidth:155},{title:'Score',field:'score',hozAlign:'right'},{title:'Consequence',field:'benefit_risk',hozAlign:'right'},{title:'Urgency',field:'weight_risk',hozAlign:'right'},{title:'Preservation',field:'benefit_sustainability',hozAlign:'right'},{title:'Mid cost',field:'cost_medium',formatter:'money',formatterParams:{symbol:'£',precision:0},hozAlign:'right'},{title:'Spent',field:'spent',formatter:'money',formatterParams:{symbol:'£',precision:0},hozAlign:'right'},{title:'Actions',formatter:cell=>actions(cell.getRow().getData()),width:125,hozAlign:'center',headerSort:false}
        ]});
    }

    function applyBudget() {
        const budget=Number(document.getElementById('annual-budget').value);
        visibleProjects=Number.isFinite(budget)&&budget>0?projects.filter(project=>UI.number(project.cost_medium)<=budget):[...projects];
        UI.text('budget-filter-note',visibleProjects.length===projects.length?'Showing the full active portfolio.':`${visibleProjects.length} project${visibleProjects.length===1?'':'s'} fit within ${UI.money(budget)} individually.`);
        drawChart(); renderTable();
    }

    async function load() {
        errorEl.hidden=true;
        try { projects=await UI.request(); visibleProjects=[...projects]; UI.renderHero(projects,{valueId:'project-hero-value',messageId:'project-hero-message',signalId:'project-hero-signal',signalLabelId:'project-hero-signal-label',countId:'project-active-count',plannedId:'project-planned-total',spentId:'project-spent-total',priorityId:'project-priority-count'}); applyBudget(); }
        catch(error){errorEl.textContent='The project portfolio could not be loaded. Please try again.';errorEl.hidden=false;UI.announce('Could not load projects','error');}
    }
    document.getElementById('apply-budget').addEventListener('click',applyBudget); document.getElementById('x-axis').addEventListener('change',drawChart); document.getElementById('y-axis').addEventListener('change',drawChart); load();
})();
