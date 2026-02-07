<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<!-- Navigation menu shared across pages -->
<div class="flex items-center space-x-2 mb-4">
  <img src="/favicon.png" alt="Finance Manager logo" class="h-8 w-8 rounded shadow" />
  <div class="flex flex-col">
<span id="site-title" class="text-xl font-semibold text-indigo-700">Personal Finance Manager</span>
<span id="release-number" class="bg-gray-200 text-gray-700 text-xs px-2 py-0.5 rounded mt-1">v0.0.0</span>
  </div>
</div>
<form id="sidebar-search-form" class="mb-4" aria-label="Search transactions">
  <label for="sidebar-search" class="sr-only">Search transactions</label>
  <div class="relative">
    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>
    <input id="sidebar-search" type="search" placeholder="Search" aria-label="Search transactions" class="unstyled w-full rounded-md border border-slate-300 py-2 pl-9 pr-3 text-sm focus:border-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-200" />
  </div>
</form>
<div class="space-y-4">
  <div class="group">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3 cursor-pointer">Start Here</h3>
    <ul class="space-y-1.5 overflow-hidden max-h-0 transition-all duration-300">
      <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="index.html"><i class="fas fa-home w-4 text-center text-slate-400"></i> Home</a></li>
      <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="upload.html"><i class="fas fa-upload w-4 text-center text-slate-400"></i> Upload OFX Files</a></li>
    </ul>
  </div>

  <div class="group">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3 cursor-pointer">Statements &amp; Transactions</h3>
    <ul class="space-y-1.5 overflow-hidden max-h-0 transition-all duration-300">
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="monthly_statement.html"><i class="fas fa-file-invoice w-4 text-center text-slate-400"></i> Monthly Statement</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="report.html"><i class="fas fa-file-lines w-4 text-center text-slate-400"></i> Transaction Reports</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="search.html"><i class="fas fa-search w-4 text-center text-slate-400"></i> Search Transactions</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="transfers.html"><i class="fas fa-right-left w-4 text-center text-slate-400"></i> Transfers</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="ignored.html"><i class="fas fa-eye-slash w-4 text-center text-slate-400"></i> Ignored Transactions</a></li>
    </ul>
  </div>

  <div class="group">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3 cursor-pointer">Dashboards &amp; Graphs</h3>
    <ul class="space-y-1.5 overflow-hidden max-h-0 transition-all duration-300">
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="yearly_dashboard.html"><i class="fas fa-calendar w-4 text-center text-slate-400"></i> Yearly Dashboard</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="all_years_dashboard.html"><i class="fas fa-calendar-alt w-4 text-center text-slate-400"></i> All Years Dashboard</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="monthly_dashboard.html"><i class="fas fa-calendar-day w-4 text-center text-slate-400"></i> Monthly Dashboard</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="group_dashboard.html"><i class="fas fa-object-group w-4 text-center text-slate-400"></i> Group Dashboard</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="account_dashboard.html"><i class="fas fa-wallet w-4 text-center text-slate-400"></i> Account Dashboard</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="recurring_spend.html"><i class="fas fa-rotate w-4 text-center text-slate-400"></i> Recurring Spend</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="pivot.html"><i class="fas fa-table w-4 text-center text-slate-400"></i> Pivot Analysis</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="graphs.html"><i class="fas fa-chart-bar w-4 text-center text-slate-400"></i> Graphs</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="ai_feedback.html"><i class="fas fa-comments-dollar w-4 text-center text-slate-400"></i> AI Feedback</a></li>
    </ul>
  </div>

  <div class="group">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3 cursor-pointer">Budgeting</h3>
    <ul class="space-y-1.5 overflow-hidden max-h-0 transition-all duration-300">
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="budgets.html"><i class="fas fa-piggy-bank w-4 text-center text-slate-400"></i> Budgets</a></li>
    </ul>
  </div>

  <div class="group">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3 cursor-pointer">Projects</h3>
    <ul class="space-y-1.5 overflow-hidden max-h-0 transition-all duration-300">
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="project_add.html"><i class="fas fa-plus w-4 text-center text-slate-400"></i> Add Project</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="projects.html"><i class="fas fa-screwdriver-wrench w-4 text-center text-slate-400"></i> View Projects</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="projects_board.html"><i class="fas fa-table-columns w-4 text-center text-slate-400"></i> Project Board</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="projects_archived.html"><i class="fas fa-box-archive w-4 text-center text-slate-400"></i> Archived Projects</a></li>
    </ul>
  </div>

  <div class="group">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3 cursor-pointer">Organise Data</h3>
    <ul class="space-y-1.5 overflow-hidden max-h-0 transition-all duration-300">
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="tags.html"><i class="fas fa-tags w-4 text-center text-slate-400"></i> Manage Tags</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="ai_tags.html"><i class="fas fa-robot w-4 text-center text-slate-400"></i> AI Tags</a></li>
        <li>
          <a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="missing_tags.html">
            <span class="flex items-center gap-2"><i class="fas fa-question-circle w-4 text-center text-slate-400"></i> Missing Tags</span>
            <span id="missing-tags-count" class="ml-auto bg-red-600 text-white text-xs font-bold rounded-full px-2 hidden"></span>
          </a>
        </li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="categories.html"><i class="fas fa-folder-open w-4 text-center text-slate-400"></i> Manage Categories</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="groups.html"><i class="fas fa-layer-group w-4 text-center text-slate-400"></i> Manage Groups</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="segments.html"><i class="fas fa-chart-pie w-4 text-center text-slate-400"></i> Manage Segments</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="palette.html"><i class="fas fa-palette w-4 text-center text-slate-400"></i> Colour Palette</a></li>
      </ul>
  </div>

  <div class="group">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3 cursor-pointer">Export</h3>
    <ul class="space-y-1.5 overflow-hidden max-h-0 transition-all duration-300">
      <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="export.html"><i class="fas fa-file-export w-4 text-center text-slate-400"></i> Export Data</a></li>
    </ul>
  </div>

  <div class="group">
    <h3 class="text-sm font-semibold uppercase tracking-wide text-slate-500 mb-3 cursor-pointer">Admin Tools</h3>
    <ul class="space-y-1.5 overflow-hidden max-h-0 transition-all duration-300">
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="processes.html"><i class="fas fa-gears w-4 text-center text-slate-400"></i> Run Processes</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="logs.html"><i class="fas fa-scroll w-4 text-center text-slate-400"></i> View Logs</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="dedupe.html"><i class="fas fa-clone w-4 text-center text-slate-400"></i> Remove Duplicates</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="backup.html"><i class="fas fa-database w-4 text-center text-slate-400"></i> Backup &amp; Restore</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="../settings.php"><i class="fas fa-cog w-4 text-center text-slate-400"></i> System Settings</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="../users.php"><i class="fas fa-users w-4 text-center text-slate-400"></i> Manage Users</a></li>
        <li><a class="flex items-center gap-2 border-l-2 border-transparent px-3 py-2 rounded-md text-sm font-normal text-slate-700 hover:text-slate-900 hover:bg-slate-50 transition-colors duration-150" href="../logout.php"><i class="fas fa-right-from-bracket w-4 text-center text-slate-400"></i> Logout</a></li>
    </ul>
  </div>
</div>

<div id="user-info" class="flex items-center mt-auto pt-4 border-t border-slate-200 text-sm text-slate-600">
  <i id="user-icon" class="fas fa-user w-4 text-center text-slate-400 mr-2"></i>
  <span id="current-user">&nbsp;</span>
</div>
