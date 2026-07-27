/**
 * Agnosis Reply Overlay block — frontend behavior.
 *
 * Interaction-surface roadmap, Phase 2 (2026-07-25), extended WP4 (2026-07-27)
 * with the reply form itself. The trigger/panel/close button and the popover
 * open/close/backdrop/Escape behavior are all native HTML Popover API
 * (`popover="auto"`, `popovertarget`) — see ActivityPub::render_reply_overlay().
 * This script's two jobs: fetching the reply list from the REST endpoint and
 * rendering it into the panel once, the first time it's opened (native
 * `toggle` event on the popover element itself — fires on both show and hide,
 * so only render on `newState === 'open'`, and only ever once per page view
 * since the list can't change while the visitor has the page open); and,
 * when a reply form is present (ActivityPub::render_reply_form() — only
 * rendered when replies are actually open), POSTing a submission to the same
 * endpoint and re-loading the list on success so a visitor sees their own
 * reply's held-for-approval state reflected (well, not shown — it isn't
 * approved yet — but at minimum the form clears and shows a confirmation).
 *
 * Expects window.agnosisReplyOverlay to be localized by
 * ActivityPub::render_reply_overlay():
 *   { apiUrl, nonce, i18n: { loading, error, submitting, submitSuccess,
 *     submitError, ... } }.
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

		var form = panel.querySelector( '[data-agnosis-reply-form]' );
		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				submitReply( form, panel, cfg );
			} );
		}
	} );

	function submitReply( form, panel, cfg ) {
		var i18n   = cfg.i18n || {};
		var submit = form.querySelector( '[type="submit"]' );
		var status = form.querySelector( '.agnosis-reply-overlay__form-status' );

		if ( status ) {
			status.textContent = i18n.submitting || 'Sending…';
			status.className   = 'agnosis-reply-overlay__form-status';
		}
		if ( submit ) {
			submit.disabled = true;
			submit.setAttribute( 'aria-busy', 'true' );
		}

		var payload = {
			name:             ( form.querySelector( '[name="name"]' ) || {} ).value || '',
			email:            ( form.querySelector( '[name="email"]' ) || {} ).value || '',
			message:          ( form.querySelector( '[name="message"]' ) || {} ).value || '',
			turnstile_token:  ( form.querySelector( '[name="cf-turnstile-response"]' ) || {} ).value || '',
		};

		fetch( cfg.apiUrl, {
			method:      'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   cfg.nonce || '',
			},
			body: JSON.stringify( payload ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( submit ) {
					submit.disabled = false;
					submit.removeAttribute( 'aria-busy' );
				}

				if ( result.ok ) {
					form.reset();
					if ( status ) {
						status.textContent = ( result.data && result.data.message ) || i18n.submitSuccess || 'Thanks — your reply has been submitted.';
						status.className   = 'agnosis-reply-overlay__form-status agnosis-reply-overlay__form-status--success';
					}
				} else {
					var msg = ( result.data && result.data.message ) ? result.data.message : ( i18n.submitError || 'Could not send your reply. Please try again.' );
					if ( status ) {
						status.textContent = msg;
						status.className   = 'agnosis-reply-overlay__form-status agnosis-reply-overlay__form-status--error';
					}
				}

				// A Turnstile token is single-use — reset the widget so a retry
				// (e.g. after a validation error unrelated to it) gets a fresh
				// token instead of silently failing verification again, same
				// convention blocks/contact-form/frontend.js already follows.
				if ( window.turnstile ) {
					window.turnstile.reset();
				}
			} )
			.catch( function () {
				if ( submit ) {
					submit.disabled = false;
					submit.removeAttribute( 'aria-busy' );
				}
				if ( status ) {
					status.textContent = i18n.submitError || 'Could not send your reply. Please try again.';
					status.className   = 'agnosis-reply-overlay__form-status agnosis-reply-overlay__form-status--error';
				}
				if ( window.turnstile ) {
					window.turnstile.reset();
				}
			} );
	}

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
