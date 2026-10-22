# Group pre-order submission

`POST /reservations/{reservation}/pre-order/submit`
(`reservations.pre-order.submit`) finalizes the reservation's shared pre-order.
It uses session authentication, normal web CSRF protection, route model binding,
and `ReservationPolicy::submitPreOrder`. Only the current reservation owner is
authorized; invitation tokens and authenticated participants do not grant access.
The endpoint allows 30 attempts per minute per authenticated user.

Send no order fields. Client totals, prices, guest/order/reservation IDs, ownership
IDs, status, and timestamps are rejected with 422, including values supplied in
the query string. Framework CSRF/method fields remain allowed. JSON clients should
send `Accept: application/json`. Unauthenticated JSON requests receive 401,
other authenticated users receive 403, and missing reservations receive 404.

The 200 response is the existing consolidated summary (`reservation_id`,
`pre_order_id`, `items`, `guests`, `total_quantity`, `grand_total`) plus
`status: "submitted"` and an ISO 8601 `submitted_at`. Decimal totals are calculated
by `ConsolidatedPreOrderService` using saved `pre_order_items.unit_price` values.
No selection, historical price, or reservation status is changed by submission.
The response has `Cache-Control: no-store, private`.

Missing or empty group orders return 422 and are never created automatically.
Selections must have positive quantities and nonnegative saved prices; a group
order containing free dishes is valid. Each guest must belong to this reservation,
and each dish's category must belong to its restaurant. Invalid legacy links fail
the entire submission rather than silently excluding rows from the final order.

Only an unsubmitted draft can transition to submitted. Other pre-order states,
`draft` with a submission timestamp, or `submitted` without one return 409.
New submissions for cancelled/completed reservations also return 409. Reservation
statuses have no broader workflow defined yet; other statuses and reservation
dates are not restricted by this endpoint. Current menu prices/availability are
not rechecked: finalization preserves the previously saved selections and prices.

Successful submission sets `status = submitted` and `submitted_at` once.
Repeating a successful request returns 200 and the original timestamp without
updating rows or prices. Existing submitted orders can be retrieved by repeating
the request, including after a later reservation status change. Dish/guest names
are live metadata, not immutable snapshots of the response.

Finalization uses one transaction with up to three deadlock retries. It locks the
reservation first, reauthorizes against its current owner, then locks its pre-order
and item rows. Guest selection writes acquire that same reservation lock first,
so a submission waits for earlier replacements and sees their committed rows;
later replacements wait and then receive 409. Simultaneous submissions serialize
and reuse the same submission timestamp. A summary failure rolls back the status
and timestamp along with the transaction. No migration or schema change is needed.

The existing guest PUT endpoint rejects both edits and clearing after submission;
guest and owner read endpoints remain available. The MySQL concurrency tests use
independent connections with committed fixtures and actual lock-wait timeouts to
verify blocking in both directions. Those two tests skip on SQLite, which does
not provide equivalent row-lock semantics.
