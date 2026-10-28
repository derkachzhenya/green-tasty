# Waiter reservations and restaurant pre-order review

> Update 2026-10-28: the previously documented service-order gap is now implemented. See [waiter flows and verification](qa-2026-10-28/waiter-flows-ru.md). The report below records the earlier 2026-10-26 state.

Verified on 2026-10-26. Local branch: `feature/waiter-reservations-preorder-review`, based on fetched `origin/main` at `976f930` (including the previous owner/guest-removal work). No commit, push, merge, or deployment was performed.

## Implemented scope

- Restaurant-scoped waiter dashboard with owner names, real booking dates/times/tables, reserved guest count, active-participant data, submitted pre-order counters, lifecycle states, pagination, and date/time/table filters. Time filtering means a reservation overlaps the selected instant; times use Asia/Tbilisi.
- New reservations for existing customer accounts, reservation editing, capacity/overlap checks, table changes, and explicit cancellation confirmation. Existing booking rules and services are reused.
- Restaurant review grouped by owner/guest, with images from existing public assets, quantities, saved unit prices, line totals, notes, and persisted Pending/Confirmed/Rejected decisions.
- All active items must have a decision before publishing a review. Published results are read-only. Owner views hide unpublished waiter decisions.
- Owner correction of a published rejection before T−30, followed by a new restaurant review revision. The rejected row remains in history with its original price, quantity, and notes; its replacement is a new row priced from the server catalog. Previously published, unaffected decisions remain valid.
- Persisted, scoped events for customer submission/update, restaurant review publication/rejection, and cancellation. Repeated requests do not create duplicate rounds or events.
- Loading/error/conflict states, refresh after stale state, responsive dialogs, keyboard controls, accessible decision labels/pressed state, and explicit focus restoration for staff booking dialogs.

## Architecture, authorization, and API

`RestaurantPolicy::staff` requires an active restaurant and an active `restaurant_staff` membership with role `waiter`. Customer registration does not assign staff access. Staff routes require authentication and verification; nested reservation bindings and service queries enforce restaurant isolation. Item operations additionally scope the item to the submitted order and its reservation participant. Owner corrections use the existing reservation owner policy. No client-side role flag grants access.

`WaiterReservationService` handles dashboard/booking operations. `RestaurantPreOrderReviewService` handles review decisions, publication, corrections, read models, immutable publication snapshots, and event deduplication. Controllers and validated requests stay thin. Existing consolidated/owner reads exclude superseded rows from current totals and expose their history in the review panel.

All mutation paths use transactions. Review/correction operations serialize through the reservation row. Table edits follow the existing restaurant → ordered table locks → reservation lock convention and recheck overlaps/capacity. Current versions are opaque quoted SHA-256 values derived from reservation/order/item state. `If-Match` is mandatory for updates, cancellation, decisions, review publication, and correction; wildcard, weak, missing, and stale versions cannot authorize a new write. An identical already-applied decision, published revision, or completed cancellation can return its current state as a read-only retry. A stale opposing decision or new write returns a conflict.

| Endpoint                                                                  | Behavior                                                |
| ------------------------------------------------------------------------- | ------------------------------------------------------- |
| `GET /staff/restaurants/{restaurant}/reservations`                        | Authorized Inertia dashboard and filtered pagination    |
| `POST /staff/restaurants/{restaurant}/reservations`                       | Create booking for an existing customer; 201            |
| `PATCH /staff/restaurants/{restaurant}/reservations/{reservation}`        | Versioned booking/table update                          |
| `POST …/{reservation}/cancel`                                             | Versioned cancellation; preserves historical records    |
| `GET …/{reservation}/review`                                              | Authorized restaurant review snapshot/version           |
| `PATCH …/{reservation}/review/items/{item}`                               | Versioned Confirmed/Rejected decision                   |
| `POST …/{reservation}/review/submit`                                      | Publish a complete review revision once                 |
| `POST /reservations/{reservation}/restaurant-review/items/{item}/replace` | Owner-only versioned rejection replacement/resubmission |

Validation failures return 422, stale/invalid state transitions return 409, unauthorized staff access returns 403, and out-of-scope/missing bound resources return 404. Unauthenticated web navigation uses the existing login flow. JSON requests use the existing CSRF/session transport; authorization/session failures never display a fabricated success. Staff responses are private/no-store and mutations use existing rate limiters.

## States, money, and role-specific timing

