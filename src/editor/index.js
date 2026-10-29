/**
 * WordPress dependencies
 */
import { Button, PanelBody, ToggleControl } from '@wordpress/components';
import { useEntityProp } from '@wordpress/core-data';
import { useDispatch, useSelect } from '@wordpress/data';
import {
	PluginDocumentSettingPanel,
	PluginSidebar,
	PluginSidebarMoreMenuItem,
	store as editorStore,
} from '@wordpress/editor';
import { useEffect, useRef } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import { costLine, groupAccounts } from '../common/copy';
import { NetworkMark, RefractMark } from '../common/marks';
import PostView from '../common/post-view';
import usePostPanel from '../common/use-post-panel';
import SocialImage from './social-image';
import './index.scss';

const PLUGIN = 'refract';
const SIDEBAR = 'refract-sidebar';
const config = window.refractEditor || {};

/**
 * The post's own Refract meta, with the site defaults filled in.
 *
 * @param {Object|null} panel PostPanel.
 * @return {Object} Meta values and setters.
 */
function useRefractMeta( panel ) {
	const postType = useSelect(
		( select ) => select( editorStore ).getCurrentPostType(),
		[]
	);
	const [ meta, setMeta ] = useEntityProp( 'postType', postType, 'meta' );
	const values = meta || {};
	const stored = values._refract_send ?? '';
	const chosen = Array.isArray( values._refract_accounts )
		? values._refract_accounts
		: [];

	return {
		hasMeta: Boolean( meta ) && '_refract_send' in values,
		send: stored === '' ? Boolean( panel?.default_send ) : stored === '1',
		selected: chosen.length ? chosen : panel?.selected || [],
		setSend: ( value ) =>
			setMeta( { ...values, _refract_send: value ? '1' : '0' } ),
		setSelected: ( ids ) =>
			setMeta( { ...values, _refract_accounts: ids } ),
	};
}

/**
 * The "Send to Refract" switch, the account chips and the cost line.
 *
 * @param {Object} props
 * @param {Object} props.panel PostPanel.
 * @param {Object} props.meta  Result of useRefractMeta().
 * @return {Element} The controls.
 */
function BeforeSend( { panel, meta } ) {
	const accounts = panel.accounts.filter(
		( account ) => account.network !== 'wordpress'
	);
	const chosen = accounts.filter( ( account ) =>
		meta.selected.includes( account.id )
	);
	const sendsOnPublish = panel.send_mode === 'publish';

	const toggle = ( id ) => {
		if ( meta.selected.includes( id ) ) {
			meta.setSelected( meta.selected.filter( ( item ) => item !== id ) );
		} else {
			meta.setSelected( [ ...meta.selected, id ] );
		}
	};

	return (
		<div className="refract-before-send">
			{ sendsOnPublish && meta.hasMeta && (
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __( 'Send to Refract', 'refract' ) }
					help={ __(
						'When you publish, Refract gets this post and writes the versions. Nothing goes out until you approve it.',
						'refract'
					) }
					checked={ meta.send }
					onChange={ meta.setSend }
				/>
			) }

			{ ( meta.send || ! sendsOnPublish ) && (
				<>
					<p className="refract-label">
						{ __( 'Accounts for this post', 'refract' ) }
					</p>
					{ accounts.length === 0 ? (
						<p className="refract-help">
							{ __(
								'There are no accounts in this workspace yet.',
								'refract'
							) }
						</p>
					) : (
						<div className="refract-chips">
							{ groupAccounts( accounts ).map( ( group ) =>
								group.accounts.map( ( account ) => {
									const isChosen = meta.selected.includes(
										account.id
									);

									return (
										<Button
											key={ account.id }
											className="refract-chip"
											size="compact"
											variant="secondary"
											isPressed={ isChosen }
											disabled={
												! meta.hasMeta ||
												( isChosen &&
													meta.selected.length ===
														1 ) ||
												( ! isChosen &&
													account.availability ===
														'soon' )
											}
											accessibleWhenDisabled
											onClick={ () =>
												toggle( account.id )
											}
										>
											<NetworkMark
												network={ account.network }
												size={ 16 }
											/>
											<span>
												{ account.name }
												<span className="screen-reader-text">
													{ ` (${ account.network_label })` }
												</span>
											</span>
										</Button>
									);
								} )
							) }
						</div>
					) }
					<p className="refract-cost">
						{ costLine( {
							accounts: chosen,
							writeMode: panel.write_mode,
							ownKey: panel.own_key,
						} ) }
					</p>
				</>
			) }
		</div>
	);
}

