# My Reservations

Implemented on `feature/my-reservations`, created from freshly fetched
`origin/main` at `a3397dc`. This replaces the starter dashboard and resolves the
reservation-discovery gap identified by the integration audit. No booking,
invitation, selection, monetary or submission business rules were changed.

## Routes and authorization

`GET /dashboard` keeps the existing `dashboard` route name and Fortify's
post-authentication destination. It now renders My Reservations through
`MyReservationsController`, using the shared Green Tasty `SiteLayout` rather than
the starter sidebar. Existing `auth` and `verified` middleware remain; email
verification is not newly enabled. Private responses have `Cache-Control:
private, no-store` and the page declares `noindex`.

`MyReservationsRequest` authorizes `ReservationPolicy::viewAny` before data is
loaded and validates an optional positive integer page (maximum 2,147,483,647).
That ability permits authenticated users to access their own index; it does not
grant access to another user's reservations. `MyReservationsService` always
adds `where('owner_user_id', $authenticatedUser->id)`. It never takes ownership
from a client query parameter. A registered participant who does not own a
reservation does not see it in this index. Deferred requests repeat the request
authorization and use the current authenticated account.

Each card links to the existing `/reservations/{reservation}` dashboard, whose
owner policies remain unchanged. Other owners still receive 403 there and at
the private summary endpoint. Anonymous HTML requests redirect to sign-in;
anonymous JSON requests receive 401.

Browser account-switch testing also reproduced a stale-history privacy issue:
Inertia could restore the previous account's already loaded reservation list
with Back, despite correct server-side scoping and no-store headers. The shared
Inertia middleware now encrypts authenticated page history. Fortify's login event
invalidates the previous history key, and the existing logout control clears the
key and prefetched pages. These small authentication changes are necessary for
the new private index; booking and account permissions are unchanged. The guest
bearer interface remains stateless and outside Inertia. History encryption uses
the installed Inertia implementation and browser Web Crypto; production must use
HTTPS, as already required for invitation links.

## Data, pagination and states

The list is a deferred Inertia prop with the existing loading/rescue/retry
component. It contains six rows per page, ordered by reservation date/time
descending and then ID descending to make equal dates deterministic. All owned
reservation states and historical dates remain visible. Upcoming/history tabs,
filters, cancellation, rescheduling and feedback are outside this feature.

Each row reuses `RestaurantBookingService::confirmation()` for the established
booking DTO and adds the actual nullable `pre_order_status`. This preserves UTC
storage and Asia/Tbilisi display, the two-hour duration and midnight end dates.
A missing pre-order displays **Not started** and is never created by the read.
Existing inactive restaurants and missing table assignments remain readable in
their owner's history; this does not change public restaurant visibility.

Only selected reservation columns and eager-loaded restaurant, table and
pre-order status fields are queried. A populated page uses at most five SELECT queries:
count, reservations, restaurants, tables and pre-orders. There is no per-row
query growth, no guest/item query, and no write. The service maps an explicit
response rather than serializing Eloquent business models. Invitation hashes,
raw links, guest identities/contact details, private requests and ownership IDs
are absent. Pagination counts are owner-scoped, and generated page URLs discard
arbitrary query parameters, including client-supplied IDs or tokens.

Pagination exposes Previous/Next links and the current/last page. Missing links
are noninteractive text. Pending navigation announces progress and rejects
repeated requests; a transport failure keeps the current page and offers the
same link for retry. A rescued data-loading failure uses the shared **Try again**
state. An empty owner sees **No reservations yet** with a real location-browsing
link. A page beyond the last page is distinguished from an empty account and
offers **Back to first page**.

## UI and Figma review

Reviewed in Chrome before implementation:

