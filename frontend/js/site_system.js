// Adds semantic styling hooks to legacy pages so they share the Financial Pulse system.
(function () {
    'use strict';

    const body = document.body;
    if (!body || !body.classList.contains('site-system-page')) return;

    function hasBackgroundClass(element, families) {
        return Array.from(element.classList).some(className => families.some(family => className.indexOf('bg-' + family + '-') === 0));
    }

    function classifyButton(button) {
        if (button.classList.contains('site-button') || button.closest('#menu,.account-dashboard')) return;
        const hasColour = hasBackgroundClass(button, ['indigo','blue','green','emerald','teal','red','rose','orange','amber','gray','slate']);
        const isProminentControl = button.matches('[type="submit"],.ops-btn-primary,[class*="px-"],[class*="py-"]');
        const looksLikeButton = button.getAttribute('role') === 'button' || hasColour || isProminentControl;
        if (!looksLikeButton) return;
        button.classList.add('site-button');
        if (hasBackgroundClass(button, ['red','rose','orange'])) button.classList.add('site-button--danger');
        else if (hasBackgroundClass(button, ['green','emerald','teal'])) button.classList.add('site-button--success');
        else if (hasBackgroundClass(button, ['gray','slate']) || button.classList.contains('border')) button.classList.add('site-button--secondary');
        else if (hasColour || button.matches('[type="submit"],.ops-btn-primary')) button.classList.add('site-button--primary');
        else button.classList.add('site-button--secondary');
    }

    function classifyCard(card) {
        if (card.dataset.siteClassified === 'true') return;
        card.dataset.siteClassified = 'true';
        card.classList.add('site-card');
        if (card.matches('.text-center') || card.closest('#totals') || card.closest('[id$="-totals"]')) {
            card.classList.add('site-metric-card');
            const label = card.querySelector('p:first-child');
            if (label) label.classList.add('site-metric-label');
        }
        if (card.querySelector('form,input,select,textarea')) card.classList.add('site-card--control');
        if (card.querySelector('[data-chart-desc],.highcharts-container') || card.matches('.ops-chart-block')) card.classList.add('site-card--chart');
        if (card.querySelector('.tabulator,[id$="-grid"],[id$="-table"]') || card.matches('.ops-table-block')) card.classList.add('site-card--data');
        const heading = card.querySelector(':scope > h2,:scope > h3,:scope > div > h2,:scope > div > h3');
        if (heading && !heading.closest('.ops-titlebar')) heading.classList.add('site-card-heading');
    }

    function markIntro(main) {
        if (!main || main.querySelector('.site-intro')) return;
        const candidates = [
            main.querySelector(':scope > p'),
            main.querySelector(':scope > .cards > p:first-child'),
            main.querySelector(':scope > section.cards > p:first-child')
        ].filter(Boolean);
        const intro = candidates.find(element => element.textContent.trim().length > 28);
        if (intro) intro.classList.add('site-intro');
    }

    function classifyActionClusters(root) {
        if (root.matches && root.matches('button,a[role="button"],a[class*="bg-"]')) classifyButton(root);
        root.querySelectorAll('button,a[role="button"],a[class*="bg-"]').forEach(classifyButton);
        const parents = new Set();
        root.querySelectorAll('.site-button').forEach(button => { if (button.parentElement) parents.add(button.parentElement); });
        parents.forEach(parent => {
            const directButtons = Array.from(parent.children).filter(child => child.classList && child.classList.contains('site-button'));
            if (directButtons.length > 1) parent.classList.add('site-action-cluster');
        });
    }

    function classify(root) {
        if (!root || root.nodeType !== 1) return;
        const cards = [];
        if (root.matches && root.matches('.cards,.ops-section,.ops-table-block,.ops-chart-block')) cards.push(root);
        root.querySelectorAll('.cards,.ops-section,.ops-table-block,.ops-chart-block').forEach(card => cards.push(card));
        cards.forEach(classifyCard);
        classifyActionClusters(root);
        if (root.matches && root.matches('.site-button') && root.parentElement) classifyActionClusters(root.parentElement);
        if (root.matches && root.matches('label') && !root.closest('#menu') && root.textContent.trim()) root.classList.add('site-field-label');
        root.querySelectorAll('label').forEach(label => {
            if (!label.closest('#menu') && label.textContent.trim()) label.classList.add('site-field-label');
        });
    }

    const main = document.querySelector('main.ops-main');
    markIntro(main);
    classify(main || document.body);

    const observer = new MutationObserver(mutations => {
        mutations.forEach(mutation => mutation.addedNodes.forEach(node => {
            if (node.nodeType === 1) classify(node);
        }));
    });
    observer.observe(main || document.body, { childList:true, subtree:true });

    window.addEventListener('load', () => {
        markIntro(main);
        classify(main || document.body);
    }, { once:true });
})();
