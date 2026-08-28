/**
 * Publica.now — settings screen behaviour.
 *
 * Connect / disconnect / refresh through the plugin's REST routes with inline
 * status, and copy-to-clipboard for the cheatsheet. Every control also works
 * without this file: the forms post to admin-post.php and the snippets can be
 * selected by hand.
 *
 * Dependencies: wp-api-fetch, wp-i18n (both enqueued by class-settings.php).
 */
( function () {
	'use strict';

	var config = window.publicanowAdmin || {};
	var i18n = ( window.wp && window.wp.i18n ) ? window.wp.i18n : null;
	var apiFetch = ( window.wp && window.wp.apiFetch ) ? window.wp.apiFetch : null;

	/*
	 * wp.i18n directly, with the text domain at every call site. A local
	 * __( text ) wrapper would be invisible to `wp i18n make-pot` and to
	 * translate.wordpress.org, which both match __( 'string', 'domain' )
	 * literally — every string in this file would silently be untranslatable.
	 */
	var __ = i18n ? i18n.__ : function ( text ) {
		return text;
	};
	var _n = i18n ? i18n._n : function ( single, plural, number ) {
		return 1 === number ? single : plural;
	};
	var sprintf = i18n && i18n.sprintf ? i18n.sprintf : function ( format ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var index = 0;
		return String( format ).replace( /%[sd]/g, function () {
			return args[ index++ ];
		} );
	};

	/**
	 * Call one of the plugin's REST routes.
	 *
	 * @param {string} route  Route relative to the namespace, e.g. "/connect".
	 * @param {string} method HTTP method.
	 * @param {Object} data   JSON body.
	 * @return {Promise<Object>} Decoded JSON, rejected with {message} on error.
	 */
	function request( route, method, data ) {
		var path = '/' + String( config.namespace || 'publica-now/v1' ).replace( /^\/+|\/+$/g, '' ) + route;
		var headers = { 'X-WP-Nonce': config.nonce || '' };

		if ( apiFetch ) {
			return apiFetch( { path: path, method: method, data: data, headers: headers } );
		}

		// wp-api-fetch is a declared dependency; this is only a safety net.
		var root = String( config.root || '' ).replace( /\/+$/, '' );
		headers['Content-Type'] = 'application/json';

		return window.fetch( root + path, {
			method: method,
			credentials: 'same-origin',
			headers: headers,
			body: data ? JSON.stringify( data ) : undefined
		} ).then( function ( response ) {
			return response.json().then( function ( json ) {
				return response.ok ? json : Promise.reject( json );
			} );
		} );
	}

	/**
	 * Show an inline status line next to the control that triggered it.
	 *
	 * @param {Element|null} element The [data-publicanow-status] element.
	 * @param {string}       type    "busy", "success" or "error".
	 * @param {string}       message Text.
	 */
	function setStatus( element, type, message ) {
		if ( ! element ) {
			return;
		}
		element.className = 'publicanow-status publicanow-status-' + type;
		element.textContent = message;
	}

	/**
	 * Message for a failed request, preferring the server's explanation.
	 *
	 * @param {Object} error Rejection value.
	 * @return {string} Message.
	 */
	function errorMessage( error ) {
		if ( error && typeof error.message === 'string' && error.message ) {
			return error.message;
		}
		return __( 'Something went wrong. Please try again.', 'publica-now' );
	}

	/**
	 * Find the status element that belongs to a control.
	 *
	 * @param {Element} control Button or form.
	 * @return {Element|null} Status element.
	 */
	function statusFor( control ) {
		var scope = control.closest( '.publicanow-card, .publicanow-connect-form, .publicanow-section' );
		return scope ? scope.querySelector( '[data-publicanow-status]' ) : null;
	}

	/**
	 * Disable or re-enable every button in a scope while a request runs.
	 *
	 * @param {Element} scope Container.
	 * @param {boolean} busy  Whether a request is in flight.
	 */
	function setBusy( scope, busy ) {
		var buttons = scope.querySelectorAll( 'button, input[type="submit"]' );
		Array.prototype.forEach.call( buttons, function ( button ) {
			button.disabled = busy;
		} );
		scope.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
	}

	/* --- Connect ----------------------------------------------------------- */

	var connectForm = document.querySelector( '[data-publicanow-connect]' );

	if ( connectForm ) {
		connectForm.addEventListener( 'submit', function ( event ) {
			var input = connectForm.querySelector( '#publicanow_creator' );
			var status = statusFor( connectForm );
			var value = input ? input.value.trim() : '';

			// Let the browser do its own "required" validation first.
			if ( ! value ) {
				return;
			}

			event.preventDefault();
			setBusy( connectForm, true );
			setStatus( status, 'busy', __( 'Checking your profile on publica.now…', 'publica-now' ) );

			request( '/connect', 'POST', { creator: value } ).then( function ( result ) {
				var name = result && result.creator && result.creator.name ? result.creator.name : value;
				setStatus(
					status,
					'success',
					/* translators: %s: publica.now creator name. */
					sprintf( __( 'Connected to %s. Reloading…', 'publica-now' ), name )
				);
				window.location.reload();
			} ).catch( function ( error ) {
				setBusy( connectForm, false );
				setStatus( status, 'error', errorMessage( error ) );
				if ( input ) {
					input.focus();
				}
			} );
		} );
	}

	/* --- Disconnect / refresh ---------------------------------------------- */

	var actionButtons = document.querySelectorAll( '[data-publicanow-action]' );

	Array.prototype.forEach.call( actionButtons, function ( button ) {
		button.addEventListener( 'click', function ( event ) {
			var action = button.getAttribute( 'data-publicanow-action' );
			var confirmText = button.getAttribute( 'data-publicanow-confirm' );
			var form = button.closest( 'form' );
			var scope = button.closest( '.publicanow-card' ) || form || button.parentNode;
			var status = statusFor( button );

			if ( confirmText && ! window.confirm( confirmText ) ) {
				event.preventDefault();
				return;
			}

			event.preventDefault();
			setBusy( scope, true );

			if ( action === 'disconnect' ) {
				setStatus( status, 'busy', __( 'Disconnecting…', 'publica-now' ) );
				request( '/disconnect', 'POST', {} ).then( function () {
					setStatus( status, 'success', __( 'Disconnected. Reloading…', 'publica-now' ) );
					window.location.reload();
				} ).catch( function ( error ) {
					setBusy( scope, false );
					setStatus( status, 'error', errorMessage( error ) );
				} );
				return;
			}

			if ( action === 'purge' ) {
				setStatus( status, 'busy', __( 'Refreshing your catalog…', 'publica-now' ) );
				request( '/purge', 'POST', {} ).then( function ( result ) {
					setBusy( scope, false );
					if ( result && result.warning ) {
						/* translators: %s: error message from publica.now. */
						var failed = __( 'Nothing was cleared: publica.now could not be reached (%s).', 'publica-now' );
						setStatus( status, 'error', sprintf( failed, result.warning ) );
						return;
					}

					var count = result && result.creator && typeof result.creator.works_count === 'number' ? result.creator.works_count : null;
					var message = __( 'Catalog refreshed.', 'publica-now' );

					if ( count !== null ) {
						/* translators: %s: number of published works. */
						message = sprintf( _n( 'Catalog refreshed. %s published work.', 'Catalog refreshed. %s published works.', count, 'publica-now' ), count );
					}

					setStatus( status, 'success', message );
				} ).catch( function ( error ) {
					setBusy( scope, false );
					setStatus( status, 'error', errorMessage( error ) );
				} );
				return;
			}

			// Unknown action: fall back to the form's normal submission.
			setBusy( scope, false );
			if ( form ) {
				form.submit();
			}
		} );
	} );

	/* --- Copy to clipboard --------------------------------------------------- */

	/**
	 * Copy text with the async clipboard API, falling back to a hidden textarea.
	 *
	 * @param {string} text Text to copy.
	 * @return {Promise<void>} Resolves when copied.
	 */
	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}

		return new Promise( function ( resolve, reject ) {
			var textarea = document.createElement( 'textarea' );
			textarea.value = text;
			textarea.setAttribute( 'readonly', '' );
			textarea.style.position = 'absolute';
			textarea.style.left = '-9999px';
			document.body.appendChild( textarea );
			textarea.select();
			var ok = false;
			try {
				ok = document.execCommand( 'copy' );
			} catch ( e ) {
				ok = false;
			}
			document.body.removeChild( textarea );
			if ( ok ) {
				resolve();
			} else {
				reject( new Error( 'copy failed' ) );
			}
		} );
	}

	var copyButtons = document.querySelectorAll( '[data-publicanow-copy]' );

	Array.prototype.forEach.call( copyButtons, function ( button ) {
		var originalLabel = button.textContent;

		button.addEventListener( 'click', function () {
			var targetId = button.getAttribute( 'data-publicanow-copy' );
			var target = targetId ? document.getElementById( targetId ) : null;

			if ( ! target ) {
				return;
			}

			copyText( target.textContent ).then( function () {
				button.textContent = __( 'Copied', 'publica-now' );
				button.classList.add( 'publicanow-copied' );
				window.setTimeout( function () {
					button.textContent = originalLabel;
					button.classList.remove( 'publicanow-copied' );
				}, 1600 );
			} ).catch( function () {
				// Select the snippet so the user can copy it by hand.
				var range = document.createRange();
				range.selectNodeContents( target );
				var selection = window.getSelection();
				selection.removeAllRanges();
				selection.addRange( range );
				button.textContent = __( 'Press Ctrl/Cmd+C', 'publica-now' );
				window.setTimeout( function () {
					button.textContent = originalLabel;
				}, 2400 );
			} );
		} );
	} );
}() );
