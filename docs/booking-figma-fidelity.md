# Booking Figma flow

Latest status: the **Final SSR acceptance (2026-10-25)** section below supersedes
the earlier browser blockers and pending-migration notes. Earlier sections remain
as the implementation and verification history.

Implemented on `feature/booking-figma-flow`, based on `origin/main` at `52f7523` (merged homepage PR #17). No commits, pushes, deployments, migrations, seeds or development-data changes were performed for this task.

## Implemented states

- Shared navigation links to `/book`; authenticated visitors also see Reservations and account settings. Existing restaurant-specific booking URLs still preselect the actual restaurant and remain protected by authentication and mandatory verification.
- Public search supports All Locations or one database-backed restaurant, date, exact start time or Show All, and guest count. The default layout uses the supplied large reserved-table photograph; validation is inline.
- Results contain real active tables, seating capacities, restaurant identities/addresses, and live half-hour slot availability. The summary reflects the applied filters. Real no-result searches use the supplied illustration and a working Show all slots action.
- Selecting an available slot opens Review & Confirm. Editing this draft rechecks availability. Nothing is persisted until Confirm.
- Guests and unverified users continue through the existing authentication/verification screens. A CSRF-protected POST stores a server-constructed local intended destination with non-secret selection hints. Returning fetches fresh availability before reopening review. No client-supplied redirect URL is used.
- Successful persistence redirects to the existing owner-only confirmation URL, opening a confirmation modal with saved details. It has real View Reservation and Make Pre-Order links and an explicit named-guest invitation form with copy feedback.
- Existing future confirmed reservations can be edited by their owner on the confirmation screen (also linked from the reservation dashboard). Time and party size are revalidated under locks; date, restaurant, table and duration stay fixed.
- The true zero-reservations state has the greeting/support strip, supplied dining illustration and Book a Table link. Loaded cards, deferred loading/retry, pagination and out-of-range pages remain separate.

## Data and security decisions

No display-only Figma address aliases, availability, ratings, occupancy figures, prices or table capacities enter booking APIs. The dropdown shows actual database addresses; if this environment still contains Demo Address 1–3, those remain visible. The previously documented guarded demo address seeder is not run here.

Availability uses active restaurants and active tables, capacity and half-open two-hour overlap checks. Daily slots are calculated in Asia/Tbilisi and returned without customer names, reservation IDs or guest data. Search uses eager queries rather than querying once per slot. Public search and authentication handoff share a 30-per-minute IP limit. Existing create and private availability limits remain in place.

Creation still locks the restaurant and selected table and rechecks conflicts. Editing takes the same restaurant/table locks, then locks the reservation parent and pre-order. It rechecks ownership, immutable restaurant/table association, status, future time, table activity, capacity, registered participant count and conflicts. It refuses changes after group pre-order submission. Selections, invitation tokens and order data are not rewritten. Transaction retries remain enabled.

All writes retain web CSRF protection. Create/edit and all private reservation/pre-order endpoints require authentication and verified email. Public browsing does not authenticate a user, send email or create a booking. GET confirmation never issues an invitation.

Invitations reuse the existing individual-guest service and API. A user explicitly supplies a guest name and creates a link; no phantom participant is inserted automatically. Only the hash is persisted. The raw URL lives in component memory, is cleared on navigation/page lifecycle events, and is never included in Inertia props/history or logs. Refreshing does not regenerate it; use existing invitation management to regenerate or revoke.

## Supplied assets reused unchanged

Under `public/images/green-tasty/`:

- `locations/Restaurant Image.png` — 928 × 832 default reserved-table photograph.
- `locations/Table image.png`, `Table image (1).png`, `Table image (2).png` — 428 × 180 reference dining-area crops, selected by canonical address or existing reference slug only for artwork.
- `locations/Empty State No tables.png` — 477 × 465 availability illustration.
- `locations/Empty State - No reservations.png` — 439 × 423 reservations illustration.
- `icons/phone-call.png` and existing logo.

Other real restaurants retain working cards and slots without borrowing an unrelated photo. No image bytes were changed or new imagery generated.

## Figma differences and visual verification

The supplied 11 screenshots were inspected as references. Live Figma properties and browser comparisons could not be obtained: the computer-use inventory returns no browsers and `Sky Computer Use native pipe startup failed`. No new rendered screenshots exist for this implementation, and pixel-perfect fidelity is **not verified**. Earlier homepage screenshots are not evidence for this booking flow.

Implemented desktop geometry targets a 1440px frame, 512px left panel, 40px panel padding, 24px result padding/gaps and two 428px cards. Form and modal corners, palette and Poppins typography reuse existing tokens. At tablet/mobile widths panels stack, cards collapse to one column where necessary, input/grid children have zero minimum width, and dialogs are constrained to viewport width/height with internal scrolling. Actual checks at 1440×900, 768×900, 390×844 and 320×640 remain blocked by browser connectivity, including screenshots, image crops, focus trapping and real sign-in return behavior.

Intentional differences to preserve current business rules:

- Reservations support **1.5 / 2 / 2.5 / 3 hours**, defaulting to 2 hours, with **30-minute** starts and a **90-day** advance window. The duration migration preserves existing intervals; see the [duration verification report](qa-2026-10-27/booking-duration-ru.md).
- No opening hours exist in the current model. Show All therefore includes all 48 daily half-hour starts, including disabled past/occupied slots, instead of inventing the screenshot's 10:00–21:30 opening hours.
- Backend guest limit remains 255 with real per-table capacity enforcement. The counter is not arbitrarily capped at four. Group support uses an actual selected restaurant phone when present; otherwise it asks visitors to contact their restaurant without displaying Figma's fictitious phone number.
- There is no implemented 30-minute cancellation policy or cancellation endpoint. Review explains confirmation timing rather than promising that policy.
- Links are per guest, not a shared anonymous group identity. The confirmation modal includes a name/create step and one-time link handling.
- Make Pre-Order opens the existing reservation's group pre-order section. There is no new owner menu/selection interface or fabricated route. Existing guest links continue to open the real guest menu and selections workflow.
- Confirmation is an owner-only persisted page with a modal over saved reservation details; it does not pretend that stale search results remain live behind it.
- The native date and select controls adapt to the platform. Backend errors, all-day slots and the extra security information can make cards/modals taller than the Figma samples.

## Review before commit (2026-10-24)

Confirmed and fixed during the independent review:

1. Availability fetches did not send `X-Requested-With`. Laravel's session middleware therefore treated the JSON endpoint as the previous page; a later collision validation redirect could land on JSON instead of the booking form. Requests now identify themselves as AJAX. Vue checks the header, and a Laravel regression verifies the previous URL and collision redirect.
2. Requests enforced 1–255 guests, but service calls lacked the same global guard and the `novalidate` forms relied on server feedback for invalid numbers. `BookingRules::MAX_GUESTS` and service validation now enforce the existing limit consistently; search, draft editing and saved editing reject non-integers, zero and excessive counts before sending. Actual table capacity is still enforced separately under lock. Editing additionally cannot reduce the count below existing participant records.
3. Reopening Edit cleared the warning after an uncertain network result, allowing another save against stale details. Reopening now preserves the block; only receipt of refreshed saved reservation data clears it.
4. Editing checked only the pre-order status string. A recorded `submitted_at` with a stale draft status could still be edited. Both the backend and `canEdit` now apply the same draft-and-not-submitted condition as the existing selection workflow; inactive restaurant/table controls are also hidden.

Slot rules were not changed. Search now calls the same `BookingRules::validateStart` used by creation for each daily start. A parity regression compares all **48 slots** on today, tomorrow and day 90 with both private availability and actual transactional booking attempts. It covers exact-now rejection, half-open overlap boundaries, midnight-spanning conflicts, blocking draft/confirmed and nonblocking cancelled/completed reservations. Each attempted test booking is rolled back in the isolated test database. Availability remains advisory: a competing reservation or elapsed time can invalidate a slot after the response, and confirmation rechecks it under the table lock.

The real JSON login path is covered end to end at the HTTP level: guest selection POST → existing login screen → JSON sign-in → intended URL with the same restaurant/date/time/guests/table → fresh search → explicit booking POST with the correct persisted IDs and UTC start. No reservation is created merely by logging in.

The existing invitation service is unchanged. It issues an individual participant identity; it does not increase the saved booking's `guest_count` or enforce an invitation quota against that number. If a strict attendance quota is required for invitations, that needs an explicit business decision and matching parent-locked checks in invitation creation/regeneration; this review does not introduce that new restriction into existing group workflows.

## Variable-duration implications (not implemented)

Figma offers **90, 120, 150 and 180 minutes**. The application currently persists only `reserved_at`; it has no stored end time or reservation duration. All existing reservations are interpreted as 120 minutes. A frontend dropdown alone would therefore misstate availability and confirmation times.

Supporting those options requires an approved backend/schema change, not a presentation adjustment:

- Add a persisted duration (for example unsigned small integer minutes with a 120-minute default/backfill) or an explicit UTC end timestamp, model casts and constraints. Preserve all historical reservations as two hours; do not reinterpret old rows when the global default changes.
- Validate the allowed duration in create/search/edit requests and services, serialize it into typed Inertia/API props, and use it for confirmation, dashboard and summaries. Duration changes require availability revalidation.
- Replace the symmetric fixed-duration conflict formula with variable interval overlap: `existing_start < requested_end AND existing_end > requested_start`. Eager-search conflict loading, SQL queries, cross-midnight handling and relevant indexes must be updated together.
- Retain restaurant/table/reservation locking and transaction retry protections. Test all 16 duration pairings, adjacent boundaries, collisions, concurrent create/edit and existing two-hour data on MySQL and SQLite.
- Decide duration-related opening/closing rules and any effect on cancellation, invitation expiry and pre-order deadlines before exposing promises in the UI. No opening-hours or 30-minute cancellation policy is currently implemented.

No such migration, data backfill or business-rule change was performed in this task.

## Verification

Final review results (2026-10-24), with isolated MySQL `testing` and in-memory SQLite:

- `./vendor/bin/sail composer run ci:check` — passed. MySQL: **401 passed, 4 skipped, 4010 assertions** (405 cases). Includes the real MySQL create/edit locking regressions.
- `./vendor/bin/sail exec -T -u sail -e AI_AGENT=codex laravel.test env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= composer run ci:check` — passed. SQLite: **387 passed, 18 skipped, 3853 assertions** (405 cases).
- Both CI runs: **192 Vue tests passed in 14 files**, TypeScript passed, lint/format checks passed (157 formatted files, 131 linted files), PHPStan passed with zero errors, Pint passed.
- MySQL's 4 skips are disabled Fortify two-factor features. SQLite additionally skips 14 cases requiring real MySQL locking, foreign-key index behavior or production email collation. Those driver-specific cases pass on MySQL.
- `./vendor/bin/sail npm run build` — passed after the last source edit. The existing optional `fontaine` optimized-font-fallback advisory remains nonblocking.
- `git diff --check` — passed. No tracked changes to `.env`, database files or supplied public images. Build and Wayfinder files remain ignored.
- Browser/Figma screenshot comparison — **blocked**, not passed; see the visual-verification limitation above.

The local application database was not reset or modified. No tests failed in the final runs.

New Laravel regressions cover public/All Locations search, table ownership, active/capacity filters, overlap boundaries, disabled past slots, validation, rate limiting, intended destination handling, mandatory verification, CSRF, owner-only edits, collisions, submitted/terminal/past reservations, participant retention and invitation behavior. A real MySQL locking test verifies that editing waits for a competing booking and rejects its committed conflict.

Vue interaction tests cover actual restaurant identity through search/review/POST, draft editing, guest/unverified handoff, restored selections, stale responses, failures and duplicate submits, persisted confirmation, invitation creation/copy/clearing, and zero-only reservations rendering. Existing authentication, homepage, invitations and group pre-order suites are rerun.

## Final acceptance attempt (2026-10-24)

The complete CI checks were rerun for this acceptance request, rather than relying on the preceding review: MySQL **401 passed / 4 skipped / 4010 assertions**; SQLite **387 passed / 18 skipped / 3853 assertions**. Both runs passed all **192 Vue tests in 14 files**, TypeScript, PHPStan, Pint and lint/format checks. A fresh production build also passed (3466 modules, 6.76 seconds); the optional `fontaine` advisory remains nonblocking. No application source or business rules needed changing during this acceptance pass.

Browser acceptance remains **blocked**. After the user reported reconnecting Chrome, another tool reset and inventory request still returned `apps: []`, `browsers: []` and `Sky Computer Use native pipe startup failed`. Explicit Chrome, built-in browser and native Chrome entry points were also unavailable during this attempt. This is a connection failure of the computer-use integration; it is not evidence of an application defect or of a successful browser test. No fresh screenshots were captured.

| Acceptance area                    | Evidence and remaining work                                                                                                                                                                                                                            |
| ---------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Restaurant, table and address      | Passing Laravel and Vue regressions cover real identities, all three canonical locations, persisted associations, saved confirmation/details and rejection of foreign tables. Repeat the complete path through My Reservations in a real browser.      |
| Availability and persistence       | Passing parity checks compare 48 slots on three boundary dates with creation; real MySQL create/edit lock tests pass. No development bookings were created.                                                                                            |
| Sign-in continuation               | HTTP and Vue regressions preserve selection, fetch fresh availability and require explicit confirmation. Browser sign-in/resume still needs observation.                                                                                               |
| Invitations and pre-order          | Existing service/HTTP tests and Vue tests cover explicit individual invitations, copy feedback, secret clearing and the real reservation group pre-order destination. Complete guest-link navigation and menu selection in the browser remain pending. |
| Errors and empty states            | Automated interactions cover validation, zero results, Show all slots, failed/stale requests and true zero reservations. Inspect their rendered appearance and announcements manually.                                                                 |
| Keyboard and responsive appearance | Labels, native controls, dialog primitives and viewport constraints were inspected in source. Tab order, visible focus, Escape, focus restoration, scrolling and absence of horizontal overflow are not browser-verified.                              |
| Figma comparison                   | All four requested captures (1440×900, 768×900, 390×844, 320×640) and side-by-side comparison remain pending. The fixed 120-minute duration and other intentional differences above remain unchanged.                                                  |

Final acceptance cannot be marked complete until those browser checks pass. Existing development records, authentication, homepage and group pre-order behavior were preserved; no commit, push or merge was performed.

## Changed paths

35 intended source, test and documentation paths (19 modified, 16 new); generated Wayfinder/build output is ignored. The pre-existing untracked `all-changes.diff` export is preserved unchanged and is excluded from this intended list. It is an extra pending path and should not be included in a future source commit.

- `app/Http/Controllers/BookingSearchController.php`
- `app/Http/Controllers/RestaurantBookingController.php`
- `app/Http/Requests/BookingAvailabilityRequest.php`
- `app/Http/Requests/BookingSearchRequest.php`
- `app/Http/Requests/UpdateBookingRequest.php`
- `app/Policies/ReservationPolicy.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/BookingRules.php`
- `app/Services/BookingSearchService.php`
- `app/Services/RestaurantBookingService.php`
- `docs/booking-figma-fidelity.md`
- `resources/css/app.css`
- `resources/js/components/bookings/BookingEmptyState.vue`
- `resources/js/components/bookings/BookingModal.vue`
- `resources/js/components/bookings/BookingSearchForm.vue`
- `resources/js/components/bookings/BookingSupport.vue`
- `resources/js/components/bookings/BookingTableCard.vue`
- `resources/js/components/bookings/EditBookingFields.vue`
- `resources/js/components/bookings/GuestCounter.vue`
- `resources/js/components/bookings/ReservationFacts.vue`
- `resources/js/components/restaurants/RestaurantLanding.test.ts`
- `resources/js/components/site/SiteHeader.vue`
- `resources/js/lib/booking.ts`
- `resources/js/pages/Dashboard.test.ts`
- `resources/js/pages/Dashboard.vue`
- `resources/js/pages/bookings/Confirmation.test.ts`
- `resources/js/pages/bookings/Confirmation.vue`
- `resources/js/pages/bookings/Create.test.ts`
- `resources/js/pages/bookings/Create.vue`
- `resources/js/pages/reservations/Show.vue`
- `resources/js/types/booking.ts`
- `routes/web.php`
- `tests/Feature/BookingFigmaFlowTest.php`
- `tests/Feature/RestaurantBookingConcurrencyTest.php`
- `tests/Feature/RestaurantBookingTest.php`

## Live browser acceptance after reconnecting Chrome (2026-10-24)

This section supersedes the earlier browser-connection blocker. Chrome and the
live Figma file were available in the continuation session. The existing feature
was inspected and exercised rather than reimplemented.

### Environment and isolation

The working branch remains `feature/booking-figma-flow`, based on `52f7523`
(merged PR #17), with the preceding uncommitted implementation preserved.
`all-changes.diff` remains untouched and excluded from the intended change set.

Sail's Laravel container and healthy MySQL 8.4 were running. Laravel and Vite's
client endpoint returned HTTP 200. Homebrew nginx still occupied IPv4 port 8080
and returned its default welcome page, while Chrome could reach Sail through the
other listener. Only that conflicting Homebrew nginx service was stopped; Sail,
MySQL and Vite were not restarted. The development database has a pending existing
`2026_10_23_190000_create_auth_challenges_table` migration. It was not applied
as part of this browser audit. Registration/verification/recovery against that
development database therefore need the normal migration before acceptance.
A previously created verified test account was confirmed to exist without reading
or exposing its password.

All browser booking, editing, invitation and selection writes used a separate
loopback server on `127.0.0.1:8093`, a newly created SQLite file under
`/private/tmp/green-tasty-booking-acceptance`, private storage, a separate session
cookie and an ephemeral key. Synthetic restaurant/table/menu fixtures and one
verified synthetic user were created directly in that isolated database. Existing
application migrations were applied only to this new empty SQLite file. No
production/demo-mode routes, authentication bypasses, supplied-image changes,
development database seeds or resets were introduced. The preview uses the
application's production build and ordinary login, CSRF, policies and services.

### Observed functional results

- Homepage and header Book a Table navigation reached `/book`. Empty date/time
  submission displayed inline validation. All Locations returned tables from all
  three synthetic restaurants; selecting Location 2, changing the date, choosing
  an exact time or Show All, and changing guest count updated the real results.
- Selecting a slot opened Review & Confirm. Draft editing changed 12:00/four
  guests to 13:00/three guests and rechecked availability.
- Sign In to Confirm opened the existing login flow. Ordinary password login
  restored Location 2, table 5, 27 October and 13:00/three guests in Review &
  Confirm. The isolated reservation count remained **zero** after login and
  visiting the genuine No Reservations state.
- Explicit Confirm created exactly one confirmed reservation: restaurant ID 2,
  table ID 5 (also owned by restaurant 2), three guests and
  `2026-10-27 09:00:00` UTC, corresponding to 13:00 Asia/Tbilisi. The persisted
  confirmation displayed the same restaurant address and table.
- Editing the saved reservation changed it to 14:00/four guests and persisted
  `2026-10-27 10:00:00` UTC while retaining its reservation, restaurant and table
  identities. My Reservations displayed those updated facts; View reservation
  reached the owner dashboard and its existing booking-change link.
- An explicit named invitation created one guest. Copy Link placed the exact
  issued URL on the browser clipboard (compared without printing the secret).
  Following it opened the actual guest menu. Saving one Garden Salad persisted
  a selection at 8.50; the owner consolidated group view then showed that guest,
  quantity one, subtotal 8.50 and grand total 8.50. Make Pre-Order reached the
  existing group section. The group order was not submitted in this audit.
- The saved two-hour booking disabled its seven overlapping half-hour starts
  (12:30 through 15:30); adjacent 12:00 and 16:00 remained available. A party
  exceeding every table's capacity produced the illustrated No Tables Available
  state. Its Show all slots action retained the genuine zero-result state.
- Review initially focused Edit. Tab from Close wrapped to Edit, Shift+Tab
  wrapped back to Close, and Escape closed the dialog and returned visible focus
  to the chosen slot. No reservation was created by those keyboard interactions.

### Browser-found visual corrections

1. Under the machine's dark appearance, portaled booking dialogs inherited the
   body's nearly white text despite having a white Figma surface. The scoped
   Green Tasty dialog now explicitly uses its foreground token (`#232323`). The
   rendered heading, facts, explanation and outline action were checked again.
2. At 320px, side-by-side time/guest controls squeezed the guest number between
   the two stepper buttons. Search and edit forms now stack those fields below
   400px. The saved-edit input measured 148px wide after the correction, with the
   value visible and the dialog scrolling internally when needed.

No backend behavior, booking duration, permission, database price or business rule
was changed by these corrections.

### Responsive and Figma evidence

Screenshots and DOM measurements were captured at **1440×900, 768×900, 390×844
and 320×640** for the default search, result cards, empty availability, review,
confirmation, editing and genuine empty reservations. Each measured page had
`documentElement.scrollWidth === innerWidth`; supplied images loaded. At narrow
sizes the long confirmation/editing dialogs use internal scrolling rather than
requiring wider pages. Viewport changes animate briefly because of the existing
dialog transition; final modal constraints were also checked after settling.

Live Figma frames inspected include the default (`112656:157169`), review,
editing (`112656:163262`), confirmed (`112656:163769`) and empty availability
variants. The default frame exposes 1440×900 and `#D9E5D9`. The reserved-table
photo, 512px form column, white form/dialog surfaces, Poppins, green actions and
supplied empty illustrations were compared directly. The documented functional
differences remain: fixed two-hour duration, all 48 daily starts, real table
capacities/identities, extra table/timezone facts, truthful confirmation copy and
explicit per-guest invitation creation. Those facts make some dialogs and cards
taller than the sample. Mobile layouts are responsive adaptations; no exact
mobile Figma counterpart or pixel-by-pixel image diff is claimed.

Evidence is outside Git under
`/private/tmp/green-tasty-booking-acceptance/screenshots/`; measurements are in
`responsive-checks.json` alongside it. Raw passwords, invitation tokens and keys
are not included in this report or screenshots.

### Checks after the browser fixes

- `./vendor/bin/sail composer run ci:check`: passed. MySQL **401 passed, 4
  skipped, 4,010 assertions** across 405 PHP cases; **192 Vue tests in 14 files**;
  TypeScript, PHPStan (zero errors), Pint, frontend lint and formatting passed.
- `./vendor/bin/sail npm run build`: passed after the final source change; 3,466
  modules. The existing optional Fontaine advisory remains nonblocking.
- `git diff --check`: passed.
- Browser warning/error capture for the isolated workflow: empty. The isolated
  Laravel log did not contain an application error. No external mail was sent.
- The initial host-side build could not find macOS Vite Plus native bindings;
  the existing Docker-installed dependencies work through Sail. Dependencies and
  lockfiles were not replaced to work around this local platform mismatch.
- The first CI attempt stopped on pre-existing Markdown formatting in this
  report; the report was formatted with the project's formatter and the full CI
  then passed. SQLite's earlier full CI results remain historical; a second full
  SQLite suite was unnecessary for these CSS/template-only changes. Browser
  persistence in this audit was nevertheless exercised on the separate SQLite
  database described above.

Functional browser acceptance of the existing booking flow is complete for the
scenarios above. Exact pixel equality to Figma is not claimed, and the existing
intentional business-rule differences remain documented. The pending development
authentication migration is an environment follow-up, not an applied audit change.
No commit, push, merge or deployment was performed.

### Cleanup and final environment check

The temporary preview server was stopped. Its synthetic database, ephemeral
credentials/key, private storage and preview scripts were removed; screenshots
and check summaries remain outside Git. The existing development counts stayed
unchanged: 6 users, 3 restaurants, 9 tables, 3 reservations and 5 participants.
No tracked `.env`, supplied public image or lockfile changes were introduced.
IPv4 `localhost:8080` now returns the Green Tasty page and Vite still returns 200.

After the final captures, the browser connection timed out during session cleanup
and a fresh inventory attempt also timed out. The completed browser observations
remain valid, but closure of the disposable tab and reset of the temporary viewport
could not be confirmed. This later tool outage is not an observed app failure.

## Final SSR acceptance (2026-10-25)

The branch is still `feature/booking-figma-flow` at `52f7523`, with the existing
uncommitted implementation and `all-changes.diff` preserved. Nothing has been
committed, pushed, merged or deployed.

### Development database safety

The existing `auth_challenges` migration was inspected and applied only after
confirming `APP_ENV=local`, the MySQL connection at host `mysql`, database
`laravel`, the compatible `users.id` key and the absence of the new table. Its
`up()` only creates the new table, its foreign key and unique index. A private
SQL backup was saved outside Git before application. Immediate before/after
hashes of all existing application rows matched exactly; no data reset or seeder
was run. The migration is now recorded as applied and its table is empty.

A later comparison detected a new development reservation and additional
participants created during the concurrent session. They were preserved. The
original reservation IDs 1–3 and participant IDs 1–5, and all other original
application rows, still matched the pre-migration hashes. Browser acceptance
writes used a separate SQLite file, separate session cookie and synthetic users;
its reservation targeted restaurant 2/table 5, distinct from the new development
reservation at restaurant 1/table 2. No attempt was made to restore the development
backup or remove concurrently created data. A final read-only comparison also
found a newly added user; every original user field still matched the private
backup, and the additional user was preserved.

### Original SSR errors and their cause

The Vite terminal's `SiteHeader.vue` trace was a real unsafe read of
`page.props.auth.user` when `auth` was absent. A real Inertia/Vue server-renderer
regression reproduced it. The header now falls back to guest navigation when
that optional runtime context is absent; backend authentication and verified-email
requirements remain unchanged. Three SSR tests cover missing auth, an explicit
guest and an authenticated user.

The later `reservations/Show.vue:101:47` trace was also reproducible, but not on
an ordinary authorized page load. `ReservationDashboardController` provides all
required props on that load: `reservation`, `canManageInvitations` and
`canSubmitPreOrder`; `groupOrder` is deliberately deferred. Both owner policies
run before the response is prepared. `Show.vue` keeps its required reservation
contract, including `reservation.restaurant.slug`.

The trigger was an HTML request carrying `X-Inertia-Partial-Component` plus
partial-data/exclusion headers. Inertia intentionally omits unrequested props,
but previously attempted to SSR that fragment as a complete page. The installed
`Inertia\Testing\ReloadRequest` used by `loadDeferredProps()` produces exactly
this kind of HTML partial request. This explains errors emitted into a running
development Vite terminal during PHP tests.

The application middleware now uses Inertia's conditional SSR gateway API to skip
prerendering partial responses, while respecting `inertia.ssr.enabled`. It does
not remove partial headers, force extra props into deferred responses, make
`reservation` optional, substitute fake data, or disable SSR for normal pages.
An initial header-normalization approach was discarded because it broke the
framework's deferred-prop testing contract.

Five new Laravel regression cases verify the full HTML SSR payload, both partial
header forms without an SSR HTTP call, and 403/404 responses before SSR. All 21
reservation-dashboard cases, including existing deferred summaries, passed.

### Actual HTTP and browser verification

The live checks used the existing Vite SSR engine and a separate loopback Laravel
server with synthetic SQLite fixtures. `inertia.ssr.throw_on_error=true` was set
only in that temporary preview, so SSR exceptions could not silently pass by
falling back to client rendering.

| Actual request                                          | Result                                                                                |
| ------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| Verified owner, existing reservation, ordinary HTML GET | HTTP 200; server-rendered HTML; all required reservation and permission props present |
| Partial HTML response                                   | HTTP 200 fragment; intentionally no full-page SSR; omitted reservation is expected    |
| Different verified user, existing private reservation   | HTTP 403; no private Inertia page or SSR rendering                                    |
| Owner, nonexistent reservation                          | HTTP 404; no private Inertia page or SSR rendering                                    |

In Chrome, the sign-in continuation was repeated on a fresh session: Escape
closed Review & Confirm after authentication; reopening also closed correctly.
After an explicit successful Confirm on another disposable reservation, Escape
closed Reservation Confirmed and left the saved reservation page visible.

The earlier acceptance in this continuation captured and measured all seven
booking states at **1440×900, 768×900, 390×844 and 320×640**: default form,
results/slots, review, edit, confirmation, empty availability and genuinely empty
reservations. Every measured page had `scrollWidth === innerWidth`, and all
images loaded. The later SSR change does not alter those layouts. Focus trapping,
reverse Tab, visible focus, Escape restoration to the selected slot, inline
required-field/guest validation and internal mobile modal scrolling were checked.
A full assistive-technology audit is not claimed.

The browser found and fixed a separate Escape failure after authentication:
Reka's document-level dismissal did not close the restored booking dialog, while
the close button did. BookingModal now handles Escape explicitly, as the existing
authentication dialog already does. Five component tests cover keyboard dismissal,
both Escape event paths, pending-operation protection and unrelated keys. The
fresh-browser checks above confirm the fix beyond those component stubs.

Real browser persistence was checked separately from visual rendering: Confirm
saved restaurant 2/table 5 at 13:00 Asia/Tbilisi (09:00 UTC); editing retained those
identities and saved 14:00/four guests (10:00 UTC). Its overlapping starts were
disabled, with adjacent boundaries available. My Reservations showed the saved
facts. A named invitation copied correctly, the guest saved a real dish, and the
owner's consolidated view showed quantity one and total 8.50. The group order was
not submitted. No emails were sent outside the temporary preview.

### Figma comparison and remaining differences

The live official desktop default, edit, confirmed and empty-reservations frames
were reopened; the filtered/review/empty-availability references from the preceding
live comparison were retained. Geometry, palette, Poppins, original photograph
and card crops, white dialog surfaces and action hierarchy were compared. The
provided images were not changed or replaced.

Exact pixel equality is not claimed. The deliberate differences remain: fixed
120-minute reservations, 30-minute starts, the 90-day window, Asia/Tbilisi,
all 48 daily starts without invented opening hours, real capacities/identities,
extra table/timezone facts and explicit individual invitation creation. These
make some surfaces taller than Figma. No fictitious cancellation policy, phone,
shared group token or variable duration was added. Mobile layouts are responsive
adaptations because matching mobile Figma frames were not available.

Evidence and sanitized check summaries are outside Git at
`/private/tmp/green-tasty-final-acceptance/`. The JSON browser measurements cover
all seven states at all four requested dimensions. Screenshots contain neither
passwords nor issued invitation tokens. The SQL backup remains private and is
not a deliverable or commit artifact.

### Final complete checks and cleanup

The final `./vendor/bin/sail composer run ci:check` completed successfully after
the final middleware change:

- MySQL PHPUnit: **406 passed, 4 skipped**, 410 cases and 4,051 assertions.
- Vue/Vitest: **200 passed** across 16 files.
- TypeScript (`vue-tsc --noEmit`), PHPStan (zero errors), Pint, frontend lint and
  formatting checks all passed.
- `./vendor/bin/sail npm run build:ssr` successfully built both the production
  client and SSR bundles. `git diff --check` passed.

The HTTP matrix above was repeated against the final middleware with SSR errors
configured to throw; it produced the same 200/partial-200/403/404 outcomes.
The full HTML controller contract is covered by an automated gateway test;
actual Vue SSR execution was additionally verified over HTTP, separately from
that mock. The MySQL suite covers authentication, reservation ownership,
availability/concurrency, invitation security, relationships and group pre-orders.
The complete SQLite automated suite was not repeated in this final SSR follow-up;
the isolated real-browser and HTTP persistence checks used SQLite.

Chrome's old test tab lost its debugger connection during screenshot capture.
It was closed through the native Chrome interface. A fresh test tab then verified
Escape again: the confirmation dialog disappeared and the saved reservation
remained visible, with no browser warnings/errors. Its screenshot is
`preview/screenshots/escape-after-confirmation-final.jpg` in the evidence directory.
The fresh test tab was also closed successfully. Existing user tabs were retained.
The earlier responsive test tabs were closed, so their temporary viewport
overrides did not remain on open test tabs.

The loopback preview server was stopped. Its disposable SQLite database, private
storage/sessions, synthetic credentials and bootstrap scripts were removed.
Screenshots, sanitized result files and logs remain outside Git; the original
private development backup was preserved. No development records were deleted or
restored. All existing unrelated Git changes and `all-changes.diff` remain in
place. There was no commit, push, merge or deployment.
