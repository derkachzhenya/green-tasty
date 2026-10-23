# End-to-end integration audit — 23 October 2026

## Scope and outcome

Branch: `test/end-to-end-integration`, created from freshly fetched `origin/main`
at `2aaf7dd` with a clean worktree. No commits, pushes or merges were made.

The connected booking → invitation → guest selection → owner submission journey
passes against MySQL. Authorization, historical prices and submission locking
remain intact. No application business rules, migrations, dependencies or frontend
production components were changed. This is an integration audit, not an AWS
deployment certification or final Figma acceptance.

One existing usability gap should be resolved before launch: the account dashboard
is still the Laravel starter placeholder and has no reservation discovery path.
Infrastructure security settings and repeatable browser CI also need follow-up.

## Changes made

| File                                                       | Purpose                                                                                                                                                                                                                                                                                             |
| ---------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `.github/workflows/tests.yml`                              | Preserve all-PR and main-push triggers; run full checks on SQLite and MySQL 8.4, with explicit PDO extensions and a runner-local disposable database.                                                                                                                                               |
| `tests/Feature/EndToEndIntegrationTest.php`                | Two connected HTTP journeys using real controllers, requests, policies, services and database writes. Includes registration/login recovery, booking, token issuance/rotation, multiple guests, removal/re-addition, mixed historical prices, submission retries, locking and reservation isolation. |
| `tests/Feature/GroupPreOrderSubmissionConcurrencyTest.php` | Add a database-name safety guard and a separate HTTP worker competing with an uncommitted guest update.                                                                                                                                                                                             |
| `tests/Support/submission-request.php`                     | Permanent regression-test worker, restricted to `APP_ENV=testing`, MySQL and database `testing`. Emits only lock progress, HTTP status and a synthetic test total.                                                                                                                                  |
| `resources/js/pages/bookings/Create.test.ts`               | Six booking component interactions: availability, capacity-choice state, network/empty recovery, accessible validation, stale response cancellation, duplicate confirmation and unconfirmed-save recovery.                                                                                          |
| `docs/end-to-end-integration-audit.md`                     | Audit evidence, limits and recommended next work.                                                                                                                                                                                                                                                   |

The test worker is intentional regression infrastructure, not a temporary preview
script. Temporary fixture/router scripts and the browser database were kept outside
the repository and removed after verification. No real credentials or invitation
URLs were added to source control. The workflow's `root` password is a public,
disposable runner-only test value, not an application or deployment secret.

## Automated results

| Command                                                                                                                                                                | Result                                                                                                                                               |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `./vendor/bin/sail artisan test --compact --filter=EndToEndIntegrationTest`                                                                                            | 2 passed, 176 assertions.                                                                                                                            |
| `./vendor/bin/sail artisan test --compact --filter='RestaurantBookingConcurrencyTest\|GroupPreOrderSubmissionConcurrencyTest\|PreOrderReservationUniqueMigrationTest'` | 9 passed, 120 assertions, real MySQL.                                                                                                                |
| `./vendor/bin/sail npm run test:unit -- resources/js/pages/bookings/Create.test.ts`                                                                                    | 6 passed.                                                                                                                                            |
| `./vendor/bin/sail composer run ci:check`                                                                                                                              | Passed: 290 PHP tests, 4 skipped, 2,330 assertions; 117 Vue tests across 7 files; frontend formatting/lint, TypeScript, Pint and PHPStan all passed. |
| `./vendor/bin/sail run env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= composer run ci:check`                                                                    | Passed: 281 PHP tests, 13 skipped, 2,210 assertions; all 117 Vue tests and all static checks passed.                                                 |
| `./vendor/bin/sail npm run build`                                                                                                                                      | Passed; production assets generated. Existing optional `fontaine` optimized-font-fallback advisory remains non-blocking.                             |
| `git diff --check` and local YAML parse                                                                                                                                | Passed.                                                                                                                                              |

