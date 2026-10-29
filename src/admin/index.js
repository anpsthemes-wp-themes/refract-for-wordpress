/**
 * WordPress dependencies
 */
import { SnackbarList } from '@wordpress/components';
import { useDispatch, useSelect } from '@wordpress/data';
import domReady from '@wordpress/dom-ready';
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

/**
 * Internal dependencies
 */
import ActivityPage from './activity';
import SettingsPage from './settings-page';
import './index.scss';

const config = window.refractAdmin || {};

/**
 * Snackbars from the notices store.
 *
 * @return {Element} The snackbar list.
 */
function Snackbars() {
	const notices = useSelect(
		( select ) =>
			select( noticesStore )
				.getNotices()
				.filter( ( notice ) => notice.type === 'snackbar' ),
		[]
	);
	const { removeNotice } = useDispatch( noticesStore );

	return (
		<SnackbarList
			className="refract-admin__snackbars"
			notices={ notices }
			onRemove={ removeNotice }
		/>
	);
}

/**
 * Both admin pages: the header with the Refract logo and the links to both
 * pages, then the page.
 *
 * @return {Element} The app.
 */
function App() {
	const isActivity = config.page === 'activity';
	const tabs = [
		{
			href: config.urls?.settings,
			label: __( 'Settings', 'refract' ),
			current: ! isActivity,
		},
		{
			href: config.urls?.activity,
			label: __( 'Activity', 'refract' ),
			current: isActivity,
		},
	];

	return (
		<>
			<div className="refract-admin__header">
				{ config.logo && (
					<img
						className="refract-admin__logo"
						src={ config.logo }
						alt=""
						width="28"
						height="28"
					/>
				) }
				<h1>
					{ isActivity
						? __( 'Refract activity', 'refract' )
						: __( 'Refract settings', 'refract' ) }
				</h1>
				{ config.urls && (
					<nav
						className="refract-admin__tabs"
						aria-label={ __( 'Refract pages', 'refract' ) }
					>
						{ tabs.map( ( tab ) => (
							<a
								key={ tab.href }
								href={ tab.href }
								aria-current={
									tab.current ? 'page' : undefined
								}
							>
								{ tab.label }
							</a>
						) ) }
					</nav>
				) }
			</div>
			{ isActivity ? (
				<ActivityPage />
			) : (
				<SettingsPage notice={ config.notice || null } />
			) }
			<Snackbars />
		</>
	);
}

domReady( () => {
	const node =
		document.getElementById( 'refract-settings' ) ||
		document.getElementById( 'refract-activity' );

	if ( node ) {
		createRoot( node ).render( <App /> );
	}
} );
