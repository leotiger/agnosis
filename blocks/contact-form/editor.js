/**
 * Agnosis Contact Form block — editor registration.
 *
 * Vanilla JS (no build step), same pattern as blocks/newsletter-signup/editor.js.
 * Dynamic block (server-side rendered via PHP render_callback) — save() returns null.
 */
( function ( blocks, element, i18n, blockEditor, components ) {

	var el            = element.createElement;
	var __            = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var Placeholder   = components.Placeholder;

	blocks.registerBlockType( 'agnosis/contact-form', {

		edit: function () {
			var blockProps = useBlockProps( {
				className: 'agnosis-contact-form-editor',
			} );

			return el(
				'div',
				blockProps,
				el(
					Placeholder,
					{
						icon:         'email',
						label:        __( 'Artist Contact Form', 'agnosis' ),
						instructions: __( 'Lets a visitor message the current artist subdomain’s artist. Content is rendered live on the frontend, for the artist currently being viewed.', 'agnosis' ),
					}
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