The four common skips concern disabled Fortify two-factor authentication. SQLite
additionally skips nine MySQL-specific cases: four booking concurrency tests,
three submission concurrency tests and two foreign-key/index rollback variants.
Those nine cases actually ran and passed on MySQL; SQLite is not used as evidence
for row locking.

Initial failures while writing the new tests were corrected: the availability
test incorrectly supplied a table ID to a strictly validated read endpoint; the
lightweight Vue test host needed DOM constructor stubs for native `v-model`; and
two Vue mock render functions needed correctly typed children. These were test
authoring failures, not weakened assertions or changes to application behavior.
No failing application regression remains in the executed suites.

## User-journey coverage

| Scenario                                           | Evidence and result                                                                                                                                                                                                                                                                                                                                          |
| -------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Register, sign in, reject wrong password, sign out | Connected HTTP regression plus existing Fortify tests; registration and logout also exercised in Chrome. Pass.                                                                                                                                                                                                                                               |
| Public locations and menus                         | Seeded locations and real deferred Inertia data; active/inactive/category/dish filtering, missing slugs, query counts and restaurant isolation covered by listing/details suites. Browser listing/details pass.                                                                                                                                              |
| Availability and booking                           | Capacity, inactive/foreign tables, input validation, schedule boundaries, duplicate requests, CSRF and atomic owner-participant creation covered. Real browser created a persisted booking and followed confirmation to owner dashboard. Pass.                                                                                                               |
| UTC/local time                                     | Browser booked 24 October at 12:00–14:00 Asia/Tbilisi; MySQL stored `2026-10-24 08:00:00`. Midnight/window/adjacent-interval cases also pass in booking tests.                                                                                                                                                                                               |
| Owner-only dashboard/summary                       | Existing policy tests plus connected two-owner regression reject another owner's dashboard, summary, submission and cross-reservation guest binding. Anonymous callers cannot replace owner authentication with an invitation. Pass.                                                                                                                         |
| Invitations                                        | Creation, SHA-256 storage, hidden hashes, no-store/no-referrer, expiry/revocation/rotation and scoped owner mutation covered. Browser copy feedback, rotation, old-link rejection, revoked-link rejection and expiry clearing pass.                                                                                                                          |
| Anonymous guest menus                              | Stateless routes, JSON and HTML contracts, no guest user record required, no other guests or private booking/contact data. Browser menu loaded the invited guest's data and no other participant's selection. Pass.                                                                                                                                          |
| Save/update/clear selections                       | Server eligibility checks and price snapshots, quantities, identical retries, mixed valid/invalid atomicity, empty removal, other guests and other reservation isolation pass. Browser save and reload restore historical prices.                                                                                                                            |
| Consolidated order                                 | Exact decimal strings and per-guest lines are covered by service/controller/integration tests, including `0.10 × 3 + 0.20 × 2 = 0.70` and differing snapshots for one dish. Browser aggregate also matches persisted rows.                                                                                                                                   |
| Explicit submission                                | Real confirmation dialog, pending disabled state, successful owner POST, submitted status/timestamp and refresh verified in browser and Vue tests. Empty/forged/unauthorized/inconsistent orders rejected by PHP tests.                                                                                                                                      |
| Duplicate submissions                              | Repeated HTTP submissions return identical summary and original timestamp; no extra pre-order or items. Vue guards duplicate clicks and real MySQL tests serialize concurrent submission/edit operations. Pass.                                                                                                                                              |
| Post-submission locking                            | Browser left Bob with an unsaved draft, then submitted as owner. Bob's subsequent save was rejected and restored to the confirmed snapshot with editing controls removed. Update and clear both return 409 in HTTP tests.                                                                                                                                    |
| Error recovery                                     | Real browser server outage preserved the guest draft and displayed an unconfirmed-save error; retry after restart persisted it. Vue tests cover transport/validation/rate-limit/auth/unknown-outcome and retry states; Inertia deferred rescue tests cover failed summary/menu loads.                                                                        |
| Responsive/keyboard                                | Owner dashboard verified at desktop, 768px tablet, 390px and 320px mobile; guest menu/locked state at desktop and 320px. No horizontal document overflow in measured views. Confirmation opens on the safe cancel action, Shift+Tab remains inside the dialog, Escape cancels/restores opener focus, Enter submits, and success focuses the summary heading. |

