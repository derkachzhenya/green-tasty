# Owner reservation dashboard and consolidated group pre-order

Owners can finalize saved selections through the
[Group Pre-Order Submission UI](group-preorder-submission-ui.md). The dashboard
shows the submitted status/timestamp and retains the confirmed locked snapshot
while refreshing the consolidated summary.

## Preview locally

Start the existing Sail application and frontend development server:

```sh
./vendor/bin/sail up -d
./vendor/bin/sail npm run dev
```

Sign in and open [My reservations](my-reservations.md), or book a table through a
restaurant's **Book a table** action and follow **View reservation** on the
persisted confirmation. For an existing reservation, select **View reservation**
from your list, or open `/reservations/{id}` while signed in as its owner, using the application's
configured local URL (currently `http://localhost:8080`). IDs belong to the
existing database; no particular ID is assumed. Existing invitation/selection
backend endpoints can populate the order. A new booking initially shows its
owner participant and a valid empty summary.

This feature does not seed, migrate, or rewrite development data. No preview
credentials or test fixtures are shipped in the application. The account
dashboard now provides the owner-scoped [My Reservations index](my-reservations.md).

## Route, authorization and data

`GET /reservations/{reservation}` is named `reservations.show`, uses Laravel
model binding, and renders Inertia `reservations/Show`. It requires session
authentication plus both existing `ReservationPolicy::view` and `viewSummary`
abilities before preparing any private data. Deferred/refresh requests repeat
authorization. An authenticated participant or different owner receives 403;
anonymous browser requests redirect to login and JSON requests receive 401.
Missing reservations return 404. Invitation bearer tokens cannot authenticate
this owner page.

Responses use `Cache-Control: private, no-store`; the page also declares
`noindex`. Payloads are explicit DTOs rather than serialized business models:

- `reservation` reuses `RestaurantBookingService::confirmation()` for restaurant
  name/address/slug, reference, status, date/time, table and guest count. It keeps
  existing UTC-to-Asia/Tbilisi display and duration rules, including an end date
  when crossing midnight. Nullable/deleted table assignments remain readable.
- Deferred `groupOrder` contains participants, the original consolidated summary,
  actual pre-order status (or null when absent), and the saved submission timestamp.
- Each participant exposes only ID, display name, owner flag, participation
  response status and derived invitation status. User IDs, contact information,
  token hashes, raw tokens and invitation URLs are omitted. The invitation UI
  adds a nullable expiration timestamp for display.
  A link is **active** only while its stored expiration is in the future and a
  token hash exists; revocation takes precedence. Missing expiration is shown as
  unavailable. The owner needs no invitation. Active does not mean opened,
  delivered, or accepted; invitation validity and the stored response status
  are displayed separately. Registered participant count may differ from the
  planned reservation guest count.

`ReservationDashboardService` coordinates this read without duplicating money
calculations. It holds a shared reservation-row lock inside a short transaction,
following the same parent-first protocol as guest replacement and submission.
This keeps order status and selections together while those writers wait. It
rechecks ownership on the locked record. With a populated order there are seven
SELECT queries regardless of participant count, including eager-loaded summary
relations. Reads never create a pre-order or participants.

The existing protected JSON endpoint
`GET /reservations/{reservation}/pre-order/summary` and its response contract are
unchanged. Both interfaces reuse `ConsolidatedPreOrderService::summarize()`.

## Summary and UI behavior

The page uses the existing `SiteLayout`, header/container, buttons, `ContentState`
and `DeferredSection`. Typed reusable components render reservation information,
participants and the consolidated summary.

The summary shows each dish's consolidated quantity and subtotal, distinct saved
unit prices, participant-specific quantities/unit prices/line subtotals,
participant subtotals, total quantity and grand total. Vue only deduplicates
displayed price strings; every monetary value and quantity total comes directly
from the existing backend service. It never reads the current menu price to
reprice selections, divides a subtotal into a made-up unit price, or converts
money to floating-point numbers. No currency symbol is invented.

Participants without selections remain visible. Native expandable `details`
sections show guest selections and can be controlled with Enter/Space. Lists,
headings, definition lists, visible focus styles, skip/jump links and live
loading/error messages support keyboard and assistive navigation. The page
stacks on narrow screens; participant cards use one/two/three columns across
mobile/tablet/desktop. Long names and references wrap.

The initial deferred request has a loading state. Missing orders and empty
drafts show zero totals and clear empty states. Rescued backend errors show a
generic message and **Try again**, without exception details. **Refresh summary**
reloads participants, order status and selections, with progress, disabled-button
and connection-error states. It does not poll automatically or refresh the
reservation's metadata; reload the page for updated booking details.

