# Guest invitation management UI

## Local preview

```sh
./vendor/bin/sail up -d
./vendor/bin/sail npm run dev
```

Sign in as the reservation owner and open `/reservations/{id}` on your configured
application URL (currently `http://localhost:8080`), or follow **View reservation**
from a saved booking confirmation. **Participants and invitations** provides:

- **Create invitation**: enter a display name and receive a new individual link.
- **Copy link**: explicitly copy that newly issued URL, or select the read-only
  field for manual copying if clipboard permission is unavailable.
- **Regenerate** / **Generate link**: confirm replacement of a guest's invitation.
- **Revoke**: confirm invalidation without deleting the guest or saved dishes.

The owner participant has no management buttons. Expired and revoked invitations
can be regenerated. Already revoked and never-invited guests have no Revoke
button. Creation works with an empty participant list. The interface sends no
emails and adds no guest selection or pre-order submission controls.

## Backend reuse and metadata

The existing owner-only JSON endpoints, Form Requests, scoped bindings, policies,
transactions, expiration checks, hashing and rate limits are unchanged:

| Operation  | Method and path                                                   |
| ---------- | ----------------------------------------------------------------- |
| Create     | `POST /reservations/{reservation}/invitations`                    |
| Regenerate | `POST /reservations/{reservation}/invitations/{guest}/regenerate` |
| Revoke     | `DELETE /reservations/{reservation}/invitations/{guest}`          |

Only two dashboard metadata additions are needed: `canManageInvitations`, derived
from the existing policy, and participant `invitation_expires_at`, an ISO timestamp
or null. No stored hash, previously issued raw URL, guest contact information or
new database fields are exposed. Every mutation still authorizes independently
on the server. A guest token does not authorize owner management.

The shared participant component displays expiration in the reservation's existing
display timezone. The UI uses the backend's default expiry; it does not add a
custom expiration picker or duplicate validation/date rules. The existing API
still supports a validated optional expiry. Invitation validity and participation
response remain separate statuses; Active does not claim email delivery or use.

## Security and state handling

`invitationApi.ts` sends ordinary same-origin JSON requests with session
credentials, the decoded encrypted `XSRF-TOKEN` cookie in `X-XSRF-TOKEN`,
`cache: no-store`, and redirect rejection. It never disables web/CSRF middleware.
Issued responses are checked for the expected fields and a same-origin HTTP(S)
invitation URL with the existing token format; arbitrary response fields are
discarded. Deployment URLs/proxy configuration must generate the correct origin.
Clipboard access requires a secure context (HTTPS or localhost); manual copying
remains available when access is denied.

Raw URLs live only in a component-local shallow ref after successful creation or
regeneration. They are not assigned to Inertia props, remembered forms, flash
data, URL/query strings, browser storage, logs, or clickable anchors. Closing the
dialog, switching reservations, navigating away, browser page hide/show/back
events, and unmount clear the secret and abort pending requests. Late responses
cannot restore a dismissed link. Same-page metadata refresh preserves a newly
issued dialog so the owner can copy it. The clipboard retains an explicitly
copied link for the owner's manual sharing; the app cannot retrieve it later.

Regeneration and revocation have explicit confirmation dialogs, initially focused
on Cancel. Mutations disable competing submissions and dismissal while pending.
After success, participants/order metadata reload from the existing dashboard
endpoint. Closing restores focus to the opening control, or the Create button
when the old action is removed. Dialogs use the existing Reka-based components
for focus trapping, Escape dismissal and accessible titles/descriptions.

Validation, session/permission changes, missing resources, rate limits, success,
loading and copying failures have explicit messages. Unexpected network/server
results are treated as uncertain and block an immediate repeat submission: a
request may have committed even when its response was lost. The owner should
close and refresh before retrying. There is no automatic mutation retry or new
idempotency protocol. If a successful link response is lost or its component
unmounts after a failed metadata reload, regenerate after recovery; old links
cannot be recovered by design. Closing a pending page does not undo a server
mutation that has already committed.

## Figma

