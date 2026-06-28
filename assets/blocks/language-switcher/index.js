/**
 * Vaani — Language Switcher block (editor side).
 *
 * Dynamic block: the front-end markup is rendered in PHP from the post's
 * available translations, so the editor shows a static placeholder rather than
 * fetching live data.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

registerBlockType( metadata.name, {
	edit() {
		const blockProps = useBlockProps( {
			className: 'vaani-language-switcher vaani-language-switcher--placeholder',
		} );

		return (
			<div { ...blockProps }>
				{ __(
					'Vaani Language Switcher — links appear here on the front end.',
					'vaani'
				) }
			</div>
		);
	},
	save() {
		return null;
	},
} );
