# Guest dish selection UI

Open a newly issued invitation URL from the owner dashboard in an ordinary browser.
No account is required. Guests browse the restaurant's active categories and
available dishes, add dishes, adjust quantities (0–99), review their own pre-order,
and choose **Save selections**. Removing every line and saving clears only that
guest's selections. Unsaved changes trigger a browser leave warning.

## Architecture and API

`GET /invitations/{token}` now negotiates its representation: `Accept: text/html`
opens a lightweight Vue shell; `Accept: application/json` (or `*/*`) preserves the
existing invitation JSON contract. Invalid HTML requests return the same generic
guest shell with HTTP 404 and an unavailable-invitation state, without private data.

The page uses a separate `guest.ts` Vite entry and Blade root rather than an
Inertia page visit. The existing invitation routes deliberately exclude the web
session middleware to prevent bearer URLs entering the session's previous URL.
This entry preserves that behavior and avoids putting tokens into Inertia history,
shared auth props, remembered forms, or serialized page data. It reuses the
existing Vue button, container, content-state, dish card, Poppins and scoped design
tokens. The rest of the application continues to use Inertia unchanged.

| Method | Route                             | Purpose                                                       |
| ------ | --------------------------------- | ------------------------------------------------------------- |
| GET    | `/invitations/{token}/menu`       | Read the guest's menu, saved selections and order editability |
| PUT    | `/invitations/{token}/selections` | Existing API: replace only this guest's draft selections      |
| GET    | `/invitations/{token}/selections` | Existing API: unchanged selection-summary contract            |

The new `invitations.menu` response contains:

```json
{
    "invitation": {
        "restaurant": { "name": "Green Tasty" },
        "reservation": { "reserved_at": "2026-11-01T08:00:00+00:00" },
        "guest": { "name": "Alice" },
        "menu_categories": []
    },
    "selections": { "items": [], "total_quantity": 0, "subtotal": "0.00" },
    "order": { "status": null, "submitted_at": null, "editable": true },
    "timezone": "Asia/Tbilisi"
}
```

`GuestMenuService` coordinates the existing invitation and selection read services;
it does not recalculate order totals or duplicate menu filtering. A transaction
locks the reservation for sharing, then revalidates/locks the invitation guest.
This follows the existing reservation → guest locking order, coordinates with
selection writes, submission and invitation rotation, and reads selections with
their corresponding order status. GET never creates or changes an order.
The controller only delegates and formats a 404/200 response.

Authentication, rate limits, validation and write locking remain in the existing
backend. The new read shares the invitation-access 60 requests/minute/IP limit;
saving retains the existing 30 requests/minute/IP limit. API calls omit cookies,
disable caching and redirects, and use `no-referrer`. HTML and JSON retain private
no-store, no-referrer and noindex headers. No tokens, hashes, other guests, contact
details, booking codes or ownership IDs are returned by the new DTO. Query IDs
cannot change its scope. The raw bearer is read from the current URL in memory;
neither browser storage nor Inertia stores it. In-flight requests are aborted and
private state cleared on unmount/pagehide; a bfcache restore reloads the invitation.
HTTPS and redaction of bearer paths from infrastructure/access/analytics logs
remain deployment requirements from [guest invitations](guest-invitations.md).

## Prices, saved state and submission

Only `items: [{ menu_item_id, quantity }]` is submitted. Prices, totals and guest
identities are never sent. Draft display calculations use integer `BigInt` cents,
not floating-point arithmetic, and always display two fractional digits without
inventing a currency. API responses are parsed into the supported typed fields.

Retained saved dishes use historical `unit_price` even when their quantity changes
or they are removed and re-added before saving. Newly added dishes display the
current menu price as an estimate; the successful server response supplies the
actual snapshot. Saving removal and later re-adding takes a new snapshot, following
the existing backend behavior. An unavailable saved dish remains visible with its
historical price and can be removed; it must be removed before saving a draft
because the existing API validates every retained dish's current eligibility.

Only draft orders without a submission timestamp are editable. Locked orders show
saved selections without edit/save controls. A concurrent submission's HTTP 409
locks the page, restores the last confirmed snapshot and explains that the edits
were not saved. **Refresh menu and status** retrieves the current snapshot; it keeps
unsaved quantities while the order is editable and uses persisted quantities once
locked. Invalid, expired, revoked and replaced links return 404, clear private page
data and ask the guest to contact the owner. Validation/network/rate-limit errors
retain the draft for retry; errors never claim that a failed save succeeded.

There is no background polling or cross-tab merging. The existing last-successful
replacement behavior for the same guest still applies. A connection lost after a
successful write has an uncertain result; retrying is safe through the existing
idempotent replacement API or the guest can refresh to see persisted selections.
The owner alone finalizes the group order; this UI never submits it.

