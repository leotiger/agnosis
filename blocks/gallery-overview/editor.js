/**
 * Gallery Overview block — editor registration.
 *
 * Server-side rendered; the editor shows a static placeholder.
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
					padding:    '2rem',
					border:     '1px dashed #555',
					textAlign:  'center',
					background: '#111',
				},
			} );

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
						{ style: { color: '#ededf0', fontFamily: 'sans-serif', margin: 0 } },
						__( '✦ Agnosis Gallery Overview — rendered on the server', 'agnosis' )
					),
					el(
						'p',
						{ style: { color: '#888', fontSize: '0.85rem', margin: '0.5rem 0 0' } },
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
