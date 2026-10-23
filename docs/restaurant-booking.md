# Restaurant booking

The existing restaurant details page now links to an authenticated booking form.
Availability comes from persisted records. Confirmation appears only after the
reservation and its owner participant have been saved in one transaction.
No migrations, business models, or group pre-order services are changed.

## Rules and assumptions

There is no authoritative booking specification in the repository and no opening
hours, restaurant timezone, or reservation end-time fields in the schema.
The following rules are explicit implementation assumptions, pending product
confirmation:

- **Asia/Tbilisi:** restaurant-local input and display timezone, consistent with
  the design's Tbilisi locations. The developer's Europe/Kyiv timezone does not
  determine restaurant booking times. Laravel remains configured to UTC.
- **120 minutes:** universal duration, supported by Figma's 12:00–14:00 example.
  Every existing reservation with a table is interpreted using this duration.
  Historical `reserved_at` values are interpreted as UTC, as configured by the
  existing application; existing data is not converted or rewritten.
- **30-minute starts:** local `:00` and `:30`, strictly in the future. Seconds
  and fractional seconds are disallowed. Figma examples are not a complete
  specification of allowed start times.
- **90-day window:** starts may fall through 23:30 on the 90th local calendar day
  after today. The end may fall beyond that day. This horizon is an assumption.
- **No invented opening hours:** all 48 half-hour starts are permitted subject
  to the future/window and availability rules. Opening-hours and holiday rules
  require a product decision and corresponding data.
- **Capacity:** 1–255 people, including the authenticated owner, and no more
  than the chosen table's capacity. Tables are not combined. The design's
  greater-than-four call note is not imposed as a capacity limit; demo tables
  support parties of six.
- **Intervals:** `[start, start + 120 minutes)`. Back-to-back bookings are valid;
  any partial overlap, including across midnight, is rejected.
- **States:** new reservations become `confirmed` on commit. Existing `draft`,
  `confirmed`, and unknown states block availability; only `cancelled` and
  `completed` release it. Clients cannot set status, code, owner, or restaurant
  IDs. This iteration adds no cancellation, rescheduling, or reactivation flow.

Rules are centralized in `BookingRules`. Changing the universal duration would
reinterpret old reservations. Supporting different durations requires preserving
an end time or duration per reservation in a future migration.

## Routes and requests

All routes use the web middleware, authenticated sessions, and private/no-store
responses. Slug binding leaves the existing model route keys unchanged.

| Method | Path                                          | Route name                    | Result                                     |
| ------ | --------------------------------------------- | ----------------------------- | ------------------------------------------ |
| GET    | `/restaurants/{restaurant:slug}/book`         | `restaurants.bookings.create` | Inertia `bookings/Create`                  |
| GET    | `/restaurants/{restaurant:slug}/availability` | `restaurants.availability`    | JSON available tables                      |
| POST   | `/restaurants/{restaurant:slug}/book`         | `restaurants.bookings.store`  | Redirect to the saved confirmation         |
| GET    | `/reservations/{reservation}/confirmation`    | `reservations.confirmation`   | Owner-only Inertia `bookings/Confirmation` |

Availability query fields are `date` (`Y-m-d`), `time` (`H:i`), and `guest_count`
(integer). Creation additionally requires `restaurant_table_id`. Unknown fields
are rejected, except Laravel's `_token`. The table must belong to this restaurant
and be active. Missing resources return 404, inactive restaurants return 403,
invalid input/capacity/conflicts return 422, and non-owner confirmation reads
return 403. Anonymous HTML requests redirect to login; JSON requests return 401.

Availability returns only `tables: [{id, name, seats}]`, sorted by capacity and ID.
It uses one scoped availability query, without guest or reservation payloads.
Limits are 60 availability checks and 10 creation attempts per minute per user.
Laravel 13 request-forgery protection remains enabled; Inertia sends the normal
session/XSRF credentials. No email or invitation is automatically issued.

## Atomicity and concurrency

`RestaurantBookingService::book()` acquires locks in this order:

1. Shared restaurant lock: stabilizes active status without requiring an
   exclusive restaurant lock for every booking.
2. Exclusive selected-table lock: serializes competing bookings for that table.
3. Locking conflict read: sees current committed reservations even if the
   connection previously established an older MySQL repeatable-read snapshot.

Restaurant activity, table ownership/activity/capacity, the current clock, and
overlaps are rechecked inside the transaction. The service then inserts a
server-owned reservation with a unique ULID reference and the owner's existing
`ReservationGuest` participant. Failure rolls back both. Deadlocks are retried
up to three times by Laravel. No price or pre-order state is touched.

