# Guest pre-order flow from Figma

Current reservations/card, owner cart, invitation, feedback, and guest preview updates are documented in [Reservations Figma states](reservations-figma-states.md). Earlier acceptance sections below describe their original implementation.

Latest status: the persistent application menu and its deployment initialization
are documented in [menu-catalog-deployment.md](menu-catalog-deployment.md).
The **Guest-screen visual refinement** below records the preceding UI acceptance. The historical independent review still documents mandatory version
checks and the existing backend security guarantees.

Implemented on `feature/guest-preorder-figma-flow`, based on booking PR #18 at
`4f6013a`. This continues the existing guest-selection architecture. It does not
replace reservation booking, authentication, invitation issuance or owner-only
group submission. No commit, push, merge or deployment is part of this task.

## Permanent catalog acceptance

The missing-content issue is fixed by the shared catalog and explicit
`menu:install --apply` initializer. It adds eight photographed dishes to Appetizers
for each of the three existing local locations without changing any original row.
The current Inna invitation for reservation 8 was opened in actual Chrome on
localhost:8080 and showed the permanent catalog; its cart was not modified.
A production-bundle check on isolated fixtures loaded all eight images at all four
required widths and confirmed/persisted 27.00 through the existing guest flow.
No AWS deployment has been performed. See [menu-catalog-deployment.md](menu-catalog-deployment.md)
for accepted starter names/prices, strict catalog validation, final regression
results, preservation evidence, remaining differences and release initialization
commands. The historical smaller-menu/unknown-asparagus limitations
below describe the earlier implementation, before this catalog was installed.

## Guest-screen visual refinement

Refined from the clean `feature/guest-preorder-figma-flow` branch at `f2c18bc`
on 2026-10-25. The six supplied screenshots are the reference; the two menu
screenshots describe the same menu with later change annotations, which take
precedence. This pass changes only guest presentation, its affected Vue test and
this document. No backend, routes, migration, authentication, shared booking
component, homepage or consolidated-order screen was changed. Original PNG bytes
and existing development data were left untouched. No commit/push/merge/deploy.

Visual changes:

- Guest-local desktop containers now use 40px gutters. The hero is 180px high at
  tablet/desktop, with the original salad photograph, adjusted crop/opacity,
  pale green background and 18px subheading. Header remains 68px plus its border;
  the green reservation strip uses actual reservation facts.
- Desktop grid uses 32px column gaps and 40px row gaps. At 1440px cards measure
  316px wide and 312px high before selection (316px with quantity controls).
  Images are 160px; spacing and descriptions no longer inflate the cards.
  Descriptions remain available to assistive technology. Desktop Pre-Order
  appears on hover or keyboard focus; touch layouts keep it visible. Sold Out
  retains its badge/faded photograph and has no purchase button.
- Quantity controls show a 32px outline inside a 44px interaction target. The
  drawer retains its 640px width and right alignment, with 40px desktop side
  padding, a taller centered title, subtle dividers and compact item rows.
  Its original 381px basket illustration is larger and positioned higher.
  Go to Menu remains an underlined action; submission is 56px high.
- The invitation uses a subtle guest-only shadow and green input focus outline.
  The name reminder is shown only after dismissing the modal, so it does not add
  a banner behind the Figma dialog. Browse Menu is 56px high. Timezone remains
  in the accessible description list without adding a visible extra row.
- The confirmation starts 24px below the header, retains its original success
  artwork and 620px card, and uses lighter dividers and a compact saved-item list.

Actual Chrome verification used production assets on `127.0.0.1:8095` with a
separate disposable SQLite database, private storage and synthetic guests. No
browser write targeted the development database. The reference comparison and
measurements cover 24 captures: invited, menu, menu-selected, empty-cart, cart and
submitted, each at 1440×1013, 768×1013, 390×844 and 320×640. Both annotated menu
references are represented by the current menu and selected-menu captures.
Some evidence captures include the full page to show vertically scrolling content.

