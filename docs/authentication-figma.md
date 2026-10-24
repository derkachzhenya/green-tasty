# Authentication Figma implementation

## Audit and implementation plan (before edits)

The clean `feature/auth-figma-modals` branch already existed at `2bf58e9`.
A fresh fetch confirmed it equals the latest `origin/main`; existing work was
not reset or discarded. The official **Design for Developers → Registration /
Sign In Flow** (`110219:17356`) and the supplied screenshots were reviewed.
Screenshot annotations are reference evidence, not independent instructions.

Existing Fortify routes provide login, logout, registration, password confirmation
and email-link password recovery. Registration creates and signs in a user
immediately. Email verification is not enabled; there is no code persistence,
expiration or resend workflow. The password reset page currently serializes its
broker token into Inertia props. Named login throttling permits five requests per
minute. Existing session regeneration and encrypted private Inertia history must
be preserved. Booking and pre-order services/policies are outside this change.

The starter auth screens are full pages using Instrument Sans, a single name
field, generic inputs and Remember me. Approved screens are Poppins modals over
the main page, separate first/last names, requirement feedback, a single code
field, Back buttons and status notifications. The six-box verification component
is explicitly **Backlog** and will not be implemented.

Measured in Figma's inspection panel:

| Element                           | Verified value                                                       |
| --------------------------------- | -------------------------------------------------------------------- |
| Registration modal `111723:47477` | 576px width, 678px filled example height, 24px corners, 40px padding |
| Form `111723:47478`               | 496px content width, 32px group gaps                                 |
| Inputs group                      | 24px field gaps                                                      |
| Heading `111723:47482`            | Poppins 26px, weight 500, 40px line height, zero tracking            |
| Input                             | 44px height, 8px corners, 16px horizontal padding, white fill        |
| Focused email input               | 2px orange `#CC8B00` border                                          |
| Primary button `111723:47491`     | 56px height, 8px corners, green gradient `#0B5B29` / `#0F7A37`       |
| Action group                      | 16px gap                                                             |
| Modal surface / shadow            | `#F7F7F7`; `0 8px 40px #000000` at 18%                               |
| Overlay `111723:47239`            | Black at 45%                                                         |

Approved Change notes: modals replace full screens, no pre-header, close icon
16px from the top/right, Sign In wording, password guidance below the inputs,
right-aligned Forgot password, explicit recovery explanation, Back buttons,
code-sent notification, bold email in verification instructions, resend countdown
and a five-minute login lockout. No separate recovery Email Sent screen.

Implementation sequence:

1. Keep Fortify login/logout/registration actions and password broker. Add response
   adapters for verification and JSON redirects, preserving intended destinations.
2. Add one migration and a dedicated service for purpose-separated, session-bound
   verification/recovery challenges. Use random codes, keyed hashes, expiration,
   attempt limits, resend cooldown, row locks and single-use reset grants.
3. Require email confirmation after registration; unverified password login resumes
   verification. Successful verification returns to Sign In, matching the design.
4. Replace reset-link delivery in the UI with the code workflow, reuse Fortify's
   reset action/broker, and scrub already-issued legacy reset links into server
   session state rather than Inertia props. Never generate new secret-bearing URLs.
5. Build reusable modal/field/notification/password-guidance components over the
   real public listing; keep step switching in memory and clear secret inputs.
6. Add backend and Vue interaction regressions, run both database suites and all
   static/build checks, then inspect responsive browser screenshots.

Explicit implementation choices where Figma is not a backend specification:
codes have 64 bits of cryptographic randomness (16 hex characters in the approved
single field), a ten-minute lifetime, five attempts, and a 60-second resend
cooldown. Verification and recovery cannot authorize each other. New registration
and recovery passwords follow the approved 8–16 character, uppercase/lowercase,
number/symbol rules; existing-password login remains compatible. Production
breached-password checking remains. The existing settings password policy is not
redesigned. Names share the existing `users.name` field; no duplicate name schema.

