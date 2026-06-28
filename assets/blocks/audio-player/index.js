/**
 * Vaani — Audio Player block (editor side).
 *
 * Dynamic block: the front-end markup is rendered in PHP from the post's
 * generated audio for the language being viewed, so the editor shows a static
 * placeholder rather than fetching live data.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps } from '@wordpress/block-editor';
import { __ } from '@wordpress/i18n';

import metadata from './block.json';

registerBlockType( metadata.name, {
	edit() {
		const blockProps = useBlockProps( {
			className: 'vaani-audio-player vaani-audio-player--placeholder',
		} );

		return (
			<div { ...blockProps }>
				{ __(
					'Vaani Audio Player — the “Listen” player appears here on the front end.',
					'vaani'
				) }
			</div>
		);
	},
	save() {
		return null;
	},
} );
