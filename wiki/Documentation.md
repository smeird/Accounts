# Documentation and release readiness

Reviewed against application commit `595a78c` on 24 October 2026. This is a documentation alignment pass, not a release announcement or a claim that every production scenario has been tested.

## Documentation ownership

`README.md` is the short product/install entry point; `REQUIREMENTS.md` describes current functional behaviour. The repository’s `wiki/` directory is the maintained source for the GitHub Wiki. Setup owns detailed installation, upgrade, migration and recovery instructions. Phone Interface, Smart tagging, Pensions and Mortgages own their specialist workflows; Architecture and Style Guide define shared implementation contracts.

Do not maintain a second independent copy in the Wiki. To publish a reviewed documentation change, commit and push the source repository first, then:

```bash
git clone https://github.com/smeird/Accounts.wiki.git /tmp/accounts-wiki
python3 scripts/sync_wiki.py /tmp/accounts-wiki
git -C /tmp/accounts-wiki diff --check
git -C /tmp/accounts-wiki diff --stat
git -C /tmp/accounts-wiki add --all
git -C /tmp/accounts-wiki commit -m "Synchronise maintained Accounts documentation"
git -C /tmp/accounts-wiki push origin HEAD
python3 scripts/sync_wiki.py /tmp/accounts-wiki --check
```

Run from the application checkout; choose a new temporary directory or reuse your existing clean Wiki clone. Publishing requires repository write access. The script rewrites links to Wiki pages and links to source files on `main`, validates linked local paths and preserves Wiki-only pages for manual review. It does not commit, push or delete pages. A clean `--check` confirms the rendered mirror; it does not prove the content is correct. Review content against code as part of every feature change.

## October alignment

- Reconciled the default three-tab phone product with the full website’s responsive navigation.
- Documented case-insensitive OFX/QFX validation and the unfiltered iPhone picker; the user confirmed file selection on a physical iPhone on 24 October.
- Documented the statement-import revision, quiet session checks and deferred refresh while edits are unfinished.
- Added missing planning and Group Analysis coverage; retained separate ledger and planner semantics.
- Corrected manual first-user provisioning and the destructive scope of the fresh-schema command.
- Removed instructions to run PostgreSQL-only current code against a MySQL source. Migration exports must come from the old compatible application before upgrading it.
- Clarified current backup format 8, gzip versus encryption, HTTPS/passkey requirements, cache policy and historical taxonomy navigation.
- Labelled historical reviews so their old findings are not presented as a current release defect inventory. Individual findings remain subject to fresh reproduction; no blanket security or concurrency clearance is implied.

## Validation and release gates

Run `python3 scripts/check_docs.py` for local Markdown paths, page anchors and Bash-snippet syntax without executing the examples. The documentation pass also checks published Wiki link rendering, documented source paths and installer validation. Python 3.9+ and Bash are required for these documentation tools. Application tests for the deployed import-refresh change include the PHP suite, web-app policy/login-return tests and a Chromium regression covering quiet unchanged resumes, import-triggered refreshes, pending edits, deferred phone refresh and authentication checks.

The Pi installer has shell syntax, self-test and policy regression coverage in `tests/deploy_installer_test.sh`. These checks do not provision a Pi or contact package/certificate services. No fresh Bookworm/Trixie hardware installation or disposable PostgreSQL restore was performed in this documentation pass; PostgreSQL server tools are not installed in the local verification environment. ShellCheck is also unavailable locally, so its documented command remains an additional release check. The installer tracks `main`, not a release tag; a GitHub release does not automatically pin future installations.

Before a release:

1. Run the installer on the supported fresh 64-bit Raspberry Pi OS target(s) you intend to claim. Verify package installation, DNS, inbound ports, certificate issue/renewal, first login, Database Health and reboot recovery. Record the OS image and installed commit.
2. Create a full backup, restore into a disposable PostgreSQL installation and compare account/ledger totals, taxonomy, users, planner documents and identity-sequence behaviour. Test any legacy backup version claimed for migration with an actual representative export.
3. Complete the [physical iPhone checklist](Setup.md#physical-iphone-verification), including Home Screen behaviour, passkeys, TOTP AutoFill, session expiry, offline recovery and import-triggered refresh with a draft. The confirmed file-picker fix covers only that selection flow.
4. Run the relevant application regression suites and browser checks on the candidate commit, inspect failed CI, and review installation/upgrade notes. The repository contains legacy deployment workflows that call a site-specific `automated_deployment.php` endpoint absent from this checkout; do not assume those workflows demonstrate a healthy deployment. Application Updates is the documented deployment control.
5. Check both repository documentation and the rendered Wiki mirror. Identify remaining known issues and write release notes before choosing a version/tag.

No new release is created by this documentation update. Fresh-install, migration and physical-device evidence above remain explicit acceptance work, rather than inferred success from unit tests.
