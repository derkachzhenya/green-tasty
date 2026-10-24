# Homepage Figma fidelity

Latest incremental scope: [hero and booking identity refinement](homepage-booking-consistency.md).
That follow-up replaces the display-only address aliases described in this earlier
audit with database addresses and adds a guarded address-only demo seeder. Earlier
browser screenshots and test counts below describe their respective iterations,
not verification of the latest hero geometry.

## Follow-up: three supplied screens (24 October)

The user explicitly approved Figma prices, weights, nutrition, ratings and reviews
as **labelled demonstration data only**. This supersedes the initial decision to
show missing-price dashes and exclude reference statistics. All fixtures live in
`resources/js/lib/figma-demo.ts`; they never enter an API request or database record.
Live matching menu prices still take precedence on homepage cards. Booking,
invitations and individual/group pre-orders retain their existing real data.

Added the separate Restaurant Profile and read-only dish dialog shown in the new
screenshots. Customer Reviews remain off the homepage. The real menu and booking
actions remain accessible on the profile below the reference section.

Properties inspected directly in Figma before edits:

- Restaurant Profile: 1440 × 1264; about section top 68, height 587, `#D6E5D5`.
- Restaurant image: 997 × 587. Downloaded its original linked photograph
  (`2ca088be76801e0658e3beb2c747887a28377996`) and made a 2048px, quality-82
  web JPEG (881 KiB). Raw downloaded originals are also present in the asset
  directory, are preserved, and are not requested by the application.
- Information card: width 580, top 115, left 820, padding/gap 24, radius 12;
  white/cream gradient and Grey Shadow. A visible demo disclosure is added.
- Review card: 316 × 324, radius 24, white, Grey Shadow. Review text and identities
  are transcribed from the user's screenshot; the layer panel repeats master text
  for overridden review instances, so the screenshot is the visual reference.
- Dish dialog: width 838, height 360, left 301/top 270 in the 1440 × 900 frame,
  padding 40/40/24/40, gap 32, radius 24, Grey Shadow.
