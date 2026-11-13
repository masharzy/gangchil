# Publishing app updates

After building both apps and signing Android with the existing release key:

```
python3 native/release/package-release.py --version 1.2.5 --apk Gangchil-Android.apk --exe Gangchil-Setup.exe --out Gangchil-Hosting-Release-1.2.5.zip
```

Extract this package into the hosting account's `public_html`. It adds versioned
files in `downloads/` and publishes `native-update.json` last. Existing data,
images and database configuration are unaffected. Publish a new package for every
future release; a local build or a ChatGPT download alone is not a published update.
Never advertise an unsigned Android build: updates must use the same signing key.
The app checks at startup/foreground, only offers newer versions, and verifies the
linked download exists before showing an update. Update opens the system browser;
installation still uses Android/Windows installation confirmation.
