(() => {
  const apply = (root = document) => {
    root.querySelectorAll('[aria-label]').forEach(el => {
      if (!el.getAttribute('data-tooltip')) {
        el.setAttribute('data-tooltip', el.getAttribute('aria-label'));
      }
    });
  };
  apply();
  const observer = new MutationObserver(mutations => {
    for (const m of mutations) {
      m.addedNodes.forEach(node => {
        if (node.nodeType === 1) {
          if (node.hasAttribute && node.hasAttribute('aria-label') && !node.getAttribute('data-tooltip')) {
            node.setAttribute('data-tooltip', node.getAttribute('aria-label'));
          }
          if (node.querySelectorAll) {
            apply(node);
          }
        }
      });
    }
  });
  observer.observe(document.body, {childList: true, subtree: true});
})();
