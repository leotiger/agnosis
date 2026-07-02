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
			var wrap   = form.closest( '.agnosis-newsletter-signup' );
			var notice = wrap.querySelector( '.agnosis-newsletter-signup__notice' );

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
					email: ( form.querySelector( '[name="email"]' ) || {} ).value || '',
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
					}
				} )
				.catch( function () {
					notice.textContent  = i18n.error || 'Something went wrong.';
					notice.className    = 'agnosis-newsletter-signup__notice agnosis-newsletter-signup__notice--error';
					notice.hidden       = false;
					submit.disabled     = false;
					submit.removeAttribute( 'aria-busy' );
				} );
			} );
		} );

	} );

} )();
