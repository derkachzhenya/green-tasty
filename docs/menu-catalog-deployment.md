# Persistent restaurant menu and deployment

The guest menu is backed by the application's database. Images alone do not create
menu items. The former `GreenTastyDemoSeeder` created only Garden Salad and Grilled
Vegetables, which explains the one-card guest screens. The earlier isolated browser
fixture was not application initialization.

`resources/data/restaurant-menu.json` is now the versioned starting catalog shared
by `RestaurantMenuInstaller` and the guest image/weight helpers. It contains eight
photographed dishes in the Appetizers category, matching the illustrated menu's
contents and order. Main Courses and Desserts are also created as categories; the
reference does not provide a separate complete catalog for those tabs. On a fresh
installation those tabs use the existing empty-category state until populated.

The known salad prices/weights are transcribed from the reference. At the owner's
request on 2026-10-25, the descriptive names Berry Breakfast Bowl, Avocado & Egg
Toast and Citrus Cream Tart and their 13.00/15.00/11.00 starter prices are accepted
catalog values. Their text was not verified from the clipped Figma second-row
labels; they are no longer awaiting name/price approval. Unknown weights and
preparation times remain omitted. The application still has no currency field;
it does not infer a currency from Figma's dollar symbols.

Green Asparagus Salad uses the original `carly-jayne-Lv174o7fn7Y-unsplash` photograph
downloaded from the official EPAM Figma image panel. It is bundled as
`public/images/green-tasty/dishes/green-asparagus-salad.jpg` and cropped with CSS.
The other seven original photographs were already in the repository. No generated
images or external runtime image URLs are required.

## Install locally

From the repository root, with Sail running:

```sh
./vendor/bin/sail artisan menu:install
./vendor/bin/sail artisan menu:install --apply
```

The first invocation prints the environment, database and target slugs without
writing any rows. The second inserts missing records. Default targets are the
three configured `location-1`, `location-2` and `location-3` slugs. On a fresh database,
those locations and their 2/4/6-seat tables are created. Existing locations, names,
addresses, tables and all existing menu rows are retained exactly. Unknown custom
slugs are refused before writes; an existing custom restaurant can be targeted:

```sh
./vendor/bin/sail artisan menu:install --restaurant=existing-slug
./vendor/bin/sail artisan menu:install --restaurant=existing-slug --apply
```

Before writing, installation validates the entire JSON catalog: nonnegative
fixed-decimal prices within the database precision, JSON booleans, known and unique
category slugs, unique dish names per category, valid labels and existing bundled
dish images. Broken JSON, unknown categories, duplicate entries, missing images
and paths outside the dish asset directory fail with an actionable CLI error.
The validation is performed even in preview mode. It prevents inserting an invalid
price from a later manual catalog edit; the regression first reproduced this issue
with a failing negative-price test.

Installation is transactional, locks each restaurant in sorted order and uses
insert-if-missing behavior. Reruns do not change prices, availability, active flags,
sort order or timestamps of existing rows. They do not create accounts, credentials,
reservations, guests, invitations or orders. They do not reset databases or apply
migrations. Menu/catalog source edits are not an automatic price-update mechanism:
existing database prices remain authoritative, including historical order snapshots.

Existing Starters / Garden Salad and Main Courses / Grilled Vegetables are preserved.
Consequently an existing database may show an extra Starters tab compared with the
three-tab reference. Appetizers is added before the original demo categories, so a
fresh page load opens the eight photographed dishes. Custom existing category order
is respected; select Appetizers explicitly if another category is first.

For the currently open invitation, refresh the guest page with Command-R and select
Appetizers. Its existing reservation/name/cart lifecycle remains unchanged. Create
another individual invitation only when a fresh guest-name/submission lifecycle is
needed. A submitted group remains read-only, as before.

## Include in an AWS release

This is the application initialization procedure, not an AWS infrastructure deployment.
No AWS resource, domain, IAM policy, database instance or deployment was changed or
verified in this task. The existing booking/authentication/invitation/group rules are
not replaced by this catalog installation.

Package the application PHP code, both JSON catalogs under `resources/data/`, all
`public/images/green-tasty/` assets, built `public/build/` files and the built SSR
bundle. The catalog and images must be present in the release, not only in a local
working directory. `public/hot` must be absent in the production release.

