/**
 * Agnosis Contact Form block — frontend form handler.
 *
 * Intercepts the contact form submit, POSTs to the per-artist contact REST
 * endpoint, and shows a success or error message without a page reload.
 * Mirrors blocks/newsletter-signup/frontend.js.
 *
 * Expects window.agnosisContactForm to be localised by
 * ContactFormBlock::enqueue_assets():
 *   { apiUrl: string, i18n: { success: string, error: string } }
 */
( function () {

	document.addEventListener( 'DOMContentLoaded', function () {

		var forms = document.querySelectorAll( '.agnosis-contact-form form' );

		forms.forEach( function ( form ) {
			var wrap   = form.closest( '.agnosis-contact-form' );
			var notice = wrap.querySelector( '.agnosis-contact-form__notice' );

			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();

				var submit = form.querySelector( '[type="submit"]' );
				var cfg    = window.agnosisContactForm || {};
				var i18n   = cfg.i18n || {};

				notice.hidden      = true;
				notice.className   = 'agnosis-contact-form__notice';
				notice.textContent = '';

				submit.disabled = true;
				submit.setAttribute( 'aria-busy', 'true' );

				var payload = {
					name:             ( form.querySelector( '[name="name"]' ) || {} ).value || '',
					email:            ( form.querySelector( '[name="email"]' ) || {} ).value || '',
					message:          ( form.querySelector( '[name="message"]' ) || {} ).value || '',
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
						notice.textContent  = i18n.success || 'Thanks — your message has been sent.';
						notice.className    = 'agnosis-contact-form__notice agnosis-contact-form__notice--success';
						notice.hidden       = false;
					} else {
						var msg = ( result.data && result.data.message ) ? result.data.message : ( i18n.error || 'Something went wrong.' );
						notice.textContent  = msg;
						notice.className    = 'agnosis-contact-form__notice agnosis-contact-form__notice--error';
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
					notice.className    = 'agnosis-contact-form__notice agnosis-contact-form__notice--error';
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
