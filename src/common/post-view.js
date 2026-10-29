/**
 * WordPress dependencies
 */
import { Button, ExternalLink, Notice, Spinner } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import Versions from './versions';

/**
 * The manual "Send to Refract" button, with its help text until the post is
 * published or scheduled.
 *
 * @param {Object}                       props
 * @param {Object}                       props.panel  PostPanel.
 * @param {?string}                      props.action The running action, if any.
 * @param {(startFrom?: string) => void} props.onSend Sends the post.
 * @return {Element} The button.
 */
export function SendButton( { panel, action, onSend } ) {
	return (
		<div className="refract-send">
			<Button
				variant="primary"
				isBusy={ action === 'send' }
				disabled={ ! panel.can_send_now || Boolean( action ) }
				accessibleWhenDisabled
				onClick={ () => onSend() }
			>
				{ __( 'Send to Refract', 'refract' ) }
			</Button>
			{ ! panel.can_send_now && (
				<p className="refract-help">
					{ __(
						'Publish the post first. Each version links to it.',
						'refract'
					) }
				</p>
			) }
		</div>
	);
}

/**
 * Where to go to set Refract up, for people who can.
 *
 * @param {Object}  props
 * @param {string}  props.settingsUrl Settings page URL.
 * @param {boolean} props.canManage   Whether the person can manage options.
 * @return {Element|null} The link.
 */
function SettingsLink( { settingsUrl, canManage } ) {
	if ( ! settingsUrl ) {
		return null;
	}

	if ( ! canManage ) {
		return (
			<p className="refract-help">
				{ __( 'Ask a site admin to set this up.', 'refract' ) }
			</p>
		);
	}

	return (
		<p>
			<a href={ settingsUrl }>
				{ __( 'Open Refract settings', 'refract' ) }
			</a>
		</p>
	);
}

/**
 * Everything the panel shows for a post, by state. The block editor passes its
 * own controls for an unsent post as `beforeSend`; the classic editor's are
 * rendered by the server.
 *
 * @param {Object}  props
 * @param {Object}  props.panel         Result of usePostPanel().
 * @param {Element} [props.beforeSend]  Controls shown before the post is sent.
 * @param {string}  [props.settingsUrl] Settings page URL.
 * @param {boolean} [props.canManage]   Whether the person can manage options.
 * @return {Element} The view.
 */
export default function PostView( {
	panel: hook,
	beforeSend = null,
	settingsUrl = '',
	canManage = false,
} ) {
	const { panel, error, action, refresh, send, approve, approveAll } = hook;

	if ( ! panel ) {
		return error ? (
			<Notice status="error" isDismissible={ false }>
				<p>{ error }</p>
				<Button variant="link" onClick={ refresh }>
					{ __( 'Try again', 'refract' ) }
				</Button>
			</Notice>
		) : (
			<Spinner />
		);
	}

	if ( ! panel.connected ) {
		return (
			<div className="refract-post">
				<p>
					{ __(
						'Connect your site to Refract to send this post.',
						'refract'
					) }
				</p>
				<SettingsLink
					settingsUrl={ settingsUrl }
					canManage={ canManage }
				/>
			</div>
		);
	}

	if ( panel.state === 'from_refract' ) {
		return (
			<p className="refract-post">
				{ __(
					'This post came from Refract, so it isn’t sent back.',
					'refract'
				) }
			</p>
		);
	}

	if ( ! panel.enabled ) {
		return (
			<div className="refract-post">
				<p>
					{ __(
						'Sending to Refract is off for this post type.',
						'refract'
					) }
				</p>
				<SettingsLink
					settingsUrl={ settingsUrl }
					canManage={ canManage }
				/>
			</div>
		);
	}

	const showSend =
		panel.send_mode === 'manual' &&
		( panel.state === 'not_sent' || panel.state === 'failed' );

	return (
		<div className="refract-post">
			{ error && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }

			{ panel.notice && (
				<Notice status="warning" isDismissible={ false }>
					{ panel.notice }
				</Notice>
			) }

			{ panel.state === 'queued' && (
				<p className="refract-post__status">
					<Spinner />
					{ __( 'Sending to Refract…', 'refract' ) }
				</p>
			) }

			{ panel.state === 'retrying' && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ __(
							'Refract couldn’t be reached. Trying again in a few minutes.',
							'refract'
						) }
					</p>
					{ panel.error && <p>{ panel.error }</p> }
				</Notice>
			) }

			{ panel.state === 'failed' && (
				<Notice status="error" isDismissible={ false }>
					<p>
						{ panel.error ||
							__(
								'The post couldn’t be sent to Refract.',
								'refract'
							) }
					</p>
					{ panel.can_send_now && panel.send_mode !== 'manual' && (
						<Button
							variant="secondary"
							isBusy={ action === 'send' }
							disabled={ Boolean( action ) }
							accessibleWhenDisabled
							onClick={ () => send() }
						>
							{ __( 'Try again', 'refract' ) }
						</Button>
					) }
				</Notice>
			) }

			{ panel.state === 'out_of_credits' && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ Number.isInteger( panel.needed ) &&
						Number.isInteger( panel.left )
							? sprintf(
									/* translators: 1: AI credits the post needs, 2: AI credits left. */
									__(
										'You’re out of AI credits for this post. It needs %1$d and you have %2$d.',
										'refract'
									),
									panel.needed,
									panel.left
								)
							: panel.error }
					</p>
					<div className="refract-post__buttons">
						{ panel.buy_url && (
							<Button
								variant="secondary"
								href={ panel.buy_url }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Buy credits', 'refract' ) }
							</Button>
						) }
						<Button
							variant="link"
							isBusy={ action === 'send-finished' }
							disabled={ Boolean( action ) }
							accessibleWhenDisabled
							onClick={ () => send( 'finished' ) }
						>
							{ __(
								'Send the same post to every account instead (free)',
								'refract'
							) }
						</Button>
					</div>
				</Notice>
			) }

			{ panel.fallback && (
				<Notice status="info" isDismissible={ false }>
					{ __(
						'You were out of AI credits, so Refract got the same post for every account.',
						'refract'
					) }
				</Notice>
			) }

			{ ( panel.state === 'not_sent' ||
				panel.state === 'failed' ||
				panel.state === 'out_of_credits' ) &&
				beforeSend }

			{ showSend && (
				<SendButton panel={ panel } action={ action } onSend={ send } />
			) }

			{ panel.refract_error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ panel.refract_error }</p>
					<Button variant="link" onClick={ refresh }>
						{ __( 'Try again', 'refract' ) }
					</Button>
				</Notice>
			) }

			{ panel.refract && (
				<Versions
					document={ panel.refract }
					action={ action }
					onApprove={ approve }
					onApproveAll={ approveAll }
				/>
			) }

			{ panel.refract?.edit_url && (
				<p>
					<ExternalLink href={ panel.refract.edit_url }>
						{ __( 'Open the post in Refract', 'refract' ) }
					</ExternalLink>
				</p>
			) }
		</div>
	);
}
