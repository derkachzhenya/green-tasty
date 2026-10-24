# Homepage hero and booking identity refinement

## Latest user-requested presentation update

The subsequent request supersedes the full-bleed artwork decision below: restore
the original centered page bounds and show the newly supplied complete avocado
bowl, without visible service/demo notices.

- Added `dishes/hero-avocado-bowl.png`, an exact byte copy of the user's `ffdg.png`
  (1254 × 1254 RGBA; SHA-256
  `f9d968178544f7cc98ec9ceeb3ef273b270e114445b9e0c92d2b35a16e451b94`).
  The existing front bowl and all original assets remain unchanged.
- Hero composition now uses a centered 1440px maximum page frame. Both pictures
  scale together with native proportions; phone/tablet artwork sits below copy.
  The file's transparent padding is clipped where necessary, not the visible bowl.
- Removed the homepage explanatory paragraph. Location/profile/review notices and
  the dish dialog description are no longer visible; accessible provenance remains
  in screen-reader text. No fixture values are used in booking/order APIs.
- Decoded the source PNG alpha channels read-only to check the visible image
  bounds. Both bowls fit within the composition at each required width, with no
  visible bowl pixels cut off (alpha > 10). This is a calculated geometry check,
  not a browser screenshot or proof of pixel-perfect rendering.

| Viewport width | Art width × height | Both visible bowls contained |
| -------------- | ------------------ | ---------------------------- |
| 320            | 288 × 141.36       | Yes                          |
| 390            | 358 × 175.72       | Yes                          |
| 768            | 704 × 345.54       | Yes                          |
| 1440           | 806.8 × 396        | Yes                          |

Chrome remains unavailable to the browser tool. Final visual and keyboard checks
of this latest presentation are pending. Database addresses, protected booking
logic, policies and the guarded demo address seeder are unchanged by this update.

The earlier full-bleed implementation and its verification report follow for context.

Scope: hero alignment and a consistent restaurant identity/address through the
existing listing, detail, booking and reservation summary. Earlier uncommitted
homepage/authentication presentation work is preserved. No profile/menu/dashboard
redesign, migration, authentication change, commit, push or deployment is included.

## Hero

The artwork was inside the 1440px centered `SiteContainer`, so larger viewports
exposed a green strip beyond the clipped artwork. The art is now a sibling of
that container, positioned relative to the full-width hero and anchored right.
The heading, subtitle and buttons retain the existing content grid.

At 1440px the art layer is 806.8px wide: the front image is 532px wide and the
back image 500px wide, with its right edge at the section edge. Native image
aspect ratios/transparency and both original PNG byte streams are preserved.
The art scales proportionally between 1280 and 1440px and stays a separate row
below the copy at smaller widths. Section overflow clips only the intended
right-edge overhang/shadows; no page-wide overflow workaround is added.

These are implementation coordinates based on the previously inspected Figma
properties and the supplied target screenshot. New visual comparisons at
1440/768/390/320px remain pending: the browser tool currently reports no available
Chrome connection. Earlier screenshots do not verify this new revision.

## Restaurant identity

`homepageLocations()` always returns the database restaurant address. The
presentation catalog can select/order the reference photo and separate labelled
Figma statistics, but cannot replace the address, ID, slug or booking destination.
`referenceAddress` is presentation metadata only. Whole cards remain semantic
Inertia links with visible focus and actual database slugs.

The existing booking flow already uses slug route binding and restaurant-scoped
availability. `StoreBookingRequest` checks table ownership and active status;
`RestaurantBookingService` repeats the ownership check through the locked
restaurant's `tables()` relationship. Reservations are created through that same
restaurant relation. Those protections were inspected and left unchanged.

Confirmation and dashboard summaries load the related restaurant's actual
address. No client restaurant ID, Figma capacity, occupancy, rating or price is
used in persistence or availability calculations.

## Dedicated demo database setup

The existing local database was inspected read-only: environment `local`, IDs
1–3, slugs `location-1`–`location-3`, addresses `Demo Address 1`–`Demo Address 3`.
**No local/development/production records were changed by this task.** Until the
following setup is run on a designated demo database, all pages consistently
display those existing addresses rather than Figma-only address substitutions.

| Existing slug | Approved database address |
| ------------- | ------------------------- |
| `location-1`  | 48 Rustaveli Avenue       |
| `location-2`  | 14 Baratashvili Street    |
| `location-3`  | 9 Abashidze Street        |

