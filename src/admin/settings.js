/**
 * WordPress dependencies
 */
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	ComboboxControl,
	Flex,
	FormTokenField,
	// ConfirmDialog is only exported under its experimental name.
	// eslint-disable-next-line @wordpress/no-unsafe-wp-apis
	__experimentalConfirmDialog as ConfirmDialog,
	Notice,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { useDispatch } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import { request } from '../common/api';
import { joinNames } from '../common/copy';
import {
	AccountsField,
	ChoiceControl,
	ConnectionWarning,
	sendModeOptions,
	useAction,
} from './fields';

/**
 * A titled card of the settings, with a one-line summary of its choices.
 *
 * @param {Object}                    props
 * @param {string}                    props.id        Anchor for the section nav.
 * @param {string}                    props.title     Section title.
 * @param {string}                    [props.summary] What's chosen, in a few words.
 * @param {import('react').ReactNode} props.children  Section content.
 * @return {Element} The section.
 */
function Section( { id, title, summary, children } ) {
	return (
		<Card className="refract-section" id={ id }>
			<CardHeader>
				<h2>{ title }</h2>
				{ summary && (
					<span className="refract-section__summary">
						{ summary }
					</span>
				) }
			</CardHeader>
			<CardBody>{ children }</CardBody>
		</Card>
	);
}

/**
 * One setting: its name on the left, the control and its help on the right.
 * Without a title, the control takes the whole row.
 *
 * @param {Object}                    props
 * @param {string}                    [props.title]  The setting's name.
 * @param {import('react').ReactNode} props.children The control.
 * @return {Element} The row.
 */
function Row( { title, children } ) {
	return (
		<div className={ title ? 'refract-row' : 'refract-row is-wide' }>
			{ title && <p className="refract-row__title">{ title }</p> }
			<div className="refract-row__control">{ children }</div>
		</div>
	);
}

/**
 * The sections, for the section nav.
 *
 * @return {Array<{id: string, title: string}>} Sections in page order.
 */
function sections() {
	return [
		{ id: 'refract-sending', title: __( 'Sending', 'refract' ) },
		{ id: 'refract-accounts', title: __( 'Accounts', 'refract' ) },
		{ id: 'refract-writing', title: __( 'Writing and images', 'refract' ) },
		{
			id: 'refract-publishing',
			title: __( 'Publishing from Refract', 'refract' ),
		},
		{ id: 'refract-previews', title: __( 'Link previews', 'refract' ) },
	];
}

/**
 * Links to the sections, marking the one in view.
 *
 * @return {Element} The nav.
 */
function SectionNav() {
	const items = sections();
	const [ active, setActive ] = useState( items[ 0 ].id );

	useEffect( () => {
		const observer = new window.IntersectionObserver(
			( entries ) =>
				entries
					.filter( ( entry ) => entry.isIntersecting )
					.forEach( ( entry ) => setActive( entry.target.id ) ),
			{ rootMargin: '-20% 0px -70% 0px' }
		);

		sections().forEach( ( item ) => {
			const node = document.getElementById( item.id );

			if ( node ) {
				observer.observe( node );
			}
		} );

		return () => observer.disconnect();
	}, [] );

	return (
		<nav
			className="refract-nav"
			aria-label={ __( 'Settings sections', 'refract' ) }
		>
			<ul>
				{ items.map( ( item ) => (
					<li key={ item.id }>
						<a
							href={ `#${ item.id }` }
							className={
								active === item.id ? 'is-active' : undefined
							}
							aria-current={
								active === item.id ? 'true' : undefined
							}
							onClick={ () => setActive( item.id ) }
						>
							{ item.title }
						</a>
					</li>
				) ) }
			</ul>
		</nav>
	);
}

/**
 * Plan and AI credits, in one line.
 *
 * @param {Object} connection AdminState connection.
 * @return {string} The line.
 */
