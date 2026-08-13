# Bundled Firebase JS SDK 10.12.2 (compat builds)

Downloaded unmodified from Google's official CDN:

- https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js
- https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js

License: Apache License 2.0 (Copyright Google LLC) - see
https://github.com/firebase/firebase-js-sdk/blob/master/LICENSE

Bundled locally so the plugin loads no executable code from external
servers (WordPress.org plugin guideline 8). To upgrade: bump
Bcsend_Web_Push::FIREBASE_JS_VERSION, replace both files with the same
version from the URLs above, and re-test web push subscribe + receive.
