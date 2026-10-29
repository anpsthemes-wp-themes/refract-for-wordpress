/**
 * WordPress dependencies
 */
import {
	Button,
	ExternalLink,
	Flex,
	FlexItem,
	Notice,
	Spinner,
} from '@wordpress/components';
import { useLayoutEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { approverLine, statusLabel } from './copy';
import { NetworkMark } from './marks';

const APPROVABLE = [ 'needs_ok', 'edited' ];
const DONE = [ 'waiting_for_approval', 'scheduled', 'posted', 'failed' ];

/**
 * Version text, clamped to four lines with "Show more".
 *
 * @param {Object} props
 * @param {string} props.text The text.
 * @return {Element} The text block.
 */
function ClampedText( { text } ) {
	const ref = useRef( null );
	const [ open, setOpen ] = useState( false );
	const [ overflows, setOverflows ] = useState( false );

	useLayoutEffect( () => {
		const node = ref.current;

		if ( node && ! open ) {
			setOverflows( node.scrollHeight > node.clientHeight + 1 );
		}
	}, [ text, open ] );

	return (
		<div className="refract-version__text-wrap">
			<p
				ref={ ref }
				className={
					open
						? 'refract-version__text'
						: 'refract-version__text is-clamped'
				}
			>
				{ text }
			</p>
			{ ( overflows || open ) && (
				<Button
					variant="link"
					onClick={ () => setOpen( ! open ) }
					aria-expanded={ open }
				>
					{ open
						? __( 'Show less', 'refract' )
						: __( 'Show more', 'refract' ) }
				</Button>
			) }
		</div>
	);
}

/**
 * One account's version.
 *
 * @param {Object}                      props
 * @param {Object}                      props.version   Version from the versions document.
 * @param {?string}                     props.action    The running action, if any.
 * @param {(versionId: string) => void} props.onApprove Approves the version.
 * @return {Element} The card.
 */
function VersionCard( { version, action, onApprove } ) {
	const { account } = version;
	const image = version.media?.[ 0 ];
	const problems = ( version.checks || [] ).filter(
		( check ) => check.level !== 'pass'
	);
	const canApprove =
		version.approvable &&
		version.id &&
		APPROVABLE.includes( version.status );

	return (
		<li className={ `refract-version is-${ version.status }` }>
			<Flex className="refract-version__head" align="center" gap={ 2 }>
				<FlexItem className="refract-version__mark">
					<NetworkMark network={ account.network } />
				</FlexItem>
				<FlexItem isBlock>
					<div className="refract-version__account">
						<strong>{ account.name }</strong>
						{ account.handle && (
							<span className="refract-version__handle">
								{ account.handle }
							</span>
						) }
					</div>
				</FlexItem>
			</Flex>

			<p className="refract-version__status">
				{ version.status === 'writing' && <Spinner /> }
				{ version.status === 'posted' && version.permalink ? (
					<ExternalLink href={ version.permalink }>
						{ statusLabel( version ) }
					</ExternalLink>
				) : (
					statusLabel( version )
				) }
			</p>

			{ version.status === 'posted' && version.clicks !== undefined && (
				<p className="refract-version__clicks">
					{ sprintf(
						/* translators: 1: number of clicks, 2: network name, for example Instagram. */
						_n(
							'%1$d click from %2$s',
							'%1$d clicks from %2$s',
							version.clicks || 0,
							'refract'
						),
						version.clicks || 0,
						account.network_label
					) }
				</p>
			) }

			{ version.status === 'failed' && version.error && (
				<Notice status="error" isDismissible={ false }>
					<p>{ version.error.message }</p>
					{ version.error.fix && <p>{ version.error.fix }</p> }
				</Notice>
			) }

			{ version.stale === 'old' && (
				<p className="refract-version__note">
					{ __(
						'The post changed after this version was written.',
						'refract'
					) }
				</p>
			) }
			{ version.stale === 'check' && (
				<p className="refract-version__note">
					{ __(
						'The post changed. Check that this version still fits.',
						'refract'
					) }
				</p>
			) }

			{ version.text && <ClampedText text={ version.text } /> }

			{ image && (
				<img
					className="refract-version__image"
					src={ image.thumb_url || image.url }
					alt={ image.alt || '' }
					style={
						image.width && image.height
							? {
									aspectRatio: `${ image.width } / ${ image.height }`,
								}
							: undefined
					}
				/>
			) }

			{ problems.length > 0 && (
				<ul className="refract-version__checks">
					{ problems.map( ( check ) => (
						<li
							key={ check.code }
							className={ `refract-check is-${ check.level }` }
						>
							{ check.message }
						</li>
					) ) }
				</ul>
			) }

			<Flex className="refract-version__actions" justify="flex-start">
				{ canApprove && (
					<Button
						variant="secondary"
						size="compact"
						isBusy={ action === `approve:${ version.id }` }
						disabled={ Boolean( action ) }
						accessibleWhenDisabled
						onClick={ () => onApprove( version.id ) }
					>
						{ __( 'Approve', 'refract' ) }
					</Button>
				) }
				{ version.edit_url && (
					<Button
						variant="tertiary"
						size="compact"
						href={ version.edit_url }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Edit in Refract', 'refract' ) }
						<span className="screen-reader-text">
							{
								/* translators: accessibility text */
								__( '(opens in a new tab)', 'refract' )
							}
						</span>
					</Button>
				) }
			</Flex>
		</li>
	);
}

/**
 * The versions of a post sent to Refract, with Approve and Approve all.
 *
 * @param {Object}                      props
 * @param {Object}                      props.document     Versions document.
 * @param {?string}                     props.action       The running action, if any.
 * @param {(versionId: string) => void} props.onApprove    Approves one version.
 * @param {() => void}                  props.onApproveAll Approves every version.
 * @return {Element} The list.
 */
export default function Versions( {
	document: doc,
	action,
	onApprove,
	onApproveAll,
} ) {
	const versions = doc.versions || [];
	const canApproveAll =
		versions.some(
			( version ) => ! DONE.includes( version.status ) && version.id
		) &&
		versions.some(
			( version ) => version.id && APPROVABLE.includes( version.status )
		);
	const skipped = doc.skipped?.length || 0;

	return (
		<div className="refract-versions">
			{ versions.length === 0 && (
				<p>{ __( 'No versions yet.', 'refract' ) }</p>
			) }

			<ul className="refract-versions__list">
				{ versions.map( ( version ) => (
					<VersionCard
						key={ version.account.id }
						version={ version }
						action={ action }
						onApprove={ onApprove }
					/>
				) ) }
			</ul>

			{ skipped > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: %d: number of versions that weren't approved. */
						_n(
							'%d version has a failing check, so it wasn’t approved.',
							'%d versions have a failing check, so they weren’t approved.',
							skipped,
							'refract'
						),
						skipped
					) }
				</Notice>
			) }

			{ canApproveAll && (
				<div className="refract-versions__footer">
					<Button
						variant="primary"
						isBusy={ action === 'approve-all' }
						disabled={ Boolean( action ) }
						accessibleWhenDisabled
						onClick={ onApproveAll }
					>
						{ doc.can_publish
							? __( 'Approve all and add to queue', 'refract' )
							: __( 'Send for approval', 'refract' ) }
					</Button>
					{ ! doc.can_publish && doc.approvers?.length > 0 && (
						<p className="refract-versions__approvers">
							{ approverLine( doc.approvers ) }
						</p>
					) }
				</div>
			) }
		</div>
	);
}
