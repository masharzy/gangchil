# GANGCHIL — Restaurant & Kabsa Place

## Stack
HTML + CSS + Vanilla JavaScript + PHP 8.1+ + MySQL/MariaDB.
No Node.js, PostgreSQL, Socket.IO or VPS needed for cheap shared hosting.

## Cash-only restaurant workflow
1. Customers open the restaurant tablet at `/` and browse the menu without signing in.
2. Customers review their items, review their choices and submit an unpaid order.
3. Staff sign in separately at `/staff/`; staff and customer sessions use different cookies, so opening the customer menu does not expose a logged-in staff workspace.
4. Tablet creates order (pending_payment).
5. Manager confirms physical cash receipt (new).
6. Chef starts preparation (preparing), marks ready (ready).
7. Waiter marks served (served).
8. Staff prints paid order receipt on an 80mm thermal printer using the tablet print service.

Uses database transactions, authoritative menu prices, request tokens to prevent duplicate checkout, audit logs and granular permissions.

## InfinityFree: working PHP + MySQL demo (optimized upload)

**Supported account requirements**: free subdomain or custom domain, PHP 8.3+, MariaDB 10.6+, PHP PDO MySQL, cURL/HTTPS, File Manager or FTP.

**Do not upload `private/config.php` to GitHub or send account/database passwords in chat.**

1. In InfinityFree: Accounts → Manage → Control Panel → **MySQL Databases** → create a database called e.g. `gangchil`. Write down the **full** database name, MySQL hostname (e.g. `sql###.infinityfree.com`, not `localhost`) and database user. The password is the **hosting-account password**, not the InfinityFree client-area password.
2. Download the ready-made ZIP by opening **GitHub → Actions → Build InfinityFree upload package → latest successful run → Artifacts → gangchil-infinityfree-htdocs**. Unzip that artifact; inside is `gangchil-infinityfree.zip`, containing files meant to be uploaded **directly to the website's `htdocs/`**. Avoid uploading another nested `htdocs/` directory.
3. The InfinityFree File Manager can upload ZIP and forward-upload extracted files; if it fails, extract the inner ZIP locally and transfer all contents using FTP/FileZilla. Make sure `index.html`, `api.php`, `install.php`, `.htaccess`, and `private/` end up directly under `htdocs/`.
4. In **`htdocs/private/`**, copy `config.example.php` to `config.php`. Edit **DB_HOST, DB_NAME, DB_USER, DB_PASSWORD** with your hosting account's MySQL details; **INSTALL_TOKEN** must be a unique random string with 24+ characters. `private/.htaccess` is in the package and must remain present to block public access to secrets; test `https://<domain>/private/config.php` returns **403 Forbidden** before creating an account.
5. On your domain open `https://<domain>/install.php`, enter INSTALL_TOKEN and create the first Owner. The installer will automatically import the protected `private/mysql.sql` schema and create initial categories; no phpMyAdmin SQL import is required for this packaging format.
6. Log in as Owner. Add product details and other staff, create an order, cash-confirm it, move it through Chef → Ready → Served, and test the paid 80mm receipt page.
7. Optional Pusher Channels WebSocket notifications: configure `PUSHER_APP_ID`, `PUSHER_KEY`, `PUSHER_SECRET`, `PUSHER_CLUSTER` in the same private config.php; PHP sends outbound HTTPS events on port 443. Check the Live indicator on two devices. Without Pusher, **↻ Refresh** loads just the list with no whole-page reload. **No background polling**.
8. Remove `install.php` from hosting once installation is complete. Export regular offsite MySQL backups.

**InfinityFree quirks**: server-sent API calls and external automation may hit a mandatory browser anti-bot challenge, but same-origin browser AJAX is supported if the browser keeps the security cookie. The Pusher private-channel authentication call is same-origin. Remote MySQL access is unavailable on the free plan. External outgoing HTTPS calls are permitted generally, but test Pusher publication on the real host. This free host is **for client demonstrations only**, not restaurant production.

## cPanel / LiteSpeed shared hosting installation
Requirements: PHP 8.1+, PDO MySQL, mbstring, MySQL or MariaDB, HTTPS and .htaccess rewrite support.

1. Create MySQL database/user in cPanel and grant database privileges.
2. Import db/mysql.sql via phpMyAdmin.
3. Upload the CONTENTS of the public folder to public_html.
4. Upload the private folder BESIDE public_html (account home level), not inside it.
5. Copy private/config.example.php to private/config.php. Set DB_HOST, DB_NAME, DB_USER, DB_PASSWORD and a long random INSTALL_TOKEN. Keep config.php private.
6. Visit https://your-domain.com/install.php, enter matching INSTALL_TOKEN and create the first Owner login.
7. Delete public_html/install.php after successful setup.
8. Visit https://your-domain.com/staff/ and sign in. Add products, staff and test payment and receipt workflows.

