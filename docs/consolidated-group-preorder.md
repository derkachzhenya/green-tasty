# Consolidated group pre-order: owner flow

For the subsequent T−45/T−30 rules and atomic personal-cart editing, see [Pre-order cutoff and owner editing](preorder-cutoff-and-owner-editing.md). Those rules supersede this earlier acceptance snapshot where they differ.

Verified on 2026-10-26. Branch: `feature/consolidated-group-preorder-figma-owner`, based on the existing working dashboard at `3d88a70`. `origin/main` was fetched and inspected; it contains no application changes newer than the dashboard's existing base. The older `feature/consolidated-group-preorder` branch remains intact. No commit, push, merge, or deployment was performed.

## Implemented flow

Reservations → **Group Pre-Order** opens a dialog on the dashboard, without navigating to the legacy reservation page. It displays real reservation metadata, order state, all participants (including empty orders), saved notes, quantities, historical prices, participant subtotals and the authoritative grand total. The list scrolls independently from the header and bottom total/action panel.

The owner can change their own quantities, remove their own lines, open their existing menu/cart, and manage unconfirmed guest drafts while the group is editable. Removing a guest clears that guest's draft selections and revokes invitation access. It retains the guest row, RSVP history, reservation relationships and booked party size. A confirmation explains this effect. Confirmed guest selections cannot be changed or removed by the owner.

Saving the owner's individual cart now opens a confirmation with a Reservations link. Its copy distinguishes saving an individual selection from submitting the entire group. The authenticated owner menu reuses its existing menu/cart and adds navigation; anonymous guest screens remain unchanged.

Final group submission uses the existing submission service. Success feedback returns to Reservations. Reopening the submitted group shows saved quantities/prices with all mutations disabled. Empty owner orders and empty guests remain visible without fabricated dishes. Guests who have confirmed selections are identified as waiting for owner submission.

## Actual state model and Figma gaps

| Persisted state / condition                     | Display and allowed behavior                                                                    |
| ----------------------------------------------- | ----------------------------------------------------------------------------------------------- |
| No group order / draft, reservation upcoming    | Created New Pre-Order; eligible participant changes; submit only a nonempty persisted draft     |
| Guest `pre_order_submitted_at` set, group draft | Actual guest name in pending banner; guest's lines immutable; owner may submit group            |
| Owner quantity changes                          | Preserves price and notes, increments selection version, clears owner's individual confirmation |
| Draft guest removed                             | Clear that guest's draft lines, revoke invitation, retain guest/booking rows                    |
| Group `submitted` with timestamp                | Sent to Restaurant; read-only; successful retries preserve the original timestamp               |
| Reservation started, completed or cancelled     | Read-only under existing reservation rules                                                      |
| Other stored group status                       | Read-only and literal `Stored status: …`; no claim of restaurant approval                       |

The schema currently has no restaurant approval event/actor, per-item accepted/rejected status, rejection reason, acceptance deadline or scheduler. **Confirmed by Restaurant, partial rejection and automatic cancellation after 15 minutes cannot truthfully be implemented or browser-tested with the existing lifecycle.** Raw terminal status values are tested to fail closed, not treated as evidence of restaurant approval. No staff permissions or simulated restaurant responses were introduced.

There is **no established 30-minute cutoff**. This change uses the existing upcoming/start boundary; a reservation 29 minutes away remains editable if otherwise eligible. Once a group is submitted, existing rules lock it immediately, even though some Figma illustrations retain controls. No new editing deadline was invented.

The database has no dish-image URL column. The dialog resolves persisted dish names through the existing bundled catalog/image helper, with an initial fallback for unmatched names. Original public images are reused unchanged. Notes are displayed from `pre_order_items.notes`; no new preference editor was added.

## Endpoints and authorization

All endpoints use the existing `web`, authenticated/verified session, private/no-store middleware and Laravel CSRF protection. Reads return an ETag and private/no-store JSON. Write routes retain the established throttles.

| Method | Endpoint under `/reservations/{reservation}/pre-order/owner-view` | Input                                                          |
| ------ | ----------------------------------------------------------------- | -------------------------------------------------------------- |
| GET    | base endpoint                                                     | No mutation                                                    |
| PATCH  | `/items/{item}`                                                   | `quantity`: strict integer 0–99; zero removes a permitted line |
| DELETE | `/guests/{guest}`                                                 | No order fields                                                |
| POST   | `/submit`                                                         | No order fields                                                |

Every write requires exactly one strong `If-Match: "<64 lowercase hexadecimal characters>"` version returned by this view. Missing, weak, wildcard or malformed versions return 422. Extra payload fields, including prices/totals/status, are rejected. Order/guest/line queries are scoped to the authorized reservation. Nonowners, including registered reservation guests, receive 403; anonymous JSON access receives 401; missing/foreign scoped records return 404. Expired CSRF/session requests fail rather than applying changes.

