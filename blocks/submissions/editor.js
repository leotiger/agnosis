/**
 * Agnosis Submissions block — editor registration.
 *
 * Vanilla JS (no build step): uses window.wp globals available in the
 * block editor. The block itself is dynamic (server-side rendered via
 * PHP render_callback), so save() always returns null.
 *
 * Editor view shows a labelled placeholder so the site editor can see
 * where the submissions list will appear on the frontend.
 */
( function ( blocks, element, i18n, blockEditor, components ) {

	var el          = element.createElement;
	var __          = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var Placeholder = components.Placeholder;

	/**
	 * Editor component — placeholder only.
	 * Real content is rendered server-side for logged-in artists.
	 *
	 * Named (capitalized) so eslint-plugin-react-hooks recognizes this as a
	 * component and allows the useBlockProps() hook call inside it.
	 */
	var Edit = function () {
			var blockProps = useBlockProps( {
				className: 'agnosis-submissions-editor',
			} );

			return el(
				'div',
				blockProps,
				el(
					Placeholder,
					{
						icon:  'art',
						label: __( 'My Submissions', 'agnosis' ),
						instructions: __(
							'Displays the logged-in artist\'s pending artwork submissions. ' +
							'Artists can approve, edit or discard each one. ' +
							'Content is rendered live on the frontend.',
							'agnosis'
						),
					},
					el(
						'p',
						{ className: 'agnosis-submissions-editor__note' },
						__(
							'Place this block on your review page (e.g. /my-submissions/) ' +
							'or in a Site Editor page template assigned to that page.',
							'agnosis'
						)
					)
				)
			);
	};

	blocks.registerBlockType( 'agnosis/submissions', {
		edit: Edit,

		/**
		 * Dynamic block — PHP render_callback handles frontend output.
		 */
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
