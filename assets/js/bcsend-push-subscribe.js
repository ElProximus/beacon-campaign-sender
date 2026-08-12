/**
 * Beacon Campaign Sender - Web push subscription.
 *
 * Loads the Firebase JS SDK (from Google's gstatic CDN, on demand and only
 * after the visitor clicks subscribe), asks browser permission, obtains the
 * FCM token, and registers it with Beacon's device registry.
 *
 * @package Bcsend_Plugin
 * @since   1.0.6
 */

(function () {
	'use strict';

	if (typeof bcsendPush === 'undefined') {
		return;
	}

	var sdkLoaded = false;

	function loadScript(src) {
		return new Promise(function (resolve, reject) {
			var s = document.createElement('script');
			s.src = src;
			s.onload = resolve;
			s.onerror = reject;
			document.head.appendChild(s);
		});
	}

	function loadFirebase() {
		if (sdkLoaded) {
			return Promise.resolve();
		}
		var base = 'https://www.gstatic.com/firebasejs/' + bcsendPush.sdkVersion;
		return loadScript(base + '/firebase-app-compat.js')
			.then(function () { return loadScript(base + '/firebase-messaging-compat.js'); })
			.then(function () {
				firebase.initializeApp(bcsendPush.firebaseConfig);
				sdkLoaded = true;
			});
	}

	function registerToken(token) {
		var headers = { 'Content-Type': 'application/json' };
		if (bcsendPush.restNonce) {
			headers['X-WP-Nonce'] = bcsendPush.restNonce;
		}
		return fetch(bcsendPush.restUrl, {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			body: JSON.stringify({ token: token, platform: 'web' })
		}).then(function (response) {
			// A 400/500 here means the token was NOT stored - never report
			// success, or the visitor believes they are subscribed forever.
			if (!response.ok) {
				throw new Error('registration-failed');
			}
			return response;
		});
	}

	function markSubscribed(buttons) {
		buttons.forEach(function (btn) {
			btn.classList.add('is-subscribed');
			if (!btn.classList.contains('bcsend-push-bell')) {
				btn.textContent = bcsendPush.labels.subscribed;
			}
			btn.disabled = true;
		});
	}

	// Re-register the current token on load for already-permitted visitors.
	// FCM rotates tokens, and the server row may have been pruned, so the
	// browser is the source of truth - never short-circuit on local state.
	function refreshExistingSubscription(buttons) {
		loadFirebase()
			.then(function () { return navigator.serviceWorker.register(bcsendPush.swUrl); })
			.then(function (registration) {
				return firebase.messaging().getToken({
					vapidKey: bcsendPush.vapidKey,
					serviceWorkerRegistration: registration
				});
			})
			.then(function (token) {
				if (!token) {
					return null;
				}
				return registerToken(token).then(function () {
					markSubscribed(buttons);
				});
			})
			.catch(function () {
				// Silent: the visitor can still click to subscribe.
			});
	}

	function subscribe(buttons) {
		if (!('serviceWorker' in navigator) || !('Notification' in window)) {
			window.alert(bcsendPush.labels.unsupported);
			return;
		}

		loadFirebase()
			.then(function () { return navigator.serviceWorker.register(bcsendPush.swUrl); })
			.then(function (registration) {
				return Notification.requestPermission().then(function (permission) {
					if ('granted' !== permission) {
						throw new Error('permission-denied');
					}
					return firebase.messaging().getToken({
						vapidKey: bcsendPush.vapidKey,
						serviceWorkerRegistration: registration
					});
				});
			})
			.then(function (token) {
				if (!token) {
					throw new Error('no-token');
				}
				return registerToken(token);
			})
			.then(function () { markSubscribed(buttons); })
			.catch(function (err) {
				if ('permission-denied' === err.message || 'denied' === Notification.permission) {
					window.alert(bcsendPush.labels.denied);
				} else {
					window.alert(bcsendPush.labels.error);
				}
			});
	}

	document.addEventListener('DOMContentLoaded', function () {
		var buttons = Array.prototype.slice.call(document.querySelectorAll('.bcsend-push-subscribe-btn'));

		if (!buttons.length) {
			return;
		}

		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				subscribe(buttons);
			});
		});

		// Permission already granted: refresh the token silently so rotated
		// tokens and pruned database rows self-heal on the next visit.
		if ('serviceWorker' in navigator && 'Notification' in window && 'granted' === Notification.permission) {
			refreshExistingSubscription(buttons);
		}
	});
})();
