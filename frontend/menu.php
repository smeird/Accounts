<?php
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!-- Navigation menu shared across pages -->
<div class="flex items-center space-x-2 mb-4">
  <img src="/favicon.svg" alt="Finance Manager logo" class="h-8 w-8" />
  <div class="flex flex-col">
    <span id="site-title" class="text-xl font-semibold text-indigo-700">Personal Finance Manager</span>
    <span id="release-number" class="bg-gray-200 text-gray-700 text-xs px-2 py-0.5 rounded mt-1">v0.0.0</span>
  </div>
</div>
<div class="space-y-2">
  <div class="group">
    <h3 class="text-lg font-semibold text-gray-700 mb-2 cursor-pointer">Start Here</h3>
    <ul class="space-y-1 overflow-hidden max-h-0 transition-all duration-300">
      <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="index.html"><i class="fas fa-home mr-1"></i> Home</a></li>
      <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="upload.html"><i class="fas fa-upload mr-1"></i> Upload OFX Files</a></li>
    </ul>
  </div>

  <div class="group">
    <h3 class="text-lg font-semibold text-gray-700 mb-2 cursor-pointer">Statements &amp; Transactions</h3>
    <ul class="space-y-1 overflow-hidden max-h-0 transition-all duration-300">
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="monthly_statement.html"><i class="fas fa-file-invoice mr-1"></i> Monthly Statement</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="report.html"><i class="fas fa-file-lines mr-1"></i> Transaction Reports</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="search.html"><i class="fas fa-search mr-1"></i> Search Transactions</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="transfers.html"><i class="fas fa-right-left mr-1"></i> Transfers</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="ignored.html"><i class="fas fa-eye-slash mr-1"></i> Ignored Transactions</a></li>
    </ul>
  </div>

  <div class="group">
    <h3 class="text-lg font-semibold text-gray-700 mb-2 cursor-pointer">Dashboards &amp; Graphs</h3>
    <ul class="space-y-1 overflow-hidden max-h-0 transition-all duration-300">
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="yearly_dashboard.html"><i class="fas fa-calendar mr-1"></i> Yearly Dashboard</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="all_years_dashboard.html"><i class="fas fa-calendar-alt mr-1"></i> All Years Dashboard</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="monthly_dashboard.html"><i class="fas fa-calendar-day mr-1"></i> Monthly Dashboard</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="group_dashboard.html"><i class="fas fa-object-group mr-1"></i> Group Dashboard</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="account_dashboard.html"><i class="fas fa-wallet mr-1"></i> Account Dashboard</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="recurring_spend.html"><i class="fas fa-rotate mr-1"></i> Recurring Spend</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="pivot.html"><i class="fas fa-table mr-1"></i> Pivot Analysis</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="graphs.html"><i class="fas fa-chart-bar mr-1"></i> Graphs</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="ai_feedback.html"><i class="fas fa-comments-dollar mr-1"></i> AI Feedback</a></li>
    </ul>
  </div>

  <div class="group">
    <h3 class="text-lg font-semibold text-gray-700 mb-2 cursor-pointer">Budgeting</h3>
    <ul class="space-y-1 overflow-hidden max-h-0 transition-all duration-300">
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="budgets.html"><i class="fas fa-piggy-bank mr-1"></i> Budgets</a></li>
    </ul>
  </div>

  <div class="group">
    <h3 class="text-lg font-semibold text-gray-700 mb-2 cursor-pointer">Projects</h3>
    <ul class="space-y-1 overflow-hidden max-h-0 transition-all duration-300">
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="project_add.html"><i class="fas fa-plus mr-1"></i> Add Project</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="projects.html"><i class="fas fa-screwdriver-wrench mr-1"></i> View Projects</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="projects_board.html"><i class="fas fa-table-columns mr-1"></i> Project Board</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="projects_archived.html"><i class="fas fa-box-archive mr-1"></i> Archived Projects</a></li>
    </ul>
  </div>

  <div class="group">
    <h3 class="text-lg font-semibold text-gray-700 mb-2 cursor-pointer">Organise Data</h3>
    <ul class="space-y-1 overflow-hidden max-h-0 transition-all duration-300">
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="tags.html"><i class="fas fa-tags mr-1"></i> Manage Tags</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="ai_tags.html"><i class="fas fa-robot mr-1"></i> AI Tags</a></li>
        <li>
          <a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="missing_tags.html">
            <span class="flex items-center"><i class="fas fa-question-circle mr-1"></i> Missing Tags</span>
            <span id="missing-tags-count" class="ml-auto bg-red-600 text-white text-xs font-bold rounded-full px-2 hidden"></span>
          </a>
        </li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="categories.html"><i class="fas fa-folder-open mr-1"></i> Manage Categories</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="groups.html"><i class="fas fa-layer-group mr-1"></i> Manage Groups</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="segments.html"><i class="fas fa-chart-pie mr-1"></i> Manage Segments</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="palette.html"><i class="fas fa-palette mr-1"></i> Colour Palette</a></li>
      </ul>
  </div>

  <div class="group">
    <h3 class="text-lg font-semibold text-gray-700 mb-2 cursor-pointer">Export</h3>
    <ul class="space-y-1 overflow-hidden max-h-0 transition-all duration-300">
      <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="export.html"><i class="fas fa-file-export mr-1"></i> Export Data</a></li>
    </ul>
  </div>

  <div class="group">
    <h3 class="text-lg font-semibold text-gray-700 mb-2 cursor-pointer">Admin Tools</h3>
    <ul class="space-y-1 overflow-hidden max-h-0 transition-all duration-300">
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="processes.html"><i class="fas fa-gears mr-1"></i> Run Processes</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="logs.html"><i class="fas fa-scroll mr-1"></i> View Logs</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="dedupe.html"><i class="fas fa-clone mr-1"></i> Remove Duplicates</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="backup.html"><i class="fas fa-database mr-1"></i> Backup &amp; Restore</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="../settings.php"><i class="fas fa-cog mr-1"></i> System Settings</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="../users.php"><i class="fas fa-users mr-1"></i> Manage Users</a></li>
        <li><a class="flex items-center text-gray-700 hover:text-gray-900 hover:bg-indigo-50 px-2 py-1 rounded" href="../logout.php"><i class="fas fa-right-from-bracket mr-1"></i> Logout</a></li>
    </ul>
  </div>
</div>

<div id="user-info" class="flex items-center mt-auto pt-4 border-t">
  <i id="user-icon" class="fas fa-user mr-2"></i>
  <span id="current-user">&nbsp;</span>
</div>
