# Group pre-order submission UI

Owners can finalize their group order from `/reservations/{reservation}`. Review
**All selected dishes**, **Selections by participant** and the **Grand total**,
choose **Submit Group Pre-Order**, then explicitly **Confirm submission**.
**Keep reviewing**, Escape and the close control cancel without a request.
The dialog initially focuses Keep reviewing; focus trapping/restoration uses the
shared Dialog primitives. While submitting, actions are disabled and dismissal
is prevented. Focus returns to the opener or the group-order heading after success.

## Backend integration

The dashboard provides `canSubmitPreOrder` from `ReservationPolicy::submitPreOrder`.
The control is absent without that ability or for a locked order, and disabled
for empty/not-started orders, cancelled/completed reservations and ongoing
refresh/submission. Nonempty orders with a zero total remain valid. The policy
flag controls the UI only; the existing endpoint/service still authorize against
the current owner on every submission.

`GroupPreOrderSubmission.vue` calls the existing
`POST /reservations/{reservation}/pre-order/submit` with **no order payload**.
Totals, prices, ownership/guest IDs and status are never sent. `sessionJson.ts`
extracts the established invitation API's session/XSRF transport for both owner
workflows: same-origin cookies, encrypted XSRF cookie header, JSON Accept,
no-store and no automatic redirects. Invitation callers keep their wrapper and
error alias, covered by existing interaction tests. Bearer guest APIs are unchanged.

The success DTO is checked for the matching reservation, submitted status, valid
timestamp, supported fields and decimal strings. Quantities, historical unit
prices and totals are displayed verbatim. `ConsolidatedPreOrderService` remains
the calculation authority; no frontend monetary arithmetic or menu-price lookup
is introduced. Token hashes, invitation URLs, contacts and arbitrary response
fields are not retained by the response parser.

After a confirmed response, the dashboard immediately shows its submitted snapshot
and reloads `groupOrder`, `reservation` and `canSubmitPreOrder` through Inertia.
The submission time has a machine-readable ISO timestamp and uses the existing
reservation display timezone. A successful refresh uses the latest submitted
summary. Network/HTTP/rescued refresh failures retain the confirmed locked snapshot
with a retry message; an older draft response cannot unlock it. `DeferredSection`
gains an optional `resolved` flag for this case; other consumers retain their
existing behavior.

Submission services, policies, transaction handling, row locking, routes, database
models and migrations are unchanged. Repeated requests remain idempotent and keep
the original timestamp and historical prices. Guest edits and clearing receive
409 after finalization; guest page-data reads report `editable: false`.

## Failures and concurrent changes

- 401/403/419 or missing reservation: block retries and offer a full dashboard reload.
- 422: show server validation messages as text; refresh/review before new confirmation.
- 409: refresh reservation/order state before permitting further submission.
- 429: show a wait-and-retry message inside confirmation.
- Network, 5xx or malformed success: the result is uncertain because the request
  may already have committed. Require successful status refresh and renewed
  confirmation. Existing backend idempotency makes a repeated request safe.

Pending requests have synchronous duplicate guards and an AbortController.
Reservation changes, unmount and pagehide invalidate late responses. A bfcache
restore requests fresh status. Aborting a browser request does not roll back a
server transaction; refreshed backend state determines what happened.

Confirmation explicitly submits **latest saved selections**. Existing row locking
serializes submission with guest writes, so selections committed before submission
acquires its lock can be included even after the owner opened confirmation. No
revision/freeze protocol is introduced. If page data changes while confirmation is
open, it closes for renewed review. Refresh and invitation management controls are
disabled during confirmation/submission. There is no polling or live notification.
An already-open guest page learns the lock on refresh or its next save; the backend
immediately prevents further persisted changes.

## Design and accessibility

