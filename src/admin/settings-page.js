/**
 * WordPress dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardFooter,
	CardHeader,
	ExternalLink,
	Notice,
	Panel,
	PanelBody,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { errorMessage, request } from '../common/api';
import {
	AccountsField,
	ChoiceControl,
	ConnectionWarning,
	TokenForm,
	sendModeOptions,
	startConnect,
	useAction,
} from './fields';
import ConnectedSettings from './settings';

/**
 * The first run: connect with OAuth, or with a token.
 *
 * @param {Object}                  props
 * @param {Object}                  props.state   AdminState.
 * @param {(state: Object) => void} props.onState Called with a new AdminState.
 * @return {Element} The screen.
 */
function FirstRun( { state, onState } ) {
	const { busy, error, run } = useAction();

	return (
		<Card className="refract-first-run">
			<CardBody>
				<h2>{ __( 'Connect your site to Refract', 'refract' ) }</h2>
				<p>
					{ __(
						'Refract turns your blog posts into posts for each social network, in your voice. You approve every one before it goes out.',
						'refract'
					) }
				</p>
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<div className="refract-first-run__actions">
					<Button
						variant="primary"
						__next40pxDefaultSize
						isBusy={ busy === 'connect' }
						disabled={ Boolean( busy ) }
						accessibleWhenDisabled
						onClick={ () => run( 'connect', startConnect ) }
					>
						{ __( 'Connect to Refract', 'refract' ) }
					</Button>
					{ state.links?.signup && (
						<ExternalLink href={ state.links.signup }>
							{ __(
								'Don’t have an account? Start a free trial',
								'refract'
							) }
						</ExternalLink>
					) }
				</div>
			</CardBody>
			<Panel>
				<PanelBody
					title={ __( 'Connect with a token instead', 'refract' ) }
					initialOpen={ false }
				>
					<TokenForm links={ state.links } onConnected={ onState } />
				</PanelBody>
			</Panel>
		</Card>
	);
}

/**
 * The short setup after connecting: workspace, accounts, when to send.
 *
 * @param {Object}                  props
 * @param {Object}                  props.state   AdminState.
 * @param {(state: Object) => void} props.onState Called with a new AdminState.
 * @return {Element} The screen.
 */
