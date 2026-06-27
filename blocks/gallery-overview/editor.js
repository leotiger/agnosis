/**
 * Gallery Overview block — editor registration.
 *
 * Server-side rendered; the editor shows a placeholder with block controls
 * for columns count.
 */
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, RangeControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';

registerBlockType( metadata.name, {
	edit( { attributes, setAttributes } ) {
		const { columns } = attributes;
		const blockProps = useBlockProps( {
			style: { padding: '2rem', border: '1px dashed #555', textAlign: 'center', background: '#111' },
		} );

		return (
			<>
				<InspectorControls>
					<PanelBody title={ __( 'Gallery settings', 'agnosis' ) }>
						<RangeControl
							label={ __( 'Columns', 'agnosis' ) }
							value={ columns }
							onChange={ ( value ) => setAttributes( { columns: value } ) }
							min={ 2 }
							max={ 5 }
						/>
					</PanelBody>
				</InspectorControls>
				<div { ...blockProps }>
					<p style={ { color: '#ededf0', fontFamily: 'sans-serif', margin: 0 } }>
						{ __( '✦ Agnosis Gallery Overview — rendered on the server', 'agnosis' ) }
					</p>
					<p style={ { color: '#888', fontSize: '0.85rem', margin: '0.5rem 0 0' } }>
						{ `${ columns } columns · proportional · random daily order` }
					</p>
				</div>
			</>
		);
	},
	save: () => null,
} );