1. Use an explicitly designated, backed-up demo/staging database. Confirm the
   application's database connection points there, not to development/production.
2. In that environment's uncommitted configuration set `APP_ENV=demo` (or
   `staging`) and `GREEN_TASTY_DEMO_ADDRESS_ALIGNMENT=true`. Rebuild/clear its
   normal configuration cache after changing configuration.
3. Run `php artisan db:seed --class=GreenTastyDemoAddressSeeder` in that environment
   (through Sail if applicable).
4. Disable the flag again and refresh the environment's configuration cache.

Do not change a production application's environment or override `APP_ENV` just
to bypass the seeder guard. The flag is disabled by default; the seeder also
refuses production/local environments even with the flag enabled. It is not
registered in `DatabaseSeeder` and does not run automatically on deployment.

The address-only seeder locks and validates all three existing fixtures inside
one transaction. Each must have the expected demo name/slug and either its exact
legacy address or approved address. Missing/edited fixtures abort the entire
operation. It updates only changed addresses (plus normal `updated_at`), retaining
IDs, slugs, names, status, phone, tables, menus, reservations, users, invitations,
orders and historical prices. A second run makes no writes.

For a brand-new empty demo database, the existing `GreenTastyDemoSeeder` may first
initialize the three locations; then run this address-only alignment. Do not run
that older general-purpose seeder against an existing populated database: it
also updates menus/tables and writes legacy addresses. This task deliberately
does not change or automatically execute it.

## Verification

Regression coverage includes all three real detail/booking destinations, database
addresses before/after alignment, Vue availability/submission URLs, persisted
restaurant/table association, confirmation/dashboard address consistency, and
HTTP plus direct-service rejection of foreign restaurant tables. Seeder tests
cover idempotency, demo/staging environments, disabled/production/local guards,
all-or-nothing refusal and preservation of existing relational data.

Final automated results:

| Check                                               | Result                                             |
| --------------------------------------------------- | -------------------------------------------------- |
| Full MySQL `composer run ci:check` through Sail     | 370 total, 366 passed, 4 skipped; 3427 assertions  |
| Full SQLite in-memory CI through Sail               | 370 total, 353 passed, 17 skipped; 3279 assertions |
| Vue component/interaction tests                     | 169 passed across 13 files                         |
| TypeScript, PHPStan, Pint, frontend lint/formatting | Passed                                             |
| Production `npm run build` through Sail             | Passed; existing optional Fontaine advisory        |
| `git diff --check`                                  | Passed                                             |
| Original supplied PNG assets                        | 16/16 SHA-256 checksums unchanged                  |

The existing skips are disabled two-factor tests and, on SQLite, MySQL-specific
locking/migration/collation tests. Browser navigation, focus, viewport screenshots and pixel comparison remain unverified
until the Chrome tool connection is available. No successful browser booking
against existing development data is claimed; persistence is exercised only
inside isolated automated test databases.

A final read-only check confirmed `APP_ENV=local`, the alignment flag `false`,
and all three legacy addresses unchanged. Automated checks passed; final browser
acceptance and applying the address-only setup on a designated demo database remain.

## Files in this increment

- `.env.example`, `config/demo.php`: disabled-by-default manual seeder setting.
- `database/seeders/GreenTastyDemoAddressSeeder.php`: guarded, atomic address alignment.
- `tests/Feature/DemoRestaurantIdentityTest.php`: 12 regression cases; fixture IDs
  deliberately differ from the Location 1–3 suffixes.
- `resources/js/components/restaurants/HomepageHero.vue`: full-width art anchoring.
- `resources/js/lib/homepage.ts`: database address plus separate reference metadata.
- `resources/js/components/restaurants/HomepageLocationCard.vue`: reference statistics
  remain separate from the displayed database address.
- `resources/js/components/restaurants/RestaurantProfileHero.vue` and
  `resources/js/pages/restaurants/Show.vue`: retain existing reference artwork/reviews
  selection while displaying the database address; no layout redesign.
- `resources/js/components/restaurants/RestaurantLanding.test.ts`,
  `resources/js/components/restaurants/FigmaScreens.test.ts`,
  `resources/js/pages/bookings/Create.test.ts`: identity/address/navigation regressions.
- This report and `docs/homepage-figma-fidelity.md`: setup, scope and verification limits.

There are 48 changed paths in the working tree including earlier uncommitted work
and supplied assets. This increment touches the 14 paths above and preserves all
16 originally supplied PNG checksums. Nothing is staged or committed.
