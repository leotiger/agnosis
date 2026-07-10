/**
 * Agnosis Artist Breadcrumb block — editor registration.
 *
 * Vanilla JS (no build step), same pattern as blocks/newsletter-signup/editor.js.
 * Dynamic block (server-side rendered via PHP render_callback) — save() returns
 * null.
 *
 * Renders real sample text ("Artist Name") plus sample Biography/Events icon
 * links inside the same wrapper markup the PHP render_callback outputs (a
 * <div class="agnosis-artist-breadcrumb"> with a
 * <span class="agnosis-artist-breadcrumb__name"> and a
 * <span class="agnosis-artist-breadcrumb__links"> inside), instead of a
 * Placeholder card explaining the block. This matters here specifically
 * because the block's Typography (Font Size, Font Family, Appearance/weight)
 * and Color (text/background) Inspector controls are block *supports* —
 * Gutenberg applies them automatically to whatever element carries
 * `useBlockProps()`, so rendering real content there means those controls
 * preview live exactly as they'll render on the frontend, on top of the
 * theme's own `.agnosis-artist-breadcrumb` rule (loaded in the editor via
 * `add_editor_style()`) for the parts supports don't cover (opacity, hover).
 *
 * 2026-07-10: the Biography/Events sample links were added specifically so
 * the new per-instance icon controls below (icon choice, size, color,
 * vertical align — plain block.json "attributes", not supports, since
 * there's no built-in support for recoloring/realigning just one inner
 * element) preview live too, same reasoning as the name/Color/Typography
 * controls above.
 *
 * The canvas has no way to preview per-subdomain rendering, so this sample
 * always shows, even editing on the main site — the "renders nothing off a
 * subdomain" behaviour is only actually visible on the frontend.
 */
( function ( blocks, element, i18n, blockEditor, components ) {

	var el                = element.createElement;
	var Fragment          = element.Fragment;
	var __                = i18n.__;
	var useBlockProps     = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody         = components.PanelBody;
	var SelectControl     = components.SelectControl;
	var RangeControl      = components.RangeControl;
	var ColorPalette      = components.ColorPalette;

	var BIOGRAPHY_ICON_OPTIONS = [
		{ label: __( 'Book', 'agnosis' ), value: 'book' },
		{ label: __( 'Person', 'agnosis' ), value: 'user' },
	];

	var EVENTS_ICON_OPTIONS = [
		{ label: __( 'Calendar', 'agnosis' ), value: 'calendar' },
		{ label: __( 'Pin', 'agnosis' ), value: 'pin' },
	];

	// Values mirror Agnosis\Network\SubdomainNavigation::VERTICAL_ALIGN_VALUES
	// on the PHP side — keep both in sync by hand.
	var VERTICAL_ALIGN_OPTIONS = [
		{ label: __( 'Baseline (default)', 'agnosis' ), value: 'baseline' },
		{ label: __( 'Top', 'agnosis' ), value: 'top' },
		{ label: __( 'Middle', 'agnosis' ), value: 'middle' },
		{ label: __( 'Bottom', 'agnosis' ), value: 'bottom' },
	];

	var VERTICAL_ALIGN_CSS = {
		baseline: 'baseline',
		top:      'flex-start',
		middle:   'center',
		bottom:   'flex-end',
	};

	// Mirrors Agnosis\Network\SubdomainNavigation::LINK_ICON_SETS on the PHP
	// side — kept in sync by hand since this is a vanilla-JS, no-build-step
	// block (same tradeoff Newsletter\PopoverBlock's own icon picker already
	// made). Inner markup only (path/circle/rect/line elements), same 24x24
	// viewBox convention as the frontend SVG.
	var ICON_MARKUP = {
		book:     '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>',
		user:     '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
		calendar: '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
		pin:      '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle>',
	};

	function iconEl( key, size, color ) {
		return el(
			'span',
			{ style: { display: 'inline-flex' } },
			el( 'svg', {
				width:                   size,
				height:                  size,
				viewBox:                 '0 0 24 24',
				fill:                    'none',
				stroke:                  color || 'currentColor',
				strokeWidth:             1.5,
				'aria-hidden':           true,
				focusable:               false,
				dangerouslySetInnerHTML: { __html: ICON_MARKUP[ key ] || '' },
			} )
		);
	}

	blocks.registerBlockType( 'agnosis/artist-breadcrumb', {

		edit: function ( props ) {
			var attributes    = props.attributes;
			var biographyIcon = attributes.biographyIcon || 'book';
			var eventsIcon    = attributes.eventsIcon || 'calendar';
			var iconSize      = attributes.iconSize || 18;
			var iconColor     = attributes.iconColor || '';
			var verticalAlign = attributes.iconVerticalAlign || 'baseline';

			var blockProps = useBlockProps( {
				className: 'agnosis-artist-breadcrumb',
			} );

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Icon settings', 'agnosis' ) },
						el( SelectControl, {
							label:    __( 'Biography icon', 'agnosis' ),
							value:    biographyIcon,
							options:  BIOGRAPHY_ICON_OPTIONS,
							onChange: function ( value ) {
								props.setAttributes( { biographyIcon: value } );
							},
						} ),
						el( SelectControl, {
							label:    __( 'Events icon', 'agnosis' ),
							value:    eventsIcon,
							options:  EVENTS_ICON_OPTIONS,
							onChange: function ( value ) {
								props.setAttributes( { eventsIcon: value } );
							},
						} ),
						el( RangeControl, {
							label:    __( 'Icon size', 'agnosis' ),
							value:    iconSize,
							min:      12,
							max:      32,
							onChange: function ( value ) {
								props.setAttributes( { iconSize: value || 18 } );
							},
						} ),
						el( SelectControl, {
							label:    __( 'Icon vertical align', 'agnosis' ),
							value:    verticalAlign,
							options:  VERTICAL_ALIGN_OPTIONS,
							onChange: function ( value ) {
								props.setAttributes( { iconVerticalAlign: value } );
							},
							help: __(
								'Icon-only links have no text baseline of their own — use this if they sit too high or low next to the artist name once Icon size is changed from the default.',
								'agnosis'
							),
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Icon color', 'agnosis' ), initialOpen: false },
						el( ColorPalette, {
							value:       iconColor,
							onChange:    function ( value ) {
								props.setAttributes( { iconColor: value || '' } );
							},
							enableAlpha: false,
							clearable:   true,
						} ),
						el(
							'p',
							{ style: { fontSize: '12px', opacity: 0.6 } },
							__( 'Leave unset to match the block’s own text color.', 'agnosis' )
						)
					)
				),
				el(
					'div',
					blockProps,
					el(
						'span',
						{ className: 'agnosis-artist-breadcrumb__name' },
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
					),
					el(
						'span',
						{
							className: 'agnosis-artist-breadcrumb__links',
							style: Object.assign(
								{ alignSelf: VERTICAL_ALIGN_CSS[ verticalAlign ] || 'baseline' },
								iconColor ? { color: iconColor } : {}
							),
						},
						el(
							'a',
							{
								href: '#',
								title: __( 'Biography', 'agnosis' ),
								onClick: function ( event ) {
									event.preventDefault();
								},
							},
							iconEl( biographyIcon, iconSize )
						),
						el(
							'a',
							{
								href: '#',
								title: __( 'Events', 'agnosis' ),
								onClick: function ( event ) {
									event.preventDefault();
								},
							},
							iconEl( eventsIcon, iconSize )
						)
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
	window.wp.i18n,
	window.wp.blockEditor,
	window.wp.components
);