| State                                | Effective behavior                                                                 |
| ------------------------------------ | ---------------------------------------------------------------------------------- |
| Customer group not submitted         | No waiter review action; existing owner/guest draft workflow remains authoritative |
| First customer submission            | Created by Customer; new lines Pending                                             |
| Waiter draft decisions               | Persisted for staff; owner still sees Pending until publication                    |
| Published review                     | Confirmed by Restaurant; confirmed and rejected lines remain visible/read-only     |
| Owner replaces a published rejection | Updated Pre-Order; new line Pending; unaffected published decisions retained       |
| Final review after correction        | New immutable review round; both roles see the same current totals                 |
| Cancelled/completed reservation      | Review read-only; no customer correction or staff booking mutation                 |

Money uses decimal arithmetic and saved `unit_price × quantity`, never submitted client prices. **Requested** is the total of current, non-superseded lines, including current rejected lines. **Confirmed** and **Rejected** contain published decisions; **Pending** contains unpublished lines. Superseded lines remain in history but do not contribute to current actionable totals. These separate totals make a partial rejection explicit instead of silently reducing an order.

Existing T−45 warning and T−30 customer hard lock are preserved. Saved unsubmitted selections are retained at cutoff, as requested previously. Customer corrections stop at T−30. Legitimate restaurant review remains available for upcoming/in-progress reservations even after the customer cutoff. Staff may change table/party size during an in-progress booking but cannot change its start time. Cancellation is restricted to future confirmed reservations. Guest removal continues to preserve reserved seats and account separately for active participants.

## Database safety

Migration: `2026_10_26_140000_add_restaurant_staff_and_preorder_review.php`.

- Adds `restaurant_staff`, `pre_order_review_rounds`, and `reservation_events`, with restrictive foreign keys and unique membership/revision/event keys.
- Adds revision/publication fields to `pre_orders` and decision/publication/supersession fields to `pre_order_items`, using compatible defaults/nullability.
- Does not reset, truncate, reseed, delete, or rewrite existing development records. Existing orders start unreviewed; no historical restaurant approval is invented.
- Applied only after verifying the development target was MySQL database **`laravel`**, inspecting status/pretend output, and taking a private row/column backup. Original columns and rows in all **15 non-runtime application tables** were compared after migration and again after final tests: identical. `restaurant_staff` in development remains empty; no real account was granted access.
- Migration/runtime tables were excluded from the comparison; their expected framework state is not business-data preservation evidence. MySQL tests use **`testing`** only; SQLite tests use **`:memory:`**; browser mutations used a separate private SQLite preview.

Private backup, comparison reports, and screenshots are outside the repository at `/private/tmp/green-tasty-waiter/`. Backups contain development data and must remain private. MySQL DDL can take metadata locks and is not an atomic multi-statement migration; production deployment still needs its usual backup/maintenance planning. No rollback was run. The migration's `down()` removes the added review schema and should not be used as a data-preserving rollback after real reviews exist.

## Automated verification

All checks below completed against the final implementation; PHP checks preceded the final frontend-only focus restoration adjustment, and frontend checks/builds were repeated after it.

| Check                                                                                                  | Actual result                                           |
| ------------------------------------------------------------------------------------------------------ | ------------------------------------------------------- |
| Sail MySQL PHPUnit (`php artisan test --compact`)                                                      | **498 passed, 4 skipped**, 502 tests, 4,993 assertions  |
| Host SQLite PHPUnit (`DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL='' php artisan test --compact`) | **480 passed, 22 skipped**, 502 tests, 4,801 assertions |
| Vue/Vitest (`npm run test:unit`)                                                                       | **25 files, 237 tests passed**                          |
| TypeScript (`npm run types:check`)                                                                     | Passed                                                  |
| PHPStan (`composer types:check`)                                                                       | Passed, 0 errors                                        |
| Pint (`php vendor/bin/pint --test`)                                                                    | Passed                                                  |
| Formatting/lint (`npm run check` / final `check:fix`)                                                  | Passed; 157 linted files, no warnings/errors            |
| Production client + SSR (`npm run build:ssr`)                                                          | Both builds passed                                      |

Existing Fortify feature-gated tests account for the four MySQL skips. SQLite additionally skips real MySQL locking/index/collation cases; SQLite success is not evidence for MySQL locking. The new real-MySQL race test uses separate transactions/connections, holds a review decision/publication lock, verifies a competing decision/cancellation/submission waits, then verifies stale writes fail and retries leave exactly one review round. Its destructive fixture setup explicitly asserts database `testing` before truncation.