Code emails must use a real configured delivery transport (or test-only array
transport), never a log or log-fallback mailer. Missing delivery configuration must
fail clearly rather than claim to send a code. No real email is sent by tests or
temporary browser fixtures.

## Implementation, verification and remaining differences

### Delivered flows

`auth/Flow` renders the existing real restaurant landing beneath one accessible
Reka dialog. Steps switch in memory without full-page reloads. Four unused starter
pages (Login, Register, ForgotPassword, ResetPassword) are replaced by this page;
password confirmation remains intact. Two-factor authentication remains disabled
and outside this feature; skipped tests do not establish two-factor readiness.

Reusable AuthModal, AuthField, PasswordGuidance and AuthNotification components
provide empty/filled/invalid/processing/success states, visibility toggles,
requirements, confirmation matching, credential errors, lockouts, Back/Close,
resend countdowns and network/session error recovery. Success after login is a
server-issued, dismissible Inertia flash notification. Failed requests preserve
non-secret input for correction; passwords/codes are cleared between steps, on
navigation and on page hiding. No auth secrets are placed in browser storage or
history props. Background content is inert during the modal; focus enters the
first field, loops inside, and Escape/Close return to the main page. Busy requests
prevent duplicate submits and accidental closure. Countdown timers do not make
screen readers announce every second.

Fortify still handles credentials, registration creation, guard/session behavior
and password reset actions/events. Registration now requires email confirmation
and returns to Sign In. Correct-password login by an unverified account resumes
confirmation without leaving an authenticated session. The Back action may edit
only the session-bound pending, unverified account; it uses transactions, validates
uniqueness, preserves resend throttling and invalidates the old generation. Names
continue to use `users.name`, without an unrelated schema change.

### Routes and security

| Method     | Route                                     | Behavior                                                          |
| ---------- | ----------------------------------------- | ----------------------------------------------------------------- |
| GET        | `/login`, `/register`, `/forgot-password` | Existing Fortify names, new modal page                            |
| POST       | `/login`, `/register`                     | Existing Fortify controllers and new response adapters            |
| POST       | `/forgot-password`                        | Generic recovery response and real code request                   |
| GET        | `/verify-email`                           | Session-bound verification modal                                  |
| POST       | `/auth/code/verify`                       | Purpose-bound, limited-attempt code verification                  |
| POST       | `/auth/code/resend`                       | Existing session challenge only; 60-second cooldown               |
| PATCH      | `/auth/registration`                      | Correct pending account details after Back                        |
| GET / POST | `/reset-password`                         | Verified server-session grant / Fortify password reset            |
| GET        | `/reset-password/{token}`                 | Compatibility exchange for previously issued broker links         |
| GET        | `/email/verify`                           | Read-only notice for existing unverified authenticated sessions   |
| POST       | `/email/verification-notification`        | Explicit CSRF-protected code request; ends the unverified session |

The new migration creates only `auth_challenges`; applied booking/pre-order
migrations are untouched. Challenge records contain keyed SHA-256 code hashes,
opaque generations, purpose, expiration, attempts and consumption metadata. Code
checks and reset grants use transactions and row locks. Failed attempts commit
before returning validation errors. Codes cannot cross sessions or purposes, and
new generations invalidate old codes. Password fingerprints invalidate grants
after an intervening password change. Reset reuses Fortify's broker, remember-token
rotation and PasswordReset event, consumes the grant, invalidates the guest
session and removes the account's existing database-backed sessions using the
configured session connection. Authenticated sessions also validate the stored
password hash on later requests. Recovery verification rotates both the session
ID and the server-only grant generation; pre-verification session data cannot
be upgraded into a reset capability. Resend and pending-account edits recheck the
locked user's email and password fingerprint, preventing stale registration
sessions from reauthorizing themselves after a password change. All private
booking, reservation, summary, submission and invitation-management routes require
a verified owner; anonymous invitation-token routes remain available.