function Setup( { state, onState } ) {
	const { connection, settings } = state;
	const selectable = state.accounts.filter(
		( account ) =>
			account.network !== 'wordpress' && account.availability !== 'soon'
	);
	const [ accounts, setAccounts ] = useState(
		settings.accounts.length
			? settings.accounts
			: selectable.map( ( account ) => account.id )
	);
	const [ touched, setTouched ] = useState( false );
	const accountIds = selectable.map( ( account ) => account.id ).join( ',' );

	useEffect( () => {
		if ( ! touched && ! settings.accounts.length && accountIds ) {
			setAccounts( accountIds.split( ',' ) );
		}
	}, [ accountIds, touched, settings.accounts.length ] );
	const [ sendMode, setSendMode ] = useState( settings.send_mode );
	const { busy, error, run } = useAction();

	const changeWorkspace = ( workspaceId ) =>
		run( 'workspace', () =>
			request( '/workspace', {
				data: { workspace_id: workspaceId },
			} ).then( ( next ) => {
				setAccounts(
					next.accounts
						.filter(
							( account ) =>
								account.network !== 'wordpress' &&
								account.availability !== 'soon'
						)
						.map( ( account ) => account.id )
				);
				onState( next );
			} )
		);

	const save = () =>
		run( 'save', () =>
			request( '/settings', {
				data: {
					settings: {
						accounts,
						send_mode: sendMode,
						setup_done: true,
					},
				},
			} ).then( onState )
		);

	const steps = [];

	if ( connection.workspaces.length > 1 ) {
		steps.push( {
			key: 'workspace',
			title: __( 'Workspace', 'refract' ),
			content: (
				<div className="refract-setup__workspace">
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Workspace', 'refract' ) }
						hideLabelFromVision
						value={ connection.workspace?.id || '' }
						disabled={ Boolean( busy ) }
						options={ [
							...( connection.workspace
								? []
								: [
										{
											label: __(
												'Choose a workspace',
												'refract'
											),
											value: '',
											disabled: true,
										},
									] ),
							...connection.workspaces.map( ( workspace ) => ( {
								label: workspace.name,
								value: workspace.id,
							} ) ),
						] }
						onChange={ changeWorkspace }
					/>
					{ busy === 'workspace' && <Spinner /> }
				</div>
			),
		} );
	}

	steps.push(
		{
			key: 'accounts',
			title: __( 'Accounts', 'refract' ),
			content: connection.workspace ? (
				<AccountsField
					accounts={ state.accounts }
					value={ accounts }
					onChange={ ( ids ) => {
						setTouched( true );
						setAccounts( ids );
					} }
				/>
			) : (
				<p className="refract-help">
					{ __(
						'Choose a workspace to see its accounts.',
						'refract'
					) }
				</p>
			),
		},
		{
			key: 'send',
			title: __( 'When to send posts to Refract', 'refract' ),
			content: (
				<ChoiceControl
					label={ __( 'When to send posts to Refract', 'refract' ) }
					selected={ sendMode }
					options={ sendModeOptions() }
					onChange={ setSendMode }
				/>
			),
		}
	);

	return (
		<Card className="refract-setup">
			<CardHeader>
				<h2>{ __( 'Set up Refract', 'refract' ) }</h2>
				<span className="refract-section__summary">
					{ __( 'You can change each of these later.', 'refract' ) }
				</span>
			</CardHeader>
			<CardBody>
				<ConnectionWarning state={ state } onState={ onState } />
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				<ol className="refract-steps">
					{ steps.map( ( step, index ) => (
						<li key={ step.key } className="refract-step">
							<h3 className="refract-step__title">
								<span
									className="refract-step__number"
									aria-hidden="true"
								>
									{ index + 1 }
								</span>
								{ step.title }
							</h3>
							<div className="refract-step__content">
								{ step.content }
							</div>
						</li>
					) ) }
				</ol>
			</CardBody>
			<CardFooter>
				<Button
					variant="primary"
					__next40pxDefaultSize
					isBusy={ busy === 'save' }
					disabled={
						Boolean( busy ) ||
						! connection.workspace ||
						( selectable.length > 0 && accounts.length === 0 )
					}
					accessibleWhenDisabled
					onClick={ save }
				>
					{ __( 'Save and finish', 'refract' ) }
				</Button>
			</CardFooter>
		</Card>
	);
}

/**
 * The Settings page: first run, setup, or the settings.
 *
 * @param {Object}      props
 * @param {Object|null} props.notice Notice from the server (after connecting).
 * @return {Element} The page.
 */
export default function SettingsPage( { notice } ) {
	const [ state, setState ] = useState( null );
	const [ loadError, setLoadError ] = useState( null );
	const [ serverNotice, setServerNotice ] = useState( notice );

	useEffect( () => {
		request( '/state' ).then( setState, ( reason ) =>
			setLoadError( errorMessage( reason ) )
		);
	}, [] );

	let screen = <Spinner />;

	if ( loadError ) {
		screen = (
			<Notice status="error" isDismissible={ false }>
				{ loadError }
			</Notice>
		);
	} else if ( state && state.connection.status === 'disconnected' ) {
		screen = <FirstRun state={ state } onState={ setState } />;
	} else if ( state && ! state.settings.setup_done ) {
		screen = <Setup state={ state } onState={ setState } />;
	} else if ( state ) {
		screen = <ConnectedSettings state={ state } onState={ setState } />;
	}

	return (
		<div className="refract-admin__page">
			{ serverNotice?.message && (
				<Notice
					status={
						serverNotice.type === 'success' ? 'success' : 'error'
					}
					onRemove={ () => setServerNotice( null ) }
				>
					{ serverNotice.message }
				</Notice>
			) }
			{ screen }
		</div>
	);
}
