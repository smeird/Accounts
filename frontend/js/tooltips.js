(() => {
  const tooltip = document.createElement('div');
  tooltip.className = 'pointer-events-none absolute hidden z-50 rounded-2xl border border-white/60 bg-white/70 px-3 py-2 text-sm font-medium text-slate-900 shadow-[0_20px_45px_rgba(15,23,42,0.35)] backdrop-blur-xl ring-1 ring-white/40 whitespace-pre-line';
  tooltip.style.maxWidth = '18rem';
  tooltip.style.opacity = '0';
  document.body.appendChild(tooltip);

  const showTooltip = (e) => {
    const text = e.currentTarget.getAttribute('data-tooltip');
    if (!text) return;
    tooltip.textContent = text;
    tooltip.classList.remove('hidden');
    tooltip.style.opacity = '1';
    const rect = e.currentTarget.getBoundingClientRect();
    const top = rect.bottom + window.scrollY + 4;
    const left = rect.left + window.scrollX + rect.width / 2;
    tooltip.style.top = `${top}px`;
    tooltip.style.left = `${left}px`;
    tooltip.style.transform = 'translateX(-50%)';
  };

  const hideTooltip = () => {
    tooltip.style.opacity = '0';
    tooltip.classList.add('hidden');
  };

  const attach = (el) => {
    el.addEventListener('mouseenter', showTooltip);
    el.addEventListener('focus', showTooltip);
    el.addEventListener('mouseleave', hideTooltip);
    el.addEventListener('blur', hideTooltip);
  };

  document.querySelectorAll('[data-tooltip]').forEach(attach);

  const observer = new MutationObserver(mutations => {
    mutations.forEach(m => {
      m.addedNodes.forEach(node => {
        if (node.nodeType === 1) {
          if (node.matches && node.matches('[data-tooltip]')) attach(node);
          if (node.querySelectorAll) {
            node.querySelectorAll('[data-tooltip]').forEach(attach);
          }
        }
      });
    });
  });
  observer.observe(document.body, {childList: true, subtree: true});
})();
