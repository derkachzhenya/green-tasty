# Guest invitations

Owners can manage individual invitations from the reservation dashboard through
the [Guest Invitation Management UI](guest-invitation-ui.md). It reuses the API
documented below and displays newly issued links only in transient client state.

All responses are JSON. Owner operations use the existing session authentication,
CSRF protection, and the `ReservationPolicy::manageInvitations` owner check.

| Method | Route                                                        | Purpose                                                                        |
| ------ | ------------------------------------------------------------ | ------------------------------------------------------------------------------ |
| POST   | `/reservations/{reservation}/invitations`                    | Create a guest and issue a link (201)                                          |
| POST   | `/reservations/{reservation}/invitations/{guest}/regenerate` | Rotate the same guest's link (200)                                             |
| DELETE | `/reservations/{reservation}/invitations/{guest}`            | Revoke the link without deleting the guest (204)                               |
| GET    | `/invitations/{token}`                                       | Open the guest menu UI (HTML) or read the existing reservation/menu JSON (200) |

Browser links now open the [guest dish selection UI](guest-dish-selection-ui.md).
JSON clients should send `Accept: application/json`; its existing response shape
is unchanged. The stateless `GET /invitations/{token}/menu` read supplies the UI
with this guest's saved selections and group-order editability as well as the menu.

Creation accepts `name` (required, up to 255 characters), optional `email`, `phone`
(up to 30 characters), and `expires_at`. Regeneration accepts only `expires_at`.
Expiration defaults to seven days after issuance; a supplied date must be in the
future and no more than 30 days away. Regeneration can reactivate revoked or expired
invitations, resetting expiry. Creating a second invitation creates a distinct guest;
use regeneration to replace an existing guest's link.

Issuance returns `guest_id`, `name`, `expires_at` (ISO 8601), and `invitation_url`.
The URL is returned only upon creation or regeneration. The existing unique
`reservation_guests.invitation_token` column stores a SHA-256 digest of a random
256-bit token. It is hidden from model serialization and excluded from public views.
The raw token is never persisted or deliberately logged by application code.

Guest access is stateless and requires no account. It does not save bearer URLs in
Laravel sessions. It returns only restaurant name, reservation date/time (ISO 8601),
the invited guest's display name, and active categories with available dishes
(ID, name, description, decimal price). Empty active categories are included.
Neither owner contact details nor other guests, reservation codes, or internal
invitation fields are returned. Reservation/guest IDs in query strings cannot
change the token's scope. Invalid, expired, and revoked links all return the same 404.

Owner mutations share a limit of 20 requests/minute per authenticated user; public
access allows 60 requests/minute per IP, independent of tokens. Responses set
`Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, and
`X-Robots-Tag: noindex, nofollow`.

Run `./vendor/bin/sail artisan migrate` to add expiry/revocation columns. The new
migration invalidates all legacy tokens because their storage format and expiry
cannot be trusted. Owners must reissue those links. Rolling back the columns does
not restore old tokens. No existing migration is edited.

Deployment must use HTTPS and redact `/invitations/*` paths in reverse-proxy,
web-server, tracing, and analytics logs: a bearer URL is a credential and may
otherwise be captured by infrastructure outside Laravel. A recipient can forward
their link; no identity verification or email delivery is implemented. Existing
reservation/guest status fields are not changed or interpreted by invitation access.
