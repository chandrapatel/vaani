/**
 * Vaani — block-editor plugin sidebar.
 *
 * One "Vaani" sidebar (opened from a translation-dashicon toolbar icon) with a
 * Translations section and an Audio section. Status is seeded from the localized
 * payload and refreshed/queued over the `vaani/v1` REST routes (apiFetch handles
 * the nonce). Replaces the former per-post "Translate into" panel and the
 * translation/audio meta boxes.
 */
import { registerPlugin } from '@wordpress/plugins';
import {
	PluginSidebar,
	PluginSidebarMoreMenuItem,
} from '@wordpress/edit-post';
import {
	PanelBody,
	Button,
	Spinner,
	ExternalLink,
	Notice,
} from '@wordpress/components';
import { useState, useCallback, createInterpolateElement } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const SIDEBAR_NAME = 'vaani-sidebar';
const config = window.vaaniSidebar || {};
const restPaths = config.rest || {};

const TRANSLATION_LABELS = {
	none: __( 'Not translated', 'vaani' ),
	pending: __( 'Queued…', 'vaani' ),
	completed: __( 'Translated', 'vaani' ),
	failed: __( 'Failed', 'vaani' ),
};

const AUDIO_LABELS = {
	none: __( 'Not generated', 'vaani' ),
	pending: __( 'Queued…', 'vaani' ),
	completed: __( 'Generated', 'vaani' ),
	failed: __( 'Failed', 'vaani' ),
};

const rowStyle = {
	display: 'flex',
	flexDirection: 'column',
	gap: '4px',
	padding: '12px 0',
	borderTop: '1px solid #f0f0f0',
};

const metaStyle = {
	display: 'flex',
	alignItems: 'center',
	flexWrap: 'wrap',
	gap: '8px',
	justifyContent: 'space-between',
};

/**
 * The status line for one language row: state label, stale badge, error text.
 */
const StatusLine = ( { row, labels } ) => (
	<span>
		<span style={ row.status === 'failed' ? { color: '#b32d2e' } : undefined }>
			{ labels[ row.status ] || row.status }
		</span>
		{ row.stale && (
			<em style={ { color: '#bd8600' } }> { __( '(stale)', 'vaani' ) }</em>
		) }
		{ row.status === 'failed' && row.error && (
			<span style={ { display: 'block', color: '#b32d2e' } }>
				{ row.error }
			</span>
		) }
	</span>
);

/**
 * Header row with a Refresh control that re-fetches a section over REST.
 */
const SectionHeader = ( { onRefresh, refreshing } ) => (
	<div style={ { display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '4px' } }>
		<Button variant="link" onClick={ onRefresh } disabled={ refreshing }>
			{ __( 'Refresh', 'vaani' ) }
		</Button>
		{ refreshing && <Spinner /> }
	</div>
);

/**
 * Shared queue/refresh state machine for a section.
 *
 * @param {string} path     REST base path for the section.
 * @param {Object} seed     Initial data from the localized payload.
 * @param {number} postId   Current post ID.
 */
const useSection = ( path, seed, postId ) => {
	const [ data, setData ] = useState( seed );
	const [ refreshing, setRefreshing ] = useState( false );
	const [ busy, setBusy ] = useState( {} );
	const [ notice, setNotice ] = useState( '' );

	const refresh = useCallback( () => {
		setRefreshing( true );
		setNotice( '' );
		apiFetch( { path: `${ path }?post=${ postId }` } )
			.then( setData )
			.catch( ( error ) => setNotice( error.message || __( 'Could not load status.', 'vaani' ) ) )
			.finally( () => setRefreshing( false ) );
	}, [ path, postId ] );

	const queue = useCallback(
		( code ) => {
			setBusy( ( current ) => ( { ...current, [ code ]: true } ) );
			setNotice( '' );
			apiFetch( {
				path,
				method: 'POST',
				data: { post: postId, lang: code },
			} )
				.then( ( row ) => {
					setData( ( current ) => ( {
						...current,
						languages: current.languages.map( ( language ) =>
							language.code === row.code ? row : language
						),
					} ) );
				} )
				.catch( ( error ) =>
					setNotice( error.message || __( 'Something went wrong. Please try again.', 'vaani' ) )
				)
				.finally( () =>
					setBusy( ( current ) => ( { ...current, [ code ]: false } ) )
				);
		},
		[ path, postId ]
	);

	return { data, refreshing, busy, notice, refresh, queue };
};

/**
 * Translations section: every enabled target language plus the cost estimate.
 */
