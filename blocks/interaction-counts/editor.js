/**
 * Agnosis Interaction Counts block — editor registration.
 *
 * Vanilla JS (no build step), same pattern as blocks/artwork-copyright/editor.js.
 * Dynamic block (server-side rendered via PHP render_callback) — save() returns
 * null.
 *
 * Interaction-surface roadmap, Phase 1 (2026-07-24): a small, independent
 * like-count / boost-count pair rendered below an artwork's content — see
 * agnosis-audit/INTERACTION-SURFACE-ROADMAP.md for the design intent this
 * follows (inline, low surface area, never competing visually with the
 * artwork). Color/Typography are ordinary block *supports* (block.json), so
 * the standard Gutenberg Inspector panels apply automatically, same as
 * agnosis/artwork-copyright.
 *
 * The canvas has no way to preview a real post's actual like/boost counts, so
 * this always shows fixed sample numbers.
 */
( function ( blocks, element, i18n, blockEditor ) {

	var el            = element.createElement;
	var __            = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;

	// Named (capitalized) so eslint-plugin-react-hooks recognizes this as a
	// component and allows the useBlockProps() hook call inside it.
	var Edit = function () {
		var blockProps = useBlockProps();

		return el(
			'p',
			blockProps,
			__( '♥ 12 · ⟲ 4', 'agnosis' )
		);
	};

	blocks.registerBlockType( 'agnosis/interaction-counts', {
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