function planLine( connection ) {
	const parts = [];

	if ( connection.plan ) {
		parts.push(
			sprintf(
				/* translators: %s: Refract plan name, for example Business. */
				__( '%s plan', 'refract' ),
				connection.plan
			)
		);
	}

	const credits = connection.credits;

	if ( credits?.own_key ) {
		parts.push( __( 'Your own AI key pays for text', 'refract' ) );
	} else if ( credits ) {
		parts.push(
			sprintf(
				/* translators: %d: number of AI credits left. */
				_n(
					'%d AI credit left',
					'%d AI credits left',
					credits.left,
					'refract'
				),
				credits.left
			)
		);

		if ( credits.refill_on ) {
			parts.push(
				sprintf(
					/* translators: %s: date the credits refill. */
					__( 'refills on %s', 'refract' ),
					new Intl.DateTimeFormat(
						document.documentElement.lang || undefined,
						{ dateStyle: 'medium' }
					).format( new Date( `${ credits.refill_on }T00:00:00` ) )
				)
			);
		}
	}

	return parts.join( ' · ' );
}

/**
 * The connection, at the top of the settings: who and where, the plan and
 * credits, Change workspace and Disconnect.
 *
 * @param {Object}                  props
 * @param {Object}                  props.state   AdminState.
 * @param {(state: Object) => void} props.onState Called with a new AdminState.
 * @return {Element} The card.
 */
function Connection( { state, onState } ) {
	const { connection } = state;
	const { busy, error, run } = useAction();
	const [ picking, setPicking ] = useState( false );
	const [ workspaceId, setWorkspaceId ] = useState(
		connection.workspace?.id || connection.workspaces[ 0 ]?.id || ''
	);
	const [ confirming, setConfirming ] = useState( false );
	const plan = planLine( connection );
	const tone = {
		connected: 'is-ok',
		needs_reconnect: 'is-warning',
		unregistered: 'is-error',
	}[ connection.status ];

	const switchWorkspace = () =>
		run( 'workspace', () =>
			request( '/workspace', {
				data: { workspace_id: workspaceId },
			} ).then( ( next ) => {
				setPicking( false );
				onState( next );
			} )
		);

	const disconnect = () => {
		setConfirming( false );
		run( 'disconnect', () =>
			request( '/disconnect', { data: { keep_settings: true } } ).then(
				onState
			)
		);
	};

	return (
		<Card className="refract-connection">
			<CardBody>
				<div className="refract-connection__main">
					<span
						className={ `refract-status ${ tone || '' }` }
						aria-hidden="true"
					/>
					<div className="refract-connection__who">
						<p className="refract-connection__line">
							{ sprintf(
								/* translators: 1: workspace name, 2: email address. */
								__( 'Connected to %1$s as %2$s', 'refract' ),
								connection.workspace?.name ||
									connection.organization,
								connection.email
							) }
						</p>
						{ plan && <p className="refract-help">{ plan }</p> }
					</div>
					<div className="refract-connection__actions">
						{ ! picking && (
							<Button
								variant="secondary"
								__next40pxDefaultSize
								onClick={ () => setPicking( true ) }
							>
								{ __( 'Change workspace', 'refract' ) }
							</Button>
						) }
						<Button
							variant="secondary"
							isDestructive
							__next40pxDefaultSize
							isBusy={ busy === 'disconnect' }
							disabled={ Boolean( busy ) }
							accessibleWhenDisabled
							onClick={ () => setConfirming( true ) }
						>
							{ __( 'Disconnect', 'refract' ) }
						</Button>
					</div>
				</div>
				<ConnectionWarning state={ state } onState={ onState } />
				{ error && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ picking && (
					<Flex align="flex-end" justify="flex-start" wrap>
						<SelectControl
							__next40pxDefaultSize
							__nextHasNoMarginBottom
							label={ __( 'Workspace', 'refract' ) }
							value={ workspaceId }
							options={ connection.workspaces.map(
								( workspace ) => ( {
									label: workspace.name,
									value: workspace.id,
								} )
							) }
							onChange={ setWorkspaceId }
						/>
						<Button
							variant="primary"
							__next40pxDefaultSize
							isBusy={ busy === 'workspace' }
							disabled={ Boolean( busy ) }
							accessibleWhenDisabled
							onClick={ switchWorkspace }
						>
							{ __( 'Use this workspace', 'refract' ) }
						</Button>
						<Button
							variant="tertiary"
							__next40pxDefaultSize
							onClick={ () => setPicking( false ) }
						>
							{ __( 'Cancel', 'refract' ) }
						</Button>
					</Flex>
				) }
				<ConfirmDialog
					isOpen={ confirming }
					confirmButtonText={ __( 'Disconnect', 'refract' ) }
					onConfirm={ disconnect }
					onCancel={ () => setConfirming( false ) }
				>
					{ __(
						'Disconnect this site from Refract? Posts you already sent stay in Refract, and your site stops sending new ones.',
						'refract'
					) }
				</ConfirmDialog>
			</CardBody>
		</Card>
	);
}

