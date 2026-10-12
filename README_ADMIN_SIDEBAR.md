# KitaKits — Admin Sidebar (fixed & organized)

## Why you kept seeing "nothing changed"
There were **two copies** of this project on the machine:

| Folder | Role |
|---|---|
| `C:\Users\cole\OneDrive\Desktop\KitaKits` | **The real project** (edit here) |
| `C:\xampp\htdocs\KitaKits` | What Apache served (was a stale duplicate) |

XAMPP's `httpd.conf` has `DocumentRoot "C:/xampp/htdocs"`, so the browser was
loading the *old* files no matter what changed on the Desktop.

**This is now fixed:** `C:\xampp\htdocs\KitaKits` is a **junction** (a link)
pointing at the Desktop folder. There is only ONE copy now. Save a file in
either location and both see the change.

The previous duplicate is parked at `C:\xampp\htdocs\KitaKits_OLD_20260930_180916`
and can be deleted whenever you're satisfied.

## Viewing the result
1. Make sure Apache is running (XAMPP Control Panel).
2. Open `http://localhost/KitaKits/`
3. Log in as an **Administrator** -> you land on the dashboard.

**No Ctrl+F5 needed.** Assets are loaded through `admin/includes/assets.php`,
which appends `?v=<filemtime>` to the CSS/JS URLs. Edit the CSS or JS, hit
normal reload, and you see the change.

## The original bug
`css/admin-sidebar.css` had two CSS rules missing a closing brace
(`.menu-btn` and `.user-name`) plus orphaned fragments after the `@media`
block. The browser swallowed everything after the unclosed brace, which killed
the menu-link rules, icon sizing, the whole collapsed state, and the content
offset — on every page. The braces are fixed and the stray fragments removed.

## Admin Dashboard

The original dashboard UI is unchanged: header, Admin Overview, stat cards,
Rental Overview + Equipment Status charts, and Recent Rentals all remain.

Two cards were **added** to match the requested stats (both query the live DB):

| Card | Source |
|---|---|
| Total Equipment | `equipment` (owner-scoped) |
| Pending Requests | `reservations` where `status='pending'` |
| Active Rentals | `reservations` where `status='approved'` and today is inside the rental window |
| Overdue Items | `reservations` where `status='approved'` and `end_date < CURDATE()` |
| Approved Rentals | `reservations` where `status='approved'` (all time) |
| Registered Users | `users` |

`style.css` pins `.stats` to `repeat(4,1fr) !important` — written for four
cards. Since there are now six, `css/admin-sidebar.css` raises it to 3
columns on desktop, 2 on tablet, 1 on mobile. Card styling is untouched;
only the column count adapts.

## Admin - Transactions

Added as a self-contained module. Three new files, plus minimal edits to
`admin/transactions.php` only. Nothing else was touched.

| File | Purpose |
|---|---|
| `admin/api/transactions.php` | JSON endpoint: `list`, `fetch`, `mark_paid` |
| `css/transactions.css` | Scoped to `body.new-dashboard`, all selectors `.tx-` |
| `js/transactions.js` | Table render, receipt modal, Mark as Paid, toasts |

### No database change was needed

`transactions` already stores `transaction_id`, `reservation_id`, `amount`,
`payment_status`, `payment_method` and `payment_date`, so nothing was added
or altered. The module joins **through `reservations`** to reach the renter
and the equipment, because `transactions.user_id` is `NULL` for the rows the
approve flow creates.

`is_paid` is resolved in SQL (`LOWER(payment_status) = 'paid'`), so the badge
and the action button can never disagree with what is stored:

| Stored status | Badge | Action |
|---|---|---|
| `paid` | green | View Receipt |
| anything else | red | Mark as Paid |

Mark as Paid only sets `payment_status = 'paid'`. It does **not** touch
`payment_date`, so the student payments page keeps its existing ordering.

> **Fixed in passing:** the previous version of this page queried
> `reservations` instead of `transactions` and hardcoded a green "Paid"
> badge on every row, so unpaid rentals were shown as paid. The new version
> reads the real `transactions` rows.

## Admin - Rental History

Added as a self-contained module. Three new files, plus minimal edits to
`admin/rental_history.php` only. Nothing else was touched.

| File | Purpose |
|---|---|
| `admin/api/rental-history.php` | JSON endpoint: `list`, `fetch`, `mark_returned` |
| `css/rental-history.css` | Scoped to `body.new-dashboard`, all selectors `.rh-` |
| `js/rental-history.js` | Table render, Mark Returned modal, toasts |

### Database

The `reservations` table had no way to record a return, so two **nullable**
columns were added to it. No new table was created, and no existing
relationship changed:

