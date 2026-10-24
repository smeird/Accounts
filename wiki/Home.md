# Accounts wiki

Accounts is a self-hosted financial workspace for importing bank statements, maintaining a trustworthy transaction ledger and understanding balances, spending, plans and financial direction.

## Documentation

- [Setup](Setup.md) — local and Apache configuration, upgrades and testing.
- [Phone Interface](PhoneInterface.md) — everyday phone use and the full-website handoff.
- [Pensions & Retirement](Pensions.md) — saved planning assumptions and illustrative income.
- [Mortgage Planner](Mortgages.md) — independent repayment and overpayment illustrations.
- [Smart tagging](SmartTagging.md) — classification, review and rule approval.
- [Tag Taxonomy Rebuild](TagTaxonomyRebuild.md) — historical phase tools and recovery guarantees.
- [Documentation and release readiness](Documentation.md) — publishing, checks and remaining device verification.
- [Architecture](Architecture.md) — application layers, financial rules and request flows.
- [Style Guide](StyleGuide.md) — the shared visual language and implementation conventions.
- [Product requirements](../REQUIREMENTS.md) — the current functional baseline.

## Current product areas

- **Overview:** Financial Overview, Accounts & Balances and Monthly Activity.
- **Transactions:** import, search, reports, transfers and exclusions.
- **Insights:** Trends & Comparisons, Daily Burn, forecast, annual review, recurring activity, financial picture, Group Analysis and matrix analysis.
- **Planning:** planning overview, budgets, project portfolio, Pensions & Retirement and Mortgage Planner.
- **Organise:** tags, aliases, categories, segments and groups.
- **System:** automation, exports, backups, health checks, settings, users and logs.

User access supports passwords with optional authenticator-app verification and discoverable passkeys managed from **System → Users**.

## Important analytical behaviour

- Transfers and `IGNORE`-tagged transactions do not inflate income or expenditure.
- Closed accounts keep their transaction history but contribute no live balance.
- Daily Burn spreads each month’s observed expenditure across its actual calendar days and reports that rate by segment.
- Forecasts use observed ledger history and expose their coverage and assumptions.
- Classifications are reusable relationships, not labels created independently for every transaction.

## Where to begin

For a new Raspberry Pi use [Setup](Setup.md#raspberry-pi-production-installation); manual installations need an empty PostgreSQL database and an explicitly created first user. Existing installations should back up and use Application Updates and Database Health, never the fresh-install table-reset command.

On iPhone, use the three-tab phone product or choose **Options → Full website** for imports and planning. Installation on the Home Screen is optional. Imports support OFX/QFX regardless of extension case; unfinished edits are protected when an import-triggered refresh is pending.

The repository’s `wiki/` folder is the maintained source for the published GitHub Wiki. Historical reviews are dated snapshots, not current defect lists: [Codebase Review](CodebaseReview.md), [Review Tasks](CodebaseReviewTasks.md), [Tagging Logic Review](TaggingLogicReview.md) and [October usability review](UsabilityReview2026-10.md).
