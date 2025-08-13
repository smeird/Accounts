// Provides a help overlay describing the purpose of the current page.
// Loads Font Awesome for the help icon if it is not already available.
document.addEventListener('DOMContentLoaded', () => {
  const page = location.pathname.split('/').pop();
  const helpTexts = {

    'index.php': `Use this page to sign in to the finance manager. Begin by typing your username and password into the boxes provided and then press the login button. If you are new or cannot remember your details, ask the person who set up the system to help you get access. Once signed in you will be taken to the main dashboard where your information is stored.`,
    'users.php': `Manage user accounts here so that each person has their own login. You can add new accounts for family members, reset passwords when someone forgets them and remove access that is no longer needed. Keeping accounts separate makes it easier to see who made changes and keeps your data safer.`,
    'index.html': `The home page is the starting point for exploring your finances. It shows a summary of the system and provides links to every feature. Use the menu on the left to open dashboards, run reports or adjust settings. Spend a moment getting familiar with these links before diving into the details.`,
    'upload.html': `Upload OFX statement files from your bank so the system can read your transactions. Click Choose File, find the statement on your computer and then press Start Upload. When the process finishes you will see the new transactions ready for review. Importing regularly keeps your records up to date.`,
    'account_dashboard.html': `Check balances and recent activity for each account in one place. Charts show how money moves in and out over time, while tables list individual transactions. Look for jumps or dips that you do not recognise and click through to investigate further. This helps you spot unusual activity quickly.`,
    'all_years_dashboard.html': `Compare totals across all recorded years to see the bigger picture. Scroll through the charts to watch how income and spending rise or fall over time. Use this long view to plan savings goals or decide where to cut costs. Understanding these trends makes long‑term planning easier.`,
    'backup.html': `Create downloads of your data or restore from a previous snapshot. Click Make Backup to save a copy to your computer so you always have a safe version. If something goes wrong you can return here and use Restore Backup to put the saved information back. Regular backups protect your records against loss or mistakes.`,
    'budgets.html': `Set monthly spending limits for each category and track how you are doing. Enter a target amount and watch the progress bars show whether you are under or over budget. You can adjust the numbers as your priorities change. Checking this page often helps avoid surprise bills.`,
    'categories.html': `Maintain the list of categories and link them to tags so transactions are grouped sensibly. Add new categories when you start tracking a different type of expense and remove ones you no longer use. Keeping this list tidy ensures reports are easy to read and understand.`,
    'graphs.html': `Explore interactive charts that analyse your finances from different angles. Switch between graph types or time ranges to highlight trends and unusual activity. Hover over a point to see exact amounts and compare periods. These visuals make it easier to spot patterns than looking at raw numbers.`,
    'group_dashboard.html': `Assess spending by category group for each month and year. Click a group to drill down into the categories it contains and see where your money is going. Comparing groups such as household, travel or leisure helps you understand which areas take the largest share of your budget.`,
    'groups.html': `Create and manage groups that bundle related categories together. You might group groceries and dining under Food or combine rent and utilities into Home. Grouping similar expenses gives you a clearer overview in dashboards and reports. Adjust the groups whenever your circumstances change.`,
    'logs.html': `Review system log entries to monitor activity and troubleshoot issues. Filters allow you to focus on a specific time or message type so the list does not feel overwhelming. Reading the logs can reveal what happened just before a problem appeared, which is helpful when asking for support.`,
    'missing_tags.html': `Find transactions that are not yet tagged so nothing is overlooked. Work through the list and assign a tag to each item using the controls provided. Tagged transactions show up correctly in reports and budgets, so keeping this page clear ensures the rest of the system stays accurate.`,
    'monthly_dashboard.html': `Inspect detailed income and expenses for a chosen month. Charts and summaries show where your money came from and where it went during that time. Compare different months to spot patterns such as higher bills in winter or extra income in summer. These insights help you plan ahead.`,
    'monthly_statement.html': `Select a month to display every transaction in order just like a bank statement. Review each entry carefully, edit its description or amount if needed and confirm the tags and categories. Taking a few minutes here keeps the rest of your analysis reliable and can help you remember purchases you forgot.`,
    'processes.html': `Run maintenance tasks such as auto‑tagging or category assignment to tidy your data. Choose a process from the list, start it and watch the progress indicator until it completes. These tools save time by doing repetitive work for you, leaving you free to focus on decisions rather than data entry.`,
    'report.html': `Generate transaction reports based on flexible criteria. Pick a date range, categories or amounts and then click Run Report to see matching transactions. You can download the results as a file to share or study further. Reports are useful for answering specific questions like how much you spent on travel last year.`,
    'search.html': `Search for transactions using keywords or amounts when you need to find something quickly. Enter a word or number, press Search and the system will list any items that match. You can click a result to view or edit the full transaction. This feature saves time when looking through large histories.`,
    'tags.html': `Add, edit and remove tags used to classify transactions. Tags act like labels such as Grocery or Salary that make filtering and reporting easier. Try to keep them short and clear so you can reuse them across many entries. Regularly reviewing tags keeps your organisation consistent.`,
    'transaction.html': `Review detailed information for a single transaction to ensure it is recorded correctly. Here you can change the description, amount, date, tags or category if something does not look right. After saving, the updates flow through to dashboards, reports and budgets automatically.`,
    'transfers.html': `Use Assist to search for same-day transactions with equal and opposite amounts. Review each suggested pair and mark it individually or mark all at once so confirmed transfers are ignored in reports. This keeps totals accurate by preventing the same money being counted twice.`,
    'yearly_dashboard.html': `Analyse totals for a chosen year using charts and tables. Look through the months to see how spending and income evolved as the year progressed. This broader view helps you understand whether you are meeting your long‑term goals and where you might need to cut back.`,
    'recurring_spend.html': `Identify expenses that recur over the past year so you are aware of ongoing commitments. The page highlights regular payments like subscriptions or rent and shows how much they cost overall. Spotting these repeated charges helps you decide which ones are essential and which could be reduced or cancelled.`

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

