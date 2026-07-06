/**
 * Agnosis Newsletter Signup block — editor registration.
 *
 * Vanilla JS (no build step), same pattern as blocks/join/editor.js. Dynamic
 * block (server-side rendered via PHP render_callback) — save() returns null.
 */
( function ( blocks, element, i18n, blockEditor, components ) {

	var el            = element.createElement;
	var __            = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var Placeholder   = components.Placeholder;

	blocks.registerBlockType( 'agnosis/newsletter-signup', {

		edit: function () {
			var blockProps = useBlockProps( {
				className: 'agnosis-newsletter-signup-editor',
			} );

			return el(
				'div',
				blockProps,
				el(
					Placeholder,
					{
						icon:         'email-alt',
						label:        __( 'Newsletter Signup', 'agnosis' ),
						// Single string literal, deliberately not built via '+' concatenation
						// across these lines — gettext-style static extractors (WP-CLI's
						// `wp i18n make-pot`, Loco Translate's scanner, etc.) only recognise
						// a literal string as a translation function's argument; a
						// concatenation expression is invisible to them even though it
						// evaluates to the same value at runtime. This exact string was
						// missing from agnosis.pot until this fix (2026-07-06).
						instructions: __( 'Email signup form for the public newsletter. A confirmation email is sent before anyone is added to the send list (double opt-in). Content is rendered live on the frontend.', 'agnosis' ),
					}
				)
			);
		},

		save: function () {
			return null;
		},

	} );

} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.i18n,
	window.wp.blockEditor,
	window.wp.components
);
