// Provides a help overlay describing the purpose of the current page.
// Loads Font Awesome for the help icon if it is not already available.
document.addEventListener('DOMContentLoaded', () => {
  const page = location.pathname.split('/').pop();
  const helpTexts = {
    'index.php': 'Use this page to sign in to the finance manager. Enter your username and password and submit the form to open the main dashboard.',
    'users.php': 'Manage user accounts here. Add new logins for colleagues or change your own password to keep your access secure.',
    'index.html': 'The home page provides an overview of the system and links to every feature. Use the side menu to navigate through dashboards, reports and settings.',
    'upload.html': 'Upload OFX statement files from your bank. Choose a file, start the upload and the transactions will be imported into the database.',
    'account_dashboard.html': 'Check balances and recent activity for each account. Use the charts and tables to monitor how funds move over time.',
    'all_years_dashboard.html': 'Compare totals across all recorded years to spot long‑term trends. The page highlights changes in income and spending year by year.',
    'backup.html': 'Create downloads of your data or restore from a previous snapshot. Use this section regularly to protect your records against loss.',
    'budgets.html': 'Set monthly spending limits for categories and track progress. Review how closely your actual costs follow the targets you set.',
    'categories.html': 'Maintain the list of categories and link them to tags. Organised categories ensure reports group transactions meaningfully.',
    'graphs.html': 'Explore interactive charts that analyse your finances. Switch between graph types to highlight trends and unusual activity.',
    'group_dashboard.html': 'Assess spending by category group for each month and year. Drill into a group to understand where your money is allocated.',
    'groups.html': 'Create groups that bundle related categories together so you can compare broader areas such as household or leisure.',
    'logs.html': 'Review system log entries to monitor activity and troubleshoot issues. Filters help you focus on specific time periods or severities.',
    'missing_tags.html': 'Find transactions that are not yet tagged so nothing is overlooked. Apply tags here to keep reports and budgets accurate.',
    'monthly_dashboard.html': 'Inspect detailed income and expenses for a chosen month. Charts and summaries show how that period compares with others.',
    'monthly_statement.html': 'Select a month to display every transaction in order. Review individual entries and confirm their tags and categories.',
    'processes.html': 'Run maintenance tasks such as auto‑tagging or category assignment. Start a process and monitor its progress on this page.',
    'report.html': 'Generate transaction reports based on flexible criteria. Filter by date range, categories or amounts and download the results.',
    'search.html': 'Search for transactions using keywords or amounts. The results list matching items so you can locate specific payments or receipts.',
    'tags.html': 'Add, edit and remove tags used to classify transactions. Clear tagging improves the accuracy of analyses and budgets.',
    'transaction.html': 'Review detailed information for a single transaction. Edit its description, amount, tags or category if necessary.',
    'transfers.html': 'List transfers detected between accounts and those marked in uploaded files. Use the table to confirm or adjust transfer recognition.',
    'yearly_dashboard.html': 'Analyse totals for a chosen year using charts and tables. Compare months within the year to see how your finances evolve.',
    'recurring_spend.html': 'Identify expenses that recur over the past year. The page highlights regular payments so you can manage ongoing commitments.'
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
