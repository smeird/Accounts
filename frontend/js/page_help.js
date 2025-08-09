// Provides a help overlay describing the purpose of the current page.
// Loads Font Awesome for the help icon if it is not already available.
document.addEventListener('DOMContentLoaded', () => {
  const page = location.pathname.split('/').pop();
  const helpTexts = {
    'index.php': 'Login to access the finance manager.',
    'users.php': 'Add new users or update your password.',
    'index.html': 'Home page with overview and navigation.',
    'upload.html': 'Upload OFX statements to import transactions.',
    'account_dashboard.html': 'Overview of account balances and activity.',
    'all_years_dashboard.html': 'Compare financial totals across every recorded year.',
    'backup.html': 'Download and restore backups of your data.',
    'budgets.html': 'Manage monthly spending limits for categories.',
    'categories.html': 'Create categories and assign tags.',
    'graphs.html': 'Visualise transactions with interactive charts.',
    'group_dashboard.html': 'Review group spending by month and year.',
    'groups.html': 'Create groups to collect related categories.',
    'logs.html': 'Review recent log entries to monitor system activity.',
    'missing_tags.html': 'Identify transactions that have not yet been tagged.',
    'monthly_dashboard.html': 'View income and outgoings for a chosen month.',
    'monthly_statement.html': 'Select a month to view a detailed list of transactions.',
    'processes.html': 'Run background tasks like auto-tagging and category assignment.',
    'report.html': 'Generate detailed transaction reports filtered by criteria.',
    'search.html': 'Find specific transactions using keywords and view results.',
    'tags.html': 'Create and manage tags for categorising transactions.',
    'transaction.html': 'Review or edit the information for a single transaction.',
    'transfers.html': 'List detected transfers between accounts.',
    'yearly_dashboard.html': 'Analyse totals for a single year through charts and tables.'
  };

  const helpText = helpTexts[page];
  if (!helpText) return;

  if (!document.getElementById('fa-icons')) {
    const link = document.createElement('link');
    link.id = 'fa-icons';
    link.rel = 'stylesheet';
    link.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css';
    document.head.appendChild(link);
  }

  const btn = document.createElement('button');
  btn.innerHTML = '<i class="fas fa-question"></i>';
  btn.className = 'fixed bottom-4 right-4 bg-blue-600 text-white rounded-full w-12 h-12 flex items-center justify-center shadow-lg';

  const overlay = document.createElement('div');
  overlay.className = 'fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center p-4';

  const box = document.createElement('div');
  box.className = 'bg-white p-6 rounded shadow max-w-md text-center';
  const text = document.createElement('p');
  text.textContent = helpText;
  const close = document.createElement('button');
  close.textContent = 'Close';
  close.className = 'mt-4 bg-blue-600 text-white px-4 py-2 rounded';
  close.addEventListener('click', () => overlay.classList.add('hidden'));

  box.appendChild(text);
  box.appendChild(close);
  overlay.appendChild(box);

  btn.addEventListener('click', () => overlay.classList.remove('hidden'));

  document.body.appendChild(btn);
  document.body.appendChild(overlay);
});
