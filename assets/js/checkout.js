/**
 * Mounts Moyasar's payment form, and polls while a payment confirms.
 *
 * Nothing in this file decides anything about money. It renders a form, and it
 * asks the server what happened. The amount it passes to Moyasar comes from the
 * intent the server already committed to, and the server re-verifies the real
 * amount against that row when the payment comes back - so a tampered value
 * here buys nothing except a mismatch that STG flags and refuses to act on.
 *
 * @package STG\Moyasar
 */

( function () {
	'use strict';

	/**
	 * Report a newly created payment's id to the server.
	 *
	 * Always resolves, and within four seconds: the form waits on this before
	 * redirecting, and a slow or failed note must never stand between a
	 * customer and their bank.
	 *
	 * @param {Object} config  Localised checkout settings.
	 * @param {Object} payment The payment Moyasar's form created.
	 * @return {Promise} Resolved when the note is sent or given up on.
	 */
	function recordAttempt( config, payment ) {
		return new Promise( function ( resolve ) {
			if ( ! payment || ! payment.id || ! config.attemptUrl || ! window.fetch ) {
				resolve();

				return;
			}

			window.setTimeout( resolve, 4000 );

			window.fetch( config.attemptUrl, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': config.restNonce
				},
				body: JSON.stringify( { payment_id: String( payment.id ) } )
			} ).then( resolve, resolve );
		} );
	}

	/**
	 * Mount the payment form.
	 */
	function mountForm() {
		var config = window.STG_MOYASAR;
		var mount = document.getElementById( 'stg-moyasar-form' );
		var fallback = document.getElementById( 'stg-moyasar-fallback' );

		if ( ! mount || ! config ) {
			return;
		}

		/*
		 * Moyasar's script is third party and loaded from their CDN. If it did
		 * not arrive - an ad blocker, a corporate proxy, a bad day for their
		 * CDN - the page must say so rather than showing an empty box that
		 * looks like a form still loading.
		 */
		if ( 'undefined' === typeof window.Moyasar ) {
			if ( fallback ) {
				fallback.hidden = false;
				// Collapse the space reserved for the form, or the message
				// sits under a large empty box.
				mount.hidden = true;
			}

			return;
		}

		/*
		 * Moyasar gets an element of its own, as an element.
		 *
		 * Its form takes over whatever it is given, id included, and then
		 * looks a selector up a second time. Given '#stg-moyasar-form' it
		 * renamed that element and its second lookup found nothing: an error
		 * in the console and an empty box where the card form should be. So
		 * the outer element stays ours and the inner one is handed over by
		 * reference, which cannot go stale.
		 */
		var target = mount.querySelector( '.mysr-form' );

		if ( ! target ) {
			target = document.createElement( 'div' );
			target.className = 'mysr-form';
			mount.appendChild( target );
		}

		try {
			startForm( config, mount, target );
		} catch ( error ) {
			showFallback( mount, fallback );
		}

		/*
		 * The form draws itself a moment after it is started, and can fail
		 * while doing so with nothing thrown here. If it has drawn nothing in
		 * a few seconds, say so instead of leaving an empty space.
		 */
		window.setTimeout( function () {
			if ( ! target.isConnected || ! mount.querySelector( 'input, button, iframe' ) ) {
				showFallback( mount, fallback );
			}
		}, 8000 );
	}

	/**
	 * Say the payment form is not available, in place of the form.
	 *
	 * @param {Element}      mount    Our element around the form.
	 * @param {Element|null} fallback The message.
	 */
	function showFallback( mount, fallback ) {
		if ( fallback ) {
			fallback.hidden = false;
			mount.hidden = true;
		}
	}

	/**
	 * Hand the checkout to Moyasar's form.
	 *
	 * @param {Object}  config Localised checkout settings.
	 * @param {Element} mount  Our element around the form.
	 * @param {Element} target The element Moyasar draws into.
	 */
	function startForm( config, mount, target ) {
		window.Moyasar.init( {
			element: target,
			// A whole number of halalas. WordPress hands script data over as
			// text, and Moyasar's API wants an integer.
			amount: parseInt( config.amount, 10 ),
			currency: config.currency,
			description: config.description,
			publishable_api_key: config.publishableKey,
			callback_url: config.callbackUrl,
			methods: config.methods,
			supported_networks: config.supportedNetworks,
			metadata: config.metadata,
			language: config.language,

			/*
			 * Guard against a second submission while the first is in flight.
			 *
			 * This is the cheapest of the three defences against a double
			 * charge and the only one the customer sees. The other two are the
			 * unique index on open intents, which means a second attempt reuses
			 * the same intent, and the compare-and-set at settlement, which
			 * means only one process can ever activate a plan. A customer who
			 * gets past all three has genuinely paid twice, and STG flags that
			 * for a person rather than swallowing it.
			 */
			on_initiating: function () {
				mount.setAttribute( 'aria-busy', 'true' );
				mount.classList.add( 'is-processing' );

				return true;
			},

			/*
			 * Tell the server which payment was just created, before the
			 * customer leaves for their bank's page.
			 *
			 * If they never come back - a closed tab, a lost signal - this id
			 * is how the hourly sweep finds the payment. It is a hint: the
			 * server asks Moyasar about it and believes only the answer.
			 */
			on_completed: function ( payment ) {
				return recordAttempt( config, payment );
			},

			on_failure: function () {
				mount.removeAttribute( 'aria-busy' );
				mount.classList.remove( 'is-processing' );
			}
		} );
	}

	/**
	 * Poll the server while a payment is still confirming.
	 *
	 * Bounded: ten attempts, three seconds apart. After that the page says it
	 * is taking longer than usual and that the customer can safely leave, which
	 * is true - the webhook and the hourly reconciliation sweep both still
	 * settle it, and both are idempotent.
	 */
	function pollPending() {
		var panel = document.querySelector( '[data-stg-poll]' );

		if ( ! panel ) {
			return;
		}

		var endpoint = panel.getAttribute( 'data-stg-endpoint' );
		var nonce = panel.getAttribute( 'data-stg-nonce' );
		var slow = panel.querySelector( '.stg-result__slow' );
		var attempts = 0;

		function check() {
			attempts += 1;

			if ( attempts > 10 ) {
				if ( slow ) {
					slow.hidden = false;
				}

				return;
			}

			window.fetch( endpoint, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': nonce }
			} )
				.then( function ( response ) {
					return response.ok ? response.json() : null;
				} )
				.then( function ( body ) {
					if ( body && body.data && body.data.settled ) {
						// The server has decided. Reload so the page renders
						// the settled state server-side rather than this script
						// trying to reproduce six different outcomes.
						window.location.reload();

						return;
					}

					window.setTimeout( check, 3000 );
				} )
				.catch( function () {
					window.setTimeout( check, 3000 );
				} );
		}

		window.setTimeout( check, 3000 );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', function () {
			mountForm();
			pollPending();
		} );
	} else {
		mountForm();
		pollPending();
	}
}() );
