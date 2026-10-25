# Reservations dashboard and guest pre-order states

Implemented from the nine supplied EPAM Figma screenshots (October 25, 2026,
22:51–22:53). This extends the existing application; it does not replace booking,
authentication, guest invitations, or consolidated group submission.

## Branch and data safety

Work remains on `feature/guest-preorder-figma-flow`. At the start, HEAD was
`322a1b7`; the available `origin/main` reference included merge `6cf2b94`.
Four supplied PNG icons were untracked. They were preserved, and no branch
switch, reset, commit, push, merge, or deployment was performed.

The local target was verified as environment `local`, connection `mysql`, database
`laravel`. The only pending migration was
`2026_10_26_000000_create_reservation_feedback_table`. Its SQL was reviewed with
`migrate --pretend`; it creates a new table with a unique reservation foreign key
and restrictive deletion. It neither alters nor backfills existing business rows.
Only that migration was applied. No seed, database reset, truncation, or fixture
was run against development data.

Before/after row hashes and counts matched for all existing tables: 8 users,
3 restaurants, 9 tables, 12 categories, 30 menu items, 8 reservations, 19 guests,
2 group orders, and 7 order lines. Hash-only evidence is outside Git in
`/private/tmp/green-tasty-reservations-states/data-preservation.json`.
The new feedback table is initially empty in development.

## Dashboard and card state contract

`/dashboard` keeps its route name, session/verified middleware, owner policy,
private no-store response, encrypted Inertia history, deferred loading/recovery,
and six-item pagination. All queries constrain ownership from authentication.

Validated `status` filters are `all`, `upcoming`, `in_progress`, `completed`, and
`canceled`. Counts cover all owned records, independently of pagination/filter.
Pagination retains only the validated filter; client IDs/tokens are discarded.
The greeting, existing support component, status navigation, pastel strips,
address/date/time/guest facts, and contextual actions follow the references.
The grid uses three columns on desktop, two at 768px, and one on narrow phones.

`ReservationCardState` maps stored statuses without updating them:

| Stored state                                            | Presentation                                |
| ------------------------------------------------------- | ------------------------------------------- |
| `cancelled`                                             | Canceled tab, Cancelled card                |
| `completed`                                             | Completed                                   |
| `confirmed`, before start                               | Upcoming / Reserved                         |
| `confirmed`, from start until the existing two-hour end | In Progress                                 |
| `confirmed`, at or after end                            | Completed                                   |
| `draft`                                                 | Upcoming / Draft                            |
| Other historical status                                 | Upcoming, with ordering actions unavailable |

Time comparisons use persisted UTC timestamps; displayed facts retain
Asia/Tbilisi and the established midnight/end-date behavior. Refresh/revisit
updates presentation; no live timer or polling was added.

Submitted progress counts distinct reservation participants with saved lines in
that reservation's order and a non-null confirmation timestamp. Unsent drafts
never count. Legacy finalized group orders also count their persisted guest
lines, even if created before per-guest confirmation timestamps existed. Counts
are not clamped to a fabricated capacity: historical over-capacity data remains
visible accurately.

A single-person booking has no invitation action. Upcoming multi-person bookings
show invitations while the group remains editable and progress is incomplete.
Add Pre-Order appears before the owner's own confirmation. Group Pre-Order
appears after owner confirmation, or for an already finalized group. Closed
cards expose neither ordering nor invitation controls. A canceled card preserves
its details. Address links keep the existing owner management page accessible.

There is no trustworthy persisted unread/change event source, so no orange
notification dots are fabricated. No cancellation/delete endpoint existed;
no decorative destructive action was introduced.

## Owner cart and group order

The existing guest menu, cart, selection validation, money arithmetic, and locking
engine are reused for `/reservations/{reservation}/owner-pre-order`.
The owner endpoint uses the authenticated owner's existing participant record;
it never creates or exposes a guest bearer URL. GET does not create an order.
Normal web middleware, cookies, CSRF, rate limits, model binding, and policies
remain enabled. Other owners, registered participants, and bearer-only visitors
cannot use it.

Both owner save and confirmation require the existing strong quoted `If-Match`.
The request only accepts validated dish IDs and quantities; client prices,
participant IDs, and cross-restaurant dishes are rejected. Shared selection code
retains saved unit prices, server totals, parent-first locks, version conflicts,
and replacement of only the caller's lines. Owner confirmation is idempotent and
does not finalize the group. Editing through the group dashboard clears only the
owner's confirmation when contents change; explicit confirmation restores it.
Guest confirmations remain immutable.

After confirmation the owner returns to the existing consolidated group view.
Guest and owner lines, quantities, saved prices, and totals remain visible there.
Final group submission uses the existing endpoint and service and locks edits
for everyone. Refresh now updates invitation/edit permissions along with the
summary, so stale action controls disappear after submission.