Repeated identical POSTs return a 422 table conflict after the first successful
booking, with one persisted reservation and participant. This prevents duplicate
bookings; it does not implement an idempotency-key/replayed-success protocol.
Availability is advisory until commit. A network interruption can occur after
commit; the UI reports an unconfirmed response rather than claiming success.

Future code that changes tables, booking times, or blocking states must use the
same table lock protocol. Direct SQL and external writers bypass the application
invariant: the current schema has no database exclusion constraint. InnoDB may
also take range/gap locks during conflict checks, so unrelated bookings can
occasionally wait or retry; this is not a throughput/load-testing result.

## UI and Figma

The supplied Figma node opens the homework cart. The reviewed **Design for
Developers → Book a Table** references were:

- Main booking screen: `112656:157169`.
- Table-results/confirmation section: `112656:164064`.
- Signed-in confirmation: `112656:163769`.
- Form error state: `112656:157186`.

The form reuses the public layout, Poppins typography, cream/green tokens,
rounded cards, and button/focus components. On desktop it uses a search panel
beside results; on small screens these stack. Native labelled date/time/quantity
inputs and a radio fieldset provide keyboard access. Errors are linked to fields;
submission errors are announced. Loading, no-availability, connection failure,
expired-session, validation, and stale-availability states are explicit. Changing
search inputs aborts stale availability responses and clears table selection.
The form disables repeated submissions while processing.

Differences from Figma: no unprovided restaurant/table photos or visual calendar
widget, and a dedicated confirmation page instead of a modal. No fake invitation,
email delivery, or unfinished reservation-management/pre-order link is shown.
The existing backend pre-order and invitation endpoints remain available.

## Verification

`RestaurantBookingTest` covers persistence, UTC/local conversion, privacy,
authorization, CSRF, rate limits, validation, capacity, inactive/foreign tables,
half-open intervals, midnight boundaries, allowed status behavior, duplicate
submissions, atomic rollback, and integration with invitations, guest selections,
consolidation and submission.

`RestaurantBookingConcurrencyTest` uses committed fixtures and independent real
MySQL connections. It proves lock waiting, commit-versus-rollback outcomes, and
protection against stale repeatable-read snapshots. A separate PHP worker runs
the full HTTP kernel concurrently with an in-flight booking transaction and
receives 422 after the first booking commits. These tests explicitly require
the isolated `testing` MySQL database; they skip on other database drivers.

```sh
./vendor/bin/sail artisan test --filter=RestaurantBooking
./vendor/bin/sail composer run ci:check
./vendor/bin/sail npm run build
```

Validation results:

- Booking suite: 51 passed, 297 assertions, including four real MySQL concurrency tests.
- Complete CI: 246 passed, four existing skipped tests, 1,575 assertions. Frontend
  formatting/lint, Vue TypeScript, Pint and PHPStan all passed.
- Production build passed. The existing optional `fontaine` font-fallback advisory
  remains non-blocking.
- Browser verification used a separate localhost server and temporary SQLite
  database with disposable fixtures. Login, live availability, empty availability,
  keyboard focus on table choices, persisted booking, and local-time confirmation
  worked. No horizontal overflow occurred at 320, 390, 768 or 1440px.
- MySQL locking was tested separately on MySQL, rather than inferred from the
  browser preview's SQLite behavior. These are controlled concurrency tests, not
  production load tests. Screen-reader software and every browser were not tested.

Development records were not modified during testing or browser verification.

## Changed files

- `app/Http/Controllers/RestaurantBookingController.php`
- `app/Http/Requests/BookingAvailabilityRequest.php`
- `app/Http/Requests/StoreBookingRequest.php`
- `app/Services/BookingRules.php`
- `app/Services/RestaurantBookingService.php`
- `app/Policies/ReservationPolicy.php`
- `app/Providers/AppServiceProvider.php`
- `routes/web.php`
- `resources/js/app.ts`
- `resources/js/pages/restaurants/Show.vue`
- `resources/js/pages/bookings/Create.vue`
- `resources/js/pages/bookings/Confirmation.vue`
- `resources/js/types/booking.ts`
- `tests/Feature/RestaurantBookingTest.php`
- `tests/Feature/RestaurantBookingConcurrencyTest.php`
- `tests/Support/booking-request.php`
- This document and the reservation navigation note in `restaurant-details.md`.
