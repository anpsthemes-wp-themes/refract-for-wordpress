/**
 * WordPress dependencies
 */
import {
	Button,
	CheckboxControl,
	Notice,
	RadioControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { errorMessage, request } from '../common/api';
import { groupAccounts } from '../common/copy';
import { NetworkMark } from '../common/marks';

/**
 * Runs one request at a time and remembers its error.
 *
 * @return {Object} `{busy, error, run, setError}`.
 */
export function useAction() {
	const [ busy, setBusy ] = useState( null );
	const [ error, setError ] = useState( null );

	const run = ( name, promise ) => {
		setBusy( name );
		setError( null );

		return promise()
			.catch( ( reason ) => {
				setError( errorMessage( reason ) );

				return null;
			} )
			.finally( () => setBusy( null ) );
	};

	return { busy, error, run, setError };
}

/**
 * The "Send posts to Refract" choices.
 *
 * @return {Array<{label: string, value: string, description: string}>} Options.
 */
export function sendModeOptions() {
	return [
		{
			label: __( 'When a post is published', 'refract' ),
			description: __(
				'Every post you publish goes to Refract.',
				'refract'
			),
			value: 'publish',
		},
		{
			label: __( 'Only when I choose, from the editor', 'refract' ),
			description: __(
				'Choose Send to Refract in the post’s Refract panel.',
				'refract'
			),
			value: 'manual',
		},
	];
}

/**
 * A small label next to a choice: Beta, Free, the credits it uses.
 *
 * @param {Object}                    props
 * @param {'cost'|'free'|undefined}   props.tone     Colour of the label.
 * @param {import('react').ReactNode} props.children The text.
 * @return {Element} The label.
 */
export function Tag( { tone, children } ) {
	return (
		<span className={ tone ? `refract-tag is-${ tone }` : 'refract-tag' }>
			{ children }
		</span>
	);
}

/**
 * Radio choices shown as cards, each with an optional description and tag.
 * The group's label is for screen readers: the row or step around it shows
 * the same words.
 *
 * @param {Object}                                                                                            props
 * @param {string}                                                                                            props.label    Group label.
 * @param {string}                                                                                            [props.help]   Help under the choices.
 * @param {string}                                                                                            props.selected Chosen value.
 * @param {Array<{label: string, value: string, description?: string, tag?: {label: string, tone?: string}}>} props.options  Choices.
 * @param {(value: string) => void}                                                                           props.onChange Called with the chosen value.
 * @return {Element} The choices.
 */
export function ChoiceControl( { label, help, selected, options, onChange } ) {
	return (
		<RadioControl
			className="refract-choices"
			label={ label }
			hideLabelFromVision
			help={ help }
			selected={ selected }
			options={ options.map( ( option ) => ( {
				label: option.label,
				value: option.value,
				description: ( option.description || option.tag ) && (
					<>
						{ option.description && (
							<span>{ option.description }</span>
						) }
						{ option.tag && (
							<Tag tone={ option.tag.tone }>
								{ option.tag.label }
							</Tag>
						) }
					</>
				),
			} ) ) }
			onChange={ onChange }
		/>
	);
}

/**
 * Starts the OAuth connection: asks the site for the authorize URL and goes
 * there.
 *
 * @return {Promise<void>} Resolves when the browser starts leaving.
 */
export function startConnect() {
	return request( '/connect', { method: 'POST' } ).then( ( result ) => {
		window.location.assign( result.authorize_url );
	} );
}

/**
 * Account cards, grouped by network, main channel first with a star, with a
 * count and Select all / Select none.
 *
 * @param {Object}                  props
 * @param {Object[]}                props.accounts Accounts from AdminState.
 * @param {string[]}                props.value    Chosen account IDs.
 * @param {(ids: string[]) => void} props.onChange Called with the new list of IDs.
 * @return {Element} The field.
 */
export function AccountsField( { accounts, value, onChange } ) {
	const groups = groupAccounts( accounts );
	const selectable = groups
		.flatMap( ( group ) => group.accounts )
		.filter( ( account ) => account.availability !== 'soon' )
		.map( ( account ) => account.id );
	const chosen = selectable.filter( ( id ) => value.includes( id ) ).length;

	const toggle = ( id, checked ) =>
		onChange(
			checked ? [ ...value, id ] : value.filter( ( item ) => item !== id )
		);

	return (
		<fieldset className="refract-accounts">
			<legend className="refract-accounts__legend">
				{ __( 'Which accounts get a version of each post', 'refract' ) }
			</legend>
			{ groups.length === 0 ? (
				<p className="refract-help">
					{ __(
						'There are no accounts in this workspace yet. Connect one in Refract, then come back.',
						'refract'
					) }
				</p>
			) : (
				<div className="refract-accounts__bar">
					<span className="refract-accounts__count">
						{ sprintf(
							/* translators: 1: accounts chosen, 2: accounts that can be chosen. */
							_n(
								'%1$d of %2$d account chosen',
								'%1$d of %2$d accounts chosen',
								selectable.length,
								'refract'
							),
							chosen,
							selectable.length
						) }
					</span>
					<Button
						variant="link"
						disabled={ chosen === selectable.length }
						accessibleWhenDisabled
						onClick={ () =>
							onChange( [
								...value,
								...selectable.filter(
									( id ) => ! value.includes( id )
								),
							] )
						}
					>
						{ __( 'Select all', 'refract' ) }
					</Button>
					<Button
						variant="link"
						disabled={ value.length === 0 }
						accessibleWhenDisabled
						onClick={ () => onChange( [] ) }
					>
						{ __( 'Select none', 'refract' ) }
					</Button>
				</div>
			) }
			<div className="refract-accounts__groups">
				{ groups.map( ( group ) => (
					<fieldset
						key={ group.network }
						className="refract-accounts__group"
					>
						<legend className="refract-accounts__network">
							<NetworkMark
								network={ group.network }
								size={ 16 }
							/>
							<span>{ group.label }</span>
							{ group.availability === 'beta' && (
								<Tag>{ __( 'Beta', 'refract' ) }</Tag>
							) }
							{ group.availability === 'soon' && (
								<Tag>{ __( 'Coming soon', 'refract' ) }</Tag>
							) }
						</legend>
						<div className="refract-accounts__list">
							{ group.accounts.map( ( account ) => (
								<CheckboxControl
									key={ account.id }
									className="refract-account"
									__nextHasNoMarginBottom
									checked={ value.includes( account.id ) }
									disabled={
										! value.includes( account.id ) &&
										account.availability === 'soon'
									}
									onChange={ ( checked ) =>
										toggle( account.id, checked )
									}
									label={
										<>
											{ account.name }
											{ account.handle && (
												<span className="refract-accounts__handle">
													{ ` ${ account.handle }` }
												</span>
											) }
											{ account.main && (
												<span
													className="refract-accounts__star"
													title={ __(
														'Main channel',
														'refract'
													) }
												>
													{ ' ★' }
													<span className="screen-reader-text">
														{ __(
															'Main channel',
															'refract'
														) }
													</span>
												</span>
											) }
										</>
									}
								/>
							) ) }
						</div>
					</fieldset>
				) ) }
			</div>
		</fieldset>
	);
}

/**
 * The paste field for an API token.
 *
 * @param {Object}                  props
 * @param {Object}                  props.links       AdminState links.
 * @param {(state: Object) => void} props.onConnected Called with the new AdminState.
 * @return {Element} The form.
 */
export function TokenForm( { links, onConnected } ) {
	const [ token, setToken ] = useState( '' );
	const { busy, error, run } = useAction();

	const submit = ( event ) => {
		event.preventDefault();
		run( 'token', () =>
			request( '/connect-token', { data: { token: token.trim() } } ).then(
				onConnected
			)
		);
	};

	return (
		<form className="refract-token" onSubmit={ submit }>
			<p>
				{ __(
					'Use this when Refract can’t send you back to this site after you sign in, for example on a local site or a site behind a login.',
					'refract'
				) }
			</p>
			<p>
				{ __(
					'In Refract, go to Settings › API and agents, create a token, and paste it here.',
					'refract'
				) }{ ' ' }
				{ links?.tokens && (
					<a
						href={ links.tokens }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Open API and agents', 'refract' ) }
						<span className="screen-reader-text">
							{
								/* translators: accessibility text */
								__( '(opens in a new tab)', 'refract' )
							}
						</span>
					</a>
				) }
			</p>
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="password"
				autoComplete="off"
				label={ __( 'API token', 'refract' ) }
				value={ token }
				onChange={ setToken }
			/>
			<Button
				variant="secondary"
				type="submit"
				isBusy={ busy === 'token' }
				disabled={ ! token.trim() || Boolean( busy ) }
				accessibleWhenDisabled
			>
				{ __( 'Connect with this token', 'refract' ) }
			</Button>
		</form>
	);
}

/**
 * Warnings for a connection that needs reconnecting, or a site that isn't
 * registered in its workspace yet.
 *
 * @param {Object}                  props
 * @param {Object}                  props.state   AdminState.
 * @param {(state: Object) => void} props.onState Called with a new AdminState.
 * @return {Element|null} The warning.
 */
export function ConnectionWarning( { state, onState } ) {
	const { connection } = state;
	const { busy, error, run } = useAction();
	const [ showToken, setShowToken ] = useState( false );

	if ( connection.status === 'needs_reconnect' ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				<p>
					{ __(
						'Refract needs you to connect again. Your site can’t send posts until you do.',
						'refract'
					) }
				</p>
				{ error && <p>{ error }</p> }
				<Button
					variant="primary"
					isBusy={ busy === 'reconnect' }
					disabled={ Boolean( busy ) }
					accessibleWhenDisabled
					onClick={ () =>
						connection.kind === 'token'
							? setShowToken( true )
							: run( 'reconnect', startConnect )
					}
				>
					{ __( 'Reconnect', 'refract' ) }
				</Button>
				{ showToken && (
					<TokenForm links={ state.links } onConnected={ onState } />
				) }
			</Notice>
		);
	}

	// With several workspaces and none picked yet, the setup's Workspace field asks; nothing went wrong.
	const awaitingChoice =
		! connection.workspace &&
		! connection.error &&
		connection.workspaces.length > 1;

	if ( connection.status === 'unregistered' && ! awaitingChoice ) {
		const workspace = connection.workspace || connection.workspaces[ 0 ];

		return (
			<Notice status="error" isDismissible={ false }>
				<p>
					{ workspace
						? sprintf(
								/* translators: %s: workspace name. */
								__(
									'Your site isn’t connected to %s in Refract yet, so it can’t send posts.',
									'refract'
								),
								workspace.name
							)
						: __(
								'Your site isn’t connected to a workspace in Refract yet, so it can’t send posts.',
								'refract'
							) }
				</p>
				{ ( error || connection.error ) && (
					<p>{ error || connection.error }</p>
				) }
				{ workspace && (
					<Button
						variant="secondary"
						isBusy={ busy === 'retry' }
						disabled={ Boolean( busy ) }
						accessibleWhenDisabled
						onClick={ () =>
							run( 'retry', () =>
								request( '/workspace', {
									data: { workspace_id: workspace.id },
								} ).then( onState )
							)
						}
					>
						{ __( 'Try again', 'refract' ) }
					</Button>
				) }
			</Notice>
		);
	}

	return null;
}