/**
 * The category picker: search and add categories, or leave it empty for all.
 * Two categories with the same name are told apart by their ID.
 *
 * @param {Object}                            props
 * @param {Array<{id: number, name: string}>} props.categories The site's categories.
 * @param {number[]}                          props.value      Chosen category IDs.
 * @param {(ids: number[]) => void}           props.onChange   Called with the new IDs.
 * @return {Element} The field.
 */
function CategoriesField( { categories, value, onChange } ) {
	const names = categories.map( ( category ) => category.name );
	const labelOf = ( category ) =>
		names.indexOf( category.name ) === names.lastIndexOf( category.name )
			? category.name
			: `${ category.name } (${ category.id })`;
	const ids = new Map(
		categories.map( ( category ) => [ labelOf( category ), category.id ] )
	);

	return (
		<div className="refract-tokens">
			<FormTokenField
				__next40pxDefaultSize
				__experimentalExpandOnFocus
				__experimentalValidateInput={ ( token ) => ids.has( token ) }
				label={ __( 'Only these categories', 'refract' ) }
				placeholder={ __( 'Search categories', 'refract' ) }
				help={ __(
					'Optional. Leave it empty to send posts from every category.',
					'refract'
				) }
				suggestions={ [ ...ids.keys() ] }
				value={ categories
					.filter( ( category ) => value.includes( category.id ) )
					.map( labelOf ) }
				onChange={ ( tokens ) =>
					onChange(
						tokens
							.map( ( token ) =>
								ids.get(
									typeof token === 'string'
										? token
										: token.value
								)
							)
							.filter( ( id ) => id !== undefined )
					)
				}
			/>
		</div>
	);
}

/**
 * Settings in a form that compares equal however the lists are ordered.
 *
 * @param {Object} settings Settings.
 * @return {string} A comparable string.
 */
function comparable( settings ) {
	return JSON.stringify(
		Object.keys( settings )
			.sort()
			.map( ( key ) => [
				key,
				Array.isArray( settings[ key ] )
					? [ ...settings[ key ] ].sort()
					: settings[ key ],
			] )
	);
}

/**
 * The Sending section's summary: when, which post types, which categories.
 *
 * @param {Object} settings Settings being edited.
 * @param {Object} state    AdminState.
 * @return {string} The summary.
 */
function sendingSummary( settings, state ) {
	const types = state.post_types
		.filter( ( type ) => settings.post_types.includes( type.name ) )
		.map( ( type ) => type.label );
	const parts = [
		settings.send_mode === 'publish'
			? __( 'When published', 'refract' )
			: __( 'When you choose', 'refract' ),
		types.length ? joinNames( types ) : __( 'No post types', 'refract' ),
	];

	if ( state.categories.length > 0 ) {
		parts.push(
			settings.categories.length
				? sprintf(
						/* translators: %d: number of categories chosen. */
						_n(
							'%d category',
							'%d categories',
							settings.categories.length,
							'refract'
						),
						settings.categories.length
					)
				: __( 'All categories', 'refract' )
		);
	}

	return parts.join( ' · ' );
}

