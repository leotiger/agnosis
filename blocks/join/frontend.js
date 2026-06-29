/**
 * Agnosis Join block — frontend form handler.
 *
 * Intercepts the application form submit, POSTs to the admission REST
 * endpoint, and shows a success message or a field-level error without
 * a page reload.
 *
 * Expects window.agnosisJoin to be localised by JoinPage::enqueue_assets():
 *   { apiUrl: string, i18n: { success: string, error: string, duplicate: string } }
 */
( function () {

	document.addEventListener( 'DOMContentLoaded', function () {

		var wrap   = document.getElementById( 'agnosis-join' );
		var form   = document.getElementById( 'agnosis-join-form' );
		var notice = document.getElementById( 'agnosis-join-notice' );

		if ( ! form ) {
			return;
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

			// Disable submit while in flight.
			submit.disabled = true;
			submit.setAttribute( 'aria-busy', 'true' );

			var payload = {
				email:         ( form.querySelector( '[name="email"]' )         || {} ).value || '',
				display_name:  ( form.querySelector( '[name="display_name"]' )  || {} ).value || '',
				bio:           ( form.querySelector( '[name="bio"]' )           || {} ).value || '',
				portfolio_url: ( form.querySelector( '[name="portfolio_url"]' ) || {} ).value || '',
				statement:     ( form.querySelector( '[name="statement"]' )     || {} ).value || '',
				language:      ( form.querySelector( '[name="language"]' )      || {} ).value || '',
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
				if ( result.ok && result.data.status === 'applied' ) {
					// Hide form, show success.
					form.hidden           = true;
					notice.textContent    = i18n.success || result.data.status;
					notice.className      = 'agnosis-join__notice agnosis-join__notice--success';
					notice.hidden         = false;
				} else {
					// Surface the server error message.
					var msg = ( result.data && result.data.message ) ? result.data.message : ( i18n.error || 'Error.' );
					notice.textContent    = msg;
					notice.className      = 'agnosis-join__notice agnosis-join__notice--error';
					notice.hidden         = false;
					submit.disabled       = false;
					submit.removeAttribute( 'aria-busy' );
				}
			} )
			.catch( function () {
				notice.textContent    = i18n.error || 'Something went wrong.';
				notice.className      = 'agnosis-join__notice agnosis-join__notice--error';
				notice.hidden         = false;
				submit.disabled       = false;
				submit.removeAttribute( 'aria-busy' );
			} );
		} );

	} );

} )();