Both drafts and submitted orders are read-only here. Submitted orders explicitly
say selections are locked; other non-draft states or a submission timestamp are
also treated conservatively as locked. A missing order is **Not started**, not
an invented draft. Selection editing, final submission, cancel/reschedule and
email controls are not included. Owners can now manage individual links through
the [guest invitation UI](guest-invitation-ui.md).

## Figma review and differences

Reviewed in the supplied
[Figma file](https://www.figma.com/design/P9rkBNLJZ3WabXSQX9b5DO/EPAM-IT-Marathon-6.0?node-id=115001-51075):

- Homework **Cart – Owner & Guests Pre-Orders**, `115001:51075`.
- Design for Developers → Owner Flow → **Reservations**, `112599:148445`.
- Design for Developers → Owner Flow → **Group Pre-order – sent**, `112599:148472`.

The implementation reuses the established Poppins, cream background, green
accents, white rounded cards, status labels and totals hierarchy. Differences:

- A reservation-specific responsive page replaces the desktop modal/cart and
  multi-reservation card grid. Aggregates appear before expandable per-guest
  sections so owners can compare group totals and individual selections.
- No original dish images are mapped in the schema, so no fake photography,
  preparation estimates, dish notes or customizations are displayed.
- Figma's **Confirmed by Restaurant** label and 30-minute editing cutoff have no
  corresponding backend state/rule. The page shows actual saved statuses and
  existing submission locks instead. Booking rules remain unchanged.
- Dollar symbols and invitation/edit/delete/submit buttons in the design are
  omitted according to the current scope and available currency configuration.
- Mobile breakpoints and the full-page composition are implementation choices;
  no exact mobile dashboard specification was verified.

## Tests and verification

```sh
./vendor/bin/sail artisan test --filter=ReservationDashboardTest
./vendor/bin/sail npm run test:unit
./vendor/bin/sail composer run ci:check
./vendor/bin/sail npm run build
```

`ReservationDashboardTest` covers owner-only initial/deferred access, authenticated
participant denial, anonymous/missing requests, response privacy, invitation
status boundaries, UTC/local and midnight display, empty and submitted orders,
historical prices, corrupt cross-reservation guest links, delegation to the
existing summary service, constant query count, no writes, rescued failures and
successful retry. Tests run on the isolated MySQL testing database and SQLite.

Vue component tests run with the already-installed Vite Plus/Vitest, Vue plugin
and Vue server renderer; no dependencies were added. They verify server-supplied
decimal strings and mixed historical prices, safe escaping, semantic/native
disclosures, empty/locked states, invitation labels and nullable table/local
dates. `npm run test:unit` is included in `composer run ci:check`. These are render
tests; browser interactions are verified separately, not simulated by SSR.

Browser verification uses a temporary SQLite database and isolated session cookie,
without modifying development records. It covers sign-in, populated/empty and
submitted orders, another owner's 403, confirmation navigation, refresh,
intentional summary failure followed by successful retry, Enter/Space disclosure
controls, and 320/390/768/1440px layouts without horizontal overflow. Production
locking behavior is covered by MySQL tests, not inferred from this preview.
No screen-reader certification, production load test or live-update guarantees
are claimed.

Final validation results:

- New dashboard feature suite: 16 passed, 233 assertions.
- Vue component suite: 9 passed, included in CI.
- Complete MySQL CI: 262 passed, four existing skips, 1,811 assertions.
- Complete SQLite CI: 254 passed, 12 existing/driver-specific skips, 1,707 assertions.
- Frontend formatting/lint, TypeScript, Pint and PHPStan passed in both CI runs.
- Production build passed. The existing optional `fontaine` font-fallback advisory
  remains non-blocking; no dependency was added to suppress it.

## Modified files

- `app/Http/Controllers/ReservationDashboardController.php`
- `app/Services/ReservationDashboardService.php`
- `routes/web.php`
- `resources/js/app.ts`
- `resources/js/pages/reservations/Show.vue`
- `resources/js/pages/bookings/Confirmation.vue`
- `resources/js/types/reservation.ts`
- `resources/js/components/reservations/ReservationOverview.vue`
- `resources/js/components/reservations/ReservationParticipants.vue`
- `resources/js/components/reservations/GroupPreOrderSummary.vue`
- `resources/js/components/reservations/reservation.test.ts`
- `tests/Feature/ReservationDashboardTest.php`
- `vitest.config.ts`
- `package.json` and `composer.json` (unit-test command and CI integration)
- This document.

Existing models, migrations, policies, booking rules and group pre-order business
services are unchanged.
