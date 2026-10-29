/**
 * WordPress dependencies
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * The page language, for dates and lists.
 *
 * @return {string|undefined} BCP 47 tag.
 */
function locale() {
	return document.documentElement.lang || undefined;
}

/**
 * Formats a date and time for the person reading it.
 *
 * @param {number|string} value Unix seconds or an ISO 8601 string.
 * @return {string} The formatted date.
 */
export function formatTime( value ) {
	const date = new Date( typeof value === 'number' ? value * 1000 : value );

	return new Intl.DateTimeFormat( locale(), {
		dateStyle: 'medium',
		timeStyle: 'short',
	} ).format( date );
}

/**
 * Joins names with commas and "and", in the page language.
 *
 * @param {string[]} names Names.
 * @return {string} The list.
 */
export function joinNames( names ) {
	return new Intl.ListFormat( locale(), {
		style: 'long',
		type: 'conjunction',
	} ).format( names );
}

/**
 * Label for a version status.
 *
 * @param {Object} version Version from the versions document.
 * @return {string} The label.
 */
export function statusLabel( version ) {
	switch ( version.status ) {
		case 'writing':
			return __( 'Writing…', 'refract' );
		case 'needs_ok':
			return __( 'Needs your OK', 'refract' );
		case 'approved':
			return __( 'Approved', 'refract' );
		case 'edited':
			return __( 'Edited by you', 'refract' );
		case 'waiting_for_approval':
			return __( 'Waiting for approval', 'refract' );
		case 'scheduled':
			return version.scheduled_for
				? sprintf(
						/* translators: %s: date and time the version goes out. */
						__( 'Scheduled for %s', 'refract' ),
						formatTime( version.scheduled_for )
					)
				: __( 'Scheduled', 'refract' );
		case 'posted':
			return __( 'Posted', 'refract' );
		case 'failed':
			return __( 'Failed', 'refract' );
		default:
			return version.status;
	}
}

/**
 * The cost line for the accounts chosen for a post.
 *
 * @param {Object}   options
 * @param {Object[]} options.accounts  Chosen account objects.
 * @param {string}   options.writeMode `note` or `finished`.
 * @param {boolean}  options.ownKey    Whether the organization's own AI key pays.
 * @return {string} The cost line.
 */
export function costLine( { accounts, writeMode, ownKey } ) {
	if ( writeMode === 'finished' ) {
		return __( 'The same post for every account · free', 'refract' );
	}

	const versions = new Set( accounts.map( ( account ) => account.network ) )
		.size;

	if ( ownKey ) {
		return sprintf(
			/* translators: %d: number of versions Refract writes. */
			_n(
				'%d version · no credits (your own AI key)',
				'%d versions · no credits (your own AI key)',
				versions,
				'refract'
			),
			versions
		);
	}

	return sprintf(
		/* translators: 1: number of versions Refract writes, 2: number of AI credits they cost. */
		_n(
			'%1$d version · %2$d credit',
			'%1$d versions · %2$d credits',
			versions,
			'refract'
		),
		versions,
		versions
	);
}

/**
 * Says who approves posts when the person can't publish.
 *
 * @param {string[]} approvers Names of the people who approve.
 * @return {string} The approver line.
 */
export function approverLine( approvers ) {
	return sprintf(
		/* translators: %s: name, or names joined with commas and "and", of the people who approve posts. */
		_n(
			'%s approves posts in this workspace.',
			'%s approve posts in this workspace.',
			approvers.length,
			'refract'
		),
		joinNames( approvers )
	);
}

/**
 * Accounts that get a version, in display order: grouped by network, the
 * group with the main channel first, and the main channel first in its group.
 * WordPress sites are never listed.
 *
 * @param {Object[]} accounts Account objects.
 * @return {Array<{network: string, label: string, availability: string, accounts: Object[]}>} Groups.
 */
export function groupAccounts( accounts ) {
	const groups = new Map();

	accounts
		.filter( ( account ) => account.network !== 'wordpress' )
		.forEach( ( account ) => {
			if ( ! groups.has( account.network ) ) {
				groups.set( account.network, {
					network: account.network,
					label: account.network_label,
					availability: account.availability,
					accounts: [],
				} );
			}

			groups.get( account.network ).accounts.push( account );
		} );

	const hasMain = ( group ) =>
		group.accounts.some( ( account ) => account.main );

	return [ ...groups.values() ]
		.map( ( group ) => ( {
			...group,
			accounts: [ ...group.accounts ].sort(
				( a, b ) => Number( b.main ) - Number( a.main )
			),
		} ) )
		.sort( ( a, b ) => Number( hasMain( b ) ) - Number( hasMain( a ) ) );
}
