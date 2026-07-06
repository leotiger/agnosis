/**
 * Agnosis Newsletter Signup block — frontend form handler.
 *
 * Intercepts the signup form submit, POSTs to the newsletter subscribe REST
 * endpoint, and shows a success or error message without a page reload.
 * Mirrors blocks/join/frontend.js.
 *
 * Expects window.agnosisNewsletter to be localised by
 * SignupBlock::enqueue_assets():
 *   { apiUrl: string, i18n: { success: string, error: string } }
 */
( function () {

	document.addEventListener( 'DOMContentLoaded', function () {

		var forms = document.querySelectorAll( '.agnosis-newsletter-signup form' );

		forms.forEach( function ( form ) {
			var wrap     = form.closest( '.agnosis-newsletter-signup' );
			var notice   = wrap.querySelector( '.agnosis-newsletter-signup__notice' );
			var langSelect = form.querySelector( '[name="language"]' );

			// Pre-select the page's current language as soon as the form
			// exists — document.documentElement.lang reflects the language
			// version the visitor is actually on (WordPress/Lingua Forge set
			// it per language version, e.g. "es-ES" on /es/). Only the
			// primary subtag is matched — the REST endpoint's
			// iso_to_wp_locale() map keys on that (mirrors the same
			// split-on-hyphen logic Admission::apply() uses for
			// Accept-Language). Left as the select's own first option when
			// there's no matching <option> (e.g. the page's language isn't
			// one Lingua Forge is configured for) — the visitor can still
			// change it before submitting either way.
			if ( langSelect ) {
				var htmlLang = ( document.documentElement.lang || '' ).split( '-' )[ 0 ].toLowerCase();
				if ( htmlLang && langSelect.querySelector( 'option[value="' + htmlLang + '"]' ) ) {
					langSelect.value = htmlLang;
				}
			}

			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();

				var submit = form.querySelector( '[type="submit"]' );
				var cfg    = window.agnosisNewsletter || {};
				var i18n   = cfg.i18n || {};

				notice.hidden      = true;
				notice.className   = 'agnosis-newsletter-signup__notice';
				notice.textContent = '';

				submit.disabled = true;
				submit.setAttribute( 'aria-busy', 'true' );

				var payload = {
					email:            ( form.querySelector( '[name="email"]' ) || {} ).value || '',
					language:         ( langSelect || {} ).value || '',
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
					if ( result.ok ) {
						form.hidden         = true;
						notice.textContent  = i18n.success || 'Check your inbox to confirm.';
						notice.className    = 'agnosis-newsletter-signup__notice agnosis-newsletter-signup__notice--success';
						notice.hidden       = false;
					} else {
						var msg = ( result.data && result.data.message ) ? result.data.message : ( i18n.error || 'Something went wrong.' );
						notice.textContent  = msg;
						notice.className    = 'agnosis-newsletter-signup__notice agnosis-newsletter-signup__notice--error';
						notice.hidden       = false;
						submit.disabled     = false;
						submit.removeAttribute( 'aria-busy' );
						// A Turnstile token is single-use — reset the widget so a
						// retry (e.g. after a validation error unrelated to it) gets
						// a fresh token instead of silently failing verification again.
						if ( window.turnstile ) {
							window.turnstile.reset();
						}
					}
				} )
				.catch( function () {
					notice.textContent  = i18n.error || 'Something went wrong.';
					notice.className    = 'agnosis-newsletter-signup__notice agnosis-newsletter-signup__notice--error';
					notice.hidden       = false;
					submit.disabled     = false;
					submit.removeAttribute( 'aria-busy' );
					if ( window.turnstile ) {
						window.turnstile.reset();
					}
				} );
			} );
		} );

	} );

} )();
