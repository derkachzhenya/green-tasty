# Pre-order cutoff and owner editing

> Historical report (2026-10-26). The T−45/T−30 retention rule described below was superseded on 2026-10-29: unconfirmed dishes now expire at T−30 while published restaurant confirmations remain. See [current implementation and verification](qa-2026-10-29/pre-order-cutoff-ru.md), including migration and scheduler requirements.

Verified locally on 2026-10-26, on `feature/consolidated-group-preorder-figma-owner`, starting from clean HEAD `e5a8061`. No commit, push, merge, deployment or development-data reset was performed. This report supersedes the earlier consolidated-group report for cutoff and personal-cart editing rules.

## Screenshot-to-feature checklist

| Supplied screenshot | Result                                                                                                                             |
| ------------------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| 01.38.58 / 01.39.01 | Existing Group Pre-Order modal and Edit my Pre-Order navigation reused. Final submitted groups retain existing immutable behavior. |
| 01.39.05            | Saved owner cart opens with Submit disabled until quantities differ from the saved selection.                                      |
| 01.39.09            | Existing owner menu, reservation strip, catalog and public images reused.                                                          |
| 01.39.12            | Existing dish preview and adding items reused; owner changes remain local until submission.                                        |
| 01.39.15            | Modified cart has Cancel Changes and Submit; cancellation restores saved quantities without a request.                             |
| 01.39.17            | Success follows the server response; View Reservations is available. Escape restores focus to the cart button.                     |
| 01.39.33            | Dashboard warning identifies the actual reservation and minutes remaining in the T−45 window.                                      |
| 01.39.37            | Modal/menu warning uses the same server timing and pending-selection definition.                                                   |
| 01.39.40 / 01.39.44 | At T−30 the group modal displays saved quantities and totals without edit/remove/submit. No new dashboard cutoff notification.     |

## Time and domain rules

The user explicitly chose **retain saved positions and block changes at T−30**, with warning copy describing the lock. Nothing is automatically canceled or deleted.

| Server time relative to reservation start | Behavior                                                                                                                                            |
| ----------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------- |
| Earlier than T−45                         | Existing editable flows; no time warning.                                                                                                           |
| T−45 inclusive to T−30 exclusive          | Warn only when an active participant has saved positions not submitted by that participant and the group remains draft. Remaining minutes round up. |
| T−30 inclusive and later                  | Reject every new pre-order mutation. Retain saved rows, notes, prices, markers and totals.                                                          |

`PreOrderTiming` compares absolute instants against the reservation start. Presentation remains Asia/Tbilisi. Tests cross UTC/local date boundaries. Individual submission and final group submission remain distinct; no restaurant approval or rejection status has been added.

The server supplies `server_now`, `warning_at`, `locks_at`, `locked` and `unconfirmed`. Open menus/group dialogs follow the snapshot plus monotonic elapsed time, updating once a second. Laravel checks the deadline again inside the mutation transaction. Changing the client's wall clock cannot authorize a write. Dashboard warnings refresh every 30 seconds and through the existing refresh path; appearance/removal can lag by that interval. An open dialog locks independently.

At cutoff, unsaved local changes revert to the last server snapshot, with an explicit announcement. Saved selections are untouched. A conflict requires refresh before another mutation.

## Owner cart and consistency

- The existing shared menu/drawer holds owner add/remove/quantity changes locally. Guest autosave remains intact. Cancel Changes sends no write.
- Owner Submit sends the complete personal draft in one versioned POST. A reservation lock, policy authorization, strict numeric `If-Match` validation and the existing selection service apply replacement and confirmation atomically.
- Existing lines retain historical prices and notes; new lines use current server catalog prices. Guest lines are unaffected. Totals are calculated on the server.
- An empty changed owner selection can be saved atomically and clears the owner's individual submission marker. Guest submission still requires a nonempty selection.
- A stale request with different quantities returns 409. An identical previously successful confirmed selection may return a read-only success, including after cutoff/group submission; it changes no timestamps or rows.
- Final group submission remains immutable. Existing opaque group-version checks, bearer-token scope, cross-reservation checks and confirmed-guest protections remain. Unsupported states stay read-only.
- No second cart, application database, migration, scheduler or restaurant state machine was added.

## Protected API paths

The existing owner selection PUT and submit POST, guest selection PUT, guest identity/name and submit POST, group quantity PATCH, group guest removal/submission, legacy group submission and invitation create/regenerate/revoke all enforce cutoff. Invitation revoke now follows reservation-first locking, matching selection/submission lock order.

Authorization/request validation still run; invalid credentials, scope or malformed payloads may reject before the cutoff check. Authorized valid new mutations at/after cutoff receive HTTP 409. Reads and successful read-only idempotent retries remain available.

## Automated verification

| Check                                                   | Final result                             |
| ------------------------------------------------------- | ---------------------------------------- |
| Complete MySQL PHPUnit suite (`testing`, not `laravel`) | 480 passed, 4 skipped; 4,777 assertions  |
| Complete SQLite suite (`:memory:`)                      | 464 passed, 20 skipped; 4,605 assertions |
| Vue suite                                               | 228 passed across 22 files               |
| TypeScript (`vue-tsc --noEmit`)                         | Passed                                   |
| PHPStan                                                 | Passed, zero errors                      |
| Pint                                                    | Passed                                   |
| Vite+ lint/format                                       | Passed                                   |
| Production client and SSR builds                        | Passed                                   |

Skips cover existing optional authentication-feature tests and MySQL-specific locking/concurrency tests excluded on SQLite. Database-specific coverage runs in MySQL.

