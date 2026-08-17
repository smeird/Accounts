(function () {
    'use strict';
    const runButton = document.getElementById('run');
    const result = document.getElementById('result');
    const debugContainer = document.getElementById('debug');
    const debugRequest = document.getElementById('debug-request');
    const debugResponse = document.getElementById('debug-response');

    function setStatus(kind, title, message) {
        result.className = `utility-status is-${kind}`;
        result.replaceChildren();
        const icon = document.createElement('i');
        const copy = document.createElement('div');
        const heading = document.createElement('strong');
        const detail = document.createElement('p');
        icon.className = kind === 'loading' ? 'fas fa-rotate' : kind === 'error' ? 'fas fa-triangle-exclamation' : 'fas fa-circle-info';
        icon.setAttribute('aria-hidden', 'true');
        heading.textContent = title;
        detail.textContent = message;
        copy.append(heading, detail);
        result.append(icon, copy);
    }

    function makeList(title, items, actionList) {
        const panel = document.createElement('section');
        const heading = document.createElement('h3');
        const list = document.createElement('ul');
        panel.className = `utility-review-list${actionList ? ' utility-review-list--actions' : ''}`;
        heading.textContent = title;
        (Array.isArray(items) ? items : []).forEach((item) => {
            const row = document.createElement('li');
            row.textContent = String(item);
            list.appendChild(row);
        });
        panel.append(heading, list);
        return panel;
    }

    function renderReview(data) {
        result.className = 'utility-review-result';
        result.replaceChildren();
        const summary = document.createElement('p');
        const columns = document.createElement('div');
        summary.className = 'utility-review-summary';
        summary.textContent = String(data.summary || 'The review did not include a summary.');
        columns.className = 'utility-review-columns';
        columns.append(makeList('What stands out', data.highlights, false), makeList('Recommended next steps', data.actions, true));
        result.append(summary, columns);
    }

    function renderDebug(data) {
        if (!data || !data.debug) { debugContainer.classList.add('hidden'); return; }
        debugRequest.textContent = data.debug.prompt || '';
        debugResponse.textContent = typeof data.debug.response === 'string' ? data.debug.response : JSON.stringify(data.debug.response, null, 2);
        debugContainer.classList.remove('hidden');
    }

    runButton.addEventListener('click', async () => {
        runButton.disabled = true;
        setStatus('loading', 'Reviewing the latest twelve months…', 'This can take a little while. You can leave the page open while the analysis completes.');
        try {
            const response = await fetch('../php_backend/public/ai_feedback.php', { method: 'POST' });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || data.error) throw new Error(data.error || 'The review could not be generated.');
            renderReview(data);
            renderDebug(data);
            if (window.showMessage) window.showMessage('Financial review ready');
        } catch (error) {
            setStatus('error', 'We could not generate the review', error.message || 'Please try again in a moment.');
            debugContainer.classList.add('hidden');
            if (window.showMessage) window.showMessage(error.message || 'Financial review failed', 'error');
        } finally { runButton.disabled = false; }
    });
})();
