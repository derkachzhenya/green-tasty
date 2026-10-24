# Authentication final security audit

Audit date: 2026-10-24 (Europe/Kyiv). Branch: `feature/auth-figma-modals`.
Audit baseline: `2bf58e9`. This report records the completed review before the
separately authorized feature commit and push. No merge, reset or development-data
migration was performed.

## Readiness

The confirmed security defects described below are fixed and have persistent
regression coverage. The automated checks and browser observations provide
evidence for the exercised flows, rather than a guarantee against every attack.
The manual browser password-change handoff is complete: the user reset the
password and signed in with the new password. The browser showed My Reservations
and a server-confirmed sign-in success notification. No functional audit blocker
remains in the exercised flows. Production readiness still depends on the
operational requirements below.

The interrupted security patch was reviewed as part of the same uncommitted
working tree, not a separately identifiable patch commit. The review covered the complete
implementation, not only the nine files mentioned in the request. There are
**54 changed paths relative to the audit baseline**, including new source/documentation
and the four deleted starter auth pages. The complete inventory is in
[authentication-figma.md](authentication-figma.md#changed-files).

## Confirmed findings and corrections

| Finding                                                       | Verified behavior before correction                                                                                     | Correction and persistent coverage                                                                                                                                                                                                                                                                                                 |
| ------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Stale registration capability after a password reset          | An old registration session could resend successfully after recovery changed the password, refreshing its fingerprint   | Resend and pending-detail edits lock and revalidate the bound user's current email/password fingerprint. `AuthSecurityAuditTest::test_stale_registration_session_cannot_reauthorize_itself_by_resending_after_a_password_reset` rejects both resend and account edits. Existing recovery tests reject stale verified reset grants. |
| Unverified sessions accessing private reservation features    | An existing unverified owner's private reservation GET returned 200                                                     | Verified middleware now covers booking, availability, reservation dashboards, consolidated summaries, submission and invitation management. The regression checks read/write rejection and absence of new guests/orders. Existing ownership policies remain in place.                                                              |
| GET verification notice had authentication side effects       | Opening the notice sent mail and logged the account out                                                                 | GET is read-only. An explicit authenticated, CSRF-protected POST requests the code and ends the unverified session. Backend tests check no GET mail/challenge/logout, real CSRF rejection and successful POST/code verification. Vue tests check no implicit request.                                                              |
| MySQL email aliases bypassing the login limit                 | After five failures, a collation-equivalent accented address could sign in                                              | Limiter keys resolve the same database user identity as Fortify. The MySQL regression first proves both spellings resolve to the same row, then requires 429 for the alias. Recovery separately refuses delivery to an unregistered collation-equivalent spelling.                                                                 |
| Existing sessions surviving password changes                  | Session invalidation depended only on database-session deletion                                                         | AuthenticateSession validates password hashes; successful login pins the hash immediately. Reset removes database sessions on the configured session connection. A regression changes the password independently and requires the old session to receive 401.                                                                      |
| Pre-verification session data could become a reset capability | The verified recovery challenge reused its generation                                                                   | Successful verification destroys the old session ID and rotates the recovery generation. A regression proves old session/grant rejection, new grant success, and correct reset-page navigation. Legacy-link exchange also rotates the session.                                                                                     |
| Worker configuration could differ from request configuration  | The transport guard was checked only before enqueue                                                                     | A shared guard runs in both request and worker. A queued-job regression changes the mailer before handling and requires no notification, invalidation of the code, and a sanitized warning.                                                                                                                                        |
| MAIL_URL could bypass the no-log guard                        | `log://localhost` overrode a declared SMTP transport and passed the request guard (200 instead of 422)                  | Guard uses Laravel's ConfigurationUrlParser and effective driver, including nested mailers and legacy configuration. Regression rejects direct and query-string log overrides, creates no challenge, and positively verifies that an SMTP URL remains allowed.                                                                     |
| MySQL repeatable-read challenge creation race                 | A competing committed challenge after an older snapshot caused a duplicate-key 500                                      | Insert-or-ignore followed by a locking current read sees the existing row and enforces cooldown. An independent-connection MySQL regression requires 429 and one row. Challenge and user locks use consistent ordering.                                                                                                            |
| Recovery error/navigation handling                            | Non-visible validation keys could hide delivery failures; refreshed verified recovery could show a consumed code screen | Vue maps non-visible errors to an alert; verified recovery navigates to reset. Persistent Vue/backend tests cover these cases. Malformed legacy email query arrays redirect safely without flashing tokens.                                                                                                                        |

The reported failures were reproduced before their corresponding fixes. One full
CI run also found a redundant nullable comparison in AuthFlowController; it was
corrected without changing behavior. No assertions were weakened or tests skipped
to obtain a passing result. The password-wiping Vue assertion now checks actual
input values rather than an absent property.

## Authentication and data-integrity review

- Fortify still validates credentials and creates users. Password recovery uses
  its broker, reset action, remember-token rotation and PasswordReset event.
- Registration and recovery purposes remain separate. Random 64-bit codes have
  keyed SHA-256 hashes, ten-minute expiration, five attempts, a 60-second resend
  cooldown and session/generation binding. Successful codes and reset grants are
  single-use. Failed attempts persist before validation errors are returned.
- Real MySQL tests cover competing code consumption and challenge creation under
  repeatable-read isolation. Existing booking and group-order concurrency tests
  also pass, including separate HTTP workers, locked guest updates, idempotent
  submission and rollback behavior.
- Known and unknown recovery addresses receive the same public response and
  enqueue encrypted jobs. SMTP latency is outside deployed HTTP requests.
  Registration's existing unique-email validation remains an account-existence
  signal; the implementation does not claim registration enumeration resistance.
- Queue payloads are encrypted with APP_KEY. The challenge table contains hashes,
  not raw codes. Jobs dispatch after commit, reject stale/consumed/expired
  generations and invalidate codes on transport failure. Failure logs contain
  only the exception class. Request and worker both reject log/fallback transports.
- Normal CSRF protection is retained. Auth pages/errors are private/no-store and
  no-referrer. Secret inputs are excluded from flashed validation data. No new
  code/token URLs or browser-storage secrets are introduced. Existing reset links
  are consumed once and redirected to a clean server-session grant; their
  historical URL exposure cannot be undone by this feature.
- Private routes still enforce ownership in Laravel. Anonymous invitation-token
  access intentionally remains independent of user registration. No booking,
  invitation-token, guest-selection, consolidation, submission or pricing service
  was changed. End-to-end and isolation regressions remain green.
- The single new migration creates auth_challenges with a unique email-hash/purpose
  key and nullable user foreign key. Previously applied migrations are unchanged.
  Both database suites build the schema. A guarded rollback/reapply of the new
  table was also checked on the empty MySQL `testing` database only.

## Final automated verification

| Check                                                                                               | Result                                                      |
| --------------------------------------------------------------------------------------------------- | ----------------------------------------------------------- |
| `./vendor/bin/sail composer run ci:check` (MySQL)                                                   | 353 PHP tests: 349 passed, 4 skipped; 3,142 assertions      |
| `./vendor/bin/sail run env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= composer run ci:check` | 353 PHP tests: 336 passed, 17 skipped; 2,994 assertions     |
| Vue component/interaction tests                                                                     | 149 passed, 11 files                                        |
| TypeScript                                                                                          | Passed                                                      |
| PHPStan                                                                                             | Passed, zero errors                                         |
| Laravel Pint                                                                                        | Passed                                                      |
| Frontend lint and formatting                                                                        | Passed, no warnings                                         |
| Production build                                                                                    | Passed; pre-existing optional Fontaine optimization warning |
| `git diff --check`                                                                                  | Passed                                                      |

Four common skips correspond to disabled Fortify two-factor features. SQLite's
additional thirteen skips require MySQL locking, migration behavior or collation;
they pass on MySQL. Two-factor authentication is not enabled or certified by this
audit. CI's existing workflow runs both database variants on pull requests and
pushes to main; it was reviewed and left unchanged.

## Browser, queue and SMTP evidence

The browser used production frontend assets, the real Laravel HTTP kernel and a
disposable SQLite database with seeded restaurants and synthetic `example.test`
accounts. A separate actual database queue worker delivered to a loopback-only
SMTP catcher. The catcher rejects recipients outside the synthetic domain. These
are real generated emails/codes, not Notification fakes or fixed codes.

Observed in Chrome:

- Registration persisted an unverified account and displayed the verification
  modal. An interrupted session expired safely; no private access was granted.
- A paused worker left an encrypted job queued without SMTP delivery. The worker
  suppressed the expired job. Sign-in correctly resumed verification.
- A preview-only empty MAIL_URL initially caused a sanitized delivery failure and
  invalidated that code. Correcting the disposable SMTP configuration and using
  the real Resend action recovered delivery. No development .env was changed.
- The genuine registration email arrived, its code verified the account, and the
  UI returned to Sign In with the creation-success notification.
- Incorrect credentials displayed the expected error. Correct credentials opened
  My Reservations with a server-confirmed success notice. Account settings and
  normal Fortify logout worked.
- Password recovery used generic account-existence-safe copy. A second real SMTP
  email arrived, and its code opened the new-password form. No code was printed
  in the report, exposed in props or placed in a URL.
- After the MAIL_URL guard fix, the worker was restarted on the final code. A
  fresh recovery email was delivered and accepted, renewing the reset handoff.
- **Manual handoff completed:** the user entered, confirmed and submitted the
  new password, then signed in with it. The browser showed My Reservations for
  Audit Browser and the server-confirmed success notification. A read-only check
  of the disposable database confirmed the recovery grant was consumed, its code
  hash and password fingerprint were cleared, and an authenticated session
  existed. Queue and failed-job counts were both zero. The password was neither
  requested in chat nor inspected. Replay rejection, remember-token rotation
  and session invalidation also remain covered by automated Laravel/Vue tests.
- Screenshots at 320x640, 390x844, 768x900 and 1440x900 showed no horizontal
  overflow. At 320x640 the panel is 608px tall and scrolls its 818px content. At
  desktop/tablet it is 576px wide; the desktop registration example is 680px tall.
  Initial focus, reverse-Tab containment/wrapping and Escape dismissal back to
  Sign in were checked. The viewport override was reset afterward.

After confirming the completed handoff, the audit tab was closed and the verified
audit-owned preview server, queue worker and SMTP catcher were stopped. The
disposable database, ephemeral key, raw test messages, private storage and preview
scripts were removed. Only screenshots remain separately under `/private/tmp`;
none of these artifacts are Git changes. Development data and services were
untouched.

## Remaining risks and Figma differences

1. Operational AWS/real-provider mail delivery has not been tested here. The
   browser reset and new-password sign-in are complete against the isolated local
   environment with genuine queued SMTP delivery.
2. Deploy the new migration, persistent server-side sessions, a real mailer and
   supervised asynchronous queue workers. Restart workers on deployment, monitor
   sanitized delivery warnings, protect/stabilize APP_KEY, use HTTPS/secure
   cookies and verify trusted-proxy configuration. SMTP receipt in a local catcher
   does not establish external inbox deliverability.
3. Invalidate pre-upgrade non-database sessions that lack a stored password hash.
   Newly authenticated sessions are pinned, and database sessions are actively
   removed during reset. Cookie-only sessions are intentionally rejected.
4. Login limits are per account/IP, not a global cross-IP account lock. Code and
   registration endpoints also have IP limits. Distributed abuse controls and
   shared production-cache behavior require deployment verification. In-flight
   mail cannot be recalled; a stale email delivered during a concurrent change
   cannot authorize a mismatched/consumed grant. Queue delivery can repeat a
   message, but the code remains single-use.
5. Establish production retention/cleanup for expired challenge rows, encrypted
   jobs and failed-job records. Disposable audit data has been removed.
6. The complete Figma audit lists measured desktop geometry and approved Change
   annotations. Original food/logo/icon exports, precise mobile frames and toast
   properties remain unavailable. The actual seeded listing remains behind the
   modal, Lucide icons are retained, toast width is an implementation choice, and
   height adapts to content (680px versus the 678px filled Figma example). Generic
   recovery text, visible focus/touch targets and the explicit read-only notice /
   POST Request Code step are intentional security/accessibility differences.
   Backlog six-box code entry is excluded. No new visual requirements were guessed.

No temporary scripts, test databases, credentials, generated test data, dependency
changes or unrelated business-code modifications are included in the Git diff.
