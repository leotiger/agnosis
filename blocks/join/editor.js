/**
 * Agnosis Join block — editor registration.
 *
 * Vanilla JS (no build step): uses window.wp globals available in the block
 * editor. The block is dynamic (server-side rendered via PHP render_callback),
 * so save() always returns null.
 *
 * The editor view shows a labelled placeholder so site editors can see where
 * the join form will appear on the frontend.
 */
( function ( blocks, element, i18n, blockEditor, components ) {

	var el            = element.createElement;
	var __            = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var Placeholder   = components.Placeholder;

	// Named (capitalized) so eslint-plugin-react-hooks recognizes this as a
	// component and allows the useBlockProps() hook call inside it.
	var Edit = function () {
		var blockProps = useBlockProps( {
			className: 'agnosis-join-editor',
		} );

		return el(
			'div',
			blockProps,
			el(
				Placeholder,
				{
					icon:         'admin-users',
					label:        __( 'Join Agnosis', 'agnosis' ),
					// Single string literals, deliberately not built via '+' concatenation
					// across lines — gettext-style static extractors (WP-CLI's
					// `wp i18n make-pot`, Loco Translate's scanner, etc.) only recognise
					// a literal string as a translation function's argument; a
					// concatenation expression is invisible to them even though it
					// evaluates to the same value at runtime. Both strings below were
					// missing from agnosis.pot until this fix (2026-07-06).
					instructions: __( 'Public application form for new artists. Submits name, email, bio, portfolio URL and a personal statement to the community for review. Content is rendered live on the frontend.', 'agnosis' ),
				},
				el(
					'p',
					{ className: 'agnosis-join-editor__note' },
					__( 'Place this block on your /join/ page or in a Site Editor page template assigned to that page.', 'agnosis' )
				)
			)
		);
	};

	blocks.registerBlockType( 'agnosis/join', {
		edit: Edit,

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
