/**
 * Agnosis Artist Breadcrumb block — editor registration.
 *
 * Vanilla JS (no build step), same pattern as blocks/newsletter-signup/editor.js.
 * Dynamic block (server-side rendered via PHP render_callback) — save() returns
 * null.
 *
 * Renders real sample text ("Artist Name") inside the same wrapper markup the
 * PHP render_callback outputs (a <div class="agnosis-artist-breadcrumb"> with
 * a link inside), instead of a Placeholder card explaining the block. This
 * matters here specifically because the block's Typography (Font Size, Font
 * Family, Appearance/weight) and Color (text/background) Inspector controls
 * are block *supports* — Gutenberg applies them automatically to whatever
 * element carries `useBlockProps()`, so rendering real text there means those
 * controls preview live exactly as they'll render on the frontend, on top of
 * the theme's own `.agnosis-artist-breadcrumb` rule (loaded in the editor via
 * `add_editor_style()`) for the parts supports don't cover (opacity, hover).
 *
 * The canvas has no way to preview per-subdomain rendering, so this sample
 * always shows, even editing on the main site — the "renders nothing off a
 * subdomain" behaviour is only actually visible on the frontend.
 */
( function ( blocks, element, i18n, blockEditor ) {

	var el            = element.createElement;
	var __            = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;

	blocks.registerBlockType( 'agnosis/artist-breadcrumb', {

		edit: function () {
			var blockProps = useBlockProps( {
				className: 'agnosis-artist-breadcrumb',
			} );

			return el(
				'div',
				blockProps,
				el(
					'a',
					{
						href: '#',
						onClick: function ( event ) {
							event.preventDefault();
						},
					},
					__( 'Artist Name', 'agnosis' )
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
	window.wp.blockEditor
);