## MySQL transaction evidence

The Sail suite uses the isolated `testing` database. Concurrency fixtures are
committed for independent connections rather than hidden in a test transaction.
Both destructive concurrency classes now refuse any other MySQL database name.
Run these classes serially, never against a development database or a shared
parallel testing database.

Booking tests hold a real table-row lock while a competing HTTP application
worker attempts a booking. They verify lock timeout/rejection after commit,
availability after rollback and a locking current read despite an older
repeatable-read snapshot. Booking and owner guest creation also roll back
together on a simulated participant exception.

Submission tests hold the reservation lock during guest replacement and during
submission. Contenders hit MySQL lock timeout until the holder commits. The new
independent PHP HTTP-kernel worker starts submission while the guest update is
uncommitted, then returns `37.50` for the newly committed quantity of three at
`12.50`. A subsequent owner retry returns the same order and a guest clear is
rejected. The lock order remains reservation → guest/order/items as appropriate;
application code was not changed.

Migration tests create randomly named separate databases, exercise unique-index
enforcement and foreign-key supporting-index rollback/re-migration, then drop only
those generated databases. Existing applied migration files were not changed.

## Browser execution and isolation

Chrome automation exercised the real compiled production frontend and Laravel
HTTP/CSRF/session stack on localhost. It used a separate MySQL 8.4 Docker
container, with an in-memory database volume, loopback-only port and no mounted
development data. A guard verified the audit database name, driver and port
before migration/seeding. Only the demo restaurant seeder ran; users, booking,
invitations and selections were created through the UI. Test-only SQL simulated
a menu price change and invitation expiry, and inspected persisted UTC/snapshots.
No real emails were sent. Existing Sail, MySQL and Vite services were preserved.

A second account was also registered through Chrome. Opening the first owner's
reservation returned a generic 403 page without reservation or participant data.
Direct registration reproduced the starter account-dashboard navigation gap.

The final browser order contained Alice's three salads at `8.50` and one main at
`14.00`, plus Bob's one salad at `9.75`: salad subtotal `35.25`, Alice `39.50`,
Bob `9.75`, grand total `49.25`, five items. MySQL retained these snapshots after
submission and rejected stale edits.

Browser checks were executed through interactive Chrome automation during this
audit. They are **not a checked-in unattended browser test suite** and do not run
in GitHub Actions. The Vue component tests use the repository's lightweight Vue
host, which does not prove native browser focus/layout. Keep these distinctions
when interpreting the green CI result.

Local proof screenshots were retained outside the repository:

- `/private/tmp/green-tasty-audit-confirmation.png`
- `/private/tmp/green-tasty-audit-submitted.png`
- `/private/tmp/green-tasty-audit-submitted-mobile.png`

Temporary preview scripts, the local preview process and its disposable MySQL
container were removed after the audit. Screenshots contain synthetic fixtures.

## CI review

The workflow already ran on every `pull_request` and every push to `main`.
It ran full static/unit/feature checks, and `composer setup` included the
production frontend build. However, its SQLite-only default skipped all actual
MySQL locking and foreign-key rollback coverage.

The updated matrix explicitly runs SQLite in memory and MySQL 8.4 `testing`,
with a healthy runner-local MySQL service. Full checks and the production build
run in both jobs. Permissions remain `contents: read`, actions remain pinned,
checkout credentials are not persisted, and no deployment secrets are required.
The MySQL root account exists only in the ephemeral runner service, allowing the
existing isolated migration regression to create/drop its own generated schemas.

