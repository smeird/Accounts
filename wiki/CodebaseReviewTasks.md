# Codebase review: proposed follow-up tasks

> Historical review. Findings below describe the code at the time of review, not a current release defect list. See [Documentation and release readiness](Documentation.md) for the 24 October 2026 validation scope and [Smart tagging](SmartTagging.md) for current classification behaviour. Old file/line references and proposed fixes must be rechecked before use.

As of 24 October 2026, setup uses an HTTP development server, upload selection no longer uses `isMacOS()`, and the PHP suite completes successfully. The old UA-specific upload tasks and early-exit claim below are superseded; they are preserved as review history.

## 1) Typo fix task
**Task:** Correct the local development URL scheme in the README from `https://localhost:8000/frontend/index.html` to `http://localhost:8000/frontend/index.html`.

**Why:** The server command shown is `php -S localhost:8000`, which serves HTTP by default, so the current URL appears to be a typo in the docs and can mislead setup.

## 2) Bug fix task
**Task:** Update `isMacOS()` so iOS user agents are not treated as macOS, and keep the macOS-specific multi-file upload branch only for real desktop macOS clients.

**Why:** The current detection checks only for `Mac OS X`, which is also present in iPhone/iPad user agents (`like Mac OS X`). That can trigger the wrong upload flow on iOS browsers.

## 3) Comment/documentation discrepancy task
**Task:** Align setup documentation to consistently instruct users to run pages through the local PHP server, rather than opening `frontend/index.html` directly from disk.

**Why:** Setup docs currently say to open `frontend/index.html` directly, but frontend scripts call backend endpoints (for example `../php_backend/public/landing_metrics.php`), which assumes HTTP serving from the project root.

## 4) Test improvement task
**Task:** Expand `frontend/js/upload.test.js` to cover edge cases for user-agent detection (iPhone, iPad, empty/undefined UA, and case-insensitive inputs).

**Why:** The current test only checks one macOS and one Windows user agent, leaving the known false-positive risk and fallback behavior untested.

## 5) Test runner bug fix task
**Task:** Refactor the link preview test in `tests/run_tests.php` so it does not include the authenticated endpoint file directly (for example, by extracting metadata parsing into a reusable function/class and testing that unit instead).

**Why:** Running `php tests/run_tests.php` currently exits before reporting PASS/FAIL lines because the `link_preview.php` include triggers API auth flow (`require_api_auth()`), which can terminate execution during test setup.