Policies independently enforce `viewSummary`, `update` and `submitPreOrder`. Browser permissions never replace server authorization.

## Concurrency, totals and preservation

Each operation locks and reauthorizes the reservation inside a transaction (up to three deadlock attempts). It uses the same parent lock as existing guest replacements and group submission. Mutations also lock the affected guest/order/line; availability checks stabilize the category then dish using shared locks. Positive quantities revalidate availability, active category and restaurant ownership. Removing an unavailable draft line is allowed.

The opaque group version hashes reservation state, group lifecycle, all relevant guest versions/confirmation/revocation state and persisted item IDs, quantities, prices, notes and update timestamps. Changes from another participant or tab invalidate the previous snapshot. A stale write returns 409 without overwriting data; the client disables writes until explicit refresh. Late responses are ignored and requests are aborted on unmount/page exit. Ambiguous network errors do not show success.

Existing `ConsolidatedPreOrderService` decimal arithmetic remains authoritative. Quantity changes retain saved `unit_price` and notes. Group submission delegates to the existing validation, locking and idempotency service. A retry of an already successful submission accepts the old well-formed version to preserve lost-response recovery, without altering lines or timestamps. Availability is checked on quantity mutation; final submission retains the existing policy for already saved items/prices. The legacy group submission endpoint remains available with its established serialized/idempotent contract; it does not gain this new view's mandatory group-version contract.

No migration, schema change or development seed was needed. No `.env` changes. Before/after counts and SHA-256 hashes match for all ten application tables: 8 users, 3 restaurants, 9 tables, 12 categories, 30 dishes, 8 reservations, 19 guests, 2 group orders, 7 order items and 0 feedback rows. The nine tables in the previous task's retained baseline also match. Browser writes were confined to a separate temporary SQLite database.

## Final automated verification

| Check                                                       | Final result                                 |
| ----------------------------------------------------------- | -------------------------------------------- |
| `./vendor/bin/sail composer run ci:check`                   | Passed                                       |
| MySQL PHPUnit, isolated `testing` database                  | 474 passed, 4 skipped; 4,706 assertions      |
| SQLite PHPUnit, `DB_CONNECTION=sqlite DB_DATABASE=:memory:` | 458 passed, 20 skipped; 4,534 assertions     |
| Vue / Vitest                                                | 222 passed in 21 files                       |
| TypeScript `vue-tsc --noEmit`                               | Passed                                       |
| PHPStan                                                     | Passed, 0 errors                             |
| Pint `--parallel --test`                                    | Passed                                       |
| Vite Plus formatting and lint                               | Passed, no warnings/errors                   |
| `npm run build:ssr`                                         | Both production client and SSR builds passed |
| `git diff --check`                                          | Passed                                       |

The repository's lint command is Vite Plus `vp check`, not a separate ESLint script. Skips are existing disabled Fortify features; SQLite additionally skips MySQL-specific collation, foreign-key/index and real-row-lock tests. MySQL runs the existing booking/group/auth concurrency coverage.

New PHP regression coverage (10 tests, 101 assertions) includes owner/nonowner authorization, cross-reservation IDs, all participants and empty orders, notes, exact historical totals, strict payload/version validation, stale cross-participant writes, availability, quantity/item deletion, guest-removal restrictions and retained rows, confirmed guests, idempotent submission/timestamp, terminal raw statuses, reservation start/cancel/completion rules and real CSRF. Partial rejection is not asserted as implemented because the schema cannot express it.

New Vue coverage includes the group-request lifecycle, authoritative version header, 409 refresh recovery, authorization failures, ambiguous network errors, duplicate-click suppression, abort/late response protection and a regression that the dashboard action is a button emitting the modal action without Inertia navigation. Existing SSR/guest/menu/auth/invitation suites remain green. Native focus/portal/responsive behavior was verified in Chrome rather than simulated in the minimal Vue test host.

## Browser verification — actually exercised

Chrome used production assets with a separate local Laravel process and production Inertia SSR server, using isolated fixture records. No development account credentials or invitation links were used. SSR/server logs showed no application exception during the final navigation checks.