Both known and unknown recovery requests enqueue the same `DeliverAuthCode` job.
The job implements Laravel ShouldBeEncrypted: the queue temporarily holds an
encrypted delivery payload, while the challenge table stores only hashes. Jobs
are dispatched after commit and suppress stale, consumed or expired generations.
SMTP processing occurs outside the public request in deployed environments,
avoiding the account-existence timing difference of synchronous delivery.
Transport failures invalidate that code and log only the exception class, never
the email, code, message body or exception trace. Manual resend can retry delivery.
Request and worker both reject log transports, including nested fallbacks and
transport overrides supplied through MAIL_URL. Unsafe configuration fails before
claiming a code was requested. Code-sent
notifications indicate dispatch to the configured delivery system, not a mailbox
receipt.

Normal CSRF middleware remains enabled on every modifying auth route. Auth pages,
responses and errors are private/no-store with no-referrer headers. Validation
never flashes code/password/token inputs. Public metadata is explicitly limited
to purpose, entered email, resend delay and verification state. No new secret
URLs are generated. Previously issued broker links inherently contain their old
token: the compatibility GET immediately consumes it and redirects to a clean
URL, without serializing it into Inertia. Old links may already be present in
historic access logs; this implementation cannot erase existing logs.

Five failed logins within five minutes trigger a full five-minute lockout starting
at the fifth failure, keyed by the database-resolved account identity and IP
(or normalized entered email for nonexistent accounts), then hashed. This shares
the limit across email spellings that MySQL's collation treats as equivalent. A separate lock bucket keeps
this duration independent of the earlier attempt window. Server Retry-After
controls the UI countdown; resend throttling is not mislabeled as a login lockout.
Code endpoints also have a 20-request/minute/IP ceiling and registration has a
10-request/minute/IP ceiling. These numeric attempt-window/IP choices are backend
assumptions; the five-minute lockout and 60-second resend are approved design notes.

### Figma comparison

| Screen/state                                        | Result / remaining difference                                                                                                                                                                     |
| --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Sign In empty/filled/required/incorrect credentials | Shared 576px modal, measured title/inputs/buttons, right Forgot password, visibility toggle and approved wording                                                                                  |
| Account locked                                      | Approved five-minute message, server-driven countdown and disabled submission                                                                                                                     |
| Registration empty/filled/errors                    | Separate names, single email, strength label beside Password, requirements beneath, confirmation error and Sign In switch                                                                         |
| Verification empty/filled/invalid/expired           | Approved single text field, bold email, Back, Confirm, code-sent status and resend countdown; Backlog six-box control excluded                                                                    |
| Recovery email/code/new password/success            | Back/explanation, shared code step, real server reset grant and return to Sign In; no deprecated Email Sent screen                                                                                |
| Success/error notifications                         | Dismissible semantic notifications; login success is confirmed by the backend; recovery copy is deliberately generic to prevent enumeration                                                       |
| Desktop measurements                                | 576px width, 40px padding, 24px radius, Poppins 26/40/500 title, 44px input, 56px primary action, 45% backdrop and measured shadow                                                                |
| Registration height                                 | Browser empty example is 680px versus the inspected filled Figma example's 678px; content is not clipped to a fixed height                                                                        |
| Mobile/tablet                                       | Responsive adaptation at 320/390/768px, 16px outer gutters, 24px mobile padding, stacked names at 320px, scrollable short-screen panel                                                            |
| Assets/background                                   | Existing seeded restaurant listing remains beneath the dialog; original Figma logo/food exports and popular-dish records are unavailable, so none are fabricated                                  |
| Icons/notification dimensions                       | Existing Lucide eye/close/status icons retained; exact original icon exports and toast dimensions were not available in the inspected properties; 416px desktop toast is an implementation choice |

