/**
 * Agnosis Follow Overlay block — frontend behavior.
 *
 * Trigger/panel open-close is the native HTML Popover API (see
 * ActivityPub::render_follow_overlay()) — this script only handles the
 * "Copy handle" button and the remote-follow form's redirect.
 *
 * Remote follow: there is no single URL a browser can open to complete a
 * cross-instance Fediverse follow (the follow has to be authorized FROM the
 * visitor's own instance, not this one) — `authorize_interaction` is the
 * de-facto standard endpoint Mastodon (and most compatible software)
 * exposes for exactly this: given `?uri=<actor URL>`, the visitor's own
 * instance resolves it and shows them a normal in-app follow confirmation.
 *
 * Expects window.agnosisFollowOverlay to be localized by
 * ActivityPub::render_follow_overlay(): { actorUrl, i18n: { invalidInstance, copied } }.
 */
( function () {

	document.addEventListener( 'DOMContentLoaded', function () {
		var cfg = window.agnosisFollowOverlay || {};

		var copyButtons = document.querySelectorAll( '[data-agnosis-copy-handle]' );
		for ( var i = 0; i < copyButtons.length; i++ ) {
			copyButtons[ i ].addEventListener( 'click', ( function ( button ) {
				return function () {
					copyHandle( button, cfg );
				};
			} )( copyButtons[ i ] ) );
		}

		var forms = document.querySelectorAll( '[data-agnosis-follow-form]' );
		for ( var j = 0; j < forms.length; j++ ) {
			forms[ j ].addEventListener( 'submit', ( function ( form ) {
				return function ( event ) {
					event.preventDefault();
					redirectToInstance( form, cfg );
				};
			} )( forms[ j ] ) );
		}
	} );

	function copyHandle( button, cfg ) {
		var i18n  = cfg.i18n || {};
		var value = button.getAttribute( 'data-agnosis-copy-handle' ) || '';
		var label = button.textContent;

		var done = function () {
			button.textContent = i18n.copied || 'Copied!';
			setTimeout( function () {
				button.textContent = label;
			}, 2000 );
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( value ).then( done, done );
		} else {
			done();
		}
	}

	function redirectToInstance( form, cfg ) {
		if ( ! cfg.actorUrl ) {
			return;
		}

		var i18n   = cfg.i18n || {};
		var input  = form.querySelector( 'input[name="instance"]' );
		var status = form.querySelector( '[data-agnosis-follow-status]' );
		var raw    = input ? input.value : '';

		var instance = String( raw )
			.trim()
			.replace( /^https?:\/\//i, '' )
			.replace( /\/.*$/, '' )
			.toLowerCase();

		if ( ! instance || ! /^[a-z0-9.-]+\.[a-z]{2,}$/i.test( instance ) ) {
			if ( status ) {
				status.textContent = i18n.invalidInstance || 'Enter your Fediverse instance domain (e.g. mastodon.social).';
			}
			return;
		}

		var target = 'https://' + instance + '/authorize_interaction?uri=' + encodeURIComponent( cfg.actorUrl );
		window.location.assign( target );
	}

} )();
