<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<!-- Shared navigation: labels describe user goals; URLs remain stable for bookmarks. -->
<div class="flex items-center space-x-2 mb-4">
  <img src="/favicon.png" alt="Finance Manager logo" class="h-8 w-8 rounded shadow">
  <div class="flex flex-col"><span id="site-title" class="text-xl font-semibold text-indigo-700">Personal Finance Manager</span><span id="release-number" class="bg-gray-200 text-gray-700 text-xs px-2 py-0.5 rounded mt-1">v0.0.0</span></div>
</div>
<form id="sidebar-search-form" class="mb-4" aria-label="Search transactions">
  <label for="sidebar-search" class="sr-only">Search transactions</label>
  <div class="relative"><i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i><input id="sidebar-search" type="search" placeholder="Find a transaction" aria-label="Search transactions" class="unstyled w-full rounded-md border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200"></div>
</form>
<div class="space-y-4">
  <div class="group">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3 cursor-pointer">Overview</h3>
    <ul class="space-y-1.5 overflow-hidden max-h-0 transition-all duration-300">
      <li><a href="index.html"><i class="fas fa-home"></i> Home</a></li>
      <li><a href="instant.html"><i class="fas fa-bolt"></i> Financial Overview</a></li>
      <li><a href="account_dashboard.html"><i class="fas fa-wallet"></i> Accounts &amp; Balances</a></li>
      <li><a href="monthly_statement.html"><i class="fas fa-file-invoice"></i> Monthly Activity</a></li>
    </ul>
  </div>
  <div class="group">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3 cursor-pointer">Transactions</h3>
    <ul class="space-y-1.5 overflow-hidden max-h-0 transition-all duration-300">
      <li><a href="upload.html"><i class="fas fa-upload"></i> Import Transactions</a></li>
      <li><a href="search.html"><i class="fas fa-search"></i> Find Transactions</a></li>
      <li><a href="report.html"><i class="fas fa-file-lines"></i> Transaction Reports</a></li>
      <li><a href="transfers.html"><i class="fas fa-right-left"></i> Account Transfers</a></li>
      <li><a href="ignored.html"><i class="fas fa-eye-slash"></i> Excluded Transactions</a></li>
    </ul>
  </div>
  <div class="group">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3 cursor-pointer">Insights</h3>
    <ul class="space-y-1.5 overflow-hidden max-h-0 transition-all duration-300">
      <li><a href="financial_trends.html"><i class="fas fa-chart-column"></i> Trends &amp; Comparisons</a></li>
      <li><a href="daily_burn.html"><i class="fas fa-fire-flame-curved"></i> Daily Burn</a></li>
      <li><a href="forecast.html"><i class="fas fa-chart-line"></i> 12-Month Forecast</a></li>
      <li><a href="yearly_dashboard.html"><i class="fas fa-calendar"></i> Year in Review</a></li>
      <li><a href="recurring_spend.html"><i class="fas fa-rotate"></i> Regular Income &amp; Bills</a></li>
      <li><a href="graphs.html"><i class="fas fa-chart-pie"></i> Financial Picture</a></li>
      <li><a href="pivot.html"><i class="fas fa-table-cells-large"></i> Analysis Matrix</a></li>
      <li><a href="ai_feedback.html"><i class="fas fa-comments-dollar"></i> AI Financial Review</a></li>
    </ul>
  </div>
  <div class="group">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3 cursor-pointer">Planning</h3>
    <ul class="space-y-1.5 overflow-hidden max-h-0 transition-all duration-300">
      <li><a href="budgets.html"><i class="fas fa-piggy-bank"></i> Budgets</a></li>
      <li><a href="projects.html"><i class="fas fa-compass-drafting"></i> Project Portfolio</a></li>
      <li><a href="projects_board.html"><i class="fas fa-table-columns"></i> Project Board</a></li>
      <li><a href="project_add.html"><i class="fas fa-plus"></i> New Project</a></li>
      <li><a href="projects_archived.html"><i class="fas fa-box-archive"></i> Project Archive</a></li>
    </ul>
  </div>
  <div class="group">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3 cursor-pointer">Organise</h3>
    <ul class="space-y-1.5 overflow-hidden max-h-0 transition-all duration-300">
      <li><a href="tagging.html"><span class="flex items-center gap-2"><i class="fas fa-tags"></i> Tagging</span><span id="missing-tags-count" class="ml-auto bg-red-600 text-white text-xs font-bold rounded-full px-2 hidden"></span></a></li>
      <li><a href="categories.html"><i class="fas fa-folder-open"></i> Categories</a></li>
      <li><a href="segments.html"><i class="fas fa-chart-pie"></i> Segments</a></li>
      <li><a href="groups.html"><i class="fas fa-layer-group"></i> Groups</a></li>
    </ul>
  </div>
  <div class="group">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3 cursor-pointer">System</h3>
    <ul class="space-y-1.5 overflow-hidden max-h-0 transition-all duration-300">
      <li><a href="processes.html"><i class="fas fa-gears"></i> Automation Centre</a></li>
      <li><a href="export.html"><i class="fas fa-file-export"></i> Export Data</a></li>
      <li><a href="dedupe.html"><i class="fas fa-clone"></i> Duplicate Check</a></li>
      <li><a href="backup.html"><i class="fas fa-database"></i> Backup &amp; Restore</a></li>
      <li><a href="database_health.html"><i class="fas fa-heart-pulse"></i> Database Health</a></li>
      <li><a href="logs.html"><i class="fas fa-scroll"></i> System Log</a></li>
      <li><a href="../settings.php"><i class="fas fa-sliders"></i> Settings</a></li>
      <li><a href="../users.php"><i class="fas fa-users"></i> Users</a></li>
      <li><a href="../logout.php"><i class="fas fa-right-from-bracket"></i> Sign Out</a></li>
    </ul>
  </div>
</div>
<div id="user-info" class="flex items-center mt-auto pt-4 border-t border-slate-200 text-sm text-slate-600"><i id="user-icon" class="fas fa-user w-4 text-center text-slate-400 mr-2"></i><span id="current-user">&nbsp;</span></div>
