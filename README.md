# GANGCHIL — Restaurant & Kabsa Place

## Stack
HTML + CSS + Vanilla JavaScript + PHP 8.1+ + MySQL/MariaDB.
No Node.js, PostgreSQL, Socket.IO or VPS needed for cheap shared hosting.

## Cash-only restaurant workflow
1. Only staff with a login can take orders on the one restaurant tablet.
2. Tablet creates order (pending_payment).
3. Manager confirms physical cash receipt (new).
4. Chef starts preparation (preparing), marks ready (ready).
5. Waiter marks served (served).
6. Staff prints paid order receipt on an 80mm thermal printer using the tablet print service.

Uses database transactions, authoritative menu prices, request tokens to prevent duplicate checkout, audit logs and granular permissions.

## cPanel / LiteSpeed shared hosting installation
Requirements: PHP 8.1+, PDO MySQL, mbstring, MySQL or MariaDB, HTTPS and .htaccess rewrite support.

1. Create MySQL database/user in cPanel and grant database privileges.
2. Import db/mysql.sql via phpMyAdmin.
3. Upload the CONTENTS of the public folder to public_html.
4. Upload the private folder BESIDE public_html (account home level), not inside it.
5. Copy private/config.example.php to private/config.php. Set DB_HOST, DB_NAME, DB_USER, DB_PASSWORD and a long random INSTALL_TOKEN. Keep config.php private.
6. Visit https://your-domain.com/install.php, enter matching INSTALL_TOKEN and create the first Owner login.
7. Delete public_html/install.php after successful setup.
8. Visit https://your-domain.com/#login and sign in. Add products, staff and test payment and receipt workflows.

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

## Render free demo (optional)
Dockerfile + render.yaml serve the same PHP code on Render.
Create an EXTERNAL MySQL database and import db/mysql.sql. Configure environment variables MYSQL_HOST, MYSQL_PORT, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASSWORD, INSTALL_TOKEN, PUSHER_APP_ID, PUSHER_KEY, PUSHER_SECRET and PUSHER_CLUSTER.
When private/config.php is absent, private/config.env.php reads these environment variables.
Run install.php to create the first owner. Render Free sleeps on inactivity, so use for demos only.

## Production checklist
Enable HTTPS; never commit private/config.php; ensure daily external backups and restore testing; confirm PHP worker/entry-process limits with shared-host provider; test realistic tablet-to-kitchen flow and print on actual hardware.
This is a source implementation, not a verified live deployment. PHP syntax and JS syntax need CI checks; real MySQL and end-to-end test are still required.
