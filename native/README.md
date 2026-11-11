# Native counter apps

Both apps display the existing hosted Gangchil interface. Printing is performed by native code; there is no localhost server, companion app, manual pairing code or Python requirement for shop staff.

## Windows build

Build machine: Node 22.12+ (Node 24 recommended). In native/windows run npm ci, npm test, npm run build. Electron's Windows runtime is downloaded with checksum verification by @electron/get. NSIS is needed only to build the installer: run makensis installer.nsi from native/windows. Output: dist/Gangchil-Setup.exe. End users only run this installer. The current build is not Windows code-signed.

Printer enumeration and silent print use Electron's Windows printer queue API. A Windows queue/driver must exist for USB/Bluetooth printers. Only the selected printer is used; the system default is not silently substituted. Receipts use 58mm paper, 48mm content and the bundled Hind Siliguri font. Windows credential protection encrypts the worker token on disk. Only the exact configured HTTPS app origin and its top-level frame can call the narrow IPC interface. Node is disabled in remote content.

## Android build

JDK 17+, Android SDK 35, Gradle 8.9+. In native/android run gradle assembleDebug, or configure a release signing setup outside the repository. The shipped APK is release-signed; reuse its private signing backup for future in-place updates. No signing key/password is in GitHub. Package: com.gangchil.counter; min SDK 26.

The current APK uses WebView for the existing interface and a connected-device foreground service for receipt jobs. Bluetooth Classic SPP and USB bulk OUT are supported. USB serial-only adapters may require a different transport. Bengali/English text is rendered to a 384-dot bitmap, avoiding reliance on printer-resident Bengali fonts. USB/Bluetooth permissions remain per device. Product file upload and report download/print bridges are included.

## Shared queue

PHP routes live in private/print_queue.php, called from public/api.php. A normal authenticated staff session with CSRF protection registers an authorized device. A random, hashed-at-rest server token authorizes only worker claim/begin/result/recover routes; disabled users/devices or removed cash permission invalidate it.

State: queued -> claimed -> dispatching -> submitted or unknown. A destination row lock serializes claims for the same physical counter printer, including claims for different orders. A fresh unpredictable claim token fences stale clients. Pre-dispatch leases last 90 seconds. A duplicate begin is rejected rather than authorizing another physical write. Dispatching jobs never expire into automatic replay. A local durable journal records the delivery boundary before printer I/O. If submission succeeded but server acknowledgement failed, only the acknowledgement is retried. After an ambiguous crash, the queue holds delivery for paper inspection. Explicit retries create a new copy ID.

The shipped UI uses one shared Counter receipt printer destination across multiple eligible devices. It does not provide automatic discovery or routing across multiple different physical printers. When two devices contend for a Bluetooth printer that accepts only one connection, an Android connection failure before begin releases the claim safely. Windows spooler acceptance may precede actual paper output.

No absolute exactly-once physical printing guarantee is claimed. Power loss, paper jams and transport acknowledgements cannot reliably prove paper delivery on this hardware.

## Tests

Windows worker tests use the actual worker code with mocked Electron/device boundaries to verify silent selected-device output, acknowledgement recovery and duplicate suppression. Frontend tests exercise current cart recovery and payment/print hooks. tests/print-queue-db.php uses the real queue routes and a disposable MySQL database in CI to test concurrent claims and delivery state transitions.

Hardware still required: Windows driver/installer run, Android installation and Doze/foreground service behavior, MHT-P11 paper layout, Bluetooth/USB transfer and reconnect tests. A closed/force-stopped app cannot process jobs. PHP hosting uptime and InfinityFree verification must be checked on-device.
