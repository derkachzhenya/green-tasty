# Restaurant details and menu browsing

`GET /restaurants/{restaurant:slug}` is a public Inertia route named
`restaurants.show`. Explicit slug binding leaves the model's default route key
and all existing reservation/invitation routes unchanged. The home route and
restaurant listing stay at `/`.

## Backend and data contract

`RestaurantController::show()` rejects inactive restaurants before preparing any
public data. `RestaurantDetailsService` supplies explicit public payloads:

- `restaurant`: ID, name, slug, address, optional phone, and active-table count.
- `menu`: active categories with ID, name, slug, and available dishes containing
  ID, name, nullable description, and the current menu price as a two-place decimal
  string. This browsing price does not change any existing pre-order snapshots.

The restaurant's relationship scopes the menu. Categories and dishes are ordered
by `sort_order`, then ID. Dishes are eager loaded in one additional query, so menu
loading always uses two queries for a populated menu regardless of category count.
The active-table count needs one additional query. Laravel's route-binding query
is separate. No reservations, guests, invitations, or pre-orders are loaded.

The menu is deferred through Inertia, with an accessible loading skeleton,
reported server errors, a generic error message, and retry. The shared
`DeferredSection` also preserves the existing listing's loading/retry behavior.
Empty active categories are retained and show their own empty state; a restaurant
without active categories shows an empty-menu state.

Inactive and missing restaurants receive the same `restaurants/NotFound` page
with an actual HTTP 404 status. The exception renderer is scoped to this route
and HTML/Inertia requests; JSON errors and other endpoints retain their existing
behavior. Auth props are explicitly supplied to the error page because failed
route binding may happen before the Inertia sharing middleware runs. The active
check runs again on deferred menu requests, so closing a location between the
initial page and menu request returns 404.

## Navigation and reservations

Listing cards link to the slug route using generated Wayfinder helpers. Details,
not-found pages, and the shared header link back to `/#locations`. The header
marks Main page as current only on the listing. Menu category links are ordinary
keyboard-accessible anchors to visible category sections, rather than tabs that
hide dishes.

The hero's **Book a table** action and the Reservations section now link to the
authenticated booking workflow. A normalized phone still provides an optional
`tel:` contact path. See [Restaurant booking](restaurant-booking.md) for the
current rules, routes, concurrency behavior, and verification. The original
details-only iteration used contact information before booking was implemented.

## Figma review and differences

The supplied [Figma link](https://www.figma.com/design/P9rkBNLJZ3WabXSQX9b5DO/EPAM-IT-Marathon-6.0?node-id=115001-51075)
opens the homework cart. The following frames on **Design for Developers** were
reviewed in the browser for this implementation:

- **Main Page & About Location → Restaurant Profile** (`111691:73904`): pale-green
  profile hero, restaurant information card, and primary/secondary actions.
- **Guest Flow → Placing pre-order by guest | Menu** (`111683:86716`): cream
  background, category navigation, white dish cards, four-column desktop menu,
  and green price text.

The implementation reuses the established public Poppins font, cream/green
tokens, shared container/header/layout, 20px grid gaps, rounded cards, focus
styles, and buttons. Menu cards have one column on mobile, two at 640px, and four
at 1280px. The profile switches to two columns at 1024px. Category links wrap on
small screens; long names and descriptions wrap. Mobile breakpoints and exact
card spacing remain implementation choices, not verified mobile Figma specs.

Differences and assumptions:

- The details and menu are combined into one public read-only page. Guest cart,
  order buttons, sorting by popularity, and invitation banners are not included.
- The existing schema has no restaurant description, photography, ratings,
  reviews, dish photos, or dish weights. These are omitted, and the existing
  utensil placeholder is reused for the desktop restaurant visual. No demo
  reviews, atmosphere descriptions, or stock photographs are invented.
- Seeded names and addresses remain unchanged. Original restaurant and dish
  image exports mapped to records are still needed for visual parity.
- Prices are displayed exactly as decimal strings without adding a currency
  symbol. Figma shows dollar symbols, but there is no currency field or project
  currency configuration; that product decision remains open.
- Figma shows unavailable dishes as sold out. The public page hides them, as
  required by this feature.
- The original details-only iteration used a contact action. The subsequent
  booking feature now connects the reservation action to its authenticated flow.

No existing migrations, business models, seeded records, or group pre-order
services were modified. Development data is only read during browser verification.

## Verification

`RestaurantDetailsTest` covers public/authenticated access, explicit slug lookup,
missing and inactive locations, visibility filtering, sort order, empty categories,
nullable fields, decimal strings, seeded data isolation, constant query count,
error reporting/retry, and closure between page load and deferred loading.
`RestaurantListingTest` remains part of focused validation for shared-component
compatibility.

```sh
./vendor/bin/sail artisan test tests/Feature/RestaurantDetailsTest.php tests/Feature/RestaurantListingTest.php
./vendor/bin/sail composer run ci:check
./vendor/bin/sail npm run build
```

Validation results:

- Focused details/listing tests: 20 passed, 301 assertions (13 new details tests).
- Complete CI: 195 passed, 4 skipped, 1278 assertions. Formatting, lint,
  TypeScript, Pint, and PHPStan passed.
- Production frontend build passed. The existing optional `fontaine` font-fallback
  optimization advisory remains non-blocking.
- Browser verification covered listing → details, the public 404 → locations back
  path, seeded menu records, and keyboard focus on menu/category/reservation
  anchors. No horizontal overflow occurred at 320, 390, 768, or 1440px.
- The Vite configuration watcher was refreshed without changing its contents to
  replace a stale development font manifest. Poppins styles are now present.

Changed files are the restaurant controller and new details service, the new slug
route and scoped exception renderer, public restaurant TypeScript types,
`Show.vue`/`NotFound.vue`, menu/skeleton/dish components, `DeferredSection.vue`,
listing/card/header navigation, the feature tests, and this document.
