/**
 * Agnosis Site Copyright block — editor registration.
 *
 * Vanilla JS (no build step), same pattern as blocks/artwork-copyright/editor.js.
 * Dynamic block (server-side rendered via
 * SubdomainNavigation::render_copyright_block()) — save() returns null. The
 * canvas can't resolve a real subdomain/handle, so this always shows a fixed
 * sample line, same convention as artwork-copyright's own editor preview.
 */
( function ( blocks, element, i18n, blockEditor ) {

	var el            = element.createElement;
	var __            = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;

	// Named (capitalized) so eslint-plugin-react-hooks recognizes this as a
	// component and allows the useBlockProps() hook call inside it.
	var Edit = function () {
		var blockProps = useBlockProps();

		return el( 'p', blockProps, __( '© 2026 Agnosis (@agnosis@agnosis.art)', 'agnosis' ) );
	};

	blocks.registerBlockType( 'agnosis/site-copyright', {
		edit: Edit,

		save: function () {
			return null;
		},

	} );

} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.i18n,
	window.wp.blockEditor
);