All 24 measurements had zero horizontal overflow and zero missing images.
Grid columns were 4/2/1/1 respectively; drawer widths were 640/640/390/320px.
The 320px name dialog measured 288×510px and fitted inside the viewport. At
320px the three-item cart scrolls internally (460px of content in 418px), while
Submit remains visible at y=568–624 in the 640px viewport. Its quantity targets
measured 44×44px. The long confirmation scrolls vertically; keyboard End exposed
its total at y=484–508. No console warnings or errors were captured in four tabs.

Browser actions verified required-name validation, Escape/reopening, category
arrow/Home navigation, an empty category, price sorting, Sold Out without an
order action, immediate cart persistence, reload restoration, quantity changes,
removal, Tab/reverse-Tab containment and Escape/focus restoration. The desktop
flow produced 46.00 → 63.00 → 44.00 → 63.00 with actual server prices. Confirmation
received focus, remained after Escape and survived reload. Three other isolated
guests each started with an empty private cart and confirmed 46.00.

Separately, a read-only CLI check of the existing consolidated service against
those browser-created fixtures verified four confirmed guests, 12 item rows,
13 total units and 201.00 grand total (63.00 + 3×46.00). The group remained draft,
awaiting its owner. This is service verification, not a new browser test of the
owner's consolidated screen. Authorization, mandatory If-Match, stale-write
protection, price snapshots and duplicate-submission guarantees were rechecked by
the complete existing automated suite; no new manual adversarial HTTP audit is
claimed in this visual pass.

Final automated checks on this refinement:

| Check                            | Result                                         |
| -------------------------------- | ---------------------------------------------- |
| Complete Sail MySQL CI           | 443 passed, 4 existing skips; 4,381 assertions |
| Complete Vue suite               | 205 passed, 18 files                           |
| TypeScript / PHPStan / Pint      | Passed; PHPStan zero errors                    |
| Frontend lint / formatting       | Passed without warnings                        |
| Production client and SSR builds | Passed                                         |
| Diff whitespace                  | Passed                                         |

The existing SQLite full-suite results in the historical review were not rerun
in this visual pass. Browser fixture storage is SQLite; that is distinct from
running the complete SQLite regression suite.

Remaining differences: actual database dishes/prices/details replace Figma sample
values; the exact asparagus photograph is not identified, so the Sold Out fixture
uses its own matching avocado photograph. Hero crop, font rasterization and some
spacing still differ; pixel-perfect identity is not claimed. The database has no
currency/preparation-time field, so prices are decimal values and cart rows show
actual unit prices instead of an invented ten-minute preparation promise. The
success copy explains owner-only final group submission; unsupported Create New
Pre-Order is still omitted. Touch targets are larger than their visible outlines.
Mobile layouts adapt desktop references; no mobile Figma frame, physical-device
matrix or full screen-reader/OS-theme audit was supplied/performed.

Files changed in this refinement (7):

- `resources/js/pages/guest/Menu.vue`
- `resources/js/components/guest/GuestDishCard.vue`
- `resources/js/components/guest/GuestCartDrawer.vue`
- `resources/js/components/guest/GuestReservationDetails.vue`
- `resources/js/components/guest/QuantityControl.vue`
- `resources/js/pages/guest/Menu.test.ts` (Sold Out now asserts no purchase action)
- `docs/guest-preorder-figma-flow.md`

Evidence: `/private/tmp/green-tasty-guest-fidelity/` (outside Git), including
`browser-checks.json`, `console.json`, `integration.json`, screenshots and final
CI/build logs. Temporary preview tabs/server, fixture database, sessions, raw
invitation links and bootstrap scripts were removed after acceptance. No secrets,
test database, temporary fixtures or generated build artifacts are candidates
for this change.

## Architecture and lifecycle

The existing invitation bearer identifies one existing `reservation_guests` row.
Opening or reopening a link never creates a participant. The original stateless
HTML/JSON negotiation and invitation JSON contract are retained. The Vue guest
entry remains separate from Inertia so a bearer URL cannot enter shared auth
props, session previous URLs or remembered Inertia history.

