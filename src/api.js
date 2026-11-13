/**
 * REST helpers.
 */
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

export const config = window.packvaultData || {};

const NS = '/packvault/v1';

/**
 * Cookie + nonce authenticated request (admins).
 *
 * @param {string} path    Route path below the namespace.
 * @param {Object} options apiFetch options.
 * @return {Promise<any>} Response.
 */
export const api = ( path, options = {} ) =>
	apiFetch( { path: NS + path, ...options } );

/**
 * Token-authenticated job request.
 *
 * Sent without cookies on purpose: a restore swaps the database (and with it
 * the login session), so job calls authenticate with the job's secret token.
 *
 * @param {Object} job    Job with id + token.
 * @param {string} action '' (status), 'run' or 'cancel'.
 * @return {Promise<Object>} Job.
 */
export const jobRequest = async ( job, action = '' ) => {
	const url =
		config.restUrl.replace( /\/$/, '' ) +
		'/jobs/' +
		job.id +
		( action ? '/' + action : '' );
	const response = await window.fetch( url, {
		method: action ? 'POST' : 'GET',
		credentials: 'omit',
		headers: { 'X-Packvault-Token': job.token, Accept: 'application/json' },
	} );
	let body = null;
	try {
		body = await response.json();
	} catch ( e ) {
		body = null;
	}
	if ( ! response.ok || ! body ) {
		const error = new Error(
			( body && body.message ) ||
				__( 'The server did not respond as expected.', 'pack-vault' )
		);
		error.status = response.status;
		error.fatal = response.status === 400 || response.status === 403;
		throw error;
	}
	return body;
};

export const sleep = ( ms ) =>
	new Promise( ( resolve ) => setTimeout( resolve, ms ) );
