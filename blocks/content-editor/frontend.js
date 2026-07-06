/**
 * Agnosis front-end correction overlay — Phase 1 (text chunks) + Phase 2
 * (photo substitution).
 *
 * Enqueued only when the current viewer may edit the singular post being
 * viewed (see ContentEditor::maybe_enqueue_assets()). Decorates every
 * [data-agnosis-edit-field] region already present in the markup:
 *   - text/excerpt/event_location/event_date (written by
 *     ContentEditor::decorate_content()/decorate_excerpt() and Profile's event
 *     render callbacks) get a pencil affordance; clicking makes the region
 *     contentEditable with a floating Save/Cancel pair.
 *   - photo (written by ContentEditor::decorate_thumbnail()) gets a pencil
 *     affordance that opens a native file picker; picking a file uploads it
 *     immediately as a direct replacement — no enhancement, no dashboard.
 *
 *   - title (written by Profile::render_artwork_title() and
 *     ContentEditor::decorate_title()) behaves like a plain text field, not a
 *     rich one — no HTML, single line.
 *   - a photo region with data-agnosis-has-original="1" also gets a small
 *     "restore earlier photo" action (Phase 3) that posts to photoRestoreUrl
 *     with no file — a pure re-pointing on the server, no upload involved.
 *
 * Expects window.agnosisContentEditor to be localised by
 * ContentEditor::maybe_enqueue_assets():
 *   { apiUrl, photoApiUrl, photoRestoreUrl, nonce, postId, i18n: { save,
 *     cancel, saving, saved, error, replacePhoto, uploading, photoSaved,
 *     restorePhoto, restoring, photoRestored } }
 */
