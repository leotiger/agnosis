/**
 * Gallery Overview block — editor registration.
 *
 * Server-side rendered — the actual artwork grid can't be shown in the editor
 * (it depends on live post data), but the *shape* of the grid the Columns
 * setting controls can be, and should be: a text line saying "3 columns"
 * doesn't tell an editor whether that's going to look cramped or sparse.
 * Renders a live CSS-grid mockup of empty tiles that reflows immediately as
 * the RangeControl changes, same idea as the newsletter-popover block's icon
 * preview updating live off its own attribute.
 *
 * No build step: uses wp.* globals enqueued by WordPress core.
 *
 * @package Agnosis\Blocks\GalleryOverview
 */
( function ( blocks, element, blockEditor, components, i18n ) {
	var el        = element.createElement;
	var __        = i18n.__;
	var Fragment  = element.Fragment;
	var useBlockProps      = blockEditor.useBlockProps;
	var InspectorControls  = blockEditor.InspectorControls;
	var PanelBody  = components.PanelBody;
	var RangeControl = components.RangeControl;

	blocks.registerBlockType( 'agnosis/gallery-overview', {

		edit: function ( props ) {
			var columns    = props.attributes.columns;
			var blockProps = useBlockProps( {
				style: {
					padding:    '1.5rem',
					border:     '1px dashed #555',
					background: '#111',
				},
			} );

			// Two preview rows' worth of empty tiles, reflowed to the current
			// column count — enough to read as "a grid of this width" without
			// pretending to show real artwork.
			var tileCount = columns * 2;
			var tiles     = [];
			for ( var i = 0; i < tileCount; i++ ) {
				tiles.push(
					el( 'div', {
						key: i,
						style: {
							aspectRatio:     '1 / 1',
							background:      '#2a2a35',
							border:          '1px solid #3a3a45',
						},
					} )
				);
			}

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Gallery settings', 'agnosis' ) },
						el( RangeControl, {
							label:    __( 'Columns', 'agnosis' ),
							value:    columns,
							onChange: function ( value ) {
								props.setAttributes( { columns: value } );
							},
							min: 2,
							max: 5,
						} )
					)
				),
				el(
					'div',
					blockProps,
					el(
						'p',
						{ style: { color: '#ededf0', fontFamily: 'sans-serif', margin: '0 0 1rem' } },
						__( '✦ Agnosis Gallery Overview — rendered on the server', 'agnosis' )
					),
					el(
						'div',
						{
							style: {
								display:             'grid',
								gridTemplateColumns: 'repeat(' + columns + ', 1fr)',
								gap:                 '0.5rem',
							},
						},
						tiles
					),
					el(
						'p',
						{ style: { color: '#888', fontSize: '0.85rem', margin: '0.75rem 0 0' } },
						columns + ' ' + __( 'columns · proportional · random daily order', 'agnosis' )
					)
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
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
);
