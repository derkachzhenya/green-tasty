# Owner pre-order and guest removal

Verified 2026-10-26. Branch `feature/owner-preorder-guest-removal` was created from clean `main` at `8620690`, after fetching `origin/main` and confirming zero divergence. No commit, push, merge or deployment was performed.

## Screenshot audit and implementation

| Reference screenshot | Existing behavior and completed work                                                                                                                                                                                                               |
| -------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 11.04.37 / 11.05.00  | Existing Reservations dashboard/cards reused. Cards refresh after changes and retain status filters. Booked places stay unchanged; active participants are shown separately after removal.                                                         |
| 11.04.39             | Empty owner section and authoritative guest totals already existed. Empty section now says Edit Pre-Order. A group with confirmed guest selections is accessible before the owner submits an individual order.                                     |
| 11.04.42             | Existing persisted catalog, reservation strip and responsive menu reused. No sample menu records, names or prices are hardcoded into the application.                                                                                              |
| 11.04.45             | Existing shared dish preview and add-to-cart behavior reused. Missing catalog nutrition/ingredients are not fabricated.                                                                                                                            |
| 11.04.48             | Existing local owner cart, quantity/removal controls, Cancel Changes and atomic Submit reused. Added My Pre-Order heading.                                                                                                                         |
| 11.04.50             | Existing server-success confirmation and View Reservations navigation reused. Truthful copy distinguishes individual save from final group submission.                                                                                             |
| 11.05.04             | Existing grouped rows, notes, historical prices and subtotals reused. Removal remains subject to existing confirmation/submission restrictions.                                                                                                    |
| 11.05.07             | Replaced inline guest-removal confirmation with a separate nested modal, actual guest name, Cancel, red outlined destructive action, Escape/close, focus trapping and restoration. Group submission confirmation remains the existing inline flow. |
| 11.05.09             | Successful removal hides active membership, updates server totals/counts, keeps the group open and restores/clamps its scroll position. Green Guest Removed toast appears only on confirmed success.                                               |

## Membership and historical integrity

The user explicitly selected: **preserve booked places and account for active participants separately**. `reservations.guest_count` therefore remains unchanged. Pre-order counters retain booked-party-size denominators. `active_participants` counts actual nonremoved membership rows, including the owner; unissued invitations are not invented as participants.

A nullable `reservation_guests.removed_at` records membership removal independently from RSVP status and invitation revocation. There is no global soft-delete scope: the historical relationship remains queryable. Active group views filter this marker. Existing revoked invitations are not automatically treated as removed memberships.

Removal uses the existing reservation-first transaction and opaque group If-Match version. Only the authenticated owner can request it; the service checks guest scope, forbids removing the owner, verifies current version, reservation lifecycle, T−30 boundary and group/individual locking rules. It deletes only the removable guest's unconfirmed draft lines, sets removed_at, revokes the token and increments selection_version atomically. It never deletes the guest or reservation row and does not trigger cascading deletion. Other participants and price snapshots are preserved.

Removed membership cannot be reactivated by invitation regeneration; its old bearer token cannot resolve. A new participant requires a new invitation. Booking edits count active membership rows when validating the minimum party size. Existing booking capacity/duration rules remain.

**Figma/domain conflict:** screenshots display Remove Guest in a Sent to Restaurant group. Existing individually confirmed guest selections and final submitted groups are immutable. This task explicitly requires respecting those rules and preserving history; removal remains unavailable/rejected for those states. No cancellation/revision/restaurant-approval workflow was invented. The UI explains draft removal and retained history rather than claiming a permanent deletion of historical records.

## Migration and development-data preservation

New migration: `2026_10_26_110000_add_removed_at_to_reservation_guests.php`.

- Verified target: local MySQL, database `laravel`.
- Checked migration status and pretend SQL first. The only pending migration added one nullable timestamp; no backfill, delete, update or constraint rebuild was specified.
- Saved a private backup outside the repository, then applied that migration successfully.
- SHA-256 snapshots of all rows in the original columns of ten application tables matched before/after migration and final verification. The new field starts null for existing guests. Development reservations, guests, catalog and monetary data remain unchanged.
- No migrate:fresh, db:wipe, development reseed or .env modification. MySQL destructive tests use `testing`; SQLite tests use `:memory:`. Browser writes use a private temporary SQLite preview only.
- Private backup: `/private/tmp/green-tasty-removal/development-backup.json`, directory mode 700/file mode 600. Keep it private; it contains development data. Safe checksum evidence and screenshots are in that directory. Preview credentials, bearer tokens, database, sessions and bootstrap scripts are removed after acceptance.

## Automated verification

| Check                              | Actual result                            |
| ---------------------------------- | ---------------------------------------- |
| Complete MySQL feature/unit suite  | 485 passed, 4 skipped, 4,831 assertions  |
| Complete SQLite feature/unit suite | 468 passed, 21 skipped, 4,650 assertions |
| Vue/Vitest suite                   | 231 passed across 23 files               |
| TypeScript                         | Passed                                   |
| PHPStan                            | Passed, zero errors                      |
| Pint                               | Passed                                   |
| Vite+ lint/format                  | Passed                                   |
| Production client and SSR build    | Passed                                   |

Existing optional authentication tests are skipped when those features are disabled. MySQL-only locking/concurrency tests are skipped on SQLite and executed in MySQL. This project uses Vite+ lint rather than a separate ESLint command.

Checks use the project's Sail environment. A direct macOS npm check could not load the Darwin Vite+ native binding from the current container-installed node_modules; the same final check passed through `vendor/bin/sail npm run check`. Dependencies and lockfiles were not replaced.