`GuestMenuService` composes the existing invitation and selection services and
adds a safe reservation context, a restaurant-scoped catalog and guest lifecycle
state. Only active categories are returned. Unavailable dishes are included in
the new catalog with `available: false`; the original invitation API continues
to return available dishes only. Actual submitted-order quantities rank Popular
First; Newest First uses database creation dates. Numeric price sorts use integer
cents. Category tabs and sorting operate on this authorized catalog in Vue.

`GuestPreOrderService` adds name confirmation and individual guest submission.
It reuses `GuestDishSelectionService::replace()` to validate and persist dishes;
it never duplicates monetary or menu ownership logic. Three additive guest
columns record `name_confirmed_at`, `pre_order_submitted_at` and
`selection_version`. Name confirmation changes only that guest's display name
and confirmation timestamp; the existing RSVP status is not repurposed.

Lifecycle:

1. A valid individual invitation opens **You're Invited!** with real restaurant,
   location, date, two-hour time range and party size. The guest confirms a name.
2. The guest browses their restaurant's catalog. Add, quantity and remove actions
   persist the guest draft immediately through the existing PUT endpoint.
3. Every successful write is followed by an authoritative menu/status read. Cart
   state restores on reload. Unsaved/uncertain writes retain a retry action and
   a leave warning; no failed request is presented as a successful submission.
4. **Submit Pre-Order** validates and saves the complete selection inside the same
   transaction that marks this guest's selection submitted. The success screen
   uses the returned saved items, prices, total and submission timestamp.
5. This guest's selections become read-only. Another guest may still contribute.
   The reservation owner alone submits the consolidated group order, following
   the existing service, policies and status restrictions.

The guest success copy explicitly distinguishes individual selection confirmation
from restaurant/group submission. There is no Create New Pre-Order action because
existing rules provide one pre-order per reservation and prohibit reopening a
submitted selection. Reload and duplicate successful submissions return the saved
result without inserting a second order or changing quantities/timestamps.

## Routes and authorization

| Method | Route                             | Purpose                                                       |
| ------ | --------------------------------- | ------------------------------------------------------------- |
| GET    | `/invitations/{token}`            | Existing private HTML guest shell or original JSON invitation |
| GET    | `/invitations/{token}/menu`       | Authorized catalog, context, selections and lifecycle state   |
| GET    | `/invitations/{token}/selections` | Existing exact selection-summary JSON contract                |
| PUT    | `/invitations/{token}/selections` | Existing complete replacement of only this guest's draft      |
| POST   | `/invitations/{token}/identity`   | Validate/confirm this existing guest's name                   |
| POST   | `/invitations/{token}/submit`     | Atomically save/confirm only this guest's selections          |

The raw token remains a SHA-256-hashed, expiring, revocable individual credential.
Malformed, unknown, expired, revoked and rotated tokens return 404 before input
validation, including when a caller is logged in as the owner. No caller-supplied
restaurant, reservation, guest, order, price, total or status determines write
scope. Quantity limits remain 1–99 and at most 100 distinct dishes. Name length
is limited to 255 characters; blank and control-character names are invalid.

All guest endpoints stay stateless and do not use cookie authentication. The
browser omits credentials, forbids redirects and uses JSON, no-store and
no-referrer. These bearer-authenticated requests have no ambient session authority;
owner operations retain their existing web-session CSRF protection. Invitation
HTML/JSON retains no-store/private, no-referrer and noindex/nofollow headers.
Tokens never enter browser storage or serialized page props. Pagehide/unmount
clears private state and aborts requests; bfcache restore revalidates the link.
Infrastructure still must redact bearer URLs from access/analytics logs and use
HTTPS outside loopback development.

Writes lock the reservation, revalidate/lock its guest, then lock the group order,
menu and selection rows in the existing order. They coordinate with rotation,
revocation, other cart writes and owner submission. The guest UI sends a quoted
`If-Match` selection version for every replacement and submission. A stale tab
gets 409 and must refresh; an identical saved replacement does not advance its
version. Both draft PUT and guest submission require a valid version. Missing, malformed,
weak, wildcard or multiple-version headers return 422; stale versions return 409.
There is no unversioned selection-write path. Existing API clients must first read
`guest_state.version` from the authorized menu response and send `If-Match: "N"`.
A retry after successful submission returns the immutable result regardless of
its old version. Closed reservations and locked group orders reject new writes.