```sql
returned_at       DATETIME NULL    -- when the equipment came back
return_condition  VARCHAR(50) NULL -- Excellent | Good | Fair | Damaged
```

Both are `NULL` for every pre-existing row, so nothing was altered. A backup
of the table was kept at `reservations_bak_20260930`.

### Status is computed in SQL

`history_state` is derived in the query, so the badge can never disagree
with the dates:

| State | Rule | Badge |
|---|---|---|
| Returned | `returned_at IS NOT NULL` | green |
| Overdue | `end_date < CURDATE()` and not returned | red |
| Ongoing | still inside the rental window | blue |

`style.css` already had `.status-badge.overdue` (red) and `.completed`
(blue), so only the green `.returned` variant was added.

> **Note:** marking a rental returned records the date and condition only.
> It does **not** add the units back to `equipment.available_quantity` -
> that stock movement belongs to the existing rental flow and was left
> alone deliberately. Say the word if you want the quantity restored on
> return.

## Admin - Rental Request

Added as a self-contained module. Three new files, plus small edits to
`admin/rental_request.php` only. No other page, table or style was touched.

| File | Purpose |
|---|---|
| `admin/api/rental-request.php` | JSON endpoint: `list`, `fetch`, `approve`, `reject` |
| `css/rental-request.css` | Scoped to `body.new-dashboard`, all selectors `.rr-` |
| `js/rental-request.js` | Table render, Review modal, approve/reject, toasts |

`approve` / `reject` deliberately repeat the rules that already existed in
`rental_request.php` (`?approve=` / `?decline=`): check stock, decrement
`available_quantity`, set the status, insert the `transactions` row and notify
the student. **The old GET links were left in place and still work.**

Two deliberate choices worth knowing:

- **Reject stores `'declined'`, not `'rejected'`.** That is the value the
  existing decline handler has always written, and `rental_history.php` and
  the student pages read it. Changing it would break them. The table simply
  labels it **Rejected** with the same red badge.
- **Approve is guarded.** Re-approving an already-decided request returns an
  error instead of decrementing stock twice.

Payment column reads the most recent `transactions.payment_status` for the
reservation; `paid` shows a green badge, anything else shows red **Unpaid**.

## Why icons disappeared on other pages
Eight of the nine admin pages had a **broken Font Awesome tag** — the line
was split so the quote was never closed:

```html
<link rel="stylesheet
href="https://cdnjs.cloudflare.com/.../all.min.css">   <!-- rel="stylesheet\nhref=" -->
```

The browser read `rel="stylesheet href="…"` and loaded nothing, so **every**
`fa-*` icon in the sidebar vanished. Only `dashboard.php` had a correct
single-line tag, which is exactly why the dashboard looked fine and the
other pages did not.

Font Awesome now lives in `admin/includes/assets.php`, loaded once for every
page, so a page can no longer break its own icons.

## File layout (don't edit these by hand on a page)
| File | Purpose |
|---|---|
| `admin/includes/sidebar.php` | The sidebar markup: logo, hamburger, profile, all 5 nav links, logout, and the logout-confirm modal. |
| `admin/includes/assets.php` | Loads Font Awesome + `admin-sidebar.css` + `admin-sidebar.js`, with cache-busting. |
| `admin/manage_equipment.php` | Equipment **table** view + Add/Edit modals (same header/sidebar as the dashboard). |
| `admin/api/equipment.php` | JSON endpoint: `list`, `fetch`, `create`, `update`, `delete`. All owner-scoped. |
| `js/manage-equipment.js` | Table rendering, debounced search, modals, toasts. |
| `uploads/` | Equipment photos, stored as `eq_<time>_<rand>.<ext>`. |
| `includes/sidebar_user.php` | The profile card (avatar initial, name, role). |
| `css/admin-sidebar.css` | **All** sidebar layout, collapse and modal styling. |
| `js/admin-sidebar.js` | Collapse/expand + persisted state + logout confirmation. |

Every page under `admin/` includes the first two. To restyle the sidebar,
edit `css/admin-sidebar.css` — do not add sidebar CSS or markup to a page.

## Notes
- Collapse state is saved in `localStorage` (`kitakits_admin_sidebar_collapsed`),
  so it stays collapsed as you move between admin pages.
- **Collapsed state hides the whole KitaKits brand block** (logo tile + wordmark)
  and shows only the navigation icons, evenly spaced on one centre axis. The
  hamburger is deliberately kept so the sidebar can be expanded again.
  Expanding restores the logo and labels automatically.
- Logout shows a **"Are you sure you want to log out?"** dialog.
  *Yes, Logout* destroys the session; *Cancel* (or Esc / backdrop click) does not.
- No database schema, reservation, equipment or transaction logic was changed.