/**
 * The settings for a connected site.
 *
 * @param {Object}                  props
 * @param {Object}                  props.state   AdminState.
 * @param {(state: Object) => void} props.onState Called with a new AdminState.
 * @return {Element} The settings.
 */
export default function ConnectedSettings( { state, onState } ) {
	const saved = state.settings;
	const [ settings, setSettings ] = useState( saved );
	const [ showErrors, setShowErrors ] = useState( false );
	const { busy, error, run } = useAction();
	const { createSuccessNotice } = useDispatch( noticesStore );
	const seoPlugin = state.seo_plugin;
	const ownKey = Boolean( state.connection.credits?.own_key );
	const dirty = comparable( settings ) !== comparable( saved );
	const publishAsMissing = settings.refract_publish && ! settings.publish_as;

	// A new AdminState (saved, or another workspace) replaces what's on screen.
	useEffect( () => {
		setSettings( saved );
		setShowErrors( false );
	}, [ saved ] );

	useEffect( () => {
		if ( ! dirty ) {
			return undefined;
		}

		const warn = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};

		window.addEventListener( 'beforeunload', warn );

		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );

	const set = ( key ) => ( value ) =>
		setSettings( ( current ) => ( { ...current, [ key ]: value } ) );

	const toggleIn = ( key, item, checked ) =>
		setSettings( ( current ) => ( {
			...current,
			[ key ]: checked
				? [ ...current[ key ], item ]
				: current[ key ].filter( ( value ) => value !== item ),
		} ) );

	const save = ( event ) => {
		event.preventDefault();

		if ( publishAsMissing ) {
			setShowErrors( true );
			document
				.getElementById( 'refract-publishing' )
				?.scrollIntoView( { block: 'start' } );

			return;
		}

		run( 'save', () =>
			request( '/settings', { data: { settings } } ).then( ( next ) => {
				onState( next );
				createSuccessNotice( __( 'Settings saved.', 'refract' ), {
					type: 'snackbar',
				} );
			} )
		);
	};

	return (
		<div className="refract-settings">
			<Connection state={ state } onState={ onState } />
			<SectionNav />

			<form className="refract-settings__form" onSubmit={ save }>
				<Section
					id="refract-sending"
					title={ __( 'Sending', 'refract' ) }
					summary={ sendingSummary( settings, state ) }
				>
					<Row title={ __( 'Send posts to Refract', 'refract' ) }>
						<ChoiceControl
							label={ __( 'Send posts to Refract', 'refract' ) }
							selected={ settings.send_mode }
							options={ sendModeOptions() }
							onChange={ set( 'send_mode' ) }
						/>
					</Row>

					<Row title={ __( 'Post types', 'refract' ) }>
						<fieldset className="refract-inline">
							<legend className="screen-reader-text">
								{ __( 'Post types', 'refract' ) }
							</legend>
							{ state.post_types.map( ( type ) => (
								<CheckboxControl
									key={ type.name }
									__nextHasNoMarginBottom
									label={ type.label }
									checked={ settings.post_types.includes(
										type.name
									) }
									onChange={ ( checked ) =>
										toggleIn(
											'post_types',
											type.name,
											checked
										)
									}
								/>
							) ) }
						</fieldset>
					</Row>

					{ state.categories.length > 0 && (
						<Row title={ __( 'Only these categories', 'refract' ) }>
							<CategoriesField
								categories={ state.categories }
								value={ settings.categories }
								onChange={ set( 'categories' ) }
							/>
						</Row>
					) }

					<Row
						title={ __(
							'When the post changes after it was sent',
							'refract'
						) }
					>
						<ChoiceControl
							label={ __(
								'When the post changes after it was sent',
								'refract'
							) }
							selected={ settings.on_update }
							options={ [
								{
									label: __(
										'Update the note in Refract',
										'refract'
									),
									description: __(
										'When the title, excerpt or text changes.',
										'refract'
									),
									value: 'update',
								},
								{
									label: __( 'Leave it', 'refract' ),
									description: __(
										'The note stays as it was sent.',
										'refract'
									),
									value: 'leave',
								},
							] }
							onChange={ set( 'on_update' ) }
						/>
					</Row>
				</Section>

				<Section
					id="refract-accounts"
					title={ __( 'Accounts', 'refract' ) }
				>
					<AccountsField
						accounts={ state.accounts }
						value={ settings.accounts }
						onChange={ set( 'accounts' ) }
					/>
				</Section>

				<Section
					id="refract-writing"
					title={ __( 'Writing and images', 'refract' ) }
					summary={ [
						settings.write_mode === 'note'
							? __( 'A version for each account', 'refract' )
							: __(
									'The same post for every account',
									'refract'
								),
						settings.images === 'crop'
							? __( 'Crop images', 'refract' )
							: __( 'Crop or make images', 'refract' ),
					].join( ' · ' ) }
				>
					<Row title={ __( 'What Refract writes', 'refract' ) }>
						<ChoiceControl
							label={ __( 'What Refract writes', 'refract' ) }
							help={ __(
								'When credits run out, Refract gets the same post for every account instead, and the post sidebar says so.',
								'refract'
							) }
							selected={ settings.write_mode }
							options={ [
								{
									label: __(
										'A version for each account, in your voice',
										'refract'
									),
									tag: ownKey
										? {
												label: __(
													'Paid by your AI key',
													'refract'
												),
											}
										: {
												label: __(
													'1 AI credit each',
													'refract'
												),
												tone: 'cost',
											},
									value: 'note',
								},
								{
									label: __(
										'The same post for every account, with the link',
										'refract'
									),
									tag: {
										label: __( 'Free', 'refract' ),
										tone: 'free',
									},
									value: 'finished',
								},
							] }
							onChange={ set( 'write_mode' ) }
						/>
					</Row>

					<Row title={ __( 'Images', 'refract' ) }>
						<ChoiceControl
							label={ __( 'Images', 'refract' ) }
							selected={ settings.images }
							options={ [
								{
									label: __(
										'Use the post’s images, cropped for each network',
										'refract'
									),
									value: 'crop',
								},
								{
									label: __(
										'Also make new images when none fit',
										'refract'
									),
									tag: {
										label: __(
											'Uses AI credits',
											'refract'
										),
										tone: 'cost',
									},
									value: 'crop_or_make',
								},
							] }
							onChange={ set( 'images' ) }
						/>
					</Row>

					<Row
						title={ __(
							'After the versions are written',
							'refract'
						) }
					>
						<p className="refract-row__text">
							{ __(
								'Versions wait for your approval. Approve them in the post editor or in Refract.',
								'refract'
							) }
						</p>
					</Row>
				</Section>

				<Section
					id="refract-publishing"
					title={ __( 'Publishing from Refract', 'refract' ) }
					summary={
						// eslint-disable-next-line no-nested-ternary
						! settings.refract_publish
							? __( 'Off', 'refract' )
							: settings.refract_can === 'publish'
								? __( 'Publish and schedule', 'refract' )
								: __( 'Drafts only', 'refract' )
					}
				>
					<Row>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __(
								'Let Refract publish to this site',
								'refract'
							) }
							help={ __(
								'From Refract, you can publish or schedule posts to this site, or send them as drafts. Posts that come from Refract are never sent back to Refract.',
								'refract'
							) }
							checked={ settings.refract_publish }
							onChange={ set( 'refract_publish' ) }
						/>
					</Row>

					{ settings.refract_publish && (
						<div className="refract-dependent">
							<Row title={ __( 'Publish as', 'refract' ) }>
								<ComboboxControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									hideLabelFromVision
									label={ __( 'Publish as', 'refract' ) }
									help={ __(
										'Required. Only people who can publish posts are listed.',
										'refract'
									) }
									value={
										settings.publish_as
											? String( settings.publish_as )
											: null
									}
									options={ state.users.map( ( user ) => ( {
										label: user.name,
										value: String( user.id ),
									} ) ) }
									onChange={ ( value ) =>
										set( 'publish_as' )(
											Number( value ) || 0
										)
									}
								/>
								{ showErrors && publishAsMissing && (
									<p
										className="refract-field-error"
										role="alert"
									>
										{ __(
											'Choose who Refract publishes as, or turn off publishing from Refract.',
											'refract'
										) }
									</p>
								) }
							</Row>

							<Row title={ __( 'Default category', 'refract' ) }>
								<SelectControl
									__next40pxDefaultSize
									__nextHasNoMarginBottom
									hideLabelFromVision
									label={ __(
										'Default category',
										'refract'
									) }
									value={ String(
										settings.default_category
									) }
									options={ [
										{
											label: __(
												'The site’s default category',
												'refract'
											),
											value: '0',
										},
										...state.categories.map(
											( category ) => ( {
												label: category.name,
												value: String( category.id ),
											} )
										),
									] }
									onChange={ ( value ) =>
										set( 'default_category' )(
											Number( value )
										)
									}
								/>
							</Row>

							<Row
								title={ __( 'What Refract can do', 'refract' ) }
							>
								<ChoiceControl
									label={ __(
										'What Refract can do',
										'refract'
									) }
									selected={ settings.refract_can }
									options={ [
										{
											label: __(
												'Publish and schedule posts',
												'refract'
											),
											value: 'publish',
										},
										{
											label: __(
												'Send drafts only',
												'refract'
											),
											description: __(
												'You publish them yourself.',
												'refract'
											),
											value: 'drafts',
										},
									] }
									onChange={ set( 'refract_can' ) }
								/>
							</Row>
						</div>
					) }
				</Section>

				<Section
					id="refract-previews"
					title={ __( 'Link previews', 'refract' ) }
					summary={
						// eslint-disable-next-line no-nested-ternary
						seoPlugin
							? sprintf(
									/* translators: %s: name of the active SEO plugin, for example Yoast SEO. */
									__( 'Handled by %s', 'refract' ),
									seoPlugin
								)
							: settings.link_previews
								? __( 'On', 'refract' )
								: __( 'Off', 'refract' )
					}
				>
					<Row>
						<ToggleControl
							__nextHasNoMarginBottom
							label={ __( 'Add social preview tags', 'refract' ) }
							help={
								seoPlugin
									? sprintf(
											/* translators: %s: name of the active SEO plugin, for example Yoast SEO. */
											__(
												'Off, because %s already adds these tags.',
												'refract'
											),
											seoPlugin
										)
									: __(
											'The tags make your links show a title and image on every network.',
											'refract'
										)
							}
							checked={ ! seoPlugin && settings.link_previews }
							disabled={ Boolean( seoPlugin ) }
							onChange={ set( 'link_previews' ) }
						/>
					</Row>
				</Section>

				<div
					className={
						dirty ? 'refract-savebar is-dirty' : 'refract-savebar'
					}
				>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					<div className="refract-savebar__inner">
						<span className="refract-savebar__status">
							{ dirty
								? __( 'You have unsaved changes.', 'refract' )
								: __( 'All changes are saved.', 'refract' ) }
						</span>
						{ dirty && (
							<Button
								variant="tertiary"
								__next40pxDefaultSize
								disabled={ Boolean( busy ) }
								accessibleWhenDisabled
								onClick={ () => {
									setSettings( saved );
									setShowErrors( false );
								} }
							>
								{ __( 'Discard changes', 'refract' ) }
							</Button>
						) }
						<Button
							variant="primary"
							type="submit"
							__next40pxDefaultSize
							isBusy={ busy === 'save' }
							disabled={ ! dirty || Boolean( busy ) }
							accessibleWhenDisabled
						>
							{ __( 'Save changes', 'refract' ) }
						</Button>
					</div>
				</div>
			</form>
		</div>
	);
}
