# Dedicated phone interface

The phone product provides three destinations: **Overview**, **This month**, and **Search**. Existing phone bookmarks enter it automatically. The desktop sidebar includes **Phone interface**; phone Options includes **Full website**, which keeps the full website selected for the current browser session. The installable phone manifest opens Overview directly and retains the existing installation identity.

For imports, planning and administration, choose **Options → Full website** and use its six task-led sidebar groups. To return, select **Phone interface** in the desktop sidebar. Ordinary Safari and Home Screen installation use the same server; installation is optional.

The independent neutral light/dark design uses a compact financial brief and dated transaction lists, rather than desktop Glass or Professional layouts. There is no permanent top navigation/back bar. The title scrolls away, and three labelled bottom tabs remain available. Merchant identity and signed amounts lead each row, with account/classification metadata below; full descriptions remain available in transaction details. Standard row text is 14px, supporting metadata 12px, and a **Larger text** option increases both. Inputs retain 16px text, interactive controls retain 44px targets, browser zoom remains available, and safe areas and the software keyboard receive dedicated spacing.

## Financial scope

Overview combines active bank and credit-card balances, retaining their bank-reported dates, and shows current-month income, spending and net movement. Saved pension valuations and mortgage debt are separate recorded values with their own dates; they are not live balances or a complete net-worth estimate. Accounts, spending categories and headline activity values open the relevant transaction evidence.

This month always means the server's current calendar month, including an empty month. Its three headline values cover the whole month and exclude transfers and ignored entries. All activity includes labelled transfers, while Money in and Money out exclude them. Ignored entries stay hidden here.

Search covers the complete history with 50 rows per server page. Dates, account, direction, signed amount limits, transfers and ignored-entry filters are available in a sheet. Structured evidence, group filters and bounded import evidence survive incoming links. The matching signed total covers all matching rows, across every page. Comparison links offer an explicit full-website handoff.

## Classification

Tap a transaction, search existing tags, select one and review its category and segment before pressing **Save classification**. The saved tag links determine classification. This changes that entry only: it does not learn a merchant rule, reclassify other merchants, create a tag or change the taxonomy. Statement details are expandable beneath the form. Transfers, ignored entries and system classifications are protected.

The save locks and rechecks transaction evidence and tag mapping in one database transaction. Stale evidence or a retired tag is rejected without changing financial fields. Navigation warns before discarding an unfinished selection. Session recovery keeps the selection in memory, requires the same user and waits for a fresh explicit Save; writes are never queued or replayed.

## Returning to the app and importing statements

After more than 30 seconds in the background, the app checks authentication. It refreshes the current view only when a statement import has changed transactions or account balances. Duplicate-only unchanged files and failed imports do not advance the change marker. Unfinished classification selections and saves defer this refresh; save or discard them explicitly. Options → Refresh figures remains available for changes outside statement imports. No write is replayed.

Import through **Options → Full website → Transactions → Import Transactions**. The native Files picker has no extension/type restriction, while the app and server check OFX/QFX extensions case-insensitively after selection. The user confirmed selection worked on a physical iPhone on 24 October 2026. This single device confirmation does not replace the broader device checks in [Setup](Setup.md#physical-iphone-verification).

## Implementation and safeguards

- Presentation: `frontend/phone.html`, `frontend/css/phone.css`, `frontend/js/phone.js`.
- Early routing: `frontend/js/phone_entry.js`; independent install entry: `frontend/phone.webmanifest`.
- Shared calculations: `InstantDashboard`, `Account`, and the common `Transaction` search query. Desktop search responses remain compatible.
- Narrow endpoints: `phone_overview.php`, `phone_transaction.php`; scoped saves: `PhoneClassification`.
- Authentication, install/update lifecycle and original-user session recovery remain owned by `web_app.js`.
- One main scroller. No financial pages, API data, credentials or drafts are cached or placed in browser storage. The service worker still caches only the public offline screen.
- Desktop Glass/Professional and tablet layouts remain available; no schema migration is required.

## Design references

The stable labelled bottom tabs, text flexibility and touch geometry follow [Apple's tab-bar guidance](https://developer.apple.com/design/human-interface-guidelines/tab-bars), [typography guidance](https://developer.apple.com/design/human-interface-guidelines/typography) and [accessibility guidance](https://developer.apple.com/design/human-interface-guidelines/accessibility). [Monzo's 2023 home-screen explanation](https://monzo.com/blog/the-new-and-improved-home-screen) supplied a historical reference for an account snapshot followed by activity; this design does not reproduce its screens.

## Verification

On 20 October 2026, all **742 PHP assertions** and **23 JavaScript test files** passed, alongside login-return validation, PHP lint and the production asset build. The PHP suite includes exact pagination totals, current-month versus desktop fallback behaviour, atomic classification, preserved ledger fields, protected transactions, stale revisions/mappings and retired tags. Node checks cover phone routing, preserved evidence, the three-destination contract, installation scope and public-only caching.

Browser checks at 320px, 390px and 430px portrait widths, an 844px landscape width and larger-text mode use synthetic local records and the actual model/API implementation. They cover narrow layouts, paging and totals, transfer filtering, search, filters, classification save/failure, and draft preservation through session expiry and same-user recovery. This dated automated run does not establish all physical iPhone Safari/Home Screen behaviour, the real software keyboard, passkey AutoFill or hardware safe-area interaction. OFX file selection was subsequently confirmed by the user on 24 October 2026; broader device acceptance remains in the release checklist.