## Invitations

The dashboard modal shows real reservation facts and reuses
`useGuestInvitations`, existing owner endpoints, secure random token issuance,
and clipboard/error handling. A guest name is required before generating a
personal link. A copied link announces **Link copied!** The modal has an
accessible title/description/close control, focus containment, Escape, and focus
restoration. Create/regenerate now recheck eligibility under the reservation
lock; closed or finalized groups cannot issue new links. Revocation and existing
bearer validation continue to work.

The stored token is a one-way hash. A previously issued raw URL cannot be
recovered. A new URL is held only in local component memory while the modal is
open; closing/navigation/unmount clears it. It is absent from list/deferred
props, storage, notifications, and logs. Existing invitation management remains
the place to regenerate/revoke previously issued links. No generic shared link
replaces guest isolation.

## Feedback

There was no persisted feedback implementation to reuse. The additive
`reservation_feedback` table stores one response per reservation: restaurant
rating and service rating, each validated from 1 through 5, plus an optional
comment up to 2,000 characters. Only the reservation owner can read or update
it, during or after the visit. Future and canceled requests return 409.
Authorization is repeated after locking the reservation on save. Repeated saves
update the same row rather than duplicating feedback.

Cards show Leave Feedback until a response exists, then Update Feedback. The
modal loads existing values and saves through the ordinary session/CSRF helper.
No waiter identity or employee relationship was invented: the current schema
has none. Public review aggregation and waiter assignment remain outside scope.

## Guest screen refinements

The existing original photographs, empty basket, quantity controls, persisted
cart, fixed drawer footer, confirmation, and concurrency recovery are retained.
A keyboard-accessible dish photograph opens a horizontal desktop preview that
stacks on phones. It uses the actual menu description and price, the established
image/weight metadata, and the existing ordering action. Sold-out/locked dishes
cannot be added from the modal.

No demo `$15`, invented currency, preparation time, or nutritional claims are
used in live ordering. Nutritional fields are not present in the current menu
schema; the modal asks the restaurant for details when a description is absent.
The immutable guest confirmation shows Create New Pre-Order disabled with an
explanation. Existing rules do not allow a second guest submission; those rules
were preserved rather than enabling a misleading action from an illustrative
Figma variant.

## Verification

Automated checks (final application code):

| Check                                                  | Result                                   |
| ------------------------------------------------------ | ---------------------------------------- |
| Complete MySQL PHP suite                               | 464 passed, 4 skipped; 4,605 assertions  |
| Complete SQLite PHP suite                              | 448 passed, 20 skipped; 4,433 assertions |
| Complete Vue suite, including new SSR regressions      | 215 passed in 19 files                   |
| TypeScript / PHPStan / Pint / frontend lint and format | Passed; PHPStan 0 errors                 |
| Production client and SSR builds                       | Passed                                   |
| `git diff --check`                                     | Passed                                   |

PHP coverage adds status boundaries, owner-scoped filters and counters, 4/4 and
legacy progress, excluding drafts, owner/participant/cross-restaurant isolation,
mandatory/stale versions, CSRF, authoritative and historical prices, repeated
confirmation, preservation of guest lines, closed invitations, final group locks,
and feedback eligibility/update uniqueness. Existing invitation, booking,
consolidated-summary, guest cart, idempotency, and race/locking tests also pass.
Vue coverage adds server-provided card actions/counters and SSR without deferred
props, alongside the existing guest submission/conflict suite. The skips are
existing disabled-authentication and database-driver-specific cases.

Browser verification was performed in Chrome using production assets and the
actual Laravel routes, session/CSRF middleware, services, and a private temporary
SQLite database outside the repository. Test owners, legitimate future bookings,
and isolated persisted historical-state fixtures were used only for verification;
no mock card states, preview users, or fixtures are shipped in application code.
The development database was not used for test mutations.

Actually verified in Chrome:

- Dashboard at 1440, 768, 390, and 320px: three/two/one/one columns, six persisted
  cards, correct active filters/counters, no horizontal overflow or broken images.
- Invitation creation for the correct reservation, clipboard success, name prompt,
  real details, modal fit at all four widths, Escape and return focus.
- Guest identification, original dish photo, actual price 17.00, horizontal/stacked
  preview at all four widths, adding via the preview, cart quantity change to two,
  total 34.00, keyboard submission, and immutable saved confirmation.
- Empty drawer at all four widths: 640px desktop/tablet and full-width phone,
  original basket, Go to Menu, removal back to empty, Escape and return focus.
- Tab focus remained inside the drawer. The owner's cart button was additionally
  corrected and measured at 44 × 44px on the actual 320px viewport.
