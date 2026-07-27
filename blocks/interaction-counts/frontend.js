/**
 * Agnosis Interaction Counts block — frontend behavior.
 *
 * Interaction-surface roadmap, Phase 3, WP2 (2026-07-27). The like half of
 * this block (ActivityPub::render_interaction_counts()) is a real button
 * whose correct initial liked/not-liked state is already rendered
 * server-side — this script's only job is the click itself: POST to like,
 * DELETE to unlike, then update the button's text/aria-pressed/class from
 * the JSON response. Boosts stay plain, non-interactive text — nothing here
 * touches them; WP2 is on-site likes only (boosting is WP5).
 *
 * Expects window.agnosisInteractionCounts to be localized by
 * ActivityPub::render_interaction_counts():
 *   { apiUrlBase, nonce, i18n: { like, likes, error } }.
 */
( function () {

	document.addEventListener( 'DOMContentLoaded', function () {
		var cfg = window.agnosisInteractionCounts || {};
		if ( ! cfg.apiUrlBase ) {
			return;
		}

		var buttons = document.querySelectorAll( '[data-agnosis-like-post-id]' );
		buttons.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				toggleLike( button, cfg );
			} );
		} );
	} );

	function toggleLike( button, cfg ) {
		if ( button.disabled ) {
			return;
		}

		var postId = button.getAttribute( 'data-agnosis-like-post-id' );
		if ( ! postId ) {
			return;
		}

		var wasLiked = 'true' === button.getAttribute( 'aria-pressed' );
		var textEl   = button.querySelector( '.agnosis-interaction-counts__likes-text' );

		button.disabled = true;

		fetch( cfg.apiUrlBase + postId + '/likes', {
			method:      wasLiked ? 'DELETE' : 'POST',
			headers:     { 'X-WP-Nonce': cfg.nonce || '' },
			credentials: 'same-origin',
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( 'undefined' === typeof data.liked || 'undefined' === typeof data.like ) {
					throw new Error( 'unexpected response' );
				}

				button.setAttribute( 'aria-pressed', data.liked ? 'true' : 'false' );
				button.classList.toggle( 'is-liked', data.liked );

				if ( textEl ) {
					var i18n = cfg.i18n || {};
					var tpl  = 1 === data.like ? ( i18n.like || '♥ %d like' ) : ( i18n.likes || '♥ %d likes' );
					textEl.textContent = tpl.replace( '%d', String( data.like ) );
				}
			} )
			.catch( function () {
				// Nothing server-side is known to have changed (the request
				// either never landed or the response was unreadable) — show
				// the error briefly, then restore whatever the count already
				// said, rather than replacing it permanently.
				var i18n = cfg.i18n || {};
				if ( textEl && i18n.error ) {
					var original = textEl.textContent;
					textEl.textContent = i18n.error;
					setTimeout( function () {
						textEl.textContent = original;
					}, 2000 );
				}
			} )
			.finally( function () {
				button.disabled = false;
			} );
	}

} )();