New coverage verifies exact T−45/T−30 boundaries, timezone/date crossing, warning eligibility, every direct mutation path after cutoff, unchanged rows/totals, owner atomic save/empty selection, historical prices, stale versions, guest isolation, immutable submitted groups, read-only retries, cancellation and automatic local-draft restoration. Controlled clocks drive time tests. A modal regression test covers returning focus to a persistent cart after the submitting button unmounts.

Logs are outside Git under `/private/tmp/gt-cutoff-*.log`. Full backend checks ran after the last backend changes. Vue, TypeScript, lint and builds were rerun after the focus fix; only regression-test/documentation text changed after the final build.

## Verified in Chrome

Real Chrome extension, production-built client and local Inertia SSR, loopback-only preview with private SQLite fixtures and a controlled server clock. No development reservations were used for writes.

1. Dashboard and modal showed the correct reservation reference, warning and remaining minutes.
2. Saved owner cart showed 17.00 and disabled Submit. A local quantity change showed 34.00 while a database read still showed quantity 1 at 17.00. Cancel restored 17.00 without persistence.
3. Dish preview used the existing photo. Adding Spring Salad produced a local 36.00. Submit saved 17.00 + 19.00, retained the owner's note and guest's 10.00 line, and kept the group draft. Group modal total was 46.00. A further owner quantity edit also saved; unchanged resubmission stayed disabled.
4. Success appeared after persistence. Escape dismissed success and returned focus to the cart button; Escape also dismissed cart/group dialogs. Enter and clicks exercised actions.
5. A cutoff reservation retained both participants and total 27.00 with no edit/remove/submit controls.
6. An already open cart crossed T−30 without reload: unsaved quantity 2/34.00 reverted to saved quantity 1/17.00, announced the discard/retention rule and became read-only. Subsequent dashboard navigation showed no warning for that reservation; another reservation still within T−45 retained its warning.
7. Cart and read-only group views were visually inspected at 1440×900, 768×1024, 390×844 and 320×740. DOM measurements confirmed scroll width equals viewport width. Group footers stayed within the mobile viewport. Mobile quantity/remove/close targets measured 44px; cart actions measured 56px high and stack on mobile.
8. Final console contained no warnings/errors. Restarted SSR log contained startup messages only, with exception propagation enabled in the isolated preview.

Evidence in `/private/tmp/green-tasty-cutoff/`:

- `dashboard-warning-1440.png`, `warning-1440.png`
- `cart-saved-1440.png`, `cart-draft-{1440,768,390,320}.png`
- `owner-success-320.png`, `cutoff-{1440,768,390,320}.png`
- `before-live-cutoff.png`, `after-live-cutoff.png`

Direct API bypasses, exact instant assertions, cross-guest/cross-reservation rejection, duplicates, empty-owner persistence and concurrency were verified by automated tests, not claimed as browser scenarios. No screen-reader session or accessibility certification was performed.

## Data safety and remaining Figma differences

Read-only SHA-256 row snapshots of ten application tables matched before/after (`mysql`, database `laravel`): 9 reservations, 22 guests, 3 group orders, 14 items; other tables also unchanged. No reset, reseed or overwrite. `.env` and schema are unchanged. Private preview credentials/database/storage/scripts are removed after verification; screenshots and safe checksum evidence remain outside Git. Existing committed public images are reused, with no new assets required. Build outputs remain ignored.

Comparison used the supplied Figma screenshots; pixel-perfect fidelity is not claimed:

- Warnings describe locking and retained dishes, per the user's explicit choice, instead of mock automatic-cancellation wording.
- No fabricated restaurant-confirmed/rejected item badges. The schema supports individual and group submissions only. Saved-unit-price labels and truthful two-stage success copy remain.
- Existing decimal money formatting is retained; no currency is invented. Missing nutrition, ingredients and preparation-time data are not fabricated.
- Fixtures use actual two-participant orders instead of mock names/four participants/illustrative totals. Existing empty-participant behavior remains covered.
- Existing responsive menu and mobile stacked actions are retained; the supplied desktop reference does not specify exact mobile pixels. Unrelated pages were not redesigned.
- AWS deployment and restaurant approval are outside this task. Cutoff enforcement requires no scheduler.

## Changed-file inventory

New:

- `app/Services/PreOrderTiming.php`
- `app/Services/OwnerPreOrderService.php`
- `resources/js/composables/usePreOrderClock.ts`
- `resources/js/composables/usePreOrderClock.test.ts`
- `tests/Feature/PreOrderCutoffTest.php`
- `docs/preorder-cutoff-and-owner-editing.md`

Modified:

- `app/Http/Controllers/MyReservationsController.php`
- `app/Http/Controllers/OwnerPreOrderController.php`
- `app/Services/GroupPreOrderSubmissionService.php`
- `app/Services/GuestDishSelectionService.php`
- `app/Services/GuestInvitationService.php`
- `app/Services/GuestMenuService.php`
- `app/Services/GuestPreOrderService.php`
- `app/Services/MyReservationsService.php`
- `app/Services/OwnerGroupPreOrderService.php`
- `app/Services/ReservationCardState.php`
- `resources/js/components/bookings/BookingModal.vue`
- `resources/js/components/bookings/BookingModal.test.ts`
- `resources/js/components/guest/GuestCartDrawer.vue`
- `resources/js/components/reservations/OwnerGroupPreOrderModal.vue`
- `resources/js/composables/useGuestSelections.ts`
- `resources/js/lib/guestSelectionApi.ts`
- `resources/js/pages/Dashboard.vue`
- `resources/js/pages/guest/Menu.vue`
- `resources/js/pages/guest/Menu.test.ts`
- `resources/js/types/guest-selection.ts`
- `resources/js/types/owner-group-order.ts`
- `tests/Feature/OwnerGroupPreOrderTest.php`
- `tests/Feature/ReservationFigmaStatesTest.php`
- `docs/consolidated-group-preorder.md`