New PHP regressions cover staff membership/revocation, cross-restaurant/reservation/item access, customer restrictions, all-decided publication, mixed review, duplicate requests/events, mandatory versions, stale conflicts, server prices, immutable historical values, correction and preserved decisions, cutoff compatibility, cancellation, filtering/table changes, invalid/terminal transitions, and existing-account booking creation. New Vue tests cover decision availability, exact versioned requests, conflicts/read-only behavior, correction inputs, and empty dashboard SSR props. Existing owner/guest/removal/auth regressions passed in the complete suites.

Isolated Laravel HTTP-kernel checks with real SSR and `throw_on_error=true` returned **200** for an authorized waiter, with the waiter heading and reservation already in server-rendered HTML; **403** for a customer and **404** for an absent restaurant. An initial restricted-shell probe could not connect to the local SSR port; the same probe with local network access passed. This was a verification-environment connection failure, not a reproduced Vue SSR exception.

## Chrome verification (separate from automated tests)

Used actual Chrome against an isolated preview backed by `/private/tmp/green-tasty-waiter/preview.sqlite`, with production assets and an isolated SSR process. Synthetic preview accounts and fixtures were outside the repository and never added to development MySQL.

Verified by real UI interactions:

1. Owner submitted a group containing owner and guest items. Waiter saw the reservation and Pending items.
2. Waiter confirmed the saved 17.00 item and rejected the saved 10.00 item. Submit remained unavailable until all items were decided; publication produced the read-only mixed result.
3. Owner saw the published rejection, chose a replacement from the catalog, and resubmitted quantity 2 at server price 19.00. Old 10.00 rejection remained in history; previously confirmed 17.00 remained confirmed.
4. Waiter saw Updated Pre-Order and the change indicator, reviewed the new 38.00 line, and published revision 2. Both sides showed **Requested 55.00 / Confirmed 55.00 / Rejected 0.00 / Pending 0.00**. The private database audit confirmed two review rounds and unchanged original item values.
5. Staff created a new booking for an existing customer, changed its table, used Keep it without cancellation, then explicitly cancelled it. Success appeared after the server response; the cancelled record remained stored.
6. Date/time/table filtering and an empty filtered state worked. Lifecycle cards showed actual in-progress/completed/cancelled states. Customer navigation to the staff route was denied.
7. A second, stale staff tab attempted a table update and received 409 with no success feedback or persisted change.
8. Review Escape returned focus to View Pre-order; read-only focus stayed inside the dialog. The booking form's forward/backward focus loop was checked. A final check exposed and fixed booking-dialog focus returning to a filter. With the final production build, Escape closed New Reservation and Edit Reservation and returned focus to their exact opening buttons; Keep it closed cancellation and returned focus to that reservation's Cancel button. DOM inspection after the close animation confirmed zero remaining dialogs in all three cases. Final Chrome console inspection contained no warnings/errors.

Responsive page and review checks:

| Viewport | Page horizontal overflow | Review width / internal client-scroll width |
| -------- | ------------------------ | ------------------------------------------- |
| 1440 px  | None                     | 838 px maximum dialog                       |
| 768 px   | None                     | 736 px dialog; 734 / 734 px                 |
| 390 px   | None                     | 358 px dialog; 356 / 356 px                 |
| 320 px   | None                     | 288 px dialog; 286 / 286 px                 |

Also inspected cancellation, cancelled-card, owner-review and empty states at 320 px. This is visual/keyboard inspection, not a screen-reader audit, automated WCAG certification, or pixel-diff comparison.

Screenshot evidence (local, outside Git): `dashboard-1440.png`, `dashboard-768.png`, `dashboard-390.png`, `dashboard-320.png`, `dashboard-lifecycle-1440.png`, `review-final-1440.png`, `review-final-768.png`, `review-final-390.png`, `review-final-320.png`, `cancel-320.png`, `cancelled-320.png`, `empty-320.png`, `owner-final-320.png`, `focus-restored.png` in the private directory above. Browser comparison used the **12 supplied Figma screenshots**, not a newly fetched live Figma file. Temporary viewport overrides were reset, all created test tabs were closed, and the browser's empty controlled-tab list confirmed closure. Temporary preview servers, database, sessions, fixture credentials/scripts, and copied SSR outputs were removed after verification; private backup/audit/screenshot evidence was retained outside Git.

## Remaining differences and limits

