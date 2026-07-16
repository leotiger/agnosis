/**
 * Agnosis Join block — frontend form handler.
 *
 * Intercepts the application form submit, POSTs to the admission REST
 * endpoint, and shows a success message or a field-level error without
 * a page reload.
 *
 * Expects window.agnosisJoin to be localised by JoinPage::enqueue_assets():
 *   {
 *     apiUrl: string,
 *     redirectUrl: string,  // optional — static, untranslated fallback (see below)
 *     i18n: { success, error, requiredField, languageRequired }
 *   }
 *
 * The /admission/apply response can also carry its own `redirect_url` —
 * resolved server-side against the language the artist just selected in this
 * form, which isn't known yet when redirectUrl above was baked into the page.
 * That value takes priority whenever present; `redirectUrl` is only a
 * fallback for the (rare) case the response doesn't include one — see
 * JoinPage::resolve_success_url()'s docblock.
 *
 * The <form> is marked `novalidate` deliberately (see JoinPage::render()) so
 * this handler — not the browser's native bubble UI — owns error display,
 * consistent with the rest of this form's custom #agnosis-join-notice
 * styling. That means required fields (name, email, language) are NOT
 * enforced by the browser at all and must be checked here before the
 * request is ever sent; the server enforces the same rules independently
 * (Admission::apply() rejects a missing/unrecognized language with a 400),
 * so this is a fast-path UX improvement, not the only guard.
 */
( function () {

	document.addEventListener( 'DOMContentLoaded', function () {

		var form   = document.getElementById( 'agnosis-join-form' );
		var notice = document.getElementById( 'agnosis-join-notice' );

		if ( ! form ) {
			return;
		}

		// Keep the free-text bio/statement textareas' `lang` attribute in sync
		// with whatever language the applicant picks in the select below them,
		// so the browser's spellcheck matches what they're actually typing.
		// Can't be resolved server-side at render time — the select sits after
		// these fields in the DOM and nothing is chosen yet on first render —
		// so this is wired up client-side once the applicant actually makes a
		// choice (and again on every subsequent change, in case they pick a
		// different language after already writing something).
		var languageSelect = form.querySelector( '[name="language"]' );
		var bioField       = form.querySelector( '[name="bio"]' );
		var statementField = form.querySelector( '[name="statement"]' );
		if ( languageSelect ) {
			languageSelect.addEventListener( 'change', function () {
				if ( bioField ) {
					bioField.lang = languageSelect.value;
				}
				if ( statementField ) {
					statementField.lang = languageSelect.value;
				}
			} );
		}

		function showNotice( message, isError ) {
			notice.textContent = message;
			notice.className   = 'agnosis-join__notice' + ( isError ? ' agnosis-join__notice--error' : ' agnosis-join__notice--success' );
			notice.hidden      = false;
		}

		/**
		 * Validate the required fields this form's markup marks with `required`
		 * (name, email, language). Returns the first invalid field element, or
		 * null when everything required is filled in.
		 */
		function firstInvalidField() {
			var fields = [
				form.querySelector( '[name="display_name"]' ),
				form.querySelector( '[name="email"]' ),
				form.querySelector( '[name="language"]' ),
			];
			for ( var i = 0; i < fields.length; i++ ) {
				if ( fields[ i ] && ! fields[ i ].value ) {
					return fields[ i ];
				}
			}
			return null;
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var submit = form.querySelector( '[type="submit"]' );
			var cfg    = window.agnosisJoin || {};
			var i18n   = cfg.i18n || {};

			// Clear previous notice.
			notice.hidden    = true;
			notice.className = 'agnosis-join__notice';
			notice.textContent = '';

			// Client-side required-field check — see the handler's docblock above
			// for why this can't just rely on the browser's native validation.
			var invalidField = firstInvalidField();
			if ( invalidField ) {
				var isLanguage = invalidField.getAttribute( 'name' ) === 'language';
				showNotice( isLanguage ? ( i18n.languageRequired || 'Please select your language.' ) : ( i18n.requiredField || 'Please fill in all required fields.' ), true );
				invalidField.focus();
				return;
			}

			// Disable submit while in flight.
			submit.disabled = true;
			submit.setAttribute( 'aria-busy', 'true' );

			var payload = {
				email:            ( form.querySelector( '[name="email"]' )               || {} ).value || '',
				display_name:     ( form.querySelector( '[name="display_name"]' )        || {} ).value || '',
				bio:              ( form.querySelector( '[name="bio"]' )                 || {} ).value || '',
				portfolio_url:    ( form.querySelector( '[name="portfolio_url"]' )       || {} ).value || '',
				statement:        ( form.querySelector( '[name="statement"]' )           || {} ).value || '',
				language:         ( form.querySelector( '[name="language"]' )            || {} ).value || '',
				turnstile_token:  ( form.querySelector( '[name="cf-turnstile-response"]' ) || {} ).value || '',
			};

			fetch( cfg.apiUrl || '', {
				method:  'POST',
				headers: { 'Content-Type': 'application/json' },
				body:    JSON.stringify( payload ),
			} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				// 'pending_confirmation' (double opt-in, security audit §3a/§4a):
				// the application isn't open for review yet — the artist still
				// has to click the confirm link in their inbox. Treated as the
				// same success path as the old 'applied' status used to be.
				if ( result.ok && ( result.data.status === 'applied' || result.data.status === 'pending_confirmation' ) ) {
					// Hide form, show success.
					form.hidden = true;
					showNotice( i18n.success || result.data.status, false );

					// If the operator configured a "what happens next" page
					// (Settings → Community → "After applying, send artists to"),
					// send the artist there instead of leaving them on the
					// inline confirmation message alone. Prefer the response's
					// own redirect_url — resolved server-side to the language
					// the artist just selected above — over the static,
					// untranslated redirectUrl baked into the page at render time.
					var redirectTo = ( result.data && result.data.redirect_url ) || cfg.redirectUrl;
					if ( redirectTo ) {
						window.location.assign( redirectTo );
					}
				} else {
					// Surface the server error message.
					var msg = ( result.data && result.data.message ) ? result.data.message : ( i18n.error || 'Error.' );
					showNotice( msg, true );
					submit.disabled       = false;
					submit.removeAttribute( 'aria-busy' );
					// A Turnstile token is single-use — reset the widget so a retry
					// (e.g. after a validation error unrelated to it) gets a fresh
					// token instead of silently failing verification again.
					if ( window.turnstile ) {
						window.turnstile.reset();
					}
				}
			} )
			.catch( function () {
				showNotice( i18n.error || 'Something went wrong.', true );
				submit.disabled       = false;
				submit.removeAttribute( 'aria-busy' );
				if ( window.turnstile ) {
					window.turnstile.reset();
				}
			} );
		} );

	} );

} )();
