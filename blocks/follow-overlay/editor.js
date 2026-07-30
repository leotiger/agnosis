/**
 * Agnosis Follow Overlay block — editor registration.
 *
 * Vanilla JS (no build step), same pattern as blocks/reply-overlay/editor.js.
 * Dynamic block (server-side rendered via ActivityPub::render_follow_overlay()) —
 * save() returns null. The canvas has no real artwork to resolve an artist's
 * handle from, so this always shows a fixed sample label, same convention as
 * reply-overlay/interaction-counts's own editor previews.
 */
( function ( blocks, element, i18n, blockEditor ) {

	var el            = element.createElement;
	var __            = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;

	// Named (capitalized) so eslint-plugin-react-hooks recognizes this as a
	// component and allows the useBlockProps() hook call inside it.
	var Edit = function () {
		var blockProps = useBlockProps();

		return el( 'p', blockProps, __( 'Follow', 'agnosis' ) );
	};

	blocks.registerBlockType( 'agnosis/follow-overlay', {
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
