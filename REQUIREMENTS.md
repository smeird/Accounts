# Section Requirements

This document outlines expected functionality for each section of the finance manager. It is for development reference only.

## Authentication / Login
- Accept username and password for authentication
- Display an error when credentials are invalid
- Start a session and redirect to the main application after successful login

## OFX Upload
- Upload a single OFX statement file
- Upload multiple OFX files in one request
- Show progress and completion status for each upload

## Home Page
- Display welcome message and application version
- Present quick feature highlights

## Monthly Statement
- Select year and month to view transactions
- Optionally filter to only untagged transactions
- Show totals and detailed statement table

## Transaction Reports
- Filter transactions by category, tag, group, text, or date range
- Save and load report configurations
- Display tabular results and a chart

## Search Transactions
- Search across transactions by keyword or exact amount
- Show results grid, totals, and a chart of matches

## Transfers
- List detected transfer candidates
- Assist in linking or marking transfers so they are excluded from totals
- Allow undoing or manually linking transfer transactions

## Yearly Dashboard
- Present yearly totals for tags, categories, and groups
- Visualise data with charts and tables, including breakdown donuts

## All Years Dashboard
- Compare cumulative totals across all recorded years
- Provide tables and charts for tags, categories, and groups

## Monthly Dashboard
- Select a year and month to view income, outgoings, and delta
- Summarise transactions with tables and charts

## Group Dashboard
- Analyse spending for category groups by month and year
- Include tables and charts for monthly and yearly totals

## Account Dashboard
- List accounts with balances and last transaction
- Edit account names inline
- Visualise account balances and link to account details

## Account Detail
- Chart balance over time for a single account
- Display latest statement transactions for the account

## Recurring Spend Detection
- Run analysis over the past year to find repeating expenses
- Present results in a grid with total recurring cost

## Graphs
- Provide multiple charts (monthly, cumulative, pie, tag, scatter)
- Allow selection of year to scope the displayed data

## Budgets
- Set monthly budgets for categories
- List current budgets in a table
- Show progress toward each budget in a chart

## Manage Tags
- Create tags with keyword and description for auto-tagging
- List and edit existing tags

## Missing Tags
- List transactions that do not have tags assigned

## Manage Categories
- Create categories with descriptions
- Assign and rearrange tags via drag and drop

## Manage Groups
- Create groups to collect categories
- List and edit existing groups

## Run Processes
- Manually trigger auto-tagging and category assignment
- Show progress indicator while background tasks run

## View Logs
- Display recent application logs
- Refresh log view and prune entries older than a given number of days

## Remove Duplicates
- Identify potential duplicate transactions
- Refresh list and run bulk deduplication with progress display

## Backup & Restore
- Download backups selecting which data parts to include
- Restore data from an uploaded backup file

## Exports
- Download transactions for a chosen date range
- Select output format such as OFX, CSV or XLSX

## Manage Users
- Add new users with credentials
- Update password for the current user

## Logout
- Destroy the user session and redirect to the login page