- Owner progress changed from 1/4 to 2/4 after confirming a separate 10.00 item.
  Group navigation showed both participants, saved prices, three units, and 44.00.
  Final submission retained 44.00, and edit/invitation actions disappeared after
  refresh and a full reload.
- Feedback persisted restaurant 5/5 and service 4/5 with its comment, reopened
  with those saved values, changed the card to Update Feedback, and closed with
  Escape/restored focus.
- Final Dashboard and reservation detail SSR reloads completed with strict SSR
  error reporting enabled. Final SSR log and browser console had no application
  errors. During a test rebuild, an older SSR process could not find obsolete
  chunk filenames; restarting it against the final bundle resolved that deployment
  lifecycle issue. SSR processes must be restarted after replacing build files.

Authorization failures, invalid/nonexistent requests, stale/omitted If-Match,
malicious client prices, legacy rows, and concurrent update cases were verified by
automated tests; no claim is made that those attacks were manually reproduced in
Chrome. Browser checks are interactive verification, not unattended browser CI.

Screenshots and dimension evidence are outside Git in
`/private/tmp/green-tasty-reservations-states/`, including `dashboard-1440.png`,
`dashboard-768.png`, `dashboard-390.png`, `dashboard-320.png`, dish preview,
empty drawer, submitted guest confirmation, and `browser-checks.json`.
Temporary servers, credentials, raw invitation evidence, fixture database and
storage are removed after acceptance.

## Remaining visual and product differences

- Minimum 44px keyboard/touch targets and responsive wrapping can make cards and
  modals taller than the compact desktop reference.
- Actual names, addresses, prices, dates, quantities, time zones, and two-hour
  duration replace illustrative Figma content.
- An invitation needs a personal guest name; old raw URLs cannot be re-shown.
  Copy feedback is inside the modal instead of a separate top-right toast.
- Support text uses the existing assistance component; no fake phone number is
  displayed. No unbacked unread indicator or delete icon is shown.
- Nutrition and waiter identities are unavailable in the current data model.
- A second guest pre-order remains disallowed; the success action is disabled.
- Existing loading, error, pagination, account navigation, and group management
  remain available even where omitted from the screenshots.
- No AWS environment, external mail delivery, real-device screen reader
  certification, or pixel-diff comparison against exported Figma frames was
  performed. This is not a deployment readiness claim for unspecified AWS
  infrastructure.

## Changed-file inventory

### Backend and routes

- `app/Http/Controllers/MyReservationsController.php`
- `app/Http/Controllers/ReservationDashboardController.php`
- `app/Http/Requests/GuestDishSelectionRequest.php`
- `app/Http/Requests/MyReservationsRequest.php`
- `app/Models/Reservation.php`
- `app/Services/GuestDishSelectionService.php`
- `app/Services/GuestInvitationService.php`
- `app/Services/GuestMenuService.php`
- `app/Services/MyReservationsService.php`
- `routes/web.php`
- `app/Http/Controllers/OwnerPreOrderController.php`
- `app/Http/Controllers/ReservationFeedbackController.php`
- `app/Models/ReservationFeedback.php`
- `app/Services/ReservationCardState.php`

### Additive database migration

- `database/migrations/2026_10_26_000000_create_reservation_feedback_table.php`

### Interface and client transport

- `resources/js/components/guest/GuestDishCard.vue`
- `resources/js/components/reservations/ReservationListCard.vue`
- `resources/js/composables/useGuestSelections.ts`
- `resources/js/guest.ts`
- `resources/js/lib/guestSelectionApi.ts`
- `resources/js/lib/sessionJson.ts`
- `resources/js/pages/Dashboard.test.ts`
- `resources/js/pages/Dashboard.vue`
- `resources/js/pages/guest/Menu.test.ts`
- `resources/js/pages/guest/Menu.vue`
- `resources/js/pages/reservations/Show.test.ts`
- `resources/js/pages/reservations/Show.vue`
- `resources/js/types/reservation.ts`
- `resources/views/guest.blade.php`
- `resources/js/components/guest/GuestDishPreview.vue`
- `resources/js/components/reservations/ReservationFeedbackModal.vue`
- `resources/js/components/reservations/ReservationInviteModal.vue`
- `resources/js/pages/Dashboard.ssr.test.ts`

### Regression tests

- `tests/Feature/MyReservationsTest.php`
- `tests/Feature/ReservationFigmaStatesTest.php`

### Documentation

- `docs/guest-preorder-figma-flow.md`
- `docs/my-reservations.md`
- `docs/reservation-dashboard.md`
- `docs/reservations-figma-states.md`

### Previously supplied, preserved PNG icons

- `public/images/green-tasty/icons/Button Icon.png`
- `public/images/green-tasty/icons/Calendar 2.png`
- `public/images/green-tasty/icons/Clock.png`
- `public/images/green-tasty/icons/People.png`
