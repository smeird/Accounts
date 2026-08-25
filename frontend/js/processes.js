(function () {
    'use strict';

    const statusPanel = document.getElementById('process-run-status');
    const statusTitle = document.getElementById('process-status-title');
    const statusMessage = document.getElementById('process-status-message');
    const statusResults = document.getElementById('process-results');
    const clearDialog = document.getElementById('process-clear-dialog');
    const resetPanel = document.querySelector('.process-reset');
    const actionButtons = Array.from(document.querySelectorAll('[data-process-action]'));
    const processCards = Array.from(document.querySelectorAll('[data-process-card]'));

    const processCopy = {
        all: {
            running: 'Refreshing all transaction insights…',
            detail: 'Applying tags first, followed by categories and segments.',
            complete: 'Full refresh complete',
            success: 'Your transaction organisation and dashboards are now up to date.'
        },
        tagging: {
            running: 'Applying tags…',
            detail: 'Matching transactions against tag names and aliases.',
            complete: 'Tagging complete',
            success: 'Saved tag rules have been applied to your transactions.'
        },
        categories: {
            running: 'Applying categories…',
            detail: 'Following your existing tag-to-category links.',
            complete: 'Categorisation complete',
            success: 'Saved category links have been applied to your transactions.'
        },
        segments: {
            running: 'Applying segments…',
            detail: 'Matching transactions against your segment rules.',
            complete: 'Segment assignment complete',
            success: 'Saved segment rules have been applied to your transactions.'
        },
        clear: {
            running: 'Clearing transaction assignments…',
            detail: 'Removing current tags, categories and segments while keeping your rules.',
            complete: 'Assignments cleared',
            success: 'Transaction assignments were cleared. Your rules and aliases are unchanged.'
        }
    };

    const resultDefinitions = {
        tagged: 'Transactions tagged',
        categorised: 'Transactions categorised',
        segmented: 'Transactions segmented',
        cleared_tags: 'Tags cleared',
        cleared_categories: 'Categories cleared',
        cleared_segments: 'Segments cleared'
    };

    function announce(message, type) {
        if (typeof window.showMessage === 'function') window.showMessage(message, type);
    }

    function setButtonsDisabled(disabled) {
        actionButtons.forEach(button => { button.disabled = disabled; });
        document.getElementById('clear-btn').disabled = disabled;
    }

    function affectedActions(action) {
        return action === 'all' ? ['tagging', 'categories', 'segments'] : [action];
    }

    function setCardState(action, state) {
        processCards.forEach(card => {
            const isAffected = affectedActions(action).includes(card.dataset.processCard);
            const stateLabel = card.querySelector('[data-process-state]');
            card.classList.remove('is-running', 'is-complete', 'is-error');

            if (!isAffected || action === 'clear') {
                stateLabel.textContent = 'Ready';
                return;
            }

            card.classList.add(`is-${state}`);
            stateLabel.textContent = state === 'running' ? 'Working' : state === 'complete' ? 'Updated' : 'Needs attention';
        });
    }

    function showRunning(action) {
        const copy = processCopy[action];
        statusPanel.hidden = false;
        statusPanel.className = 'process-run-status is-running';
        statusPanel.setAttribute('aria-busy', 'true');
        statusPanel.querySelector('.process-run-status__icon i').className = 'fas fa-spinner';
        statusTitle.textContent = copy.running;
        statusMessage.textContent = copy.detail;
        statusResults.hidden = true;
        statusResults.replaceChildren();
        setCardState(action, 'running');
    }

    function renderResults(data) {
        const fragment = document.createDocumentFragment();

        Object.entries(resultDefinitions).forEach(([key, label]) => {
            if (!Object.prototype.hasOwnProperty.call(data, key)) return;
            const result = document.createElement('div');
            const resultLabel = document.createElement('span');
            const resultValue = document.createElement('strong');
            result.className = 'process-result';
            resultLabel.textContent = label;
            resultValue.textContent = Number(data[key] || 0).toLocaleString();
            result.append(resultLabel, resultValue);
            fragment.appendChild(result);
        });

        statusResults.replaceChildren(fragment);
        statusResults.hidden = statusResults.childElementCount === 0;
    }

    function showSuccess(action, data) {
        const copy = processCopy[action];
        statusPanel.className = 'process-run-status is-success';
        statusPanel.setAttribute('aria-busy', 'false');
        statusPanel.querySelector('.process-run-status__icon i').className = 'fas fa-check';
        statusTitle.textContent = copy.complete;
        statusMessage.textContent = copy.success;
        renderResults(data);
        setCardState(action, 'complete');
        announce(copy.complete);
    }

    function showError(action, message) {
        statusPanel.className = 'process-run-status is-error';
        statusPanel.setAttribute('aria-busy', 'false');
        statusPanel.querySelector('.process-run-status__icon i').className = 'fas fa-triangle-exclamation';
        statusTitle.textContent = 'Process could not finish';
        statusMessage.textContent = message;
        statusResults.hidden = true;
        setCardState(action, 'error');
        announce(message, 'error');
    }

    async function readResponse(response) {
        let payload;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error('The server returned an unreadable response. Please try again.');
        }

        if (!response.ok || payload.error) throw new Error(payload.error || 'The process could not be completed.');
        return payload;
    }

    async function runProcess(action) {
        if (!processCopy[action]) return;

        setButtonsDisabled(true);
        showRunning(action);
        statusPanel.scrollIntoView({
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            block: 'nearest'
        });

        try {
            const response = await fetch('../php_backend/public/run_processes.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action })
            });
            showSuccess(action, await readResponse(response));
        } catch (error) {
            showError(action, error instanceof Error ? error.message : 'The process could not be completed.');
        } finally {
            setButtonsDisabled(false);
        }
    }

    actionButtons.forEach(button => {
        button.addEventListener('click', () => runProcess(button.dataset.processAction));
    });

    // Expanding the final section changes the scroll height. iPad Safari can
    // otherwise leave the new action inside its bottom rubber-band region.
    resetPanel?.addEventListener('toggle', () => {
        if (!resetPanel.open) return;
        window.requestAnimationFrame(() => {
            resetPanel.querySelector('.process-reset__action')?.scrollIntoView({
                behavior: 'auto',
                block: 'nearest'
            });
        });
    });

    document.getElementById('clear-btn').addEventListener('click', () => {
        if (typeof clearDialog.showModal === 'function') {
            clearDialog.showModal();
        } else if (window.confirm('Clear all current tag, category and segment assignments?')) {
            runProcess('clear');
        }
    });

    document.getElementById('process-clear-cancel').addEventListener('click', () => clearDialog.close());
    document.getElementById('process-clear-confirm').addEventListener('click', () => {
        clearDialog.close();
        runProcess('clear');
    });
})();