## Relationships and prices

Existing relationships remain restaurant → reservation → guests and one group
pre-order → items, with each item linked to its reservation guest and menu item.
No foreign key, unique index or existing row is removed. Group summaries continue
to show persisted selections from both draft and confirmed guests; this task does
not change which saved rows the owner can review or submit.

New lines snapshot actual database prices. Retained lines keep their existing
unit-price snapshots when quantities change, including during guest submission.
Removing a saved line and later re-adding it takes a new snapshot. Server totals
use Brick Math decimal arithmetic; client estimates use BigInt cents. Figma's
illustrative inconsistent cart total is not reproduced. A dish must still be
available in an active category at the reservation's restaurant when saved or
submitted. An unavailable saved line is retained for review/removal and prevents
submission until corrected.

## Screens and assets

Implemented: invitation/name dialog, guest header and reservation strip, menu
hero, category tabs, sorting, four-column desktop cards, Sold Out state, empty
cart, populated right drawer, saved-cart/error/read-only states, submitted summary
and unavailable invitation. The previous fixed order panel was replaced by the
shared Reka Sheet drawer. Existing BookingModal, Button, quantity controls,
containers, scoped palette and date/money helpers are reused.

Original supplied logo, `salad1.png` hero, canonical dish photographs,
`icons/Illustration.png` empty basket and `icons/Check Image.png` success image are
reused without image editing. The newly supplied high-resolution `gjgk.png` is
used for its matching Avocado Pine Nut Bowl. Unknown dish names receive a neutral
photograph-unavailable state instead of another dish's image. Figma weights are
presentation-only metadata for the three explicitly identified reference dishes;
they never enter order requests or persisted prices.

Intentional differences from the six supplied Figma screenshots:

- Actual database names, category contents, availability and prices are used.
  No development menu is overwritten to manufacture reference dishes.
- The existing reservation duration remains **120 minutes**, in Asia/Tbilisi.
- The schema has no currency or preparation-time field. No dollar currency or
  promised ten-minute readiness is invented. Weights are shown only for known
  reference metadata; unknown dishes omit them.
- Name and cart changes are persisted, with explicit validation, stale-tab,
  network/rate-limit, unavailable and locked states that the static references
  do not illustrate.
- Guest confirmation leaves final group submission with the owner, and another
  pre-order is not offered after confirmation. Success copy reflects that rule.
- The exact Sold Out photograph and metadata for every illustrated dish are not
  available under an identified database name. Only known matching assets are
  mapped; supplied unrelated images are preserved.
- Mobile layouts adapt the supplied 1440px reference; no mobile Figma frame was
  supplied. Touch targets and additional timezone/status information can make
  cards and overlays taller. Pixel equality is not claimed.

## Verification

Initial implementation acceptance on 2026-10-25, before the independent review
below:

| Check                               | Actual result                                                                                 |
| ----------------------------------- | --------------------------------------------------------------------------------------------- |
| Complete Sail MySQL CI              | **431 passed, 4 existing skips**, 435 PHP cases, 4,219 assertions                             |
| Complete in-memory SQLite PHP suite | **415 passed, 20 skips**, 4,047 assertions; MySQL-only locking/collation cases remain skipped |
| Complete Vue suite                  | **203 passed**, 18 test files                                                                 |
| TypeScript                          | `vue-tsc --noEmit` passed                                                                     |
| PHPStan                             | Passed, zero errors                                                                           |
| Pint, frontend lint and formatting  | Passed, no frontend lint warnings                                                             |
| Production client + SSR build       | `npm run build:ssr` passed                                                                    |
| Diff whitespace                     | `git diff --check` passed                                                                     |

New backend tests cover name validation, scope/privacy, actual reservation facts,
required version/name/nonempty submission, unavailable and foreign dishes,
historical prices, idempotency, guest immutability, stale tabs, closed/locked
orders, invalid/expired/revoked/rotated links, independent guests and owner
consolidation/submission. Two new real MySQL connection tests verify that guest
confirmation serializes with cart changes and owner submission and rechecks a
version after waiting. Existing reservation, authentication, invitation, group
order and relationship tests remain in the complete suite.