- Keyboard activation from Reservations opens and keeps the group modal on `/dashboard`.
- Owner, confirmed guest, editable guest draft and empty guest sections show their actual items and saved notes/prices.
- Owner quantity 2 → 3 updates total 63.00 → 80.00. Opening the owner's menu/cart preserves quantity 3 and own subtotal 51.00. Individual-cart submission shows the new confirmation and returns to Reservations.
- Editable guest quantity 1 → 2 updates grand total 80.00 → 99.00. Confirmed guest controls are absent.
- Removing an empty guest requires confirmation and leaves a visible revoked/empty state; backend tests additionally cover removing a draft with items.
- Removing the owner's last line leaves owner subtotal 0.00, retains guests and correctly changes grand total to 48.00. Final submission succeeds without owner items.
- Final submission at 320px shows success, updates the dashboard, and reopening shows Sent to Restaurant, total 48.00, no edit/remove controls and disabled submit.
- An independent service mutation while a second order's modal was open caused a real stale-write response. The UI displayed the conflict, disabled mutations and refreshed to the saved total 34.00 without overwriting it.
- Registered guest login on a separate local hostname was denied the owner's group endpoint with a rendered 403 page.
- Escape closes the modal before and after submission; focus returns to its dashboard button when that opener still exists. Shift+Tab from the close button wraps to the last enabled footer action, remaining in the dialog. Confirmation focuses Keep reviewing. Buttons are keyboard operable and have descriptive accessible names.
- An Inertia-link navigation conflict and inherited 520px dialog sizing were found in Chrome and fixed. The final mobile confirmation targets are at least 44px high; quantity and close targets are 44×44px.

At 900px viewport height, settled measurements were:

| Viewport width | Dialog width | Top/bottom | Body horizontal overflow | Page horizontal overflow | Footer  |
| -------------- | ------------ | ---------- | ------------------------ | ------------------------ | ------- |
| 1440           | 838          | 16 / 884   | None                     | None                     | Visible |
| 768            | 736          | 16 / 884   | None                     | None                     | Visible |
| 390            | 358          | 16 / 884   | None                     | None                     | Visible |
| 320            | 288          | 16 / 884   | None                     | None                     | Visible |

The participant body scrolls while header/footer remain available. At 320px, title/metadata wrap and quantity controls use an extra row. Desktop focus rings, colors, white cards, thumbnails, divider, warning banner and bottom total were compared visually to the supplied Figma screenshots. These are visual/manual checks, not a pixel-difference score or a full screen-reader audit.

Safe screenshots and measurement records are retained outside Git at `/private/tmp/green-tasty-owner-group/`: `group-{1440,768,390,320}.png`, `owner-saved.png`, `no-owner-items.png`, `confirm-320.png`, `submitted-320.png`, `locked-320.png`, `stale-320.png`, `guest-forbidden.png`, `responsive-final.json`, `data-preservation.json`. Temporary database, storage, keys, credentials and runtime scripts are removed after verification.

## Remaining visual/product differences

- Restaurant-confirmed/rejected/per-item expiry banners are unavailable until the real backend lifecycle is designed. Submitted orders use the existing stricter read-only policy.
- The existing currency model has no ISO currency field. Saved decimal amounts are displayed consistently with the current reservation UI; a dollar symbol is not invented from a Figma example.
- Actual dish/participant names, numbers, notes, dates and totals differ from illustrative Figma records. Existing catalog images are resolved by name, not a nonexistent database image field.
- Responsive layout is an adaptation of the supplied desktop screenshots. Larger touch targets and confirmation steps intentionally take more space than the miniature Figma controls. Revoked/empty guests remain visible to preserve history.
- The owner individual-cart success copy explains the actual two-stage submission instead of claiming the group was already sent. The existing standalone owner header has no Inertia account-menu context; navigation is reused as normal links.
- Legacy reservation details/consolidated summary and existing add-pre-order visibility rules remain available. After an owner changes their draft, the dashboard requires reconfirming the personal cart before showing its Group Pre-Order action again, as before.
- Browser coverage verifies stale recovery and submitted locking; malformed versions, cross-reservation mutation attempts, duplicate HTTP retries, unknown terminal statuses and start-boundary behavior are proven by automated tests. Real restaurant approval/partial rejection and AWS deployment were not tested or claimed.

## Changed-file inventory

Modified:

- `resources/js/components/reservations/ReservationListCard.vue` — modal action instead of legacy navigation.
- `resources/js/lib/sessionJson.ts` — protected GET and optional strong version header.
- `resources/js/pages/Dashboard.vue` — modal lifecycle and dashboard refresh.
- `resources/js/pages/guest/Menu.vue` — owner navigation and individual-cart success confirmation.
- `routes/web.php` — owner-view read/mutation/submission routes.

Added:

- `app/Http/Controllers/OwnerGroupPreOrderController.php`
- `app/Http/Requests/OwnerGroupPreOrderRequest.php`
- `app/Services/OwnerGroupPreOrderService.php`
- `resources/js/components/reservations/OwnerGroupPreOrderModal.vue`
- `resources/js/components/reservations/ReservationListCard.test.ts`
- `resources/js/composables/useOwnerGroupOrder.ts`
- `resources/js/composables/useOwnerGroupOrder.test.ts`
- `resources/js/types/owner-group-order.ts`
- `tests/Feature/OwnerGroupPreOrderTest.php`
- `docs/consolidated-group-preorder.md`

No public images, secrets, test databases, temporary fixtures or generated build artifacts are included in the changed-file inventory. Existing originals are unchanged. Production build outputs remain ignored by Git.