The workflow YAML parses locally and both database configurations pass locally.
No GitHub Actions run for this unpushed branch was triggered or observed. Verify
both matrix jobs remotely when a PR is eventually opened; update required-check
names in branch protection if needed. Branch protection itself was not changed.

## Findings and recommended follow-up

| Priority             | Finding or limitation                                                                                                                                                                                                                                                                                                                                                   | Recommended action                                                                                                                                                                                                                                                           |
| -------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Before launch        | **Returning-user reservation discovery is unfinished.** `My account` opens `resources/js/pages/Dashboard.vue`, a starter placeholder with external starter-kit links and no list/link to owned reservations. Direct registration without an intended booking also lands here. Confirmation → reservation works, but returning owners must retain their reservation URL. | Add an owner-scoped reservations index and clear navigation back to locations; add a fresh-login → existing reservation browser regression. This is a separate UI feature, not silently implemented during the audit.                                                        |
| Before deployment    | Invitation URLs are bearer credentials. App responses use no-store/no-referrer/noindex and hashes are hidden, but reverse-proxy/load-balancer/APM access-log configuration is outside this repository audit.                                                                                                                                                            | Enforce HTTPS and redact `/invitations/*` paths before logs/analytics capture them; verify with a synthetic token on staging. Never rely on PHP `SensitiveParameter` alone to sanitize infrastructure request URLs.                                                          |
| Before deployment    | AWS TLS/proxy/cookie/database/cache settings were not deployed or tested. The browser fixture uses local mode and test transports, not production infrastructure.                                                                                                                                                                                                       | Verify `APP_ENV=production`, `APP_DEBUG=false`, correct HTTPS `APP_URL`, secure cookies, trusted proxy/IP handling, private database networking, least-privilege runtime DB credentials, shared persistent session/rate-limit storage, backups and health checks in staging. |
| Follow-up            | Browser journeys are not yet unattended CI tests; Safari/Firefox, real touch devices, screen-reader behavior and load/soak testing were not covered.                                                                                                                                                                                                                    | Add a maintained browser test job with isolated seeded fixtures and artifacts; retain this audit's flow as the initial scenario. Do not describe Vue host tests as browser coverage.                                                                                         |
| Product confirmation | Asia/Tbilisi timezone, 120-minute duration, 30-minute slots and 90-day window are existing documented defaults; no separate stakeholder acceptance source was found in the repository. All-day booking is allowed because opening-hours data does not exist.                                                                                                            | Confirm these defaults before final Figma approval; keep them unchanged until requirements are agreed.                                                                                                                                                                       |
| Product confirmation | Existing Figma notes describe restaurant acceptance and an editing cutoff. Current backend immediately locks guest selections when the owner submits; there is no restaurant-acceptance or reopening workflow.                                                                                                                                                          | Keep submitted UI wording aligned with actual behavior; agree any acceptance/cutoff feature separately. No behavior changed here.                                                                                                                                            |
| Existing behavior    | Owner summary is a saved snapshot, without live updates. Submission includes the latest committed selections at lock acquisition, which can differ from the last displayed preview. Unknown network outcomes require reading current status before retry.                                                                                                               | Preserve clear confirmation/recovery wording; add revision confirmation only if the product explicitly requires freezing the reviewed version.                                                                                                                               |
| Existing behavior    | No currency schema exists. Displayed monetary strings have two decimals but no invented currency symbol. Retained dishes preserve historical prices; a removed-and-saved dish re-added later uses the then-current menu price.                                                                                                                                          | Confirm currency/product copy before refinement; preserve current snapshot rules unless requirements change.                                                                                                                                                                 |

No security authorization regression, double booking, floating-point total error,
cross-reservation selection leak or post-submission write was reproduced in the
executed scenarios. The usability gap and unverified deployment/browser coverage
remain explicit limits; passing tests alone are not release approval.
