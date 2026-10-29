/**
 * WordPress dependencies
 */
import {
	Card,
	CardBody,
	ExternalLink,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { errorMessage, request } from '../common/api';
import { formatTime } from '../common/copy';

/**
 * What happened, in words.
 *
 * @param {string} event Activity event key.
 * @return {string} The label.
 */
function eventLabel( event ) {
	const labels = {
		sent: __( 'Sent to Refract', 'refract' ),
		updated: __( 'Note updated in Refract', 'refract' ),
		versions_ready: __( 'Versions ready', 'refract' ),
		approved: __( 'Approved', 'refract' ),
		queued: __( 'Queued', 'refract' ),
		posted: __( 'Posted', 'refract' ),
		failed: __( 'Failed', 'refract' ),
		send_failed: __( 'Couldn’t send to Refract', 'refract' ),
		out_of_credits: __( 'Out of AI credits', 'refract' ),
		received: __( 'Received from Refract', 'refract' ),
		removed: __( 'Removed by Refract', 'refract' ),
		connected: __( 'Connected', 'refract' ),
		disconnected: __( 'Disconnected', 'refract' ),
	};

	return labels[ event ] || event;
}

/**
 * Label for an entry's link.
 *
 * @param {Object} entry Activity entry.
 * @return {string} The label.
 */
function linkLabel( entry ) {
	if ( entry.event === 'posted' ) {
		return __( 'View the post', 'refract' );
	}

	if ( entry.event === 'out_of_credits' ) {
		return __( 'Buy credits', 'refract' );
	}

	return __( 'Open in Refract', 'refract' );
}

/**
 * The Activity page: the last 100 events.
 *
 * @return {Element} The page.
 */
export default function ActivityPage() {
	const [ problems, setProblems ] = useState( false );
	const [ events, setEvents ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		let current = true;

		setEvents( null );
		setError( null );
		request( problems ? '/activity?problems=1' : '/activity' ).then(
			( result ) => current && setEvents( result.events ),
			( reason ) => current && setError( errorMessage( reason ) )
		);

		return () => {
			current = false;
		};
	}, [ problems ] );

	return (
		<div className="refract-admin__page refract-activity">
			<Card>
				<CardBody>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Only problems', 'refract' ) }
						checked={ problems }
						onChange={ setProblems }
					/>
					<p className="refract-help">
						{ __( 'Events are kept for 30 days.', 'refract' ) }
					</p>
				</CardBody>
			</Card>

			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ ! events && ! error && <Spinner /> }

			{ events && events.length === 0 && (
				<p>
					{ problems
						? __( 'No problems in the last 30 days.', 'refract' )
						: __( 'Nothing has happened yet.', 'refract' ) }
				</p>
			) }

			{ events && events.length > 0 && (
				<table className="wp-list-table widefat striped refract-activity__table">
					<thead>
						<tr>
							<th scope="col">{ __( 'Time', 'refract' ) }</th>
							<th scope="col">{ __( 'Post', 'refract' ) }</th>
							<th scope="col">
								{ __( 'What happened', 'refract' ) }
							</th>
							<th scope="col">{ __( 'Account', 'refract' ) }</th>
							<th scope="col">{ __( 'Result', 'refract' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ events.map( ( entry ) => (
							<tr key={ entry.id }>
								<td>{ formatTime( entry.time ) }</td>
								<td>
									{ entry.post_url ? (
										<a href={ entry.post_url }>
											{ entry.post_title ||
												__( '(no title)', 'refract' ) }
										</a>
									) : (
										entry.post_title || '—'
									) }
								</td>
								<td>{ eventLabel( entry.event ) }</td>
								<td>{ entry.account || '—' }</td>
								<td>
									<span
										className={
											entry.ok
												? 'refract-result is-ok'
												: 'refract-result is-error'
										}
									>
										{ entry.ok
											? __( 'OK', 'refract' )
											: __( 'Error', 'refract' ) }
									</span>
									{ entry.message && (
										<span className="refract-result__message">
											{ entry.message }
										</span>
									) }
									{ entry.link && (
										<ExternalLink href={ entry.link }>
											{ linkLabel( entry ) }
										</ExternalLink>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}