- Dish picture: 294 × 296, circular crop. Used a direct 2x Figma PNG export
  (`strawberry-detail.png`, 644 × 648 including the export's shadow gutter).
- Strawberry ingredients, ~503 kcal, protein/fat/carbohydrate ranges and vitamins
  were read from the actual Figma text layers. Other dishes have no invented macros.

The design itself differs between the $17 card and $15 strawberry dialog. Both
are retained only as illustrative demo values. They are not live quotations.
Category controls preview the same four sample reviews, sorting works locally,
and unavailable extra pages are disabled rather than fabricated.

The new dialog uses existing Reka UI primitives for focus trapping, Escape,
outside-click dismissal and focus restoration. It contains no purchase/selection
form. An explicit demo label identifies prices, nutrition, statistics and reviews.
All 16 previously supplied PNG files remain byte-identical. Two new web assets
and two raw Figma image originals are now present in the asset directory.

Browser verification of this follow-up is pending: Chrome disconnected during the
Figma export. The user reported reconnecting, but browser inventory still returned
no available browser after reconnect/reset. Earlier screenshots below document the
previous homepage iteration, not proof of the new profile/dialog. Do not claim a
verified pixel-perfect match or keyboard/viewport verification for these new screens.

Follow-up checks:

| Check                                          | Result                                                      |
| ---------------------------------------------- | ----------------------------------------------------------- |
| MySQL full CI                                  | 354 passed, 4 skipped; 3212 assertions                      |
| SQLite in-memory full CI                       | 341 passed, 17 skipped; 3064 assertions                     |
| Vue                                            | 165 passed in 13 files; 5 new profile/dialog/review tests   |
| TypeScript, PHPStan, Pint, lint and formatting | Passed                                                      |
| Production build                               | Passed; existing optional Fontaine/plugin timing advisories |
| Original supplied assets                       | All 16 SHA-256 checksums unchanged                          |
| `git diff --check`                             | Passed                                                      |

Vue tests cover actual booking/menu destinations, unrelated-location isolation,
demo disclosures, review sorting, category state, disabled unavailable pages,
dialog open/close, read-only behavior and absence of invented nutrition. Updated
homepage tests cover the explicitly approved demo fallback. Real Reka focus and
responsive screenshot checks remain pending for the new screens.

## Baseline audit, before application edits

24 October 2026. Branch `feature/homepage-figma-fidelity` starts at fetched
`origin/main`, `862f11c` (merged authentication PR #16). The only pending PR is
Dependabot #1, unrelated GitHub Actions updates. GitHub's connector did not have
repository access; the signed-in GitHub browser UI confirmed these facts. No
commits, pushes, merges, resets or development-data writes are part of this task.
Existing migration files are unchanged. The only pre-existing untracked paths
are the supplied images.

The official [EPAM IT Marathon Figma](https://www.figma.com/design/P9rkBNLJZ3WabXSQX9b5DO/EPAM-IT-Marathon-6.0)
is accessible through Chrome, although the web fetch tool cannot open it. The
current **DESIGN FOR DEVELOPERS → Main Page & About Location → Visitor role/Main
page** frame is the target. **Restaurant Profile** is a separate adjacent frame:
its Customer Reviews, ratings and supplied avatars do not belong on this homepage.
Backlog food-preference/nutrition badges are not approved homepage requirements.

| Element               | Inspected node     | Verified Figma properties                                                                                     |
| --------------------- | ------------------ | ------------------------------------------------------------------------------------------------------------- |
| Main frame            | `111691:73805`     | 1440 × 1460, cream `#F7F7EC`                                                                                  |
| Header                | `111691:73835`     | 1440 × 68, white, 1px bottom `#DADADA`; Main page / Book a Table / Sign In                                    |
| Hero                  | `111691:73806`     | 1440 × 396, top 68, frame fill `#D6E5D5`                                                                      |
| Hero text and buttons | `111691:73807`     | Left 56, top 88 inside hero; 613 × 220, vertical gap 24                                                       |
| First bowl group      | `111691:73817`     | Left 634, top 3.4; 532.02 × 404.42                                                                            |
| Second bowl group     | `111691:73814`     | Left 940.8, top −154.2; 617.22 square including shadow/rotation                                               |
| Second bowl image     | `111691:73816`     | Original design layer 539.15 square, rotation 9.05°; supplied export has different cropped transparent bounds |
| Main content          | `111691:73820`     | Left 52, top 524, width 1336, height 883, section gap 60                                                      |
| Dish grid             | `111691:73824`     | 1336 × 308, horizontal gap 24                                                                                 |
| Dish card             | `111691:73825`     | 316 × 308, padding 24, gap 16, radius 24, white                                                               |
| Dish image            | child of dish card | 196 square, circular crop; supplied source is 224 square                                                      |
| Dish information      | child of dish card | 268 × 48, horizontal space-between                                                                            |
| Locations section     | `111691:73829`     | 1336 × 443, heading-to-cards gap 32                                                                           |
| Location card/photo   | `111691:73832`     | Card 432 × 371; photo 432 × 243 with 24px top corners                                                         |
| Card effect           | Grey Shadow        | X/Y 0, blur 10, spread 4, `#DADADA` at 70%                                                                    |
| Typography sheet      | Properties styles  | Poppins; H1 48/52, H2 32/40, H3 26/40, navigation 20/32, body 14/24, caption 12/16                            |

The inspected hero frame still explicitly uses `#D6E5D5`, while the application's
shared refined palette uses `#D9E5D9`. Match that frame locally in the homepage
hero, leaving the shared palette and account/booking/pre-order surfaces intact.
Mobile counterparts and complete hover/focus/error specifications were not
verified; responsive adaptations and accessible focus remain implementation choices.

### Initial differences

- Text-only logo; 14px navigation and Locations link; missing official image logo
  and Book a Table label. Account state and routes already work.
- Hero has the correct words but no bowls and only Explore locations; missing
  primary/secondary booking/menu CTAs and exact 56px copy alignment.
- No popular-dish section or image-to-dish mapping.
- Location cards have utensil placeholders, full seeded restaurant names and extra
  navigation text, rather than the approved photos/address hierarchy.
- Existing loading/empty/retry states are useful and must remain.

### Supplied assets and data decisions

All 16 supplied PNG files were hashed before edits. Do not alter their bytes.
Contrary to the task's missing-asset note, `dishes/salad1.png` (532 × 405),
`dishes/salad2.png` (500 × 532), and `locations/Picture.png` (460 × 271) are
present and visually match the two reference bowls and Rustaveli photo. Use those
exact files with their native aspect ratios; do not enlarge dish thumbnails into
hero art. The source bowl export's transparent bounds differ from Figma's internal
rotated layer, so visual placement must be compared, not claimed as an identical
internal transform. The logo is exactly 112 × 38. Dish sources are 224 square;
location sources are 460 × 271. Photos may be cropped with CSS object-fit while
retaining source proportions. Avatars are intentionally unused on this frame.

The current database has only `location-1` / `location-2` / `location-3`, with
Demo Address 1–3, and Garden Salad / Grilled Vegetables. The user explicitly
approved presentation mapping Location 1 → 48 Rustaveli Avenue, Location 2 → 14
Baratashvili Street, Location 3 → 9 Abashidze Street, with visible demo labels.
Keep actual IDs/slugs and real detail routes; do not update these records.
Exact real address matches should take precedence over this approved demo mapping.
Unmapped active restaurants must remain accessible; inactive records must not
gain fabricated links or stats.

The four named popular dishes have no matching current records. There is no dish
weight, popularity statistic, occupancy, currency configuration or reviews source.
Keep the fixed Figma names/order and original photos as a labelled design preview;
use real active-menu prices when matching names exist, otherwise the explicitly
approved Figma demo price/weight. Demonstration statistics apply only to the mapped
demo locations; unrelated real locations retain their actual table counts.
The preview price is the lowest current available price across active locations,
labelled “from”; it is not a booking quote or a historical pre-order price.

## Implemented refinement

- Reused SiteContainer, Button, DeferredSection, existing public listing and generated
  route helpers. The shared header now uses the original 112 × 38 logo, current-page
  underline, Book a Table and the existing Sign In / My reservations destinations.
  Tablet navigation stays on one line; phones use a two-row header. Account state
  still comes exclusively from the existing authenticated Inertia props.
- Added a reusable hero with the original transparent bowl exports, two-line copy,
  exact desktop geometry and both CTAs. Book a table and View menu both lead to the
  location chooser: the application has restaurant-specific booking/menu routes,
  not an invented global booking or menu endpoint.
- Added four cards in the approved order, preserving native image proportions.
  Export sizes include a 14px shadow gutter: `(224 − 196) / 2` for dishes and
  `(460 − 432) / 2` for photos. CSS accounts for that gutter without rewriting the
  PNGs; thumbnails are never used as hero images. Price columns retain their space
  even when prices are unavailable, preserving the approved title wrapping.
- During comparison, inspected the actual hero shadow ellipses as well: 450 × 17
  and 450 × 19, `#134A28` at 40%, layer blur 50. The desktop CSS uses those values;
  front top 357 is parent top 3.4 + local top 353.6, back top 315 is parent top
  −154.2 + local top 469.2. Phone/tablet art and shadows scale proportionally as an
  implementation adaptation, rather than claiming verified mobile Figma coordinates.
- Added one shared presentation catalog, `resources/data/homepage.json`, for names,
  image mappings and the approved demo slugs. It contains no prices, weights,
  occupancy, ratings or review text. Deploy it with the PHP application, alongside
  the source assets: it is also read by the public price query.
- `HomepageMenuService` issues one read-only query for the minimum current DECIMAL
  price per named dish, restricted to available dishes in active categories at
  active restaurants. Existing public location loading remains a separate read-only
  query. No N+1 queries, client money calculations or pre-order price changes.
- Added a separately deferred `featuredPrices` homepage prop with the existing
  sanitized error/retry pattern. Other data contracts and all protected endpoints
  are unchanged. The auth page reuses the refined landing component as its background
  with prices explicitly resolved as unavailable; it starts no new deferred price
  request and changes no authentication actions.
- Location cards use approved demo labels, original photos, Figma statistics for
  demo locations and actual restaurant detail links. Exact real-address matches take priority;
  arbitrary additional active locations retain their real address/link and the
  existing type of image placeholder. Missing/inactive records acquire no synthetic
  destinations. Loading skeletons match the final desktop card height.

## Previous iteration: browser verification

The guest preview used a new, guarded SQLite file under
`/private/tmp/green-tasty-homepage-fidelity/`, the existing public demo restaurant/menu
seeder, a separate temporary APP_KEY and a distinct session cookie. No users,
credentials, bookings or orders were created in that preview. Existing development
data, `.env`, the running Sail/Vite servers and original `public/hot` were preserved.
Final screenshots loaded production `/build/assets/` scripts, not the Vite client.
Temporary preview scripts, key, database and server are removed after verification;
only screenshots and non-secret measurement/checksum evidence remain outside Git.

At 1440px the measured browser geometry is:

| Element                         | Figma                     | Implemented           | Difference |
| ------------------------------- | ------------------------- | --------------------- | ---------- |
| Header height                   | 68                        | 68                    | 0px        |
| Hero top / height               | 68 / 396                  | 68 / 396              | 0px        |
| Hero copy left / top            | 56 / 156 including header | 56 / 156              | 0px        |
| Popular heading left / top      | 52 / 524                  | 52 / 524              | 0px        |
| Dish cards top / size / gap     | 596 / 316 × 308 / 24      | 596 / 316 × 308 / 24  | 0px        |
| Locations heading left / top    | 52 / 964                  | 52 / 964              | 0px        |
| Location cards top / size / gap | 1036 / 432 × 371 / 20     | 1036 / 432 × 371 / 20 | 0px        |
| Page height                     | 1460                      | 1460                  | 0px        |

These are layout-box comparisons, not a claim that every raster pixel is identical.
Figma's canvas reference is captured at approximately 54% zoom; the comparison
displays the implementation at the same scale. Browser font antialiasing and the
different exported bowl bounds prevent an honest exact-pixel-diff percentage.

| Production viewport | Measured scroll width | Header height | Hero height | Result                                                               |
| ------------------- | --------------------- | ------------- | ----------- | -------------------------------------------------------------------- |
| 1440 × 900          | 1440                  | 68            | 396         | Four dish columns, three location columns; no overflow/broken images |
| 768 × 900           | 768                   | 68            | 588         | Two-column cards, stacked hero art; no overflow/broken images        |
| 390 × 844           | 390                   | 121           | 471.38      | Single-column cards, adjacent CTAs; no overflow/broken images        |
| 320 × 640           | 320                   | 121           | 538.39      | Single-column cards, wrapped CTAs; no overflow/broken images         |

Browser interactions confirmed:

- Book a Table navigates to `/#locations`; the first mapped card opens the real
  `restaurants/location-1` page with actual Garden Salad 8.50 / Grilled Vegetables
  14.00 menu data. Booking from that profile still redirects a guest to real login.
- Sign In opens the existing modal, focuses Email, supports password visibility,
  and Escape returns to the homepage with focus restored to Sign In.
- Keyboard Tab exposes Skip to content; Enter focuses `main-content`.
- The existing authenticated development session still reaches My reservations.
  Its 320px header has no overflow or overlapping controls; no login, logout or
  credential changes were needed to perform this check.
- The production guest preview reported no browser warnings/errors. Loading,
  independent price/location failures, retry, duplicate retry prevention and empty
  results are covered by automated component/API tests.

### Screenshots and evidence

All paths below are under `/private/tmp/green-tasty-homepage-fidelity/`:

- `figma-reference-editor.jpg`: full Figma editor evidence.
- `figma-main-page.jpg`: clean target frame crop.
- `figma-side-by-side.jpg`: original and implementation at the same display scale.
- `responsive-comparison.jpg`: all four requested viewport captures together.
- `homepage-1440x900.jpg`, `homepage-768x900.jpg`, `homepage-390x844.jpg`,
  `homepage-320x640.jpg`: exact-size viewport screenshots.
- `homepage-{1440,768,390,320}-full.jpg`: corresponding complete pages including
  locations below the fold.
- `sign-in-320x640.jpg`, `authenticated-header-320x640.jpg`: interaction evidence.
- `before-1440x900.jpg`: pre-implementation capture (the original viewport capture
  is supplementary evidence; final dimensions were explicitly measured).
- `layout-metrics.json`, `asset-checksums.json`: measured boxes and the unchanged
  SHA-256 checksums for all 16 supplied PNGs.

### Automated checks

| Check                                                | Result                                                      |
| ---------------------------------------------------- | ----------------------------------------------------------- |
| Full MySQL `./vendor/bin/sail composer run ci:check` | 358 total; 354 passed, 4 skipped; 3212 assertions           |
| Full SQLite in-memory CI                             | 358 total; 341 passed, 17 skipped; 3064 assertions          |
| Vue component/interaction tests                      | 160 passed across 12 files, including 11 new homepage tests |
| TypeScript, PHPStan, Pint, frontend lint/formatting  | Passed                                                      |
| Production build                                     | Passed; existing optional Fontaine optimization advisory    |
| `git diff --check` and supplied-image checksums      | Passed; all 16 PNGs byte-identical                          |

Five new Laravel cases cover public deferred prices, active/available data filtering,
exact decimal starting prices, empty results, a single read-only query, unchanged
records and sanitized independent retry. The complete suites retain authentication,
email-code/security, booking, real MySQL concurrency, authorization, invitations,
guest isolation, historical prices, idempotent submission and selection locking tests.
Four shared skips concern disabled 2FA; SQLite additionally skips MySQL-only tests.

## Remaining differences and deployment considerations

1. Four dish names/photos remain a clearly labelled design preview until matching
   menu records are supplied. The current six actual menu items are different.
   Missing prices use the approved demo `$17` and `430 g`; real matching prices are
   displayed as “from” DECIMAL strings. No fake menu records are created.
2. Figma's table totals and daily occupancy are mock data, displayed only on mapped
   demo cards and identified by the shared disclosure and Demo Address labels.
   Exact address matches automatically avoid the demo label when real records exist.
3. The preview disclosure occupies part of Figma's otherwise empty 60px section gap
   on desktop. On phones it remains in normal flow so it cannot overlap content.
4. Bowl PNG bounds differ from internal Figma image transforms. Original bytes and
   proportions are retained; images/shadows were compared visually, with verified
   desktop geometry and proportional phone/tablet positioning. Complete mobile and
   hover/error designs were not available in the reviewed target frame, so these
   adaptations are not presented as measured Figma mobile specifications.
5. Profile reviews, atmosphere and rating are labelled design examples, not real
   customer feedback or an actual branch rating. Other dishes lack detailed Figma
   ingredient/nutrition references; only the strawberry dialog includes these.
   The live menu and reservation section extend the profile below the Figma frame
   to preserve existing functionality. No new review API or fake destinations exist.
6. Include `resources/data/homepage.json` and all referenced public PNGs in deployment.
   No new dependencies, migrations, credentials, auth flags, permission changes,
   or seed resets are required. The optional Fontaine warning remains non-blocking.

## Changed paths

42 paths total: the 22 application/test/documentation paths below, plus the 16
pre-existing supplied PNG files and four new Figma-derived images under
`public/images/green-tasty/`. All supplied PNG bytes are preserved.
Ignored `.DS_Store` files are not included. Nothing is staged or committed.

- `app/Http/Controllers/RestaurantController.php`
- `app/Services/HomepageMenuService.php`
- `resources/data/homepage.json`
- `resources/js/lib/homepage.ts`
- `resources/js/lib/figma-demo.ts`
- `resources/js/components/site/SiteHeader.vue`
- `resources/js/components/restaurants/HomepageHero.vue`
- `resources/js/components/restaurants/PopularDishCard.vue`
- `resources/js/components/restaurants/HomepageLocationCard.vue`
- `resources/js/components/restaurants/RestaurantLanding.vue`
- `resources/js/components/restaurants/RestaurantGridSkeleton.vue`
- `resources/js/components/restaurants/DishPreview.vue`
- `resources/js/components/restaurants/RestaurantProfileHero.vue`
- `resources/js/components/restaurants/FigmaReviews.vue`
- `resources/js/components/restaurants/FigmaScreens.test.ts`
- `resources/js/components/restaurants/RestaurantLanding.test.ts`
- `resources/js/pages/restaurants/Index.vue`
- `resources/js/pages/restaurants/Show.vue`
- `resources/js/pages/auth/Flow.vue` (background presentation prop only)
- `resources/js/pages/Dashboard.test.ts` (updated navigation labels; routes unchanged)
- `tests/Feature/HomepageTest.php`
- `docs/homepage-figma-fidelity.md`

Preview locally through the existing application at `http://localhost:8080/` with
Sail/Vite running, or use the production build with your normal Laravel web server.
No database seeding/reset is needed for the existing three locations. The temporary
8093 audit server is stopped after verification; it is not a deployment dependency.