- [Design for Developers → Owner Flow → Reservations](https://www.figma.com/design/P9rkBNLJZ3WabXSQX9b5DO/EPAM-IT-Marathon-6.0?node-id=112599-148445), desktop 1440 × 900.
- [Reservations page, empty state](https://www.figma.com/design/P9rkBNLJZ3WabXSQX9b5DO/EPAM-IT-Marathon-6.0?node-id=112656-165416).

The page follows the existing Poppins typography, cream background (`#F7F7EC`),
green greeting band, white rounded cards, compact date/guest/time/pre-order
metadata, status strips and green actions. It uses one/two/three card columns
across mobile/tablet/desktop. Decorative Lucide icons come from the installed
icon set. Headings have unique IDs, dates/times retain text labels, links have
descriptive names and visible focus, and actions use minimum 44px heights.

Intentional differences from Figma:

- Actual backend labels such as **Confirmed**, **Draft**, **Completed** and
  **Cancelled** replace mock **Reserved**/**In Progress** states. Pre-order
  status is shown separately, without invented restaurant-acceptance wording,
  participant-completion counts or client-derived business rules.
- **View reservation** opens the already implemented management workflow.
  The card does not duplicate invitation/submission controls or add the design's
  deletion, feedback or rescheduling actions.
- The existing shared header is retained with its account link renamed
  **My reservations**. **Account settings** remains reachable, and **Book a
  table** first leads to location selection. There is no fabricated global
  booking destination or assistance phone number.
- Existing semantic status colors are reused instead of introducing an exact
  Figma pastel palette. Full restaurant names and the explicit timezone require
  more card space than the design's address-only examples. Dates retain the
  existing ISO display convention.
- The empty state uses the shared `ContentState` component. Figma's dining
  illustration is not included; no substitute artwork was invented.
- Six-item server pagination, loading/error states and responsive breakpoints
  are implementation choices; the reviewed design does not define these details.

## Verification

```sh
./vendor/bin/sail composer run ci:check
./vendor/bin/sail run env DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= composer run ci:check
./vendor/bin/sail npm run build
```

Results:

| Check            | Result                                                                               |
| ---------------- | ------------------------------------------------------------------------------------ |
| MySQL full CI    | 313 PHP tests passed, 4 skipped, 2,747 assertions.                                   |
| SQLite full CI   | 304 PHP tests passed, 13 skipped, 2,627 assertions.                                  |
| Vue tests        | 131 passed across 8 files, including 14 new My Reservations tests.                   |
| Static checks    | TypeScript, PHPStan, Pint, frontend formatting and lint passed.                      |
| Production build | Passed. Existing optional `fontaine` fallback-optimization advisory is non-blocking. |

The four common skips concern disabled two-factor authentication. SQLite also
skips the nine MySQL-specific locking/migration regressions; these pass during
the MySQL CI run. The new feature adds 23 Laravel cases, including data-provider
variants, and 14 Vue cases. Existing dashboard and reservation workflows remain
covered by the full suites.

Laravel tests cover authentication, policy denial, owner/participant isolation,
account changes between initial/deferred requests, exact DTO privacy, empty and
out-of-range pages, stable owner-scoped pagination, rejected page input,
pre-order states, historical/inactive restaurant records, nullable tables,
midnight display, constant query count, read-only behavior and failure recovery.
Vue tests cover authenticated/anonymous navigation, real dashboard links,
empty/loading/rescue states, safe text rendering, unique headings, pagination
boundaries, duplicate-navigation guards, network recovery and read-only status
presentation. Authorization itself is enforced by Laravel, not by Vue filtering.

Chrome verification uses production assets, real Laravel session/CSRF middleware,
synthetic owners and a guarded temporary SQLite database outside the repository.
It checks sign-in → list, 13-row pagination, card → owner dashboard → list,
account settings, empty-account switching, ownership rejection, booking/location
navigation and 320px/mobile and 768px/tablet layouts. Development records and
existing Sail/Vite processes are not changed. These interactive browser checks
are separate from the lightweight Vue test host and are not unattended browser CI.

The history regression was repeated with the final production bundle: Back after
logout showed sign-in instead of either the old private list or profile. Signing
in as the second account showed only its empty list, and opening the first
owner's reservation returned a generic 403. The temporary preview process,
fixture/router scripts and SQLite database were removed; existing Sail services
remain running. Synthetic proof screenshots are retained outside the repository
at `/private/tmp/green-tasty-my-reservations-desktop.png`,
`/private/tmp/green-tasty-my-reservations-mobile.png` and
`/private/tmp/green-tasty-my-reservations-empty.png`.

## Preview and limitations

Start Sail and the normal frontend development server, then sign in and open
**My reservations** or `/dashboard` at the configured application URL. Existing
owners see their own data. A new account sees the empty state; **Book a table**
leads to real restaurant selection and the existing persisted booking workflow.
No preview credentials or synthetic records are shipped in the application.

There is no live polling: revisit the list to obtain updated reservation/order
statuses. Pagination uses normal offset semantics, so newly inserted/deleted
reservations can move between pages across requests. Large-volume performance,
real-device/screen-reader certification and exact mobile Figma matching have not
been established. No dependencies, schema changes, commits or pushes are needed
for this implementation.

## Changed files

- `app/Http/Controllers/MyReservationsController.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `app/Http/Requests/MyReservationsRequest.php`
- `app/Services/MyReservationsService.php`
- `app/Policies/ReservationPolicy.php`
- `app/Providers/FortifyServiceProvider.php`
- `routes/web.php`
- `resources/js/app.ts`
- `resources/js/pages/Dashboard.vue`
- `resources/js/pages/Dashboard.test.ts`
- `resources/js/components/reservations/ReservationListCard.vue`
- `resources/js/components/reservations/ReservationPagination.vue`
- `resources/js/components/site/SiteHeader.vue`
- `resources/js/components/AppSidebar.vue`
- `resources/js/components/UserMenuContent.vue`
- `resources/js/types/reservation.ts`
- `tests/Feature/MyReservationsTest.php`
- `docs/reservation-dashboard.md`
- `docs/my-reservations.md`
