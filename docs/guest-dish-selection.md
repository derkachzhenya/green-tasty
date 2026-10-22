# Guest dish selection

`GET /invitations/{token}/selections` (`invitations.selections.show`) reads only
the invited guest's saved selections. The existing `GET /invitations/{token}`
returns reservation/menu information and does not include selections. The new
read endpoint uses the same invitation authentication, is stateless, and shares
the invitation-access limit of 60 requests per minute per IP with invitation viewing.
It never creates a pre-order, updates timestamps, or modifies saved selections.

`PUT /invitations/{token}/selections` (`invitations.selections.update`) replaces
the invited guest's complete draft selection set and returns JSON. The invitation
is the sole credential; neither a Laravel account nor session cookies are needed.
The route is stateless, inherits invitation privacy headers, and allows 30 write
attempts per minute per IP, including invalid-token attempts.

```json
{
    "items": [
        { "menu_item_id": 1, "quantity": 2 },
        { "menu_item_id": 2, "quantity": 1 }
    ]
}
```

Only `items` is allowed at the root. Each item accepts only `menu_item_id` and
`quantity`, both JSON integers. IDs must be distinct and positive; quantities must
be 1–99. At most 100 distinct dishes are accepted. Send `{"items":[]}` to remove
all selections, or omit an existing dish from a nonempty set to remove that dish.
No prices, guest IDs, reservation IDs, order IDs, notes, or status changes are
accepted from the caller. Menu items must belong to the token's reservation
restaurant, be available, and have an active category. All dishes are checked
before any selection is modified; an invalid replacement leaves the old set intact.

The 200 response contains only this guest's `items` (menu ID, current name,
quantity, saved `unit_price`, and line `subtotal`), `total_quantity`, and `subtotal`.
All money values are decimal strings with two fractional digits, calculated using
the existing Brick Math dependency. Nothing about other guests or their selections
is returned.

GET and PUT share one decimal summary implementation and the same response shape.
GET returns `{"items":[],"total_quantity":0,"subtotal":"0.00"}` when the guest
has no saved selections. It includes historical prices even if the menu price has
changed, a dish/category is no longer available, or the order has been submitted.
Such orders remain read-only: the draft-only editing rules below still apply to PUT.
Both the guest ID and the pre-order's reservation are scoped from the invitation;
query-string IDs cannot change that scope. Invalid, expired, revoked, and rotated
tokens receive 404. The API never returns other guests' selections or token hashes.

Newly selected dishes snapshot the current menu price. Retained dishes preserve
their historical unit price, including when their quantity changes. Removing and
later readding a dish takes a new snapshot. Identical requests preserve row IDs,
timestamps, and prices; input order does not affect response ordering. Retried
requests still require currently valid invitations and available dishes.

An empty request does not create an otherwise nonexistent pre-order. An existing
group pre-order remains when the caller clears their selections. Only a `draft`
pre-order with `submitted_at = null` is editable; all other states, including a
draft with a submission timestamp, return 409. Invalid/expired/revoked/rotated
invitations return the same 404 as invitation viewing. Invalid selections return 422. Validation happens only after token authentication, and validation responses
remain JSON even without an explicit JSON Accept header.

Writes use a database transaction with up to three deadlock retries. The lock
order is reservation, revalidated invitation/guest, pre-order, menu categories,
menu items, and existing guest selections. Reservation locking serializes initial
pre-order creation across guests; the existing unique reservation index remains
a backstop. Guest locking coordinates with invitation revocation/regeneration.
Shared category and menu locks keep eligibility and prices stable until commit.
Future final-submission code must coordinate with these reservation/pre-order
locks and set status/submission fields in its transaction. Concurrent valid
replacements are serialized; the last successful replacement wins.

The consolidated summary service needs no changes: it reads the saved guest rows
and their historical prices. No schema change is required. The bearer-link HTTPS
and infrastructure log-redaction requirements in [guest invitations](guest-invitations.md)
also apply to this endpoint.
