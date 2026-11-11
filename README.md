# Gangchil

Restaurant counter software: PHP + MySQL backend, customer tablet menu, Owner/Manager workspace, Windows desktop app and Android app. Customer ordering happens on a device supplied by the restaurant. Customers do not need their own phones or an account.

The existing hosted interface is used inside both native apps, preserving the menu, cart, dashboard, orders, products, categories, expenses, reports, activity log, staff management and phone/PIN sign-in. Native printer settings replace the old companion/pairing-code setup.

## Cash and printing

1. Customer places an unpaid order on the restaurant device.
2. Owner/Manager receives cash. Payment and a unique receipt job are saved in the same MySQL transaction.
3. An authorized printer-connected app claims the shared receipt job.
4. The native app prints without the browser print dialog and acknowledges submission.

There is no mandatory primary computer. Multiple authorized Windows/Android devices can handle the same counter printer. A database lock serializes that printer's work; another device cannot claim a second receipt while one is claimed or being delivered. Only a claim that has not begun delivery can expire and transfer automatically. Uncertain physical delivery is held for a paper check, because replaying it could produce a duplicate. Explicit reprints get a new job identity.

A successful acknowledgement means sent to the Windows spooler or printer transport, not proof that paper physically emerged. Cash is not charged again after a print error.

## Install the apps

Windows: run the supplied Gangchil-Setup.exe, open Gangchil, sign in, open Printer settings, select the installed receipt printer and test. Python, command files, pairing codes and a separate connector are unnecessary. Windows may still need the manufacturer's printer driver. The close button keeps the app in the notification tray; Exit Gangchil stops it.

Android 8+: install the supplied Gangchil-Android.apk. Pair the printer in Android Bluetooth settings (or connect USB OTG), open Gangchil, sign in and select the connected printer. Grant the relevant Bluetooth/USB and notification permissions. A foreground service can receive receipt jobs while viewing the customer menu or while the screen is off. Android battery/Doze restrictions can delay background work; internet connectivity alone does not guarantee delivery. Force-stopping the app stops the service.

Only devices that will physically print need a printer selection. Other order/payment devices use the shared database and queue without selecting their own printer. Bluetooth pairing and device permissions are local OS requirements; a database preference cannot grant them on a different device. At least one connected app and the printer must be running when a receipt is to print. No separate server PC is required. A sleeping/offline/closed device cannot print.

The apps currently use https://gangchil.42web.io. Internet is required for the hosted UI and central database. They are not standalone offline databases.

## Hosting

Keep PHP and MySQL/MariaDB on the same shared hosting account. Images stay in public/uploads. Apps communicate with the HTTPS PHP API; they never connect directly to MySQL. The existing InfinityFree account is the temporary backend. Its browser verification and quotas must be checked on real devices; paid shared hosting can replace it later. Do not advertise this as a verified production deployment before hardware and hosting tests.

Requirements: PHP 8.2+, PDO MySQL, mbstring, OpenSSL, MySQL/MariaDB with InnoDB, HTTPS, and rewrite support. Configuration is private/config.php (see config.example.php), or environment variables in config.env.php. Never commit real database credentials or signing keys.

For an existing hosting installation, preserve configuration, uploads and database data. Deploy private/print_queue.php first, then public/api.php and public/thermal-print.js to the corresponding existing htdocs paths. The complete current public/private source is included for fresh packages. Additive migrations create the queue tables. There is no database reset. Reload old browser tabs and stop the old Print Connector before switching to native printing.

GitHub Actions packages an InfinityFree htdocs ZIP. It does not deploy to the hosting account. New installations use install.php with an installation token and an Owner phone/4-digit PIN; remove the installer afterwards. Existing installations do not run the installer again.

## Source and validation

See [native/README.md](native/README.md) for app builds and queue design. Run:

```
node tests/frontend-smoke.cjs
node --test native/windows/test/*.test.cjs
```

The CI workflow also syntax-checks PHP/JS and exercises real MySQL concurrent claims, stale claim fencing, safe pre-delivery failover, acknowledgement retries, uncertain delivery holds and device revocation against a disposable database. Unit/CI tests do not replace testing the MHT-P11 on Windows and Android. Windows installation, Android background behavior and physical paper output remain device checks.
