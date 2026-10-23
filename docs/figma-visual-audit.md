# Figma visual refinement audit

Branch: `feature/figma-visual-refinement`, based on freshly fetched `origin/main`
at `9b07ddf`. Baseline recorded on 23 October 2026 **before application edits**.
This phase changes presentation only; API contracts, policies, services, money,
booking rules, invitation security and persisted records remain authoritative.

## Reference and evidence

The original [EPAM IT Marathon design](https://www.figma.com/design/P9rkBNLJZ3WabXSQX9b5DO/EPAM-IT-Marathon-6.0)
was accessible in Chrome. The **Design for Developers** page was inspected,
including its current **New Green** palette rather than **DO NOT USE Green**.
The frame properties and component inspection panel supplied the values below.

| Reference              | Node            | Verified observations                                                                                               |
| ---------------------- | --------------- | ------------------------------------------------------------------------------------------------------------------- |
| Visitor main page      | `111691:73805`  | 1440px, cream page, 68px white header, 112 × 38px logo, salad artwork, three location cards                         |
| Restaurant profile     | `111691:73904`  | Large restaurant photograph, overlapping white information card, green hero, customer reviews                       |
| Book a Table           | `112656:157169` | Pale green `#D9E5D9`, white search panel, large reserved-table photograph                                           |
| Signed-in confirmation | `112656:163769` | Centered white modal over results; booking metadata and actions                                                     |
| Reservations           | `112599:148445` | Greeting band, three card columns, pastel status strips, compact icon metadata                                      |
| Guest invitation       | `112080:145288` | White dialog; its Modal window `112080:145867` is 520px wide, radius 24px, padding 40px, gap 24px                   |
| Guest menu             | `111683:86716`  | Four dish columns, image hero, category navigation, 24px card corners and padding; card `111683:86741` has 16px gap |
| Submitted group order  | `112599:148472` | White grouped participant sections in a modal, quantity and price columns, prominent total                          |
| Current color palette  | `112669:171336` | `#232323` text, `#DADADA` border, `#D9E5D9` green background, `#F7F7EC` cream, `#0F7A37` / `#0B5B29` green gradient |

Both inspected dish cards (default and hover) and the invitation modal expose
**Grey Shadow**: `0 0 10px 4px #DADADA` at 70% opacity. Typography uses Poppins;
the existing 48/52px hero, 32/40px section headings and 500 heading weight agree
with the reviewed typography sheet. Header gutters differ between frames:
40px on the guest/menu and profile designs, 52px on the main page. A single
consistent responsive shell is retained rather than switching gutters by route.

The invitation overlay (`112080:145329`) is black at **45%** opacity. The app's
baseline shared dialog overlay uses 80%, making the underlying page too dark.
This additional difference was recorded before changing the overlay style.

Original logo SVG export, PNG/SVG export controls and Copy as SVG were attempted.
They did not produce a usable downloaded file or clipboard asset. No traced,
generated or stock replacement is introduced. Original exports are still needed.
Figma editor screenshots are reference evidence, not production image assets.

## Baseline differences, recorded before changes

| Screen                       | Visual differences                                                                                                                                                    | Planned scope / retained limitation                                                                                                                                     |
| ---------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| All screens                  | Pale green is `#D6E5D5`, borders `#E4E7DF`; many cards use 16px corners, ordinary bottom shadows; dialogs use 8px corners, 24px desktop padding                       | Correct verified palette, card and dialog geometry/shadow; retain accessible focus and 44px controls                                                                    |
| Shared shell                 | Text wordmark replaces original logo; header has Locations and My reservations destinations instead of the mock navigation/account icon; mobile wraps                 | Keep working navigation; original logo export needed; no invented global booking destination                                                                            |
| Restaurant listing           | No salad hero artwork, popular-dish photos/section or restaurant photos; placeholder image blocks; extra restaurant names/table counts and menu navigation            | Refine card surface; retain real seeded data and working actions; photos require exports and record mapping; popularity has no defined data source                      |
| Restaurant details           | Smaller two-column hero with utensil placeholder rather than photograph/overlap; no atmosphere description, ratings or reviews; menu follows on same page             | Refine surface/buttons; keep data-backed combined details/menu, no fabricated reviews or descriptions                                                                   |
| Booking                      | Cream rather than green form area; ordinary bordered search panel; native controls/radio results instead of photo cards, slot grid, visual calendar and steppers      | Apply verified green form-area treatment and panel geometry; keep actual availability and accessible native inputs; no fake photo or global location search             |
| Booking confirmation         | Dedicated page with reference/table/timezone rather than modal; equal primary button emphasis; plain definition list                                                  | Refine white confirmation surface and action hierarchy; preserve persisted confirmation and navigation                                                                  |
| My Reservations              | Larger title/intro, taller cards with full names and timezone; generic semantic status strips; no dining illustration for empty state                                 | Refine cards; retain backend status labels, pagination and extra useful metadata; no fabricated phone/help, feedback/delete/reschedule controls                         |
| Reservation dashboard        | Full page instead of modal/card composition; more explicit headings and metadata; participants shown as cards                                                         | Apply shared surfaces consistently; keep readable owner-only full page and API data                                                                                     |
| Invitation management        | Individual guest cards with expiry, response and revoke/regenerate controls; create form and confirmations differ from single shared-link mock                        | Refine verified dialog dimensions/radius/padding; retain individual credentials, one-time link display and explicit confirmations                                       |
| Guest dish selection         | No hero/dish photos or weights; sections rather than filtering tabs; persistent desktop order panel rather than drawer; stack on mobile                               | Refine dish/order surfaces and category spacing; keep historical prices, quantities, precise totals, real save states and keyboard access                               |
| Consolidated group pre-order | Full-page aggregate followed by expandable guest sections; explicit multiple historical prices; green total panel rather than modal footer                            | Refine grouped cards/total hierarchy; retain server totals, actual Submitted status/timestamp and immediate lock, no invented restaurant acceptance or 30-minute cutoff |
| States/responsiveness        | Loading, retries, errors, invalid invitations and pagination are not completely specified in the reviewed Figma frames; no verified 320/390/768px mobile counterparts | Keep existing states and accessible wrapping; document responsive behavior as implementation choices, not exact mobile Figma measurements                               |

Prices have no configured currency: no dollar sign is added. Unavailable dishes
stay hidden as required by the existing backend. No new booking states, contact
numbers, image-to-record mapping or design-only functionality is inferred.

## Baseline browser review

Production assets were served at a separate loopback origin using a guarded,
temporary SQLite database, synthetic owner/participants, real session login,
CSRF middleware and actual services. Development MySQL data, `.env` and the
existing Sail/Vite processes were not modified.

Baseline full-page screenshots are outside Git at
`/private/tmp/green-tasty-figma-audit/before-{screen}-1440.jpg`. Screens include
`listing`, `details`, `booking`, `confirmation`, `my-reservations`, `dashboard`,
`invitation-dialog`, `submitted-summary`, `guest-selection` and `guest-locked`.
All nine page types measured `innerWidth = scrollWidth = 1440` before edits.
The invitation section and consolidated draft summary are part of `dashboard`.

## Incremental changes and final verification

### Completed improvements

1. Corrected the pale green and neutral border tokens. Completed scoped input,
   secondary, popover and destructive tokens so Green Tasty dialogs/controls do
   not inherit the starter account theme. Muted text uses the palette's darker
   `#454444` neutral for readable small text on both white and cream surfaces.
2. Added one reusable `.gt-card` surface with the verified 24px radius and Grey
   Shadow. Restaurant/dish cards, reservation cards/overview, participants,
   consolidated and per-guest selections, submission panel and guest order panel
   share it. Restaurant-card hover borders and keyboard outlines remain visible.
3. Applied the current green gradient to primary shared buttons and green
   outlined secondary buttons. Hover, focus and disabled behavior remain; the
   confirmation page now has one primary **View reservation** action, with
   **View menu** and **Book another table** secondary.
4. Applied the verified pale green booking background and white search surface,
   retaining native date/time inputs, availability queries and radio selection.
5. Refined only Green Tasty's portaled dialogs: 520px maximum width, 24px corners,
   40px desktop / 24px mobile padding, 24px gaps, 24/32px medium headings and the
   verified 45% overlay. Close buttons have 44px targets; titles reserve room for
   them. The account/settings dialogs keep their existing theme. Focus trapping,
   initial focus, Escape and focus restoration remain handled by existing Reka
   components and application code.

No Laravel files, migrations, dependencies, data contracts, security settings,
business calculations or request handlers changed. Existing behavioral tests
cover the affected components; no tests assert cosmetic class strings.

### Responsive and interaction checks

Full-page Chrome screenshots use real production assets and synthetic fixtures.
The actual DOM viewport width was checked, rather than inferred from a resize
request. The matrix below covers nine page variants, plus invitation and
submission confirmation dialogs at each width (44 final captures).

| Screen / state                                               | 320px | 390px | 768px | 1440px |
| ------------------------------------------------------------ | ----- | ----- | ----- | ------ |
| Listing                                                      | Pass  | Pass  | Pass  | Pass   |
| Restaurant details and menu                                  | Pass  | Pass  | Pass  | Pass   |
| Booking form                                                 | Pass  | Pass  | Pass  | Pass   |
| Persisted booking confirmation                               | Pass  | Pass  | Pass  | Pass   |
| My Reservations, six cards                                   | Pass  | Pass  | Pass  | Pass   |
| Owner dashboard, invitation statuses and draft group summary | Pass  | Pass  | Pass  | Pass   |
| Submitted group summary                                      | Pass  | Pass  | Pass  | Pass   |
| Guest menu and saved draft selections                        | Pass  | Pass  | Pass  | Pass   |
| Guest locked selections                                      | Pass  | Pass  | Pass  | Pass   |
| Create invitation dialog                                     | Pass  | Pass  | Pass  | Pass   |
| Submission confirmation dialog                               | Pass  | Pass  | Pass  | Pass   |

Every page measured `document.documentElement.scrollWidth === innerWidth` at all
four widths. The header wraps on phones; cards stack, then use tablet/desktop
columns; guest order panels move below the menu before the desktop breakpoint.
Full-page images also include the lower consolidated summary and grand total.
Long pages remain vertically scrollable, rather than hiding content.

Keyboard checks confirmed invitation-name initial focus, a 44px Cancel button,
Escape/Cancel restoring focus to Create invitation, and submission initially
focusing **Keep reviewing**. Submission dialogs fit the tested viewports; their
existing max-height/overflow handles shorter screens. No order was submitted as
part of the visual checks. Live availability also rendered real table radio
options at 320px, with confirmation disabled until a table is chosen. Saved
historical guest prices and the 31.50 consolidated total remain visible; submitted
guest views contain locked text and no selection/save controls.

Screenshots stay outside the source tree at
`/private/tmp/green-tasty-figma-audit/after-{screen}-{width}.jpg`, using the baseline
screen names above plus `submission-dialog`. An additional capture is
`after-booking-availability-320.jpg`. An invalid invitation was also checked at
320px; its generic error state fits without exposing reservation data. The
temporary server, SQLite database, fixture credentials and preview scripts were
removed after verification. Browser viewport overrides were reset and audit tabs
closed. Only screenshots and viewport measurements remain outside the repository.

### Automated checks

| Check                                                                   | Result                                                                          |
| ----------------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| MySQL `./vendor/bin/sail composer run ci:check`                         | Passed: 313 PHP tests, 4 skipped, 2,747 assertions                              |
| SQLite complete CI, `DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL=` | Passed: 304 PHP tests, 13 skipped, 2,627 assertions                             |
| Vue component / interaction tests                                       | 131 passed across eight files, in both CI runs                                  |
| TypeScript, PHPStan, Pint, frontend lint and formatting                 | Passed                                                                          |
| Production build                                                        | Passed; existing optional `fontaine` optimization advisory remains non-blocking |

The four shared skips concern disabled two-factor authentication. SQLite also
skips nine MySQL-specific locking/migration checks, which pass in the MySQL run.
Existing coverage includes booking concurrency, owner authorization, invitation
privacy/revocation, guest isolation, historical decimal prices, idempotent
submission, locked guest selections and error/retry interactions.

### Remaining visual differences and next references

- **Highest impact:** obtain the original logo SVG, transparent salad hero art,
  both dining empty-state illustrations and restaurant/dish photograph exports.
  Restaurant/dish images also need an explicit mapping to existing records. The
  text wordmark and utensil placeholders remain; no replacement assets were
  invented. These gaps prevent a claim of pixel-perfect parity.
- The design's popular dishes, ratings, reviews, atmosphere text, daily occupancy,
  dish weights, preparation estimates and currency require real data or product
  requirements. They are not fabricated during a visual pass.
- Dedicated booking confirmation, owner dashboard and guest order panel remain
  pages/panels rather than Figma's modals/drawers. Individual expiring invitations
  remain distinct from its shared invitation mock. Actual submission immediately
  locks selections; no restaurant-acceptance state or 30-minute cutoff is added.
- My Reservations retains taller cards with full restaurant names, explicit
  timezone, actual status labels, six-item pagination and working dashboard links.
  Exact Figma status-strip colors and the empty-state illustration remain open.
- The shared header keeps working navigation and the text wordmark. Main-page
  side gutters remain 52px at desktop, while some other Figma frames use 40px.
  Existing Lucide icons and responsive breakpoints remain; the design's exact
  mobile frames, original icon exports and complete hover/error specifications
  would be needed for stricter matching. Accessibility focus indicators and 44px
  controls are retained even where small mock controls differ.

This is the first verified refinement increment, not a declaration of complete
visual parity. No commits, pushes or merges were made.

### Changed files

- `resources/css/app.css`
- `resources/js/components/restaurants/RestaurantCard.vue`
- `resources/js/components/restaurants/MenuDishCard.vue`
- `resources/js/components/guest/GuestOrderPanel.vue`
- `resources/js/components/reservations/ReservationListCard.vue`
- `resources/js/components/reservations/ReservationOverview.vue`
- `resources/js/components/reservations/ReservationParticipants.vue`
- `resources/js/components/reservations/GroupPreOrderSummary.vue`
- `resources/js/components/reservations/GroupPreOrderSubmission.vue`
- `resources/js/pages/bookings/Create.vue`
- `resources/js/pages/bookings/Confirmation.vue`
- `docs/figma-visual-audit.md`
