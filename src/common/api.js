/**
 * WordPress dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const NAMESPACE = '/refract-admin/v1';

/**
 * Calls one of the plugin's admin REST routes.
 *
 * @param {string} path             Route path inside the namespace, for example `/state`.
 * @param {Object} [options]        Request options.
 * @param {string} [options.method] HTTP method. Defaults to GET, or POST when data is given.
 * @param {Object} [options.data]   JSON body.
 * @return {Promise<Object>} The decoded response.
 */
export function request( path, { method, data } = {} ) {
	return apiFetch( {
		path: NAMESPACE + path,
		method: method || ( data === undefined ? 'GET' : 'POST' ),
		data,
	} );
}

/**
 * Text to show for a failed request.
 *
 * @param {Object} error Error thrown by apiFetch.
 * @return {string} The message.
 */
export function errorMessage( error ) {
	if ( error && typeof error.message === 'string' && error.message ) {
		return error.message;
	}

	return __( 'Something went wrong. Try again.', 'refract' );
}
