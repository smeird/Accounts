// Loads live system counts for the landing page hero cards.
document.addEventListener('DOMContentLoaded', () => {
  const elements = {
    accounts: document.getElementById('landing-stat-accounts'),
    transactions: document.getElementById('landing-stat-transactions'),
    tags: document.getElementById('landing-stat-tags'),
  };

  if (!elements.accounts && !elements.transactions && !elements.tags) {
    return;
  }

  const formatter = new Intl.NumberFormat(undefined, { maximumFractionDigits: 0 });

  const setFallback = () => {
    Object.values(elements).forEach((el) => {
      if (el) {
        el.textContent = '—';
      }
    });
  };

  const fetchMetrics = typeof fetchNoCache === 'function' ? fetchNoCache : window.fetch.bind(window);

  fetchMetrics('../php_backend/public/landing_metrics.php')
    .then((response) => {
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }
      return response.json();
    })
    .then((data) => {
      if (elements.accounts) {
        elements.accounts.textContent = typeof data.accounts === 'number'
          ? formatter.format(data.accounts)
          : '—';
      }
      if (elements.transactions) {
        elements.transactions.textContent = typeof data.transactions === 'number'
          ? formatter.format(data.transactions)
          : '—';
      }
      if (elements.tags) {
        elements.tags.textContent = typeof data.tags === 'number'
          ? formatter.format(data.tags)
          : '—';
      }
    })
    .catch((error) => {
      console.error('Failed to load landing metrics', error);
      setFallback();
    });
});