const TranslationsSection = ( { postId } ) => {
	const { data, refreshing, busy, notice, refresh, queue } = useSection(
		restPaths.translations,
		config.translations || { languages: [], cost: {} },
		postId
	);

	if ( ! data.languages.length ) {
		return (
			<p>
				{ createInterpolateElement(
					__(
						'No target languages are enabled yet. <a>Enable languages in Vaani settings</a>.',
						'vaani'
					),
					{
						// eslint-disable-next-line jsx-a11y/anchor-has-content
						a: <a href={ config.settingsUrl } />,
					}
				) }
			</p>
		);
	}

	return (
		<>
			<p className="description">
				{ __(
					'Translations use the last saved content. Save the post first if you have unsaved changes.',
					'vaani'
				) }
			</p>
			<SectionHeader onRefresh={ refresh } refreshing={ refreshing } />
			{ notice && (
				<Notice status="error" isDismissible={ false }>
					{ notice }
				</Notice>
			) }
			{ data.languages.map( ( row ) => (
				<div key={ row.code } style={ rowStyle }>
					<strong>{ row.label }</strong>
					<StatusLine row={ row } labels={ TRANSLATION_LABELS } />
					<div style={ metaStyle }>
						{ row.editUrl ? (
							<ExternalLink href={ row.editUrl }>
								{ __( 'Edit', 'vaani' ) }
							</ExternalLink>
						) : (
							<span />
						) }
						<Button
							variant="secondary"
							isBusy={ !! busy[ row.code ] }
							disabled={ !! busy[ row.code ] }
							onClick={ () => queue( row.code ) }
						>
							{ row.exists
								? __( 'Re-translate', 'vaani' )
								: __( 'Translate now', 'vaani' ) }
						</Button>
					</div>
				</div>
			) ) }
			{ data.cost && data.cost.operations > 0 && (
				<p className="description">{ data.cost.text }</p>
			) }
		</>
	);
};

/**
 * Audio section: the original plus each published translation.
 */
const AudioSection = ( { postId } ) => {
	const { data, refreshing, busy, notice, refresh, queue } = useSection(
		restPaths.audio,
		config.audio || { languages: [] },
		postId
	);

	return (
		<>
			<p className="description">
				{ __(
					'Generate spoken audio of the original and each published translation.',
					'vaani'
				) }
			</p>
			<SectionHeader onRefresh={ refresh } refreshing={ refreshing } />
			{ notice && (
				<Notice status="error" isDismissible={ false }>
					{ notice }
				</Notice>
			) }
			{ data.languages.map( ( row ) => (
				<div key={ row.code } style={ rowStyle }>
					<strong>{ row.label }</strong>
					<StatusLine row={ row } labels={ AUDIO_LABELS } />
					<div style={ metaStyle }>
						{ row.url ? (
							<ExternalLink href={ row.url }>
								{ __( 'File', 'vaani' ) }
							</ExternalLink>
						) : (
							<span />
						) }
						<Button
							variant="secondary"
							isBusy={ !! busy[ row.code ] }
							disabled={ !! busy[ row.code ] }
							onClick={ () => queue( row.code ) }
						>
							{ row.exists
								? __( 'Regenerate', 'vaani' )
								: __( 'Generate', 'vaani' ) }
						</Button>
					</div>
				</div>
			) ) }
		</>
	);
};

const VaaniSidebar = () => {
	const { postId, isNew } = useSelect(
		( select ) => ( {
			postId: select( editorStore ).getCurrentPostId(),
			isNew: select( editorStore ).isEditedPostNew(),
		} ),
		[]
	);

	return (
		<>
			<PluginSidebarMoreMenuItem target={ SIDEBAR_NAME } icon="translation">
				{ __( 'Vaani', 'vaani' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name={ SIDEBAR_NAME }
				title={ __( 'Vaani', 'vaani' ) }
				icon="translation"
			>
				{ isNew ? (
					<PanelBody>
						<p>
							{ __(
								'Save the post first to translate it or generate audio.',
								'vaani'
							) }
						</p>
					</PanelBody>
				) : (
					<>
						<PanelBody
							title={ __( 'Translations', 'vaani' ) }
							initialOpen={ true }
						>
							<TranslationsSection postId={ postId } />
						</PanelBody>
						<PanelBody
							title={ __( 'Audio', 'vaani' ) }
							initialOpen={ false }
						>
							<AudioSection postId={ postId } />
						</PanelBody>
					</>
				) }
			</PluginSidebar>
		</>
	);
};

registerPlugin( SIDEBAR_NAME, { render: VaaniSidebar, icon: 'translation' } );
