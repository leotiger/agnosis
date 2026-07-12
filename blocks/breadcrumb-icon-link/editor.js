/**
 * Agnosis Breadcrumb Icon Link block — editor registration.
 *
 * Vanilla JS (no build step), same pattern as blocks/newsletter-popover/editor.js
 * (whose icon-picker convention this mirrors closely). Dynamic block
 * (server-side rendered via PHP render_callback) — save() returns null.
 *
 * 2026-07-10: split out of `agnosis/artist-breadcrumb` (which still exists,
 * unchanged, for sites whose customized header template already has it) as
 * part of a move toward small, single-purpose, real-WP-block-supports blocks.
 * One registered block type, `type` attribute picks Biography vs Events (like
 * `core/social-link`'s single block type + `service` attribute) — surfaced as
 * two named block variations below so the inserter shows "Biography Icon" and
 * "Events Icon" as distinct entries, same UX core/social-link itself uses for
 * "Twitter", "Facebook", etc.
 *
 * Only the icon *glyph* (`icon` attribute) needs a custom control — there's no
 * WP core equivalent for "which of these hand-drawn shapes." Size and color
 * are ordinary block *supports* (Typography → Font Size, Color → Text) instead:
 * the frontend SVG is sized in `1em` (see PHP render_callback), so the
 * standard Font Size control directly controls icon size, and stroke="currentColor"
 * means the standard Text Color control directly recolors it — no bespoke
 * Range/ColorPalette controls needed the way `artist-breadcrumb`'s
 * `iconSize`/`iconColor` attributes required.
 */
( function ( blocks, element, i18n, blockEditor, components ) {

	var el                = element.createElement;
	var Fragment          = element.Fragment;
	var __                = i18n.__;
	var useBlockProps     = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody         = components.PanelBody;
	var SelectControl     = components.SelectControl;

	// Mirrors Agnosis\Network\SubdomainNavigation::LINK_ICON_SETS on the PHP
	// side — kept in sync by hand since this is a vanilla-JS, no-build-step
	// block (same tradeoff Newsletter\PopoverBlock's own icon picker already
	// made). Inner markup only (path/circle/rect/line elements), same 24x24
	// viewBox convention as the frontend SVG.
	var ICON_SETS = {
		biography: {
			book: '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>',
			user: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
		},
		events: {
			calendar: '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
			pin:      '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle>',
		},
		// 2026-07-12 — mirrors Agnosis\Network\SubdomainNavigation::LINK_ICON_SETS's
		// own 'contact' entry (kept in sync by hand, same tradeoff as the two
		// sets above). The editor preview still renders as a plain icon link
		// like biography/events — the popover trigger/panel behaviour only
		// exists in the PHP render_callback's frontend output.
		contact: {
			mail:    '<rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="m2 6 10 7 10-7"></path>',
			message: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>',
		},
	};

	var ICON_OPTIONS = {
		biography: [
			{ label: __( 'Book', 'agnosis' ), value: 'book' },
			{ label: __( 'Person', 'agnosis' ), value: 'user' },
		],
		events: [
			{ label: __( 'Calendar', 'agnosis' ), value: 'calendar' },
			{ label: __( 'Pin', 'agnosis' ), value: 'pin' },
		],
		contact: [
			{ label: __( 'Envelope', 'agnosis' ), value: 'mail' },
			{ label: __( 'Speech bubble', 'agnosis' ), value: 'message' },
		],
	};

	var DEFAULT_ICON = { biography: 'book', events: 'calendar', contact: 'mail' };

	var LABELS = {
		biography: __( 'Biography', 'agnosis' ),
		events:    __( 'Events', 'agnosis' ),
		contact:   __( 'Contact', 'agnosis' ),
	};

	blocks.registerBlockType( 'agnosis/breadcrumb-icon-link', {

		edit: function ( props ) {
			var type = ICON_SETS[ props.attributes.type ] ? props.attributes.type : 'biography';
			var icon = props.attributes.icon || DEFAULT_ICON[ type ];
			var set  = ICON_SETS[ type ] || {};

			var blockProps = useBlockProps( {
				href: '#',
				title: LABELS[ type ],
				onClick: function ( event ) {
					event.preventDefault();
				},
				style: { display: 'inline-flex', alignItems: 'center', lineHeight: 0 },
			} );

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Link settings', 'agnosis' ) },
						el( SelectControl, {
							label:    __( 'Link type', 'agnosis' ),
							value:    type,
							options:  [
								{ label: __( 'Biography', 'agnosis' ), value: 'biography' },
								{ label: __( 'Events', 'agnosis' ), value: 'events' },
								{ label: __( 'Contact', 'agnosis' ), value: 'contact' },
							],
							onChange: function ( value ) {
								// Reset icon to the new type's default rather than
								// keeping a glyph that belongs to the old type.
								props.setAttributes( { type: value, icon: '' } );
							},
						} ),
						el( SelectControl, {
							label:    __( 'Icon', 'agnosis' ),
							value:    icon,
							options:  ICON_OPTIONS[ type ],
							onChange: function ( value ) {
								props.setAttributes( { icon: value } );
							},
						} )
					)
				),
				el( 'a', blockProps, el( 'svg', {
					width:                   '1em',
					height:                  '1em',
					viewBox:                 '0 0 24 24',
					fill:                    'none',
					stroke:                  'currentColor',
					strokeWidth:             1.5,
					'aria-hidden':           true,
					focusable:               false,
					dangerouslySetInnerHTML: { __html: set[ icon ] || set[ DEFAULT_ICON[ type ] ] || '' },
				} ) )
			);
		},

		save: function () {
			return null;
		},

	} );

	// Named inserter entries, same UX core/social-link uses for "Twitter",
	// "Facebook", etc. — one registered block type, `type` attribute picks
	// the variant. Users can still insert the plain "Breadcrumb Icon Link"
	// and switch `type` from the Inspector instead; these are just a
	// friendlier default entry point.
	blocks.registerBlockVariation( 'agnosis/breadcrumb-icon-link', {
		name:       'biography',
		title:      __( 'Biography Icon', 'agnosis' ),
		description: __( 'Icon-only link to the artist’s biography.', 'agnosis' ),
		icon:       'book-alt',
		attributes: { type: 'biography' },
		isActive:   [ 'type' ],
		scope:      [ 'inserter' ],
	} );

	blocks.registerBlockVariation( 'agnosis/breadcrumb-icon-link', {
		name:       'events',
		title:      __( 'Events Icon', 'agnosis' ),
		description: __( 'Icon-only link to the artist’s events archive.', 'agnosis' ),
		icon:       'calendar-alt',
		attributes: { type: 'events' },
		isActive:   [ 'type' ],
		scope:      [ 'inserter' ],
	} );

	blocks.registerBlockVariation( 'agnosis/breadcrumb-icon-link', {
		name:       'contact',
		title:      __( 'Contact Icon', 'agnosis' ),
		description: __( 'Opens a popover with a form to message the artist directly. Hidden automatically if the artist has turned contact messages off.', 'agnosis' ),
		icon:       'email',
		attributes: { type: 'contact' },
		isActive:   [ 'type' ],
		scope:      [ 'inserter' ],
	} );

} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.i18n,
	window.wp.blockEditor,
	window.wp.components
);
