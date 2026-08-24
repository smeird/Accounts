(function () {
    'use strict';
    const byId = id => document.getElementById(id);
    const form = byId('fix-form');
    const problem = byId('problem');
    const analyse = byId('analyse');
    const preview = byId('preview');
    const apply = byId('apply');
    let planId = null;

    function status(kind, title, detail) {
        const root = byId('status');
        root.className = `fix-status is-${kind}`;
        root.replaceChildren();
        const icon = document.createElement('i');
        const copy = document.createElement('div');
        const strong = document.createElement('strong');
        const p = document.createElement('p');
        icon.className = kind === 'loading' ? 'fas fa-rotate' : kind === 'error' ? 'fas fa-triangle-exclamation' : kind === 'success' ? 'fas fa-circle-check' : 'fas fa-circle-info';
        icon.setAttribute('aria-hidden', 'true'); strong.textContent = title; p.textContent = detail;
        copy.append(strong, p); root.append(icon, copy);
    }
    function pill(name, target) {
        const el = document.createElement('span');
        el.className = `fix-pill${target ? ' fix-pill--target' : ''}`;
        el.textContent = name; return el;
    }
    function money(value) { return Number(value || 0).toLocaleString('en-GB', {style:'currency', currency:'GBP'}); }
    function render(data) {
        planId = data.plan_id;
        byId('preview-summary').textContent = data.summary || 'Review this tag correction carefully.';
        byId('source-tags').replaceChildren(...(data.source_tags || []).map(tag => pill(tag.name, false)));
        const target = pill(data.target_tag_name, true);
        if (!data.target_tag_id) target.append(document.createTextNode(' · new'));
        byId('target-tag').replaceChildren(target);
        byId('affected-count').textContent = Number(data.affected_count || 0).toLocaleString('en-GB');
        byId('confidence').textContent = `${Math.round(Number(data.confidence || 0) * 100)}%`;
        byId('match-terms').textContent = (data.match_terms || []).join(', ') || 'Every transaction with source tag';
        const warnings = byId('warnings');
        warnings.replaceChildren();
        if ((data.warnings || []).length) {
            const list = document.createElement('ul');
            data.warnings.forEach(item => { const li = document.createElement('li'); li.textContent = item; list.appendChild(li); });
            warnings.appendChild(list); warnings.hidden = false;
        } else warnings.hidden = true;
        const rows = byId('sample-rows'); rows.replaceChildren();
        (data.samples || []).forEach(row => {
            const tr = document.createElement('tr');
            [row.date, row.description, row.memo || '—', money(row.amount)].forEach(value => { const td = document.createElement('td'); td.textContent = value; tr.appendChild(td); });
            rows.appendChild(tr);
        });
        if (data.debug) {
            byId('debug-request').textContent = data.debug.prompt || '';
            byId('debug-response').textContent = typeof data.debug.response === 'string' ? data.debug.response : JSON.stringify(data.debug.response, null, 2);
            byId('debug').hidden = false;
        } else byId('debug').hidden = true;
        preview.hidden = false; preview.scrollIntoView({behavior:'smooth', block:'start'});
    }
    async function request(payload) {
        const response = await fetch('../php_backend/public/ai_tag_correction.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload), cache:'no-store'});
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.error) throw new Error(data.error || 'The correction could not be processed.');
        return data;
    }
    problem.addEventListener('input', () => { byId('character-count').textContent = `${problem.value.length.toLocaleString('en-GB')} / 2,000`; });
    form.addEventListener('submit', async event => {
        event.preventDefault(); preview.hidden = true; planId = null; analyse.disabled = true;
        status('loading', 'Analysing the tagging pattern…', 'The AI is interpreting your description and the server is finding the exact matching records.');
        try { const data = await request({action:'preview', problem:problem.value.trim()}); render(data); status('info', 'Preview ready', 'Check the proposed tags and sample transactions below. Nothing has changed yet.'); }
        catch (error) { status('error', 'We could not prepare a safe correction', error.message); if (window.showMessage) window.showMessage(error.message, 'error'); }
        finally { analyse.disabled = false; }
    });
    byId('cancel').addEventListener('click', () => { preview.hidden = true; planId = null; status('info', 'Preview discarded', 'No transaction data was changed.'); problem.focus(); });
    apply.addEventListener('click', async () => {
        if (!planId) return;
        const count = byId('affected-count').textContent;
        if (!window.confirm(`Apply this tag-only correction to ${count} transactions? This cannot be undone automatically.`)) return;
        apply.disabled = true;
        status('loading', 'Applying the confirmed correction…', 'Only the saved transaction tag assignments are being updated.');
        try {
            const data = await request({action:'apply', plan_id:planId, remove_unused_sources:byId('remove-sources').checked});
            planId = null; preview.hidden = true;
            const merged = (data.merged_source_tag_ids || []).length;
            status('success', 'Tag correction complete', `${Number(data.updated).toLocaleString('en-GB')} transactions now use ${data.target_tag_name}.${data.skipped ? ` ${data.skipped} changed records were safely skipped.` : ''}${merged ? ` ${merged} unused source tag${merged === 1 ? ' was' : 's were'} retained as merged history.` : ''}`);
            if (window.showMessage) window.showMessage('Tag correction applied');
        } catch (error) { status('error', 'The correction was not applied', error.message); if (window.showMessage) window.showMessage(error.message, 'error'); }
        finally { apply.disabled = false; }
    });
})();
