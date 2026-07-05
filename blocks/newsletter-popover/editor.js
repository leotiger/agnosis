/**
 * Agnosis Newsletter Popover block — editor registration.
 *
 * Vanilla JS (no build step), same pattern as blocks/newsletter-signup/editor.js.
 * Dynamic block (server-side rendered via PHP render_callback) — save() returns null.
 *
 * The block renders as a small ~36px icon button on the frontend, sitting
 * flush inline with a Social Icons block (see agnosis-theme's footer). A
 * `Placeholder` (large card, heading, instructions) is the wrong tool here —
 * that's meant for blocks that need real editor setup, and on a block this
 * small it just blows out the surrounding layout instead of previewing it.
 * Editor shows a compact stand-in the same size/shape as the real button
 * instead, so the block behaves like any other small inline Gutenberg block
 * (e.g. a single Social Icon) rather than an oversized "explanation" panel.
 */
( function ( blocks, element, i18n, blockEditor, components ) {

	var el                = element.createElement;
	var Fragment          = element.Fragment;
	var __                = i18n.__;
	var useBlockProps     = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody         = components.PanelBody;
	var SelectControl     = components.SelectControl;

	var ICON_OPTIONS = [
		{ label: __( 'Bell', 'agnosis' ), value: 'bell' },
		{ label: __( 'Envelope', 'agnosis' ), value: 'envelope' },
		{ label: __( 'Star', 'agnosis' ), value: 'star' },
		{ label: __( 'Lightning bolt', 'agnosis' ), value: 'zap' },
	];

	// Mirrors Agnosis\Newsletter\PopoverBlock::ICONS on the PHP side — kept in
	// sync by hand since this is a vanilla-JS, no-build-step block. Inner
	// markup only (path/polygon elements), same 24x24 viewBox convention as
	// the frontend SVG.
	var ICON_MARKUP = {
		bell:     '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path>',
		envelope: '<rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="m2 6 10 7 10-7"></path>',
		star:     '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>',
		zap:      '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>',
	};

	blocks.registerBlockType( 'agnosis/newsletter-popover', {

		edit: function ( props ) {
			var icon = props.attributes.icon || 'bell';

			// Inline styles approximating the real .lf-icon-btn treatment
			// (blocks/newsletter-popover/frontend.css) rather than depending on
			// that stylesheet being loaded inside the editor — keeps this file
			// self-contained, same as gallery-overview's editor preview.
			var blockProps = useBlockProps( {
				title: __( 'Newsletter Popover — opens the signup form', 'agnosis' ),
				style: {
					display:        'inline-flex',
					alignItems:     'center',
					justifyContent: 'center',
					width:          '2.25rem',
					height:         '2.25rem',
					border:         '1px solid currentColor',
					opacity:        0.75,
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
						{ title: __( 'Newsletter settings', 'agnosis' ) },
						el( SelectControl, {
							label:    __( 'Trigger icon', 'agnosis' ),
							value:    icon,
							options:  ICON_OPTIONS,
							onChange: function ( value ) {
								props.setAttributes( { icon: value } );
							},
							help: __(
								'Pick something that won’t blend in with icons already sitting next to it (e.g. Social Share).',
								'agnosis'
							),
						} )
					)
				),
				el(
					'div',
					blockProps,
					el( 'svg', {
						width:                   18,
						height:                  18,
						viewBox:                 '0 0 24 24',
						fill:                    'none',
						stroke:                  'currentColor',
						strokeWidth:             1.5,
						'aria-hidden':           true,
						focusable:               false,
						dangerouslySetInnerHTML: { __html: ICON_MARKUP[ icon ] || ICON_MARKUP.bell },
					} )
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
	window.wp.blockEditor,
	window.wp.components
);
