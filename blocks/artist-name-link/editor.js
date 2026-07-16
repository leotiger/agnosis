/**
 * Agnosis Artist Name Link block — editor registration.
 *
 * Vanilla JS (no build step), same pattern as blocks/newsletter-signup/editor.js.
 * Dynamic block (server-side rendered via PHP render_callback) — save() returns
 * null.
 *
 * 2026-07-10: split out of `agnosis/artist-breadcrumb` (which still exists,
 * unchanged, for sites whose customized header template already has it) as
 * part of a move toward small, single-purpose, real-WP-block-supports blocks:
 * Color/Typography here are ordinary block *supports*, so the standard
 * Gutenberg Inspector panels apply automatically — no custom size/color
 * controls needed the way `artist-breadcrumb`'s icon attributes required.
 * Meant to sit inside a Group block next to `agnosis/breadcrumb-icon-link`
 * instances, laid out via that Group's own layout controls (justify content,
 * vertical alignment) rather than any CSS this block ships itself.
 *
 * Renders real sample text ("Artist Name") on the same element `useBlockProps()`
 * decorates, so Color/Typography Inspector controls preview live exactly as
 * they'll render on the frontend.
 *
 * The canvas has no way to preview per-subdomain rendering, so this sample
 * always shows, even editing on the main site — the "renders nothing off a
 * subdomain" behaviour is only actually visible on the frontend.
 */
( function ( blocks, element, i18n, blockEditor ) {

	var el            = element.createElement;
	var __            = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;

	// Named (capitalized) so eslint-plugin-react-hooks recognizes this as a
	// component and allows the useBlockProps() hook call inside it.
	var Edit = function () {
		var blockProps = useBlockProps( {
			href: '#',
			onClick: function ( event ) {
				event.preventDefault();
			},
		} );

		return el( 'a', blockProps, __( 'Artist Name', 'agnosis' ) );
	};

	blocks.registerBlockType( 'agnosis/artist-name-link', {
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
