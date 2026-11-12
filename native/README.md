# Native counter apps

Both apps package the existing Gangchil interface, fonts and scripts locally. Hosting serves authenticated API/database requests and uploaded images. The built-in browser engine renders bundled files; shop staff do not install a browser or open a website. Printing is performed by native code; there is no localhost server, companion app, manual pairing code or Python requirement for shop staff.

## Windows build

Build machine: Node 22.12+ (Node 24 recommended). In native/windows run npm ci, npm test, npm run build. Electron's Windows runtime is downloaded with checksum verification by @electron/get. NSIS is needed only to build the installer: run makensis installer.nsi from native/windows. Output: dist/Gangchil-Setup.exe. End users only run this installer. The current build is not Windows code-signed.

Printer enumeration and silent print use Electron's Windows printer queue API. A Windows queue/driver must exist for USB/Bluetooth printers. Only the selected printer is used; the system default is not silently substituted. Receipts always occupy 48mm with 2mm side padding (44mm content) and 3mm top padding. The app does not override paper size: usePrinterDefaultPageSize retains the selected printer driver paper; printableArea starts inside the driver-reported hardware printable area. There is no paper selector. A4 leaves the rest blank; thermal uses the same physical receipt dimensions. Long names and amounts wrap; an overflow check holds unsafe output. Windows physical USB detection refreshes about every 1.5 seconds, subject to Windows reporting delay. Saved queues without confirmed connectivity are never labelled Ready. Windows credential protection encrypts the worker token on disk. Only the exact configured HTTPS app origin and its top-level frame can call the narrow IPC interface. Node is disabled in remote content.

## Android build

JDK 17+, Android SDK 35, Gradle 8.9+. In native/android run gradle assembleDebug, or configure a release signing setup outside the repository. The shipped APK is release-signed; reuse its private signing backup for future in-place updates. No signing key/password is in GitHub. Package: com.gangchil.counter; min SDK 26.

The current APK uses WebView for the bundled interface and a connected-device foreground service for receipt jobs. Bluetooth Classic SPP and USB bulk OUT are supported. USB serial-only adapters may require a different transport. Bengali/English text is rendered to a 384-dot bitmap, avoiding reliance on printer-resident Bengali fonts. USB/Bluetooth permissions remain per device. Product file upload and report download/print bridges are included.

## App assets and updates

Run `python3 native/shared/bundle-ui.py` after changing public UI files, then copy native/shared/ui into native/android/assets/ui and native/windows/native-ui.js into native/android/assets/native-ui.js. App updates carry UI changes; server API changes are deployed separately. The included fonts and Pusher retain their licenses in native/shared/licenses. InfinityFree browser verification is initialized in a separate hidden health window before API calls; local UI renders immediately.

Cash confirmation wakes the local worker immediately, without waiting for the periodic fallback. Pusher status events wake other active app devices when configured. Shared claim/begin checks remain necessary to prevent duplicate prints; network latency, OS spooler and hardware warm-up still affect physical output time.

## Shared queue

PHP routes live in private/print_queue.php, called from public/api.php. A normal authenticated staff session with CSRF protection registers an authorized device. A random, hashed-at-rest server token authorizes only worker claim/begin/result/recover routes; disabled users/devices or removed cash permission invalidate it.

State: queued -> claimed -> dispatching -> submitted or unknown. A destination row lock serializes claims for the same physical counter printer, including claims for different orders. A fresh unpredictable claim token fences stale clients. Pre-dispatch leases last 90 seconds. A duplicate begin is rejected rather than authorizing another physical write. Dispatching jobs never expire into automatic replay. A local durable journal records the delivery boundary before printer I/O. If submission succeeded but server acknowledgement failed, only the acknowledgement is retried. After an ambiguous crash, the queue holds delivery for paper inspection. Explicit retries create a new copy ID.

The shipped UI uses one shared Counter receipt printer destination across multiple eligible devices. It does not provide automatic discovery or routing across multiple different physical printers. When two devices contend for a Bluetooth printer that accepts only one connection, an Android connection failure before begin releases the claim safely. Windows spooler acceptance may precede actual paper output.

No absolute exactly-once physical printing guarantee is claimed. Power loss, paper jams and transport acknowledgements cannot reliably prove paper delivery on this hardware.

## Tests

Windows worker tests use the actual worker code with mocked Electron/device boundaries to verify silent selected-device output, acknowledgement recovery and duplicate suppression. Frontend tests exercise current cart recovery and payment/print hooks. tests/print-queue-db.php uses the real queue routes and a disposable MySQL database in CI to test concurrent claims and delivery state transitions.

Hardware still required: Windows driver/installer run, Android installation and Doze/foreground service behavior, MHT-P11 paper layout, Bluetooth/USB transfer and reconnect tests. A closed/force-stopped app cannot process jobs. PHP hosting uptime and InfinityFree verification must be checked on-device.

## New hosting and first-owner setup (1.2.0)
Both apps use `https://gangchil.xyz` for PHP APIs and product uploads. HTML, scripts and fonts remain bundled inside each application. Database credentials stay on the PHP server; no database password is embedded in either app. No old data/images are imported.

The staff app opens `/install.php` when no owner exists. Setup needs a name, Bangladeshi phone number and 4-digit PIN, with CSRF protection and a database advisory lock; there is no installation key. Setup locks after the first account is created. The host needs working DNS and valid HTTPS before apps can use its API.

Windows polls authenticated order alerts while running, including in the tray. Per-account cursors persist across restarts and consume muted events without replay. Fully exiting the application stops this listener. The build workflow produces an **unsigned** Windows installer; a trusted code-signing certificate/service is required for trusted Gangchil signing.

Android has native Firebase Messaging integration, including background notification messages and foreground notification display. It does not depend on a printer being paired. Users enable notifications in the staff notification settings and allow the Android permission. This functionality is **not operational until a Firebase project is configured**. Expo also requires Android FCM credentials and is not used in this Java app.

Place these two files outside the web root, next to `private/bootstrap.php` (never commit real values):

- `firebase-client.json`: public Android configuration keys `apiKey`, `appId`, `projectId`, `messagingSenderId` from the Firebase Android app for package `com.gangchil.counter`.
- `firebase-service-account.json`: FCM service-account credentials for the same project, with permission to send messages. Never bundle this private key in the APK or EXE.

The app retrieves the public configuration after staff sign-in, so enabling the server configuration does not require another APK build. Token registration is staff authenticated and CSRF protected; sign-out unregisters the token. Server dispatch checks owner/manager status and notification mute preferences.

For retry delivery even with no staff app open, configure cPanel cron every minute:
`/usr/local/bin/php /home/laxqfoch/private/native_push_worker.php >/dev/null 2>&1`

The GitHub Actions Android artifact is unsigned. Sign using the existing release keystore before distribution. An Android release signature is different from a Windows trusted publisher certificate. Physical Android/Windows notification delivery requires device testing after DNS, HTTPS and FCM configuration are live.