/**
 * One line about the post's Refract state, for the summary row.
 *
 * @param {Object|null} panel PostPanel.
 * @param {Object}      meta  Result of useRefractMeta().
 * @return {string} The summary.
 */
function summary( panel, meta ) {
	if ( ! panel ) {
		return '';
	}

	if ( ! panel.connected ) {
		return __( 'Not connected', 'refract' );
	}

	if ( panel.state === 'from_refract' ) {
		return __( 'From Refract', 'refract' );
	}

	if ( ! panel.enabled ) {
		return __( 'Off for this post type', 'refract' );
	}

	const versions = panel.refract?.versions || [];

	if ( versions.length ) {
		const waiting = versions.filter( ( version ) =>
			[ 'needs_ok', 'edited' ].includes( version.status )
		).length;

		if ( versions.some( ( version ) => version.status === 'writing' ) ) {
			return __( 'Writing…', 'refract' );
		}

		return waiting
			? sprintf(
					/* translators: %d: number of versions that need approval. */
					_n(
						'%d version needs your OK',
						'%d versions need your OK',
						waiting,
						'refract'
					),
					waiting
				)
			: __( 'Sent to Refract', 'refract' );
	}

	switch ( panel.state ) {
		case 'queued':
			return __( 'Sending to Refract…', 'refract' );
		case 'retrying':
		case 'failed':
		case 'out_of_credits':
			return __( 'Couldn’t send to Refract', 'refract' );
		case 'sent':
			return __( 'Sent to Refract', 'refract' );
	}

	if ( panel.send_mode === 'manual' ) {
		return __( 'Send from the Refract panel', 'refract' );
	}

	return meta.send
		? __( 'Sent to Refract when published', 'refract' )
		: __( 'Not sent to Refract', 'refract' );
}

/**
 * The Refract sidebar and the summary row in the post settings.
 *
 * @return {Element} The plugin UI.
 */
function RefractPlugin() {
	const { postId, isSaving, isOpen } = useSelect(
		( select ) => ( {
			postId: select( editorStore ).getCurrentPostId(),
			isSaving:
				select( editorStore ).isSavingPost() &&
				! select( editorStore ).isAutosavingPost(),
			isOpen:
				select( 'core/interface' )?.getActiveComplementaryArea(
					'core'
				) === `${ PLUGIN }/${ SIDEBAR }`,
		} ),
		[]
	);
	const hook = usePostPanel( postId );
	const meta = useRefractMeta( hook.panel );
	const { enableComplementaryArea } = useDispatch( 'core/interface' );
	const { refresh } = hook;
	const wasSaving = useRef( false );

	useEffect( () => {
		if ( wasSaving.current && ! isSaving ) {
			refresh();
		}

		wasSaving.current = isSaving;
	}, [ isSaving, refresh ] );

	useEffect( () => {
		if ( isOpen ) {
			refresh();
		}
	}, [ isOpen, refresh ] );

	return (
		<>
			<PluginSidebarMoreMenuItem
				target={ SIDEBAR }
				icon={ <RefractMark /> }
			>
				{ __( 'Refract', 'refract' ) }
			</PluginSidebarMoreMenuItem>
			<PluginSidebar
				name={ SIDEBAR }
				title={ __( 'Refract', 'refract' ) }
				icon={ <RefractMark /> }
			>
				<PanelBody>
					<PostView
						panel={ hook }
						settingsUrl={ config.settingsUrl }
						canManage={ Boolean( config.canManage ) }
						beforeSend={
							hook.panel && (
								<BeforeSend
									panel={ hook.panel }
									meta={ meta }
								/>
							)
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Social preview image', 'refract' ) }
					initialOpen={ false }
				>
					<SocialImage />
				</PanelBody>
			</PluginSidebar>
			<PluginDocumentSettingPanel
				name="refract-summary"
				title={ __( 'Refract', 'refract' ) }
			>
				<p className="refract-summary">
					{ summary( hook.panel, meta ) }
				</p>
				<Button
					variant="secondary"
					size="compact"
					onClick={ () =>
						enableComplementaryArea(
							'core',
							`${ PLUGIN }/${ SIDEBAR }`
						)
					}
				>
					{ __( 'Open Refract', 'refract' ) }
				</Button>
			</PluginDocumentSettingPanel>
		</>
	);
}

registerPlugin( PLUGIN, { render: RefractPlugin } );