Exact mobile design frames, original icon/food/logo exports and complete toast
properties would be needed to claim pixel parity. Recovery enumeration-safe copy,
visible focus rings, content-driven heights and usable hit targets are intentional
security/accessibility differences.

### Verification

Final checks passed:

| Check                                                                                                      | Result                                                       |
| ---------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------ |
| MySQL `./vendor/bin/sail composer run ci:check`                                                            | 353 PHP tests: 349 passed, 4 skipped; 3142 assertions        |
| SQLite `./vendor/bin/sail run env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= composer run ci:check` | 353 PHP tests: 336 passed, 17 skipped; 2994 assertions       |
| Vue unit/interaction tests                                                                                 | 149 passed across 11 files                                   |
| TypeScript / PHPStan / Pint / frontend lint and formatting                                                 | Passed, no lint warnings                                     |
| Production `./vendor/bin/sail npm run build`                                                               | Passed; existing optional Fontaine optimization warning only |
| `git diff --check`                                                                                         | Passed                                                       |

The four common skips are disabled Fortify two-factor features. SQLite also skips
thirteen MySQL-specific locking, migration and collation tests; those passed
in MySQL. Two auth concurrency tests exercise independent MySQL connections:
competing code consumption waits for a row lock, and challenge creation sees a
competing committed row despite an older repeatable-read snapshot. Existing booking, invitation, guest-selection,
consolidation, submission and ownership regressions remain green.

Coverage includes
registration/login/recovery, strict validation and Unicode password boundaries,
single-use/expired/incorrect codes, attempts, resend and full lockout timing,
legacy links, purpose/session/generation isolation, encrypted queued delivery,
SMTP failures, CSRF, session rotation/invalidation, anonymous-only endpoints,
real independent-connection MySQL locking and existing complete booking/pre-order
journeys. Vue tests cover switching, requests, validation, countdowns, duplicate
requests, failures, visibility, Escape/busy behavior and server login notices.

Browser review uses an isolated temporary SQLite database and synthetic accounts.
The final audit runs an actual database queue worker and loopback SMTP mail catcher.
Expired queued work was suppressed; real registration and recovery messages were
delivered by the worker and their codes entered through the browser. No mock code,
production account or externally delivered email is used. Screenshots were reviewed
at 1440×900, 768×900, 390×900 and 320×900, plus 390×844 and short 320×640. There is
no horizontal overflow. The 320×640 registration panel scrolls within its 608px
height. Focus containment, initial focus, close behavior, validation, registration,
real email-code confirmation, recovery-to-reset navigation, incorrect credentials,
server-confirmed login success, account settings navigation and logout were checked.
Actual password changes are covered by Laravel and Vue automated tests. The user
also completed the required browser password-change handoff and signed in with
the new password. The authenticated dashboard and consumed recovery grant were
verified afterward; see authentication-final-audit.md for details. The disposable
preview, worker and SMTP catcher were stopped and their private data removed.

### Deployment and local preview

