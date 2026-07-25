/**
 * Agnosis Reply Overlay block — editor registration.
 *
 * Vanilla JS (no build step), same pattern as blocks/interaction-counts/editor.js.
 * Dynamic block (server-side rendered via ActivityPub::render_reply_overlay()) —
 * save() returns null.
 *
 * Interaction-surface roadmap, Phase 2 (2026-07-25): a "N replies" trigger
 * that opens a native-Popover-API panel — same mechanism as
 * Newsletter\PopoverBlock's existing subscribe popover, just pointed at a
 * fetched reply list instead of a static form. The canvas has no real post to
 * fetch replies for, so this always shows a fixed sample count, same as
 * agnosis/interaction-counts's own editor preview.
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
			__( '3 replies', 'agnosis' )
		);
	};

	blocks.registerBlockType( 'agnosis/reply-overlay', {
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