Vue tests exercise the new categories/sorting, name gate, empty/populated drawers,
autosave and reload, quantities/removal, historical and server-confirmed totals,
submission/refresh/pending protection, read-only and unavailable states,
422/429/network/conflict handling, storage avoidance and bfcache cleanup. Real Vue
server-renderer tests verify the valid loading shell and unavailable shell without
browser/account context. The production guest entry itself intentionally remains
a stateless client-rendered shell; no full guest-page SSR is claimed.

### Actual Chrome acceptance

The complete path used a loopback Laravel server with isolated SQLite fixtures,
its own session cookie and synthetic accounts. It served the real production
client assets. A loopback production SSR process rendered the owner reservation
page with `data-server-rendered="true"`; preview SSR exceptions were configured to
throw. The preview's first production owner request found no SSR listener; after
starting the built SSR process, the owner page rendered successfully. No source
change or suppression of application errors was needed for that preview setup.
Static preview routing was corrected to decode the space in `Check Image.png`;
all final image measurements below passed.

The browser verified:

- Owner sign-in, opening an actual reservation, issuing and copying an individual
  invitation, opening it as a stateless guest and confirming a name.
- Required-name validation with `aria-invalid`, Escape dismissal, reopening,
  Tab focus cycling, reverse Tab in the cart and focus restoration to its trigger.
  The close target measured 44×44px and keyboard focus was visibly styled.
- Actual category navigation with arrow/Home keys, price sorting, Sold Out
  disabled controls and the original artwork.
- Immediate persistence, reload restoration, quantity changes and removing a
  line. The first guest selected 2×17.00 + 1×10.00 + 1×19.00 = **63.00**.
  Decreasing and removing produced the expected 46.00 and 44.00 intermediate
  totals. Confirmation appeared only after the server response and survived reload.
- A separately issued second guest saw only their own one-item cart and confirmed
  **12.00**, without the first guest's private selections.
- The owner saw both participants, their correct item associations and **75.00**
  consolidated total. The existing owner confirmation submitted the group order;
  the persisted summary then showed submitted/locked with the same total.
- Invalid invitation UI hides reservation data. Expiry, revocation and cross-scope
  denial were additionally verified by automated HTTP tests, not every state
  through manual Chrome actions.
- The scoped cream/white public palette retained readable dark text inside an
  explicitly dark HTML root/color-scheme in the preview. The OS theme itself was
  not changed. This is a dark-ancestor CSS check, not a complete OS/browser theme
  matrix or a full screen-reader/forced-colors accessibility audit.

Final screenshots and measurements cover **invited, menu, empty-cart, cart,
submitted and invalid** at **1440×1013, 768×1013, 390×844 and 320×640**: 24 captures.
Every final measurement had `scrollWidth === innerWidth` and zero missing images.
The menu measured four columns at 1440px, two at 768px and one at 390/320px. Cart
width was 640px on desktop/tablet and the full viewport on mobile; list content
scrolls internally while totals and submission remain available. Measurements
were repeated after entry animations finished, rather than treating intermediate
slide positions as final layout. Console warnings/errors were empty on the final
guest and owner checks. Geometry, typography, hero crop, card and overlay layout
were visually compared with all six supplied reference screenshots. Exact pixel
identity is not claimed.

Evidence is outside Git at `/private/tmp/green-tasty-guest-preorder/`:
`browser-checks.json`, `data-preservation.json`, final CI/build logs and
`screenshots/`. Screenshots do not contain invitation bearer URLs or passwords.
The SQL development backup is private, outside Git and is not a deliverable.

### Migration safety and cleanup

Before application, the target was verified as `APP_ENV=local`, MySQL host `mysql`,
database `laravel`. A private full SQL backup was taken and `migrate --pretend`
showed only three additive guest columns. Only this migration was applied; no
reset, development seeder or restore was executed. A later field-by-field
comparison found **every original application row unchanged**, including all
8 users, 3 restaurants, 9 tables, 6 categories, 6 dishes, 5 reservations and
11 participants. Existing keys and relationships were retained. All browser
acceptance writes, including final group submission, went to isolated fixtures.

