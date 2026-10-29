/**
 * WordPress dependencies
 */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { addAction, removeAction } from '@wordpress/hooks';

/**
 * Internal dependencies
 */
import { errorMessage, request } from './api';

const POLL_MS = 10000;

/**
 * Whether the panel is still changing on its own and should be polled.
 *
 * @param {Object|null} panel PostPanel.
 * @return {boolean} True while writing, queued or retrying.
 */
function isBusy( panel ) {
	if ( ! panel ) {
		return false;
	}

	if ( panel.state === 'queued' || panel.state === 'retrying' ) {
		return true;
	}

	return Boolean(
		panel.refract?.versions?.some(
			( version ) => version.status === 'writing'
		)
	);
}

/**
 * Loads a post's Refract panel and keeps it fresh: every 10 s while versions
 * are being written or the send is queued, and when the heartbeat reports a
 * newer webhook event for the post.
 *
 * @param {number} postId Post ID.
 * @return {Object} Panel state and actions.
 */
export default function usePostPanel( postId ) {
	const [ panel, setPanel ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ action, setAction ] = useState( null );
	const eventAt = useRef( null );
	const mounted = useRef( true );

	const apply = useCallback( ( next ) => {
		if ( ! mounted.current ) {
			return;
		}

		eventAt.current = next.event_at;
		setPanel( next );
		setError( null );
	}, [] );

	const refresh = useCallback( () => {
		if ( ! postId ) {
			return Promise.resolve();
		}

		return request( `/posts/${ postId }` ).then( apply, ( reason ) => {
			if ( mounted.current ) {
				setError( errorMessage( reason ) );
			}
		} );
	}, [ postId, apply ] );

	const run = useCallback(
		( name, path, data = {} ) => {
			setAction( name );
			setError( null );

			return request( `/posts/${ postId }${ path }`, { data } )
				.then( apply, ( reason ) => {
					if ( mounted.current ) {
						setError( errorMessage( reason ) );
					}
				} )
				.finally( () => {
					if ( mounted.current ) {
						setAction( null );
					}
				} );
		},
		[ postId, apply ]
	);

	useEffect( () => {
		mounted.current = true;
		refresh();

		return () => {
			mounted.current = false;
		};
	}, [ refresh ] );

	const polling = isBusy( panel );

	useEffect( () => {
		if ( ! polling ) {
			return undefined;
		}

		const timer = setInterval( refresh, POLL_MS );

		return () => clearInterval( timer );
	}, [ polling, refresh ] );

	useEffect( () => {
		const namespace = `refract/post-panel/${ postId }`;

		addAction( 'heartbeat.send', namespace, ( data ) => {
			data.refract_post_id = postId;
		} );
		addAction( 'heartbeat.tick', namespace, ( response ) => {
			const at = Number( response?.refract_event_at ) || 0;

			if ( at && at !== eventAt.current ) {
				eventAt.current = at;
				refresh();
			}
		} );

		return () => {
			removeAction( 'heartbeat.send', namespace );
			removeAction( 'heartbeat.tick', namespace );
		};
	}, [ postId, refresh ] );

	return {
		panel,
		error,
		action,
		refresh,
		send: ( startFrom ) =>
			run(
				startFrom ? 'send-finished' : 'send',
				'/send',
				startFrom ? { start_from: startFrom } : {}
			),
		approve: ( versionId ) =>
			run( `approve:${ versionId }`, '/approve', {
				version_id: versionId,
			} ),
		approveAll: () => run( 'approve-all', '/approve-all' ),
	};
}
