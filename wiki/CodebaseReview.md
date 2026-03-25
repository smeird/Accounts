# Codebase Review (Repository-Wide)

## Scope and approach

This review covers the whole repository as of the current branch head, including:

- Frontend pages and shared JavaScript under `frontend/`
- Backend endpoints and domain models under `php_backend/`
- Entry/auth pages at the project root (`index.php`, `users.php`, `logout.php`)
- Existing tests under `tests/`
- Operational and product documentation under `README.md` and `wiki/`

The review is static (code and docs inspection) and avoids any checks that require a live database.

## High-level assessment

The project has a clear and pragmatic architecture for a PHP 7+ application:

- A broad set of focused backend endpoints in `php_backend/public/` enables feature-level iteration.
- Domain/model classes under `php_backend/models/` keep data access concerns in one place.
- Frontend utilities (`page_header.js`, `input_help.js`, `aria_tooltips.js`, etc.) show a move toward reusable UI primitives.
- Documentation is relatively strong, especially with architecture/request-flow Mermaid diagrams and dedicated wiki pages.

Overall, this is a feature-rich codebase with solid momentum, but it would benefit most from consistency hardening (security headers, endpoint conventions, and testing strategy standardization).

## Strengths observed

1. **Feature breadth is unusually high for the stack size**
   - Reporting, dashboards, exports, backups/restores, projects, recurring spend, AI tagging/budgeting, and 2FA are all present and integrated.

2. **Reusable frontend patterns are emerging**
   - Shared helpers for page headers, input help, tooltip behavior, and scroll animations are good foundations for reducing UI drift.

3. **Documentation quality is above average**
   - `README.md` includes architecture and request-flow diagrams.
   - The wiki already contains setup/architecture/review-oriented content and can support onboarding well.

4. **Testing hooks exist in both JS and PHP**
   - There is a dedicated JS unit test (`frontend/js/upload.test.js`) and multiple PHP tests in `tests/`.

## Key risks and gaps

1. **Endpoint-level consistency risk**
   - Many standalone endpoint files in `php_backend/public/` increase the chance of inconsistent authentication, validation, and error formatting.
   - Recommendation: define and enforce a minimal endpoint contract (auth, method checks, JSON envelope, error code policy).

2. **Mixed setup guidance can confuse local development**
   - Project docs include multiple local-running narratives (direct file opening vs server-backed flows), while many pages call backend endpoints.
   - Recommendation: standardize docs around one local workflow (serve from repo root via PHP built-in server or equivalent).

3. **Testing strategy is present but fragmented**
   - Tests exist, but coverage appears uneven across feature areas and endpoint behavior.
   - Recommendation: prioritize deterministic unit/service tests around parser, filters, and endpoint input-validation logic.

4. **Operational hardening opportunities**
   - Because the app is endpoint-heavy and handles auth, backups, exports, and AI calls, explicit hardening patterns should be documented and automated where possible.
   - Recommendation: centralize security and response helpers where feasible to reduce copy/paste divergence.

## Prioritized follow-up plan

### P0 (next 1-2 iterations)

- Create an endpoint checklist and apply it to all `php_backend/public/*.php` files:
  - Auth guard
  - HTTP method enforcement
  - Input validation and normalization
  - Uniform JSON success/error schema
  - Cache-control behavior for sensitive responses
- Resolve known documentation contradictions in local setup instructions.
- Expand upload UA detection tests to cover iOS and fallback cases.

### P1 (short term)

- Extract shared request/response utilities used by endpoints.
- Add targeted tests for non-DB logic paths (parser utilities, request normalizers, report filter parsing).
- Document API endpoint groups and expected payloads in wiki pages for maintainability.

### P2 (medium term)

- Introduce lightweight architecture boundaries:
  - endpoint/controller layer
  - service layer (business logic)
  - repository/model layer
- Add CI checks for:
  - PHP lint
  - JS unit tests
  - markdown/link sanity checks
- Define a small compatibility matrix (PHP version, browser assumptions, required extensions).

## Suggested review cadence

- **Per PR:** endpoint checklist + lint + targeted tests.
- **Monthly:** documentation drift pass (README + wiki + setup).
- **Quarterly:** architecture debt review of the top 5 most-changed modules.

## Notes

This review intentionally avoids running database-dependent tests or runtime database validation, per environment instructions.