All agent-created preview tabs were closed and their temporary viewport overrides
cleared. Two stale tabs required closing through the native Chrome interface;
final browser inventory contained no preview tabs. User tabs were retained. Both
temporary loopback servers were stopped. The disposable SQLite database, storage,
sessions, synthetic credentials, raw test links and preview bootstrap scripts
were removed. Evidence and the private backup remain outside the repository.
The branch remains uncommitted, with supplied/unrelated assets preserved.

## Final independent review

Three confirmed concurrency defects were reproduced with failing regressions and
fixed without changing booking rules, presentation layout or group architecture:

1. A draft PUT could omit If-Match and overwrite a newer cart. The HTTP request
   now requires a quoted version and the service requires a nonnullable explicit
   integer. Both production callers forward the client's validated version.
   No controller or service default can skip the comparison under the guest lock.
2. Name confirmation refreshed the menu/version but retained an older input cart.
   It now adopts the corresponding server selection snapshot as well.
3. A cart could change between successful PUT and its follow-up read, allowing a
   pending Submit action to confirm newly read items without another review.
   The client now compares the saved response with the read snapshot, shows the
   latest cart and stops that submission when contents/prices or status changed.
   Another explicit review/Submit action is required.

A successful submission retry still returns the existing immutable selection;
ignoring an old version in this read-only retry cannot modify any rows. This is
separate from draft replacement, which always checks the supplied version.
Legacy tests now send explicit versions for their existing price, isolation,
status and idempotency scenarios. Dedicated regressions use raw missing/stale or
malformed headers rather than helpers, and assert persisted rows/version remain
unchanged. Cross-guest/query/body spoofing and same-dish/different-snapshot-price
consolidation receive additional explicit regression coverage.

The migration was inspected again; the already applied local MySQL schema has
two nullable timestamps and unsigned selection_version defaulting to zero, with
the two existing guest foreign keys retained. No migration or data reset was run
in this independent review. Development counts/hashes were captured without
printing private row contents. New development rows created since the prior
acceptance were retained.

The nine supplied PNGs were checked for valid structure/CRC, dimensions and text
metadata. No private key, credential, bearer URL or environment-secret signature
was found in candidate source/asset metadata. Required new images are
`dishes/gjgk.png`, `icons/Illustration.png` and `icons/Check Image.png`; the other
six supplied PNGs are preserved, unused optional assets. PNG bytes were not edited.
The candidate file inventory contains no .env, SQL/SQLite database, private
preview credentials, disposable bootstrap fixture, log or generated bundle/map.
The existing .env, development SQLite file and production build outputs remain
ignored. Permanent regression test source is intentionally included.

The existing 24 screenshot measurements were rechecked against the supplied
Figma designs; this review does not claim a new complete browser end-to-end run.
No layout changes were made during these concurrency fixes. Remaining visual
items are the known data-dependent dish count/photograph differences, hero crop
and spacing/card height differences, larger accessible quantity controls, the
smaller empty-basket illustration, additional timezone/status copy, and the
missing currency/preparation metadata. The confirmation omits unsupported
Create New Pre-Order and explains the owner's final group submission. The 320px
confirmation scrolls vertically. These differences are documented; exact pixel
identity and a complete OS-theme/screen-reader audit are not claimed.

Final complete rerun after the independent-review fixes:

| Check                        | Actual result                                            |
| ---------------------------- | -------------------------------------------------------- |
| MySQL complete CI            | **443 passed, 4 skips**, 447 PHP cases, 4,381 assertions |
| SQLite complete PHP suite    | **427 passed, 20 skips**, 4,209 assertions               |
| Vue complete suite           | **205 passed**, 18 files                                 |
| TypeScript, PHPStan, Pint    | Passed; PHPStan zero errors                              |
| Frontend lint and formatting | Passed without warnings                                  |
| Production client and SSR    | Both builds passed                                       |
| Diff whitespace              | Passed                                                   |

