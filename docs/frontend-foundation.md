# Frontend foundation

The public `/` route now renders `restaurants/Index` through Laravel and Inertia.
It replaces the starter welcome page without changing authentication screens,
the dashboard, or the group pre-order endpoints and services.

## Design reference

The supplied [Figma reference](https://www.figma.com/design/P9rkBNLJZ3WabXSQX9b5DO/EPAM-IT-Marathon-6.0?node-id=115001-51075)
opens the homework cart frame. The file was inspected in the browser; the
implementation uses the **Design for Developers → Main Page & About Location →
Visitor role/Main page** frame (`111691:73805`) for the public foundation.

Verified desktop values:

| Element         | Figma value                                          |
| --------------- | ---------------------------------------------------- |
| Page            | 1440px wide, cream `#F7F7EC`                         |
| Text            | Poppins, neutral `#232323`                           |
| Hero            | 396px high, pale green `#D6E5D5`                     |
| H1              | 48px / 52px, weight 500                              |
| Section heading | 32px / 40px, weight 500                              |
| Eyebrow         | 14px / 24px, uppercase, green `#0B5B29`              |
| Header          | 68px high; original logo 112 × 38px                  |
| Content         | 1336px wide, 52px side margins, 60px section spacing |
| Location grid   | Three columns, 432px-wide cards, 20px gaps           |

Public design tokens are scoped to `.green-tasty` so existing application themes
keep working. Poppins is loaded through the existing Vite/Bunny font integration.
The public page follows the light Figma reference even when account screens use
dark mode. Neutral border/muted-text colors and focus styles are implementation
choices for this foundation, rather than verified Figma tokens.

## Components and data

- `SiteLayout`, `SiteHeader`, and `SiteContainer` provide the shared public shell,
  skip link, navigation, width, and responsive gutters.
- `RestaurantCard`, `RestaurantGridSkeleton`, and `ContentState` provide location
  cards and reusable loading, empty, and error states.
- `RestaurantController` delegates to `RestaurantListingService`. The service
  returns only active restaurants, sorted by name and ID, and counts active tables
  in the same query. It exposes only ID, name, slug, address, and table count.
- `RestaurantListing` defines the TypeScript payload. No reservations, guests,
  invitation tokens, or pre-order data are loaded by this feature.
- Inertia deferred props display the skeleton while the real database query
  loads. Rescued failures are reported server-side and display a generic retry
  state without returning exception details. Retry failures retain the error UI.
- On narrow screens the header wraps, cards stack, and headings scale down.
  Two columns start at 640px and three at 1024px. These breakpoints are responsive
  implementation choices; a mobile main-page frame was not verified.
- Semantic landmarks, heading order, a labelled list, decorative icons, visible
  keyboard focus, 44px controls, reduced-motion skeletons, and wrapping long
  names/addresses support accessibility.

The listing reads the existing `GreenTastyDemoSeeder` records; there are no Vue
fixtures or fallback restaurant records. The existing seed values are intentionally
preserved. To populate an empty local database explicitly:

```sh
./vendor/bin/sail artisan db:seed --class=GreenTastyDemoSeeder
```

No development database seeding or migration is required by the frontend code.

## Differences and remaining references

- Seeded locations have demo names and addresses, so the page displays those
  values instead of the three Tbilisi addresses pictured in Figma.
- Restaurant photography is not represented in the existing schema or assets.
  Cards reserve the image area with a neutral utensil placeholder. No stock
  photograph is presented as a real restaurant.
- The header uses a text wordmark, and the hero uses the verified text and
  background without the salad artwork. Browser export/download attempts did
  not yield usable original asset files. To match these details, supply the logo
  SVG, transparent hero artwork, and restaurant images with their restaurant-slug
  mapping. No substitute branding or imagery has been invented.
- Daily occupancy is omitted because the backend has no defined daily occupancy
  calculation. Table counts come from active database records.
- The popular-dishes section, booking flow, restaurant detail pages, and menu
  browsing are outside this foundation. Navigation uses working home/location
  links and the existing sign-in/account routes. The hero links to Locations.
- A mobile layout/export and exact card-radius/shadow tokens would allow a later
  visual-fidelity pass. The current card radius and shadow are conservative
  implementation choices based on the desktop appearance.

## Verification

`RestaurantListingTest` covers public and signed-in access, deferred loading,
seeded data, the public-field contract, empty data, inactive restaurant filtering,
active-table counts, one-query loading, and reported failures with retry recovery.
Run the complete repository check with:

```sh
./vendor/bin/sail composer run ci:check
```

Verified on this branch:

- Focused feature tests: 7 passed, 112 assertions.
- Complete CI: 182 passed, 4 skipped, 1089 assertions; frontend lint/formatting,
  TypeScript, Pint, and PHPStan passed.
- Production build: passed. The font plugin emits an advisory about optional
  `fontaine` fallback optimization; the Poppins files build and load successfully.
- Browser: all three existing seeded locations rendered, with no console errors.
  Widths of 320, 390, 768, and 1440px had no horizontal overflow; the grid changed
  from one to two to three columns. The keyboard skip link focused `main-content`.

The browser checks read the existing development data. Feature tests used the
configured testing database. Development data, business models, and migrations
were not modified.
