/**
 * The Remove key confirmation, and a busy state on the buttons that save.
 *
 * Makes no request and reads no credential: there is none in the page to
 * read. Without this file the Remove link opens the same confirmation as a
 * page, and every form submits exactly as it does with it.
 *
 * @package STG\Moyasar
 */

( function () {
	'use strict';

	var dialog = document.getElementById( 'stg-pay-remove' );
	var opener = null;

	/**
	 * Open the confirmation for the credential a Remove link belongs to.
	 *
	 * @param {Event} event Click.
	 */
	function ask( event ) {
		var link = event.target.closest ? event.target.closest( '[data-stg-remove]' ) : null;

		if ( ! link || ! dialog || 'function' !== typeof dialog.showModal ) {
			// No dialog support: the link's own address does the same job.
			return;
		}

		event.preventDefault();

		dialog.querySelector( '[data-stg-remove-field]' ).value = link.getAttribute( 'data-stg-remove' );
		dialog.querySelector( '[data-stg-remove-name]' ).textContent = link.getAttribute( 'data-stg-remove-name' );
		dialog.querySelector( '[data-stg-remove-impact]' ).textContent = link.getAttribute( 'data-stg-remove-impact' );

		opener = link;

		// A server-rendered open confirmation is not modal; make it one.
		if ( dialog.open ) {
			dialog.close();
		}

		dialog.showModal();

		// Land on Cancel, not on the button that removes.
		var cancel = dialog.querySelector( '[data-stg-remove-cancel]' );

		if ( cancel ) {
			cancel.focus();
		}
	}

	/**
	 * Mark a form as on its way, once, so a second press does nothing.
	 *
	 * @param {Event} event Submit.
	 */
	function busy( event ) {
		var form = event.target;
		var text = form.getAttribute ? form.getAttribute( 'data-stg-busy' ) : '';

		if ( ! text ) {
			return;
		}

		if ( 'true' === form.getAttribute( 'aria-busy' ) ) {
			event.preventDefault();

			return;
		}

		form.setAttribute( 'aria-busy', 'true' );

		var button = form.querySelector( 'button[type="submit"], input[type="submit"]' );

		if ( button ) {
			button.classList.add( 'is-busy' );
			button.setAttribute( 'aria-disabled', 'true' );

			if ( 'INPUT' === button.tagName ) {
				button.value = text;
			} else {
				button.textContent = text;
			}
		}
	}

	document.addEventListener( 'click', ask );
	document.addEventListener( 'submit', busy );

	if ( ! dialog ) {
		return;
	}

	dialog.addEventListener( 'click', function ( event ) {
		var cancel = event.target.closest ? event.target.closest( '[data-stg-remove-cancel]' ) : null;

		// Opened by the server (no opener): let Cancel be the link it is.
		if ( cancel && opener && dialog.open ) {
			event.preventDefault();
			dialog.close();
		}
	} );

	dialog.addEventListener( 'close', function () {
		if ( opener ) {
			opener.focus();
			opener = null;
		}
	} );
}() );