Coverage includes successful removal, cancellation without writes, forbidden/nonowner/foreign-reservation/owner-removal attempts, malformed/stale versions, exact T−30 cutoff, confirmed guest/submitted group immutability, historical-row preservation, token invalidation/regeneration rejection, changed totals/counters, empty owner order and creation through existing service, truthful success/error notifications and modal focus restoration primitives.

A new real two-connection MySQL test holds a competing selection transaction: removal waits for the parent row lock, then rejects the old group version after commit. The newer selection remains intact; a refreshed version can remove the editable draft. No version-check bypass was added.

Full backend suites ran after final backend changes. Frontend checks/builds were repeated after final style refinements. Logs are outside Git under `/private/tmp/gt-removal-*.log`.

## Real Chrome verification

Used the Chrome extension with production client/SSR on loopback and an isolated private SQLite database. Restaurant/table/menu records were copied from the current development catalog; account, reservations and guests were synthetic.

- Empty owner section displayed 0.00, Alex draft 29.00, Julia confirmed selection 17.00; group total 46.00. Dashboard exposed both Add Pre-Order and Group Pre-Order.
- Removal modal displayed Alex's name. Cancel, Escape and the close icon retained the guest and returned focus to the original Remove Guest button. Initial focus was Cancel. Tab navigation stayed in the nested dialog.
- Verified red destructive border/text and 44px close target. Screenshots/DOM measurements at 1440×900, 768×1024, 390×844 and 320×740 confirmed no horizontal overflow and accessible confirmation actions.
- Owner opened existing dish details, added local items and canceled the first draft to an empty cart without a database write. Then submitted two items for 27.00. Fixture database reads confirmed two owner lines added and the guest-item hash unchanged. Confirmation/View Reservations worked; the card changed to 2/4 submitted and group total 73.00.
- Confirmed Alex removal: confirmation closed, group stayed open, Alex disappeared, total became 44.00, active memberships became 2 and booked places remained 4. Dashboard updated without losing navigation state. A second synthetic participant was used to capture the green Guest Removed toast immediately after success.
- Exercised Upcoming filter and retained `/dashboard?status=upcoming` through group operations.
- With another removal confirmation open, a competing guest update was saved through the existing selection service. Confirm returned a conflict: guest remained, success toast was absent and repeated removal was disabled. Cancel and Refresh order recovered the authoritative state.
- After reloading the final client/SSR build, removal at 320px again left the group open with total 44.00, two active participants and four booked places. Focus returned to the accessible 44px group close button. The notification leaves horizontal space for that button.
- Opened the original removed guest's invitation in Chrome: Invitation unavailable was displayed. Reset the viewport and closed the test tab; the controlled-tab list was empty.
- SSR exception propagation was enabled in the private preview. No SSR application exception was observed. An expected HTTP 409 from the intentional conflict is distinguished from an application/SSR defect.

Evidence files in `/private/tmp/green-tasty-removal/` include `empty-owner-1440.png`, `confirmation-final-1440.png`, `confirmation-{768,390,320}.png`, `owner-cart-320.png`, `owner-success-320.png`, `guest-removed-1440.png`, `guest-removed-toast-1440.png`, `conflict-1440.png` and final responsive group screenshots. `guest-removed-final-{1440,320}.png` shows the final build after removal; the earlier toast capture predates the spacing refinement around the close button.

Final development audit confirmed identical original-column hashes in all ten tables, zero existing guests marked removed and the new migration recorded as applied. Temporary preview servers were stopped and synthetic databases, tokens, credentials, sessions and scripts removed. The private development backup and review evidence were retained outside Git.

Direct unauthorized/cross-reservation attempts, exact cutoff boundaries, historical submitted-order rejection and MySQL lock contention are automated-test results, not claimed as browser scenarios. No screen-reader session or accessibility certification was performed.

## Remaining differences and limitations

- Comparison uses the supplied screenshots; pixel-perfect fidelity is not claimed. Existing group dialog is 838px maximum and confirmation 520px; mobile adapts to viewport margins.
- Preserved booked places and separate active counts add explanatory text beyond the mock. Example Figma totals are replaced by persisted server totals.
- Confirmation explains draft membership removal, token invalidation and retained history. Final submitted groups/confirmed individual selections remain protected.
- Existing decimal money formatting and truthful individual-save copy remain; unsupported restaurant approval, currency/nutrition/preparation-time fields are not invented.
- Existing extra categories in a restaurant catalog remain visible; this task does not clean or reseed catalog data.
- Success toast appears within the group dialog and auto-dismisses after six seconds. No unrelated dashboard or page redesign, new package or public image asset was required.
- AWS deployment is outside this task. New deployments must run the additive migration before serving code that queries removed_at.

## Changed files

- `app/Models/ReservationGuest.php`
- `app/Services/GuestInvitationService.php`
- `app/Services/MyReservationsService.php`
- `app/Services/OwnerGroupPreOrderService.php`
- `app/Services/ReservationDashboardService.php`
- `app/Services/RestaurantBookingService.php`
- `database/migrations/2026_10_26_110000_add_removed_at_to_reservation_guests.php`
- `docs/owner-preorder-guest-removal.md`
- `resources/js/components/guest/GuestCartDrawer.vue`
- `resources/js/components/reservations/GuestRemovalModal.vue`
- `resources/js/components/reservations/OwnerGroupPreOrderModal.test.ts`
- `resources/js/components/reservations/OwnerGroupPreOrderModal.vue`
- `resources/js/components/reservations/ReservationListCard.vue`
- `resources/js/types/owner-group-order.ts`
- `resources/js/types/reservation.ts`
- `tests/Feature/GroupPreOrderSubmissionConcurrencyTest.php`
- `tests/Feature/MyReservationsTest.php`
- `tests/Feature/OwnerGroupPreOrderTest.php`
- `tests/Feature/ReservationFigmaStatesTest.php`
