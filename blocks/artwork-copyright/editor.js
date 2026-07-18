/**
 * Agnosis Artwork Copyright block — editor registration.
 *
 * Vanilla JS (no build step), same pattern as blocks/artist-name-link/editor.js.
 * Dynamic block (server-side rendered via PHP render_callback) — save() returns
 * null.
 *
 * 2026-07-18: new block for correctly crediting artwork copyright on single
 * artwork pages — "© {artwork's publish year} {artist name}". Color/Typography
 * here are ordinary block *supports* (block.json), so the standard Gutenberg
 * Inspector panels apply automatically. `usesContext: ["postId"]` (rather than
 * the subdomain-based resolution artist-name-link uses) matches its siblings
 * agnosis/artwork-title, agnosis/event-title, etc. — all resolve their post via
 * block context because they're meant to sit inside the Post Template of the
 * relevant single-post FSE template, not a subdomain header.
 *
 * Renders real sample text ("© 2026 Artist Name") on the same element
 * useBlockProps() decorates, so Color/Typography Inspector controls preview
 * live exactly as they'll render on the frontend. The canvas has no way to
 * preview per-post publish year/author, so this sample always shows.
 */
( function ( blocks, element, i18n, blockEditor ) {

	var el            = element.createElement;
	var __            = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;

	// Named (capitalized) so eslint-plugin-react-hooks recognizes this as a
	// component and allows the useBlockProps() hook call inside it.
	var Edit = function () {
		var blockProps = useBlockProps();

		return el( 'p', blockProps, __( '© 2026 Artist Name', 'agnosis' ) );
	};

	blocks.registerBlockType( 'agnosis/artwork-copyright', {
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