- **Service orders are not implemented in the existing domain.** Create Order / Edit Order / View Order and waiter Menu navigation are intentionally absent; cards state that no service order is recorded. A real dine-in order workflow requires a separate implementation. This is the task's explicitly permitted documented workflow gap, not a placeholder success button.
- New Reservation requires an existing customer account. There is no walk-in/contact-only customer creation flow or staff-management UI. Staff membership must be provisioned by a trusted administrator; no sample staff account is seeded into development/production.
- Owner corrections replace one published rejected line at a time and immediately create an updated review revision. They do not reopen the entire submitted group cart or permit changing confirmed lines. Because the existing unique participant/dish key and history are preserved, the replacement dish must not already have a row for that participant, including a superseded historical row.
- Notifications are persisted event records shown on refresh/reload, with the latest 10 restaurant events; there is no live push, unread acknowledgement, email/SMS delivery, or notification center. An orange Updated indicator clears when the current revision is reviewed.
- The modal includes additional explicit Requested/Confirmed/Rejected/Pending totals, saved unit prices, and superseded history beyond Figma's simpler presentation. No restaurant approval is inferred for unreviewed historical orders.
- Cards use real data and native date/time/table fields, ISO card dates, accessible 44 px targets, and a restaurant selector in staff navigation. Their spacing/filter presentation/header differ from the supplied fixed desktop frames. The Figma assistance phone block is omitted because no configured restaurant assistance number exists. Money follows the existing server decimal format rather than inventing a currency from the screenshot's dollar symbol.
- No exact pixel-equivalence claim: screenshots contain illustrative content and inconsistent sample totals/prices. Supplied designs were visually compared at the requested widths; live Figma layers/tokens and screen-reader behavior were not independently audited.
- Production/AWS deployment, production staff assignment, external notifications, load testing, and browser execution against production MySQL were not performed. Backend behavior and races were tested with real MySQL `testing`; destructive browser scenarios were isolated SQLite.

## Manual access after review

Use a trusted local administrator console, choosing a real existing account and restaurant. Do not expose this as public self-service or store a shared password in source control:

```php
$staff = App\Models\User::where('email', 'your-existing-staff-email')->firstOrFail();
$restaurant = App\Models\Restaurant::where('slug', 'your-restaurant-slug')->firstOrFail();
$staff->staffRestaurants()->syncWithoutDetaching([
    $restaurant->id => ['role' => 'waiter', 'is_active' => true],
]);
```

Sign in normally. The header exposes the authorized restaurant's Reservations link. Route: `/staff/restaurants/{restaurant_id}/reservations`. An administrator can revoke that membership with `updateExistingPivot($restaurant->id, ['is_active' => false])`; the next request is denied. These provisioning commands were **not** executed against development data during this task.

## Changed-file inventory

New backend/schema/routes:

- `app/Http/Controllers/RejectedItemCorrectionController.php`
- `app/Http/Controllers/WaiterReservationController.php`
- `app/Http/Requests/CorrectRejectedItemRequest.php`
- `app/Http/Requests/WaiterReservationRequest.php`
- `app/Policies/RestaurantPolicy.php`
- `app/Services/RestaurantPreOrderReviewService.php`
- `app/Services/WaiterReservationService.php`
- `database/migrations/2026_10_26_140000_add_restaurant_staff_and_preorder_review.php`
- `routes/staff.php`

Existing backend integration:

- `app/Http/Controllers/ReservationDashboardController.php`
- `app/Http/Middleware/HandleInertiaRequests.php`
- `app/Models/PreOrderItem.php`
- `app/Models/User.php`
- `app/Services/ConsolidatedPreOrderService.php`
- `app/Services/GroupPreOrderSubmissionService.php`
- `app/Services/OwnerGroupPreOrderService.php`
- `app/Services/RestaurantBookingService.php`
- `routes/web.php`

New frontend:

- `resources/js/components/reservations/OwnerRestaurantReview.vue`
- `resources/js/components/reservations/RestaurantReviewModal.vue`
- `resources/js/pages/staff/Reservations.vue`
- `resources/js/types/restaurant-review.ts`

Existing frontend integration:

- `resources/js/app.ts`
- `resources/js/components/reservations/OwnerGroupPreOrderModal.vue`
- `resources/js/components/site/SiteHeader.vue`
- `resources/js/pages/reservations/Show.vue`
- `resources/js/types/owner-group-order.ts`

Tests/support/documentation:

- `tests/Feature/RestaurantReviewConcurrencyTest.php`
- `tests/Feature/WaiterPreOrderReviewTest.php`
- `resources/js/components/reservations/RestaurantReviewModal.test.ts`
- `resources/js/pages/staff/Reservations.ssr.test.ts`
- `resources/js/pages/reservations/Show.test.ts`
- `resources/js/test-support/renderComponent.ts`
- `docs/waiter-reservations-preorder-review.md`

Existing public dish images are reused; no new public binary assets are required. Secrets, `.env`, test databases, preview fixtures, screenshots, backups, session files, logs, and generated client/SSR outputs are not part of this inventory or Git changes.