Apply the single new auth migration during normal deployment; this task does not
run migrations against the local development database. Configure an actual SMTP
or mail API transport and a persistent server-side session driver. Log mailers,
log fallbacks and cookie-only sessions are rejected. Array mail is allowed only
in local/testing environments. Public environments require an asynchronous
database/Redis/SQS/Beanstalk queue; run a supervised `php artisan queue:work`
worker and monitor sanitized authentication-delivery warnings. Restart workers
when deploying configuration or code changes; null/unset MAIL_URL should be used
when configuring SMTP through individual variables (an empty URL is not equivalent
in Laravel's transport resolution). Keep APP_KEY safe
and stable so encrypted queued jobs remain decryptable. Use HTTPS and secure
session cookies in deployment. Establish a retention policy for expired challenge
records and normal queue cleanup before production operation. Invalidate
pre-upgrade non-database sessions that lack a stored password hash. Database
sessions are actively removed on reset; newly authenticated sessions pin the hash
at login. Limits are per account/IP, so deployment proxy configuration and
distributed-abuse controls still need operational verification.

Locally, after applying the migration intentionally and configuring a real local
mail catcher plus a worker, run Sail and the normal frontend development server;
open `/login` or `/register`, or use the Sign in header link. A local mail catcher
receives actual generated codes. No codes are rendered by the application or
printed to logs. Temporary browser-preview databases, scripts and outbox files
are removed after review; screenshots stay outside the repository.

No booking, invitation, reservation-policy or pre-order business logic was
changed. No dependencies, credentials or real emails were added. The audit was
completed before the separately authorized feature commit and push; no automatic
merge or deployment is included.

### Changed files

54 changed paths relative to the audit baseline, including new source/docs and four
deleted starter pages. Generated build output and temporary audit fixtures are
excluded. The independent security audit is recorded in
[authentication-final-audit.md](authentication-final-audit.md).

- `app/Actions/Fortify/CreateNewUser.php`
- `app/Actions/Fortify/ResetUserPassword.php`
- `app/Concerns/AuthPasswordRules.php`
- `app/Concerns/AuthRegistrationRules.php`
- `app/Http/Controllers/AuthFlowController.php`
- `app/Http/Middleware/AuthSecurityHeaders.php`
- `app/Http/Requests/AuthCodeRequest.php`
- `app/Http/Requests/AuthRecoveryRequest.php`
- `app/Http/Responses/AuthLockoutResponse.php`
- `app/Http/Responses/AuthLoginResponse.php`
- `app/Http/Responses/AuthRegisterResponse.php`
- `app/Jobs/DeliverAuthCode.php`
- `app/Models/AuthChallenge.php`
- `app/Models/User.php`
- `app/Notifications/AuthVerificationCode.php`
- `app/Providers/FortifyServiceProvider.php`
- `app/Services/AuthCodeService.php`
- `app/Support/AuthMailConfiguration.php`
- `app/Support/FiveMinuteLoginRateLimiter.php`
- `bootstrap/app.php`
- `config/fortify.php`
- `database/migrations/2026_10_23_190000_create_auth_challenges_table.php`
- `docs/authentication-figma.md`
- `docs/authentication-final-audit.md`
- `resources/css/app.css`
- `resources/js/app.ts`
- `resources/js/components/auth/AuthField.vue`
- `resources/js/components/auth/AuthModal.test.ts`
- `resources/js/components/auth/AuthModal.vue`
- `resources/js/components/auth/AuthNotification.vue`
- `resources/js/components/auth/PasswordGuidance.vue`
- `resources/js/components/restaurants/RestaurantLanding.vue`
- `resources/js/layouts/SiteLayout.test.ts`
- `resources/js/layouts/SiteLayout.vue`
- `resources/js/lib/authRequest.ts`
- `resources/js/lib/authValidation.test.ts`
- `resources/js/lib/authValidation.ts`
- `resources/js/lib/sessionJson.ts`
- `resources/js/pages/auth/Flow.vue`
- `resources/js/pages/auth/ForgotPassword.vue` (removed; replaced by auth/Flow)
- `resources/js/pages/auth/Login.vue` (removed; replaced by auth/Flow)
- `resources/js/pages/auth/Register.vue` (removed; replaced by auth/Flow)
- `resources/js/pages/auth/ResetPassword.vue` (removed; replaced by auth/Flow)
- `resources/js/pages/restaurants/Index.vue`
- `resources/js/types/auth-flow.ts`
- `routes/auth.php`
- `routes/web.php`
- `tests/Feature/Auth/AuthCodeConcurrencyTest.php`
- `tests/Feature/Auth/AuthCodeFlowTest.php`
- `tests/Feature/Auth/AuthSecurityAuditTest.php`
- `tests/Feature/Auth/AuthenticationTest.php`
- `tests/Feature/Auth/PasswordResetTest.php`
- `tests/Feature/Auth/RegistrationTest.php`
- `tests/Feature/EndToEndIntegrationTest.php`