## Real-time order updates: Pusher Channels + manual Refresh (no polling)
- Server is still **PHP + MySQL** on standard cheap shared hosting. No Node.js, WebSocket server or VPS is needed.
- Configure a **Pusher Channels Sandbox** app at https://dashboard.pusher.com and copy **APP_ID, KEY, SECRET, CLUSTER** to the matching `PUSHER_*` constants in the private config.php.
- PHP uses an authenticated **private-gangchil-staff** channel. When an order is saved or its status changes, PHP commits the database transaction and then publishes a tiny `gangchil:changed` event over HTTPS. No order/customer details are transmitted in that event.
- Staff browsers maintain a Pusher-managed WebSocket; on a change event, only the order list is fetched with AJAX. A green "Live" status confirms subscription; amber/red statuses show when realtime is unavailable.
- A small **↻ Refresh** button refreshes the order list only, **without a full-page reload**. It works even if Pusher is down or not configured.
- **NO periodic AJAX polling**. The app also fetches fresh data when reopening the order screen or when a reconnect/visibility change happens, so a disconnected tablet can recover missed changes.
- Publish failures cannot roll back or erase committed orders. MySQL remains the source of truth. No guarantee of zero-second delivery.
- Pusher's free tier currently advertises 200,000 messages/day and 100 concurrent connections; quota is affected by fan-out to connected staff devices. Verify the live terms before relying on it.
- Provider must allow PHP to make outbound HTTPS requests to Pusher and must have PHP cURL or HTTPS stream support. SSL/TLS is essential.
- Run an end-to-end push/reconnect/manual-refresh test using at least two staff devices before live operation.

## Thermal receipts
Staff click Print Receipt on a paid order. receipt.php provides an 80mm print stylesheet and the browser's Print dialog. A compatible Android/iPad printer driver or print service is necessary. Direct silent ESC/POS printing is NOT implemented; test the actual tablet/printer pair before purchasing.

## Railway full-database client demo (one project, no fake API)
Use Railway Trial/Free to run **both** the real PHP application and a **MySQL service** inside the same Railway project.
1. Connect Railway and create a project. Add a **MySQL** service using + New → Database → MySQL.
2. Add an **app service** from GitHub repository `masharzy/gangchil`, branch `master`, with Dockerfile deployment (Railway automatically detects the repository Dockerfile).
3. On the PHP app service, configure **variable references** to the MySQL service (change the service label if necessary):
   - `MYSQLHOST=${{MySQL.MYSQLHOST}}`
   - `MYSQLPORT=${{MySQL.MYSQLPORT}}`
   - `MYSQLUSER=${{MySQL.MYSQLUSER}}`
   - `MYSQLPASSWORD=${{MySQL.MYSQLPASSWORD}}`
   - `MYSQLDATABASE=${{MySQL.MYSQLDATABASE}}`
   - `INSTALL_TOKEN=` a long randomly generated secret (32+ characters)
   - Optional Pusher values: `PUSHER_APP_ID`, `PUSHER_KEY`, `PUSHER_SECRET`, `PUSHER_CLUSTER`.
4. Set PHP app **health check** to `/health` and generate a Railway public domain. The MySQL service stays **private**.
5. Open `https://<app-domain>/install.php`; enter the secret installation token, owner name/mobile/password (12+ chars) to automatically import the included MySQL schema and create the first owner, default categories. Manual SQL import is not required on this Docker demo.
6. Sign in, add menu items and staff accounts, then verify tablet order → manager cash confirmation → chef preparation → waiter served → paid receipt print.
7. Delete or restrict the installer for long-term production use. After first Owner creation, it blocks additional installs automatically.
8. Store credentials **only in Railway service variables**. Never add them to GitHub, screenshots or chat messages.

This deploy uses real MySQL transactions and persists orders when the PHP container restarts. PHP's default file-based login sessions can be lost after a container replacement; staff may need to sign in again. No external service is needed for MySQL. Pusher credentials are needed for instantaneous remote notifications; otherwise the button-based manual Refresh still works.
Railway's new Config-as-Code format is evolving, so set deployment settings in the Railway dashboard, not a deprecated railway.json. Trial credits may expire; check your billing dashboard to avoid surprises.

## Render free demo (optional)
Dockerfile + render.yaml serve the same PHP code on Render.
Create an EXTERNAL MySQL database and import db/mysql.sql. Configure environment variables MYSQL_HOST, MYSQL_PORT, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASSWORD, INSTALL_TOKEN, PUSHER_APP_ID, PUSHER_KEY, PUSHER_SECRET and PUSHER_CLUSTER.
When private/config.php is absent, private/config.env.php reads these environment variables.
Run install.php to create the first owner. Render Free sleeps on inactivity, so use for demos only.

## Production checklist
Enable HTTPS; never commit private/config.php; ensure daily external backups and restore testing; confirm PHP worker/entry-process limits with shared-host provider; test realistic tablet-to-kitchen flow and print on actual hardware.
This is a source implementation, not a verified live deployment. PHP syntax and JS syntax need CI checks; real MySQL and end-to-end test are still required.

## Customer tablet and staff workspace
The website root is the public customer menu. `/staff/` serves the separate protected workspace; `/staff/api/` uses a staff-only session cookie scoped to `/staff/`. Public APIs allow menu reads, CSRF session bootstrap and creating pending-cash orders only. Public visitors cannot list orders, confirm cash, change the menu or manage staff. Paid receipts are available to authenticated staff at `/staff/receipt.php`. No database migration is required for this change. Existing staff must sign in again after the cookie separation. Test both surfaces before leaving the tablet with customers.

## Bangladesh mobile sign-in
Staff accounts now use a unique Bangladeshi mobile number and password. Local 01XXXXXXXXX, +880 and Bengali-digit inputs are normalized to the same 11-digit number. The existing owner must save a mobile number in Your account while still signed in; owners can assign numbers to existing staff in Staff & access. The additive compatibility migration retains historical records and legacy email columns but email is not used for authentication or displayed. Existing dining-table records are retained as history; active table routes, selections, permissions and receipt labels are removed. New installations do not create dining tables.
