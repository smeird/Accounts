# Accounts wiki

Accounts is a self-hosted financial workspace for importing bank statements, maintaining a trustworthy transaction ledger and understanding balances, spending, plans and financial direction.

## Documentation

- [Setup](Setup.md) — local and Apache configuration, upgrades and testing.
- [Architecture](Architecture.md) — application layers, financial rules and request flows.
- [Style Guide](StyleGuide.md) — the shared visual language and implementation conventions.
- [Product requirements](../REQUIREMENTS.md) — the current functional baseline.

## Current product areas

- **Overview:** Financial Overview, Accounts & Balances and Monthly Activity.
- **Transactions:** import, search, reports, transfers and exclusions.
- **Insights:** Trends & Comparisons, Daily Burn, forecast, annual review, recurring activity, financial picture and matrix analysis.
- **Planning:** budgets and project portfolio.
- **Organise:** tags, aliases, categories, segments and groups.
- **System:** automation, exports, backups, health checks, settings, users and logs.

## Important analytical behaviour

- Transfers and `IGNORE`-tagged transactions do not inflate income or expenditure.
- Closed accounts keep their transaction history but contribute no live balance.
- Daily Burn spreads each month’s observed expenditure across its actual calendar days and reports that rate by segment.
- Forecasts use observed ledger history and expose their coverage and assumptions.
- Classifications are reusable relationships, not labels created independently for every transaction.