## Design and accessibility

Reviewed Figma [guest menu (111683:86716)](https://www.figma.com/design/P9rkBNLJZ3WabXSQX9b5DO/EPAM-IT-Marathon-6.0?node-id=111683-86716)
and [cart (115001:51075)](https://www.figma.com/design/P9rkBNLJZ3WabXSQX9b5DO/EPAM-IT-Marathon-6.0?node-id=115001-51075).
The cream `#F7F7EC` background, white rounded cards, green actions, Poppins,
reservation banner and quantity controls follow the established public UI.

Differences: an always-visible desktop order panel replaces the modal cart; it
stacks below the menu on smaller screens with a header jump link. Category links
scroll to sections instead of hiding dishes behind tabs. Dish images, weights,
preparation times, popularity sorting, hero photography and currency symbols are
omitted because the current data does not supply them. Unavailable menu dishes
are hidden by the existing service rather than shown as sold out. The action is
**Save selections**, not Figma's **Submit Pre-Order**, because final submission
belongs to the owner. Reservation dates use the existing `BookingRules::TIMEZONE`
(`Asia/Tbilisi`), with the timezone shown explicitly; UTC storage is unchanged.

Native buttons, labeled quantity groups/outputs, 44px targets, visible focus,
skip/category/order links, semantic headings/lists and alert/status messages
support keyboard use. Menu, selection, validation, initial/refresh loading, empty,
locked, invalid and retry states are explicit. JavaScript is required to edit;
the HTML root provides a noscript explanation.

## Validation and preview

```sh
./vendor/bin/sail composer run ci:check
./vendor/bin/sail npm run build
```

Vue interaction tests cover menu browsing, exact totals, historical snapshots,
removal/re-adding, server-confirmed pricing, empty replacements, duplicate/pending
saves, quantity limits, unavailable dishes, loading/retry/422/429/404/network
failures, submitted/racing orders, refresh, storage avoidance and bfcache cleanup.
Decimal utility tests also cover large totals and malformed decimal strings.
Laravel feature tests cover HTML/JSON negotiation, guest privacy, read-only empty
data, historical prices, eligible menu filtering, other-guest/reservation isolation,
consolidated-summary integration, lock predicates and every invalid token state.
The existing invitation, selection, concurrency and submission tests remain enabled.

Verified on this branch: complete MySQL CI passed (283 PHP tests passed,
4 existing skips, 2,033 assertions), all 70 Vue tests passed, and the production
build passed. The complete in-memory SQLite CI also passed (275 PHP tests passed,
12 existing/driver skips, 1,929 assertions, and 70 Vue tests). This adds 14 Laravel
feature cases and 37 Vue/decimal cases. The
build retains the existing optional `fontaine` advisory. Browser checks used
production assets with an isolated disposable SQLite database: saved selections
survived reload, historical prices remained unchanged, submitted orders had no
editing controls, invalid links hid private data, and 320px/390px/768px layouts had no
horizontal overflow, including long unbroken names at 320px. Keyboard order
navigation focused the order panel, and Enter activated dish selection.

For local preview, start Sail and Vite (`./vendor/bin/sail up -d` and
`./vendor/bin/sail npm run dev`), open an owned reservation dashboard, create an
invitation and open the returned link. Existing saved selections restore on reload.
Do not paste a real bearer URL into shared screenshots or logs. No migrations,
dependencies or development-data changes are needed for this feature.

## Changed files

- Backend: `app/Http/Controllers/GuestInvitationController.php`, new
  `app/Http/Controllers/GuestMenuController.php`, new `app/Services/GuestMenuService.php`,
  and `routes/web.php`.
- Entry/build: new `resources/views/guest.blade.php`, new `resources/js/guest.ts`,
  and `vite.config.ts`.
- UI: new `resources/js/pages/guest/Menu.vue`, new
  `resources/js/components/guest/GuestOrderPanel.vue`, new
  `resources/js/components/guest/QuantityControl.vue`, and
  `resources/js/components/restaurants/MenuDishCard.vue` (optional action slot and
  heading level; existing default appearance unchanged).
- State/types: new `resources/js/composables/useGuestSelections.ts`, new
  `resources/js/lib/guestSelection.ts`, new `resources/js/lib/guestSelectionApi.ts`,
  and new `resources/js/types/guest-selection.ts`.
- Tests: new `tests/Feature/GuestMenuTest.php`, new
  `resources/js/pages/guest/Menu.test.ts`, new
  `resources/js/lib/guestSelection.test.ts`, and `tests/Feature/GuestInvitationTest.php`
  (disable Vite in its existing browser security test now that HTML has an entry).
- Documentation: this file, `docs/guest-dish-selection.md`, and
  `docs/guest-invitations.md`.