( function () {

	document.addEventListener( 'DOMContentLoaded', function () {

		var cfg = window.agnosisContentEditor || {};
		if ( ! cfg.apiUrl ) {
			return;
		}

		var regions = document.querySelectorAll( '[data-agnosis-edit-field]' );
		regions.forEach( function ( region ) {
			if ( 'photo' === region.getAttribute( 'data-agnosis-edit-field' ) ) {
				decoratePhoto( region, cfg );
			} else {
				decorate( region, cfg );
			}
		} );
	} );

	// -------------------------------------------------------------------------
	// Text / excerpt / event fields (Phase 1)
	// -------------------------------------------------------------------------

	/**
	 * Add the pencil affordance to one editable text region.
	 */
	function decorate( region, cfg ) {
		var pencil = document.createElement( 'button' );
		pencil.type = 'button';
		pencil.className = 'agnosis-editable__pencil';
		pencil.setAttribute( 'aria-label', cfg.i18n && cfg.i18n.save ? cfg.i18n.save : 'Edit' );
		pencil.innerHTML = '&#9998;';

		pencil.addEventListener( 'click', function () {
			startEditing( region, cfg );
		} );

		region.classList.add( 'agnosis-editable--decorated' );
		region.appendChild( pencil );
	}

	/**
	 * Switch a region into edit mode: hide the pencil, make the content
	 * editable, and show a floating Save/Cancel pair beneath it.
	 */
	function startEditing( region, cfg ) {
		if ( region.classList.contains( 'agnosis-editable--active' ) ) {
			return;
		}

		var field       = region.getAttribute( 'data-agnosis-edit-field' );
		var postId      = region.getAttribute( 'data-agnosis-post-id' ) || cfg.postId;
		var originalHtml = region.innerHTML;
		var isPlainField = 'event_location' === field || 'event_date' === field || 'title' === field;

		region.classList.add( 'agnosis-editable--active' );
		region.setAttribute( 'contenteditable', 'true' );
		region.setAttribute( 'spellcheck', 'true' );
		region.focus();

		var i18n = cfg.i18n || {};

		var toolbar = document.createElement( 'div' );
		toolbar.className = 'agnosis-editable__toolbar';

		var notice = document.createElement( 'span' );
		notice.className = 'agnosis-editable__notice';
		toolbar.appendChild( notice );

		var cancelBtn = document.createElement( 'button' );
		cancelBtn.type = 'button';
		cancelBtn.className = 'agnosis-editable__cancel';
		cancelBtn.textContent = i18n.cancel || 'Cancel';

		var saveBtn = document.createElement( 'button' );
		saveBtn.type = 'button';
		saveBtn.className = 'agnosis-editable__save';
		saveBtn.textContent = i18n.save || 'Save';

		toolbar.appendChild( cancelBtn );
		toolbar.appendChild( saveBtn );
		region.insertAdjacentElement( 'afterend', toolbar );

		function cleanup() {
			region.classList.remove( 'agnosis-editable--active' );
			region.removeAttribute( 'contenteditable' );
			toolbar.remove();
		}

		cancelBtn.addEventListener( 'click', function () {
			region.innerHTML = originalHtml;
			cleanup();
			decorate( region, cfg );
		} );

		saveBtn.addEventListener( 'click', function () {
			var value = isPlainField ? region.textContent.trim() : region.innerHTML;

			saveBtn.disabled   = true;
			cancelBtn.disabled = true;
			notice.textContent = i18n.saving || 'Saving…';

			fetch( cfg.apiUrl, {
				method:  'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   cfg.nonce || '',
				},
				credentials: 'same-origin',
				body: JSON.stringify( {
					id:    parseInt( postId, 10 ),
					field: field,
					value: value,
				} ),
			} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						return { ok: response.ok, data: data };
					} );
				} )
				.then( function ( result ) {
					if ( result.ok && 'saved' === result.data.status ) {
						notice.textContent = ( result.data.message ) || ( i18n.saved || 'Saved.' );
						region.removeAttribute( 'contenteditable' );
						region.classList.remove( 'agnosis-editable--active' );
						window.setTimeout( function () {
							toolbar.remove();
							decorate( region, cfg );
						}, 2000 );
					} else {
						notice.textContent = ( result.data && result.data.message ) || ( i18n.error || 'Something went wrong.' );
						saveBtn.disabled    = false;
						cancelBtn.disabled  = false;
					}
				} )
				.catch( function () {
					notice.textContent = i18n.error || 'Something went wrong.';
					saveBtn.disabled    = false;
					cancelBtn.disabled  = false;
				} );
		} );
	}

	// -------------------------------------------------------------------------
	// Photo substitution (Phase 2)
	// -------------------------------------------------------------------------

	/**
	 * Add the pencil affordance to a featured-image region. Clicking opens a
	 * native file picker; picking a file uploads it immediately as a direct
	 * replacement (no crop/enhance step — the artist's photo is used as-is).
	 */
	function decoratePhoto( region, cfg ) {
		var i18n = cfg.i18n || {};

		var pencil = document.createElement( 'button' );
		pencil.type = 'button';
		pencil.className = 'agnosis-editable__pencil agnosis-editable__pencil--photo';
		pencil.setAttribute( 'aria-label', i18n.replacePhoto || 'Replace photo' );
		pencil.innerHTML = '&#9998;';

		var input = document.createElement( 'input' );
		input.type = 'file';
		input.accept = 'image/jpeg,image/png,image/webp,image/gif';
		input.className = 'agnosis-editable__photo-input';
		input.hidden = true;

		pencil.addEventListener( 'click', function () {
			input.click();
		} );

		input.addEventListener( 'change', function () {
			if ( input.files && input.files[ 0 ] ) {
				uploadPhoto( region, input.files[ 0 ], cfg );
			}
			input.value = '';
		} );

		region.classList.add( 'agnosis-editable--decorated' );
		region.appendChild( pencil );
		region.appendChild( input );

		maybeAddRestoreButton( region, cfg );
	}

	/**
	 * Add the "restore earlier photo" affordance (Phase 3) when this region's
	 * current attachment has an earlier version recorded — no-ops (and removes
	 * any existing button) otherwise. Callable repeatedly: after a fresh
	 * upload the region always has an earlier version, so this is re-run from
	 * uploadPhoto()'s success handler too.
	 */
	function maybeAddRestoreButton( region, cfg ) {
		var existing = region.querySelector( '.agnosis-editable__restore' );

		if ( '1' !== region.getAttribute( 'data-agnosis-has-original' ) ) {
			if ( existing ) {
				existing.remove();
			}
			return;
		}

		if ( existing ) {
			return;
		}

		var i18n = cfg.i18n || {};

		var restoreBtn = document.createElement( 'button' );
		restoreBtn.type = 'button';
		restoreBtn.className = 'agnosis-editable__restore';
		restoreBtn.textContent = i18n.restorePhoto || 'Restore earlier photo';

		restoreBtn.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			restorePhoto( region, cfg );
		} );

		region.appendChild( restoreBtn );
	}

	function restorePhoto( region, cfg ) {
		if ( ! cfg.photoRestoreUrl ) {
			return;
		}

		var i18n         = cfg.i18n || {};
		var postId       = region.getAttribute( 'data-agnosis-post-id' ) || cfg.postId;
		var attachmentId = region.getAttribute( 'data-agnosis-attachment-id' ) || 0;

		var notice = region.querySelector( '.agnosis-editable__photo-notice' );
		if ( ! notice ) {
			notice = document.createElement( 'span' );
			notice.className = 'agnosis-editable__photo-notice';
			region.appendChild( notice );
		}
		notice.textContent = i18n.restoring || 'Restoring…';
		region.classList.add( 'agnosis-editable--active' );

		fetch( cfg.photoRestoreUrl, {
			method:  'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   cfg.nonce || '',
			},
			credentials: 'same-origin',
			body: JSON.stringify( {
				id:            parseInt( postId, 10 ),
				attachment_id: parseInt( attachmentId, 10 ) || 0,
			} ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				region.classList.remove( 'agnosis-editable--active' );

				if ( result.ok && 'saved' === result.data.status && result.data.image_url ) {
					var img = region.querySelector( 'img' );
					if ( img ) {
						img.src = result.data.image_url;
					}
					notice.textContent = result.data.message || i18n.photoRestored || 'Restored.';
					if ( result.data.attachment_id ) {
						region.setAttribute( 'data-agnosis-attachment-id', result.data.attachment_id );
					}
					// Restoring always leaves an earlier version behind too (the
					// pointer is reversed, never deleted — see ContentEditor::
					// restore_photo()), so the button stays; just refresh it.
					maybeAddRestoreButton( region, cfg );
				} else {
					notice.textContent = ( result.data && result.data.message ) || ( i18n.error || 'Something went wrong.' );
				}

				window.setTimeout( function () {
					notice.remove();
				}, 3000 );
			} )
			.catch( function () {
				region.classList.remove( 'agnosis-editable--active' );
				notice.textContent = i18n.error || 'Something went wrong.';
				window.setTimeout( function () {
					notice.remove();
				}, 3000 );
			} );
	}

	function uploadPhoto( region, file, cfg ) {
		var i18n          = cfg.i18n || {};
		var postId        = region.getAttribute( 'data-agnosis-post-id' ) || cfg.postId;
		var attachmentId  = region.getAttribute( 'data-agnosis-attachment-id' ) || 0;

		var notice = region.querySelector( '.agnosis-editable__photo-notice' );
		if ( ! notice ) {
			notice = document.createElement( 'span' );
			notice.className = 'agnosis-editable__photo-notice';
			region.appendChild( notice );
		}
		notice.textContent = i18n.uploading || 'Uploading…';
		region.classList.add( 'agnosis-editable--active' );

		var formData = new FormData();
		formData.append( 'id', postId );
		formData.append( 'attachment_id', attachmentId );
		formData.append( 'file', file );

		fetch( cfg.photoApiUrl || cfg.apiUrl.replace( '/text', '/photo' ), {
			method:      'POST',
			headers:     { 'X-WP-Nonce': cfg.nonce || '' },
			credentials: 'same-origin',
			body:        formData,
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					return { ok: response.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				region.classList.remove( 'agnosis-editable--active' );

				if ( result.ok && 'saved' === result.data.status && result.data.image_url ) {
					var img = region.querySelector( 'img' );
					if ( img ) {
						img.src = result.data.image_url;
					}
					notice.textContent = result.data.message || i18n.photoSaved || 'Photo replaced.';
					if ( result.data.attachment_id ) {
						region.setAttribute( 'data-agnosis-attachment-id', result.data.attachment_id );
					}
					// A fresh replacement always leaves an earlier version behind
					// (see ContentEditor::save_photo()) — surface the restore action.
					region.setAttribute( 'data-agnosis-has-original', '1' );
					maybeAddRestoreButton( region, cfg );
				} else {
					notice.textContent = ( result.data && result.data.message ) || ( i18n.error || 'Something went wrong.' );
				}

				window.setTimeout( function () {
					notice.remove();
				}, 3000 );
			} )
			.catch( function () {
				region.classList.remove( 'agnosis-editable--active' );
				notice.textContent = i18n.error || 'Something went wrong.';
				window.setTimeout( function () {
					notice.remove();
				}, 3000 );
			} );
	}

} )();
