/**
 * Agnosis Reply Overlay block — frontend behavior.
 *
 * Interaction-surface roadmap, Phase 2 (2026-07-25). The trigger/panel/close
 * button and the popover open/close/backdrop/Escape behavior are all native
 * HTML Popover API (`popover="auto"`, `popovertarget`) — see
 * ActivityPub::render_reply_overlay(). This script's only job is fetching
 * the reply list from the REST endpoint and rendering it into the panel,
 * once, the first time it's opened (native `toggle` event on the popover
 * element itself — fires on both show and hide, so only render on `newState
 * === 'open'`, and only ever once per page view since the list can't change
 * while the visitor has the page open).
 *
 * Expects window.agnosisReplyOverlay to be localized by
 * ActivityPub::render_reply_overlay(): { apiUrl, i18n: { loading, error } }.
 */
( function () {

	document.addEventListener( 'DOMContentLoaded', function () {
		var cfg = window.agnosisReplyOverlay || {};
		if ( ! cfg.apiUrl ) {
			return;
		}

		var panel = document.querySelector( '[data-agnosis-reply-list]' );
		if ( ! panel ) {
			return;
		}

		var loaded = false;

		panel.addEventListener( 'toggle', function ( event ) {
			if ( 'open' !== event.newState || loaded ) {
				return;
			}
			loaded = true;
			loadReplies( panel, cfg );
		} );
	} );

	function loadReplies( panel, cfg ) {
		var inner = panel.querySelector( '.agnosis-reply-overlay__inner' );
		if ( ! inner ) {
			return;
		}

		var i18n = cfg.i18n || {};
		inner.innerHTML = '<p class="agnosis-reply-overlay__status">' + escapeHtml( i18n.loading || 'Loading…' ) + '</p>';

		fetch( cfg.apiUrl, { credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				renderReplies( inner, data && data.replies ? data.replies : [] );
			} )
			.catch( function () {
				inner.innerHTML = '<p class="agnosis-reply-overlay__status">' + escapeHtml( i18n.error || 'Something went wrong.' ) + '</p>';
			} );
	}

	function renderReplies( inner, replies ) {
		if ( ! replies.length ) {
			inner.innerHTML = '';
			return;
		}

		var html = replies.map( function ( reply ) {
			var author = reply.url
				? '<a href="' + escapeAttr( reply.url ) + '" rel="nofollow ugc noopener" target="_blank">' + escapeHtml( reply.author || '' ) + '</a>'
				: escapeHtml( reply.author || '' );

			var date = reply.date ? formatDate( reply.date ) : '';

			return (
				'<div class="agnosis-reply-overlay__item">' +
					'<span class="agnosis-reply-overlay__author">' + author + '</span>' +
					( date ? '<span class="agnosis-reply-overlay__date">' + escapeHtml( date ) + '</span>' : '' ) +
					'<div class="agnosis-reply-overlay__content">' + sanitizeContent( reply.content || '' ) + '</div>' +
				'</div>'
			);
		} ).join( '' );

		inner.innerHTML = html;
	}

	/**
	 * The REST endpoint's `content` field is already server-sanitized
	 * (wp_kses() at ingestion, ActivityPub::handle_create_reply()) to a small
	 * allowlist (p/br/span/a) — this just guards against the fetch itself
	 * being tampered with in transit by re-stripping anything outside that
	 * same allowlist client-side, rather than trusting the network round trip.
	 */
	function sanitizeContent( html ) {
		var div = document.createElement( 'div' );
		div.innerHTML = html;

		var allowed = { P: true, BR: true, SPAN: true, A: true };
		var walker  = document.createTreeWalker( div, NodeFilter.SHOW_ELEMENT, null );
		var toStrip = [];
		var node;

		while ( ( node = walker.nextNode() ) ) {
			if ( ! allowed[ node.tagName ] ) {
				toStrip.push( node );
			} else {
				// Strip every attribute except href/rel on <a>.
				Array.prototype.slice.call( node.attributes ).forEach( function ( attr ) {
					if ( 'A' === node.tagName && ( 'href' === attr.name || 'rel' === attr.name ) ) {
						return;
					}
					node.removeAttribute( attr.name );
				} );
			}
		}

		toStrip.forEach( function ( strippedNode ) {
			strippedNode.replaceWith( document.createTextNode( strippedNode.textContent ) );
		} );

		return div.innerHTML;
	}

	function escapeHtml( text ) {
		var div = document.createElement( 'div' );
		div.textContent = text;
		return div.innerHTML;
	}

	function escapeAttr( text ) {
		return String( text ).replace( /"/g, '&quot;' );
	}

	function formatDate( iso ) {
		var date = new Date( iso );
		if ( isNaN( date.getTime() ) ) {
			return '';
		}
		return date.toLocaleDateString( undefined, { year: 'numeric', month: 'long', day: 'numeric' } );
	}

} )();