Reviewed Figma [Cart — Owner & Guests Pre-Orders (115001:51075)](https://www.figma.com/design/P9rkBNLJZ3WabXSQX9b5DO/EPAM-IT-Marathon-6.0?node-id=115001-51075)
and [Group Pre-order — sent (112599:148472)](https://www.figma.com/design/P9rkBNLJZ3WabXSQX9b5DO/EPAM-IT-Marathon-6.0?node-id=112599-148472).
The UI retains cream backgrounds, white cards, Poppins, green actions, grouped
participant selections and a highlighted total. Existing Button/Dialog and summary
components are reused.

Differences: an action below the dashboard summary and a compact confirmation
dialog replace the cart drawer/sent modal. Success stays on the dashboard with
locked status and timestamp. Figma's restaurant-confirmation label and 30-minute
cutoff are omitted because the actual backend locks immediately on owner
submission and has no restaurant-acceptance workflow. Currency symbols, images,
preparation times and notes are not added where the DTO supplies none.

Actions stack on mobile and the dialog scrolls within the viewport. Native
buttons/forms, visible focus, 44px targets, Cancel-first focus, focus trapping and
restoration, Escape handling, alert/status messages, semantic lists and `<time>`
support keyboard and screen-reader navigation. No screen-reader certification or
production load-test claim is made.

## Tests and preview

```sh
./vendor/bin/sail composer run ci:check
./vendor/bin/sail npm run build
```

Vue interactions cover confirmation/cancellation, empty authenticated request,
CSRF handling, loading/dismissal/duplicate guards, policy/terminal/empty/free/locked
states, authorization/validation/conflict/rate-limit/network errors, uncertain
recovery, response validation/privacy, changed selections and late responses.
Dashboard tests cover automatic refresh, exact server totals, timestamp timezone,
failed/rescued/stale refreshes and renewed confirmation. Summary render tests also
cover the submission timestamp.

Four Laravel feature cases cover independent policy abilities, denial, dashboard/API
integration, exact historical totals, idempotent resubmission, anonymous guest
read-only metadata, rejected edits/clearing and privacy/isolation. Existing backend
submission and MySQL concurrency tests remain enabled.

Validation results: complete MySQL CI passed with 287 PHP tests passed, 4 existing
skips and 2,138 assertions. Complete SQLite CI passed with 279 PHP tests passed,
12 existing/driver skips and 2,034 assertions. All 111 Vue tests passed, including
41 new submission/timestamp cases. The production build passed with the existing
optional `fontaine` advisory. Formatting/lint, TypeScript, Pint and PHPStan passed.

Browser checks used production assets, a disposable SQLite database and an isolated
session cookie. They covered real owner login/CSRF submission, exact historical
totals, automatic refresh, submitted timestamp, no remaining submit controls,
guest read-only selections, empty/cancelled reservations, Escape cancellation,
Tab focus trapping, Cancel-first focus and post-success focus on the new summary
heading. The 320px and 768px layouts had no horizontal overflow; the mobile modal
fit within a 320×568 viewport. Temporary preview scripts/database are kept outside
the repository and removed after verification. These checks do not infer MySQL
locking behavior from SQLite; the existing MySQL concurrency tests verify that.

To preview, start Sail and Vite, sign in as an owner, open a reservation with saved
guest dishes and scroll to Group pre-order. Empty orders show a disabled action and
explanation. Use disposable test orders to explore finalization: there is no reopen
control in this workflow.

## Changed files

- `app/Http/Controllers/ReservationDashboardController.php`: policy ability.
- `resources/js/pages/reservations/Show.vue`: integration and resilient refresh.
- New `resources/js/components/reservations/GroupPreOrderSubmission.vue`: confirmation/request state.
- `resources/js/components/reservations/GroupPreOrderSummary.vue`: submitted time and shared lock predicate.
- `resources/js/components/site/DeferredSection.vue`: optional resolved-data rendering.
- `resources/js/types/reservation.ts`: submitted response type.
- New `resources/js/lib/groupPreOrder.ts`: lock/time helpers and supported response parser.
- New `resources/js/lib/sessionJson.ts`, plus `resources/js/lib/invitationApi.ts`: shared authenticated transport.
- New `resources/js/test-support/groupOrder.ts`: typed fixtures.
- New `resources/js/components/reservations/GroupPreOrderSubmission.test.ts` and
  `resources/js/pages/reservations/Show.test.ts`, plus
  `resources/js/components/reservations/reservation.test.ts`.
- New `tests/Feature/GroupPreOrderSubmissionUiTest.php`.
- This report, `docs/group-preorder-submission.md` and `docs/reservation-dashboard.md`.

No dependencies, schema, credentials or development records are changed.