All nine application-table counts/hashes matched from the start to the end of
this review; no development row was modified by the checks. No migration was
applied again. The complete API/authentication/booking/group-order regressions
remained enabled. New required-header failures use HTTP 422; stale writes use 409. These are validation failures, not a fallback to an unversioned update.
The repository's guest UI already sends the required header; external older
clients must adopt it before upgrading.

Readiness: no remaining confirmed functional or authorization blocker was found
after the fixes and complete reruns. The documented visual differences and lack
of a fresh full browser E2E pass in this review remain explicit limitations.
Evidence for this independent review is at
`/private/tmp/green-tasty-guest-review/`; all files there are outside Git. No
secrets, database dumps, disposable preview fixtures or generated builds appear
in the changed/untracked candidate inventory. Nothing was staged, committed,
pushed, merged or deployed.

## Complete changed-file inventory

Application, tests and documentation (`M` modified, `A` new, `D` removed):

- `M` `app/Http/Controllers/GuestDishSelectionController.php`
- `M` `app/Http/Requests/GuestDishSelectionRequest.php`
- `M` `app/Models/ReservationGuest.php`
- `M` `app/Services/GuestDishSelectionService.php`
- `M` `app/Services/GuestMenuService.php`
- `M` `docs/guest-dish-selection-ui.md`
- `M` `docs/guest-dish-selection.md`
- `M` `resources/css/app.css`
- `D` `resources/js/components/guest/GuestOrderPanel.vue`
- `M` `resources/js/composables/useGuestSelections.ts`
- `M` `resources/js/lib/guestSelectionApi.ts`
- `M` `resources/js/pages/guest/Menu.test.ts`
- `M` `resources/js/pages/guest/Menu.vue`
- `M` `resources/js/types/guest-selection.ts`
- `M` `routes/web.php`
- `M` `tests/Feature/EndToEndIntegrationTest.php`
- `M` `tests/Feature/GroupPreOrderSubmissionConcurrencyTest.php`
- `M` `tests/Feature/GroupPreOrderSubmissionTest.php`
- `M` `tests/Feature/GroupPreOrderSubmissionUiTest.php`
- `M` `tests/Feature/GuestDishSelectionReadTest.php`
- `M` `tests/Feature/GuestDishSelectionTest.php`
- `M` `tests/Feature/GuestMenuTest.php`
- `M` `tests/Feature/RestaurantBookingTest.php`
- `A` `app/Http/Controllers/GuestPreOrderController.php`
- `A` `app/Http/Requests/GuestNameRequest.php`
- `A` `app/Http/Requests/SubmitGuestPreOrderRequest.php`
- `A` `app/Services/GuestPreOrderService.php`
- `A` `database/migrations/2026_10_25_100000_add_guest_preorder_lifecycle_to_reservation_guests.php`
- `A` `docs/guest-preorder-figma-flow.md`
- `A` `resources/js/components/guest/GuestCartDrawer.vue`
- `A` `resources/js/components/guest/GuestDishCard.vue`
- `A` `resources/js/components/guest/GuestReservationDetails.vue`
- `A` `resources/js/lib/guestMenu.test.ts`
- `A` `resources/js/lib/guestMenu.ts`
- `A` `resources/js/pages/guest/Menu.ssr.test.ts`
- `A` `tests/Feature/GuestPreOrderFlowTest.php`

Supplied image assets preserved without editing their bytes:

- `public/images/green-tasty/dishes/Dish picture (1).png` — unused optional supplied asset.
- `public/images/green-tasty/dishes/Dish picture (2).png` — unused optional supplied asset.
- `public/images/green-tasty/dishes/Dish picture.png` — unused optional supplied asset.
- `public/images/green-tasty/dishes/gh.png` — unused optional supplied asset.
- `public/images/green-tasty/dishes/gjgk.png` — required by the new UI.
- `public/images/green-tasty/dishes/kjkj.png` — unused optional supplied asset.
- `public/images/green-tasty/dishes/ug.png` — unused optional supplied asset.
- `public/images/green-tasty/icons/Check Image.png` — required by the new UI.
- `public/images/green-tasty/icons/Illustration.png` — required by the new UI.
