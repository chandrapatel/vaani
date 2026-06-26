/**
 * Vaani — per-post target-language selection panel.
 *
 * Adds a "Translate into" panel to the block-editor document sidebar listing
 * the globally enabled languages as checkboxes. Selections are stored in the
 * `_vaani_target_langs` post meta.
 */
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';
import { CheckboxControl } from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const config = window.vaaniLanguagePanel || {};
const languages = config.languages || {};
const metaKey = config.metaKey;
const languageCodes = Object.keys( languages );

const LanguagePanel = () => {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);

	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
	const selected = ( meta && meta[ metaKey ] ) || [];

	const toggle = ( code, checked ) => {
		const next = checked
			? [ ...selected, code ]
			: selected.filter( ( value ) => value !== code );

		setMeta( { ...meta, [ metaKey ]: next } );
	};

	return (
		<PluginDocumentSettingPanel
			name="vaani-target-languages"
			title={ __( 'Translate into', 'vaani' ) }
		>
			{ languageCodes.length === 0 ? (
				<p>
					{ createInterpolateElement(
						__(
							'No target languages are enabled yet. <a>Enable languages in Vaani settings</a>.',
							'vaani'
						),
						{
							a: (
								// eslint-disable-next-line jsx-a11y/anchor-has-content
								<a href={ config.settingsUrl } />
							),
						}
					) }
				</p>
			) : (
				languageCodes.map( ( code ) => (
					<CheckboxControl
						key={ code }
						label={ languages[ code ] }
						checked={ selected.includes( code ) }
						onChange={ ( checked ) => toggle( code, checked ) }
					/>
				) )
			) }
		</PluginDocumentSettingPanel>
	);
};

registerPlugin( 'vaani-language-panel', { render: LanguagePanel } );
