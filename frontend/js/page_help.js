// Provides a help overlay describing the purpose of the current page.
(function () {
if (window.pageHelpInitialised) return;
window.pageHelpInitialised = true;

const init = () => {
  const page = location.pathname.split('/').pop();
  const helpTexts = {

    'index.php': `Use this page to sign in to the finance manager. Choose a registered passkey for Face ID, Touch ID, your device PIN or a security key, or enter your username and password. Password accounts with authenticator verification will still ask for their six-digit code. Once signed in you will be taken to the main dashboard.`,
    'users.php': `Manage user accounts and sign-in methods here. Add a passkey for a quick phishing-resistant login, name each passkey so you recognise it, and remove devices you no longer use. Password and authenticator-app access remain available as recovery methods. You can also add separate accounts or change the current password.`,
    'settings.php': `Shape the workspace from one place. Appearance controls set the default surfaces, density, corners, backdrop, page-header size, top colour-bar thickness and motion across signed-in pages; typography controls change headings, body copy, tables and charts. You can also configure AI behaviour, log retention and automatic logout. The sidebar Professional theme switch can still override the saved surface style on an individual device.`,
    'logout.php': `This page confirms you have been signed out. Use the button to return to the login screen when you are ready to sign in again.`,
    'index.html': `The home page is the starting point for exploring your finances. It shows a summary of the system and provides links to every feature. Use the menu on the left to open dashboards, run reports or adjust settings. Spend a moment getting familiar with these links before diving into the details.`,
    'instant.html': `Financial Overview is your command centre. Start with the total account position and this month's cash flow, then scan the trend and attention cards for anything that needs action. The lower panels show spending categories, budget pressure, account balances and recent transactions.`,
    'upload.html': `Upload OFX statement files from your bank so the system can read your transactions. Click Choose File, find the statement on your computer and then press Start Upload. When the process finishes you will see the new transactions ready for review. Importing regularly keeps your records up to date.`,
    'account.html': `Drill into a single account to review its balance trend and transaction statement. Sort code and account number or card number are shown at the top. Click any row to edit a transaction and ensure your records match the bank.`,
    'account_dashboard.html': `Check balances and recent activity for each account in one place. Charts show how money moves in and out over time, while tables list individual transactions. Sort codes and account numbers are listed where available. Look for jumps or dips that you do not recognise and click through to investigate further. This helps you spot unusual activity quickly.`,
    'all_years_dashboard.html': `The long-term view now opens in Trends & Comparisons, where you can compare all recorded years and switch between categories, segments, groups and tags.`,
    'financial_trends.html': `Choose a period and comparison, then switch between categories, segments, groups and tags to understand what changed and where your money went. The summary, charts and exact values update together, and any spending driver can be opened as a transaction search.`,
    'daily_burn.html': `Choose a history window to see the daily cost of your expenditure. The segment chart spreads each month’s observed spending across that month’s calendar days, while the actual-spending chart preserves the real transaction-day spikes. Use the segment rows to open the transactions behind any daily rate. Transfers, income and excluded transactions are not counted.`,
    'backup.html': `Create downloads of your data or restore from a previous snapshot. Click Make Backup to save a copy to your computer so you always have a safe version. If something goes wrong you can return here and use Restore Backup to put the saved information back. Regular backups protect your records against loss or mistakes.`,
    'database_health.html': `Check whether this installation has the tables, columns, indexes and relationships expected by the current application version. Review every finding before selecting safe repairs. Database Health changes structure only and does not rewrite transaction or other business records; definition changes that could affect stored values remain marked for manual review.`,
    'tag_migration.html': `Prepare safely for the controlled tag rebuild. This page records an immutable snapshot of every transaction's current tag, category and segment, keeps transfers and excluded transactions protected, and previews any restore before live classifications can change.`,
    'tag_taxonomy_discovery.html': `Build a cleaner tag vocabulary without changing the live ledger. Start from a protected Phase 1 snapshot, extract stable transaction patterns, let AI propose broad reusable canonical tags in bounded batches, then edit, approve or reject every proposal before marking the staged taxonomy ready.`,
    'tag_taxonomy_cutover.html': `Apply a fully reviewed tag taxonomy through a separate, atomic cutover. The page verifies the immutable snapshot, protects transfers and exclusions, keeps deferred and newer transactions untouched, distinguishes incoming from outgoing rules, and reconciles financial totals. After application, Clean legacy catalogue independently retires every noncanonical legacy tag and rule from future use without retagging historical transactions. Later classification work can block the broader cutover rollback without blocking this safe catalogue cleanup.`,
    'export.html': `Use Standard Export for a direct OFX, CSV or JSON extract. Use Excel Financial Workbook when you want a finished file with a period Summary, ready-made Pivot Analysis, and a filterable Transactions ledger. Transfers and ignored rows remain visible in the ledger but are excluded from financial totals.`,
    'budgets.html': `Set monthly spending limits for each category and track how you are doing. Enter a target amount and watch the progress bars show whether you are under or over budget. You can adjust the numbers as your priorities change. Checking this page often helps avoid surprise bills.`,
    'projects.html': `Compare projects by genuine importance as well as cost. Priority is calculated consistently from consequence of delay, urgency, asset preservation, financial impact and daily-life impact. Critical and important work remains visible even when it is not yet affordable.`,
    'projects_archived.html': `Browse archived projects and restore any that you wish to revisit.`,
    'projects_board.html': `Scan active projects in priority order. Critical work appears first, followed by important, preventive, improvement and nice-to-have projects. Each card shows the five signals behind its priority alongside cost and spending progress.`,
    'project_add.html': `Describe the project, estimate its cost and rate the five priority signals using the examples in each list. Consequence and urgency carry the most weight; a severe, urgent problem is automatically critical. Asset preservation means extending useful life or avoiding repair and replacement, not simply whether a project is environmentally green.`,
    'categories.html': `Choose a category from the left, then assign tags without leaving the page. Search or filter the tag list and use Add, Move here, or Remove; every change saves immediately and updates existing transactions using that tag. Create a new category from the quick-add field when needed.`,
    'segments.html': `Choose a segment from the left, then assign categories without leaving the page. Search or filter the category list and use Add, Move here, or Remove; every change saves immediately and updates existing transactions using that category. Create a new segment from the quick-add field when needed.`,
    'dedupe.html': `Review duplicate transactions and remove unwanted copies. Click Refresh to scan for duplicates and use Dedupe All to clear them quickly.`,
    'graphs.html': `Use Financial Picture to understand your current account position, annual income, spending, cash flow and savings rate at a glance. Choose a year to compare monthly movement, find negative cash-flow periods, see the largest category and tag drivers, and identify when spending landed. Confirmed account transfers are excluded from the analysis.`,
    'group_dashboard.html': `Group analysis now opens in Trends & Comparisons, with fair period comparisons and direct links to the underlying transactions.`,
    'groups.html': `Create and manage reusable transaction groups from one workspace. Search the group catalogue, add a new group, edit its details, see how many transactions use it, and activate or deactivate it without losing history. Active groups remain available for new assignments, while group analysis and reports provide the wider view.`,
    'logs.html': `Review system log entries to monitor activity and troubleshoot issues. Filters allow you to focus on a specific time or message type so the list does not feel overwhelming. Reading the logs can reveal what happened just before a problem appeared, which is helpful when asking for support.`,
    'tagging.html': `Maintain one controlled transaction vocabulary. The Inbox maps unmatched wording to existing canonical tags, Canonical Tags supports safe edit, merge and retirement, Rules records deterministic matching evidence, AI holds unfamiliar suggestions for review, and Rebuild History preserves the completed migration audit.`,
    'missing_tags.html': `This bookmarked legacy view has been replaced by the Inbox in the unified Tagging workspace. Use it to map unmatched wording to existing canonical tags without creating unnecessary merchant-specific labels.`,
    'monthly_dashboard.html': `Monthly analysis now opens in Trends & Comparisons, where partial months are labelled and compared using the same elapsed dates.`,
    'monthly_statement.html': `Select a month to display every transaction in order just like a bank statement. Review each entry carefully, edit its description or amount if needed and confirm the tags and categories. Taking a few minutes here keeps the rest of your analysis reliable and can help you remember purchases you forgot.`,
    'processes.html': `Keep transaction organisation up to date from one place. Run the recommended full refresh to apply tags, categories and segments in order, or run a single step after changing its rules. Use the rule tools to improve future matches. Clearing assignments is kept in a separate, confirmed reset area and does not delete saved rules or aliases.`,
    'report.html': `Generate transaction reports based on flexible criteria. Pick a date range, categories or amounts and then click Run Report to see matching transactions. You can download the results as a file to share or study further. Reports are useful for answering specific questions like how much you spent on travel last year.`,
    'search.html': `Search for transactions using keywords or amounts when you need to find something quickly. Enter a word or number, press Search and the system will list any items that match. You can click a result to view or edit the full transaction. This feature saves time when looking through large histories.`,
    'tags.html': `Add, edit and remove tags used to classify transactions. Tags act like labels such as Grocery or Salary that make filtering and reporting easier. Try to keep them short and clear so you can reuse them across many entries. Regularly reviewing tags keeps your organisation consistent.`,
    'ai_tags.html': `Use AI to suggest reusable tags for untagged transactions, then connect unassigned tags to categories you already have. Existing category mappings are preserved and uncertain category suggestions are left for review.`,
    'ai_data_fix.html': `Describe a tagging mistake in everyday English, including the incorrect tag, correct tag and any merchant wording that narrows the correction. Review the exact source and destination, affected count and sample transactions before applying. This tool can change tags only; amounts, dates, accounts, categories, segments and groups remain untouched.`,
    'ai_feedback.html': `Send a summary of the last year to an AI to receive an overall financial analysis. The long text explains key trends without asking you any questions.`,
    'transaction.html': `Review the statement detail and identifiers behind a single transaction, then refine its tag, category or reporting group. You can also mark the movement as a transfer or print its record. Saved classification changes flow through to dashboards, reports and budgets automatically.`,

    'transfers.html': `Use Assist to search for equal and opposite transactions across the normal bank settlement window. Review each suggested pair and mark it individually or mark all at once so confirmed transfers are ignored in reports. Undo any transfer if you mark it by mistake to keep totals accurate and prevent the same money being counted twice.`,

    'yearly_dashboard.html': `Analyse totals for a chosen year using charts and tables. Look through the months to see how spending and income evolved as the year progressed. This broader view helps you understand whether you are meeting your long‑term goals and where you might need to cut back.`,
    'forecast.html': `Project your financial position across the next 12 months using your latest complete transaction history. Compare the expected path with conservative and optimistic planning scenarios, review likely spending drivers, and open the methodology panel to see the assumptions and data coverage behind the forecast.`,
    'recurring_spend.html': `See the repeated outgoings and income shaping your monthly baseline. The analysis loads automatically from the latest twelve months, excludes internal transfers, and recognises stable merchant wording across a flexible monthly collection window. Patterns need at least two matching months and activity within the latest 50 days. Use the summary for next month’s expected position, sort or search either table, and open History to review the underlying transactions.`,
    'pivot.html': `Compare income and spending across segments, categories and tags. Choose one year for a monthly view or all years for an annual view, expand rows for detail, and select any amount to inspect its underlying transactions. Internal transfers are excluded.`

  };

  const helpText = helpTexts[page];
  if (!helpText) return;

  const btn = document.createElement('button');
  btn.innerHTML = '<i class="fas fa-question w-6 h-6"></i>';
  btn.className = 'fixed bottom-4 right-4 bg-indigo-600 text-white rounded-full w-12 h-12 flex items-center justify-center shadow-lg';

  const overlay = document.createElement('div');
  overlay.className = 'fixed inset-0 bg-black bg-opacity-50 hidden flex items-center justify-center p-4';

  const box = document.createElement('div');
  box.className = 'cards max-w-md text-center';
  const text = document.createElement('p');
  text.textContent = helpText;
  const close = document.createElement('button');
  close.textContent = 'Close';
  close.className = 'mt-4 bg-indigo-600 text-white px-4 py-2 rounded';
  close.addEventListener('click', () => overlay.classList.add('hidden'));

  box.appendChild(text);
  box.appendChild(close);
  overlay.appendChild(box);

  btn.addEventListener('click', () => overlay.classList.remove('hidden'));

  document.body.appendChild(btn);
  document.body.appendChild(overlay);
};

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
}());
