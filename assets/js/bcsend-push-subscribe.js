/**
 * Beacon Campaign Sender - Web push subscription.
 *
 * Loads the bundled Firebase JS SDK (shipped with the plugin, on demand and only
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
	var sdkPromise = null;
	var ownerStorageKey = 'bcsend_push_owner_' + (bcsendPush.siteKey || 'default');

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
		if (sdkPromise) {
			return sdkPromise;
		}
		// SDK is bundled with the plugin - no external executable code.
		var base = bcsendPush.sdkBase;
		sdkPromise = loadScript(base + '/firebase-app-compat.js')
			.then(function () { return loadScript(base + '/firebase-messaging-compat.js'); })
			.then(function () {
				firebase.initializeApp(bcsendPush.firebaseConfig);
				sdkLoaded = true;
			})
			.catch(function (error) {
				sdkPromise = null;
				throw error;
			});
		return sdkPromise;
	}

	function currentUserId() {
		var value = parseInt(bcsendPush.userId, 10);
		return isNaN(value) || value < 1 ? 0 : value;
	}

	function storedOwnerId() {
		try {
			var value = parseInt(window.localStorage.getItem(ownerStorageKey), 10);
			return isNaN(value) || value < 1 ? 0 : value;
		} catch (error) {
			return 0;
		}
	}

	function rememberOwner(userId) {
		try {
			window.localStorage.setItem(ownerStorageKey, String(userId > 0 ? userId : 0));
		} catch (error) {
			// Storage can be disabled; server-side ownership protection remains.
		}
	}

	function registerToken(token, releaseOwner) {
		var headers = { 'Content-Type': 'application/json' };
		if (bcsendPush.restNonce) {
			headers['X-WP-Nonce'] = bcsendPush.restNonce;
		}
		return fetch(bcsendPush.restUrl, {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			body: JSON.stringify({
				token: token,
				platform: 'web',
				release_owner: !!releaseOwner
			})
		}).then(function (response) {
			// A 400/500 here means the token was NOT stored - never report
			// success, or the visitor believes they are subscribed forever.
			if (!response.ok) {
				throw new Error('registration-failed');
			}
			return response;
		});
	}

	function getCurrentToken() {
		return loadFirebase()
			.then(function () { return navigator.serviceWorker.register(bcsendPush.swUrl); })
			.then(function (registration) {
				return firebase.messaging().getToken({
					vapidKey: bcsendPush.vapidKey,
					serviceWorkerRegistration: registration
				});
			});
	}

	function syncTokenOwnership(token, forceRelease) {
		var userId = currentUserId();
		var releaseOwner = !!forceRelease || (0 === userId && storedOwnerId() > 0);

		return registerToken(token, releaseOwner).then(function () {
			rememberOwner(releaseOwner ? 0 : userId);
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
		getCurrentToken()
			.then(function (token) {
				if (!token) {
					return null;
				}
				return syncTokenOwnership(token, false).then(function () {
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
				return syncTokenOwnership(token, false);
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

		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				subscribe(buttons);
			});
		});

		// Refresh on pages with a subscribe control, and whenever the browser's
		// remembered WordPress owner differs from the current login. The latter
		// attaches a newly logged-in user or releases a user after logout even
		// when the current page has no subscribe button.
		var ownershipChanged = currentUserId() !== storedOwnerId();
		if ('serviceWorker' in navigator && 'Notification' in window && 'granted' === Notification.permission && (buttons.length || ownershipChanged)) {
			refreshExistingSubscription(buttons);
		}

		// Standard WordPress logout links are intercepted briefly so a shared
		// browser stops being associated with the departing user before the
		// logout navigation completes. The next anonymous refresh keeps the
		// subscription itself active for non-targeted announcements.
		if (currentUserId() > 0 && 'serviceWorker' in navigator && 'Notification' in window && 'granted' === Notification.permission) {
			document.addEventListener('click', function (event) {
				var link = event.target.closest ? event.target.closest('a[href*="action=logout"]') : null;
				if (!link || event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
					return;
				}

				event.preventDefault();
				var destination = link.href;
				var navigated = false;
				var finishLogout = function () {
					if (!navigated) {
						navigated = true;
						window.location.assign(destination);
					}
				};
				window.setTimeout(finishLogout, 1500);

				getCurrentToken()
					.then(function (token) {
						return token ? syncTokenOwnership(token, true) : null;
					})
					.catch(function () {
						// Logout must continue even if Firebase is unavailable.
					})
					.then(finishLogout);
			}, true);
		}
	});
})();
