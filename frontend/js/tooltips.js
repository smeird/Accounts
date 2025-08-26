(() => {
  const tooltip = document.createElement('div');
  tooltip.className = 'absolute hidden z-50 bg-gray-800 text-white text-xs rounded py-1 px-2 pointer-events-none shadow-lg';
  document.body.appendChild(tooltip);

  const showTooltip = (e) => {
    const text = e.currentTarget.getAttribute('data-tooltip');
    if (!text) return;
    tooltip.textContent = text;
    tooltip.classList.remove('hidden');
    const rect = e.currentTarget.getBoundingClientRect();
    const top = rect.bottom + window.scrollY + 4;
    const left = rect.left + window.scrollX + rect.width / 2;
    tooltip.style.top = `${top}px`;
    tooltip.style.left = `${left}px`;
    tooltip.style.transform = 'translateX(-50%)';
  };

  const hideTooltip = () => {
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