Once the server's production environment and database are configured, a release
uses the repository's normal build and migration process, for example:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build:ssr
php artisan migrate:status
# Review pending migrations and back up the existing target before applying them.
php artisan migrate --force
php artisan menu:install
# Verify the printed environment/database before the explicit write step.
php artisan menu:install --apply
php artisan optimize
```

Use the actual public HTTPS domain in APP_URL, APP_ENV=production, APP_DEBUG=false
and the intended MySQL connection. Retain an existing APP_KEY on upgrades. Serve
Laravel from `public/`; grant its process write access to storage/bootstrap cache.
Configure persistent sessions and the real mail transport for authentication flows.
The bundled menu does not provide production login credentials. Keep the built
Inertia SSR server running under the deployment's process manager and verify its
configured endpoint; the stateless guest entry itself is client-rendered.
These are application requirements; HTTPS/proxy behavior, mail delivery, restart
behavior and the complete deployed environment still require acceptance on the
chosen AWS setup. See the official [Laravel 13 deployment documentation](https://laravel.com/framework/docs/13.x/deployment).

No `migrate:fresh`, destructive reset or generic `db:seed` is required. The old
`GreenTastyDemoSeeder` uses update-or-create and may overwrite fixture values; the
new installation command deliberately does not call it or DatabaseSeeder.

## Verified results, 2026-10-25

- MySQL full CI after catalog validation: 455 passed, 4 existing skips, 4,447 assertions.
- SQLite full suite after catalog validation: 439 passed, 20 skips, 4,275 assertions.
- Vue: 207 passed; TypeScript, PHPStan (zero errors), Pint, lint/format passed.
- Production client and SSR builds passed. Build-tool plugin timing diagnostics
  are informational; they do not report compilation failures.
- New tests cover write-free preview, all-or-nothing target validation, fresh setup,
  rerun idempotency, retaining changed prices/availability, keeping old linked orders,
  isolation from untargeted restaurants, new menu API data, server price authority,
  missing sold-out purchase actions and existing stale-write protection.
  Nine additional catalog cases verify invalid negative/scientific prices,
  string availability, unknown categories, duplicate dishes/categories, missing or
  out-of-directory assets and malformed JSON. All fail before creating any rows.
  The negative-price test failed before the fix and passed afterward.
- Local application MySQL was explicitly verified as environment local / database
  laravel. Installation added 6 categories and 24 dishes across 3 existing locations.
  It created no restaurant/table/account/reservation/guest/order rows. A second
  installation created zero records. Every original row hash matched afterward:
  8 users, 3 restaurants, 9 tables, 6 original categories, 6 original dishes,
  8 reservations, 17 guests, 1 order and 1 order item.
- Actual Chrome on localhost:8080 opened the existing Inna invitation for reservation
  8 and displayed all eight photographed dishes in Appetizers, including Sold Out.
  This was a read-only check of the real application database; its cart remained empty.

A separate clean installation was also tested in Chrome using the built guest
entry under `public/build/` with no Vite hot file. It used isolated SQLite fixtures,
not the user's database. All eight dishes and photographs loaded at 1440×1013,
768×1013, 390×844 and 320×640, with no horizontal overflow. The normal scrollbar
reduces the usable content width by 15px on that browser. Price sorting and the
empty Desserts state worked. Name confirmation, saving 17.00 + 10.00, submitting
27.00 and reopening the persisted confirmation all passed. The existing consolidated
service returned the same 27.00 and two units, with group submission still draft.
This verifies the production asset path and catalog initialization locally; it is
not an AWS environment acceptance test. The temporary preview server, database,
raw links and scripts were removed after verification.

Further browser evidence is recorded in the guest fidelity document.
Evidence/logs and row hashes are private and outside Git at
`/private/tmp/green-tasty-catalog/`. There is no database dump, fixture credential or
raw invitation URL in the changed source. Nothing was committed, pushed or deployed.

## Follow-up completion

The owner accepted the existing three descriptive dish names and starter prices;
no further name/price approval is pending. The current permanent catalog was
validated again with `menu:install` in preview mode and was not reapplied. This
follow-up changed only installer validation, its regressions and documentation;
no UI or database row was changed. The preceding production-build/browser results
remain applicable; a new complete browser flow was not repeated for the CLI-only
validation fix. Full MySQL/SQLite/Vue CI and static/format checks were rerun as
reported above. Follow-up evidence is outside Git at
`/private/tmp/green-tasty-catalog-final/`.

Main Courses/Desserts remain empty on a fresh installation until restaurant menu
content is supplied for them; they show the implemented empty state. This is an
explicit catalog-content limitation, not a broken pre-order route. AWS environment
acceptance is still a separate step once a deployment target has been chosen.
No commit, push, merge or deployment has been performed.