Reviewed **Owner Flow → Guest Invite Link Modal**, node `112080:145288`, and the
reservation cards at `112599:148445` in the supplied
[Figma file](https://www.figma.com/design/P9rkBNLJZ3WabXSQX9b5DO/EPAM-IT-Marathon-6.0?node-id=112080-145288).
The implementation reuses Poppins, cream/green tokens, white cards, the centered
dialog, read-only link field and explicit copied confirmation.

Differences: Figma shows one shared group invite, while the existing secure API
issues individual guest links. The UI therefore requires a guest name and lists
per-guest states and expiration. It adds regeneration/revocation confirmations,
one-time-link guidance and manual-copy fallback. Location/date/time remain on the
underlying dashboard rather than being repeated in every dialog. Messages are
inline live regions instead of the design's floating toast. No fake invite URL,
email delivery, photography or unimplemented order actions are added.

## Verification

```sh
./vendor/bin/sail artisan test --filter='GuestInvitationManagementTest|ReservationDashboardTest|GuestInvitationTest'
./vendor/bin/sail npm run test:unit
./vendor/bin/sail composer run ci:check
./vendor/bin/sail npm run build
```

Seven new feature tests exercise the real APIs followed by dashboard reloads,
permission and expiry metadata, hash/raw-token privacy, regeneration invalidation,
revocation with retained selections, expired/invalid rejection, owner/participant
authorization, cross-reservation binding, and real encrypted XSRF-cookie headers.
Existing invitation and dashboard coverage remains enabled.

Vue tests now include 24 invitation interaction cases alongside the nine existing
summary render tests. They exercise HTTP requests and headers, copy success and
fallback, confirmation/cancellation, validation/auth/rate-limit failures, loading,
uncertain outcomes, URL validation, expired/revoked/empty states, and secret
cleanup on navigation, context change and unmount. Client-compiled templates use
Vue's custom renderer with minimal host nodes and stubbed dialog primitives;
actual browser portals/focus behavior are verified separately. The existing
server-rendered tests also remain in the suite. No test dependency was added.

Browser verification uses only disposable SQLite fixtures on a separate local
server and session cookie. Creation, real clipboard copying, regeneration,
revocation, whitespace-name validation, focus return, Cancel focus, Escape,
and responsive layouts are checked without modifying development records.
The dashboard and dialogs are checked at 320/390/768/1440px. No screen-reader
certification, automatic status polling, custom expiry UI or cross-browser
clipboard guarantees are claimed.

Final validation results:

- Focused management/dashboard feature tests: 23 passed, 317 assertions.
- Full MySQL CI: 269 passed, four existing skips, 1,895 assertions.
- Full SQLite CI: 261 passed, 12 existing/driver-specific skips, 1,791 assertions.
- Vue suite: 33 passed (24 new interaction cases and nine existing render tests).
- Formatting, lint, TypeScript, Pint and PHPStan passed in both CI runs.
- Production build passed with the existing optional `fontaine` advisory.
- Browser checks passed at 320, 390, 768 and 1440px without horizontal overflow.

## Modified files

- `app/Http/Controllers/ReservationDashboardController.php`
- `app/Services/ReservationDashboardService.php`
- `resources/js/types/reservation.ts` and new `types/invitation.ts`
- `resources/js/pages/reservations/Show.vue`
- `resources/js/components/reservations/ReservationParticipants.vue`
- New `resources/js/components/reservations/GuestInvitationManager.vue`
- New `resources/js/composables/useGuestInvitations.ts`
- New `resources/js/lib/invitationApi.ts`
- New `resources/js/components/reservations/GuestInvitationManager.test.ts`
- `resources/js/components/reservations/reservation.test.ts`
- `vitest.config.ts` and new `resources/js/test-support/{clientEnvironment,renderComponent}.ts`
- New `tests/Feature/GuestInvitationManagementTest.php`
- `tests/Feature/ReservationDashboardTest.php`
- This document and the dashboard/backend invitation documentation links.

No migrations, dependencies, invitation business services, auth policies or
existing API contracts are changed. No commits or pushes are made for this task.
