import { __, _n, sprintf } from '@wordpress/i18n';

export const formatBytes = ( bytes, decimals = 1 ) => {
	if ( bytes === null || bytes === undefined || isNaN( bytes ) ) {
		return '—';
	}
	if ( bytes < 1024 ) {
		return `${ bytes } B`;
	}
	const units = [ 'KB', 'MB', 'GB', 'TB', 'PB' ];
	let value = bytes;
	let i = -1;
	do {
		value /= 1024;
		i++;
	} while ( value >= 1024 && i < units.length - 1 );
	return `${ value.toFixed( value >= 100 ? 0 : decimals ) } ${ units[ i ] }`;
};

export const formatDate = ( timestamp ) => {
	if ( ! timestamp ) {
		return '—';
	}
	return new Date( timestamp * 1000 ).toLocaleString( undefined, {
		dateStyle: 'medium',
		timeStyle: 'short',
	} );
};

export const timeAgo = ( timestamp ) => {
	if ( ! timestamp ) {
		return __( 'Never', 'pack-vault' );
	}
	const diff = Math.round( Date.now() / 1000 - timestamp );
	const future = diff < 0;
	const abs = Math.abs( diff );
	let text;
	if ( abs < 60 ) {
		return future ? __( 'in a moment', 'pack-vault' ) : __( 'just now', 'pack-vault' );
	} else if ( abs < 3600 ) {
		const m = Math.round( abs / 60 );
		/* translators: %d: minutes */
		text = sprintf( _n( '%d minute', '%d minutes', m, 'pack-vault' ), m );
	} else if ( abs < 86400 ) {
		const h = Math.round( abs / 3600 );
		/* translators: %d: hours */
		text = sprintf( _n( '%d hour', '%d hours', h, 'pack-vault' ), h );
	} else {
		const d = Math.round( abs / 86400 );
		/* translators: %d: days */
		text = sprintf( _n( '%d day', '%d days', d, 'pack-vault' ), d );
	}
	return future
		? /* translators: %s: duration */ sprintf( __( 'in %s', 'pack-vault' ), text )
		: /* translators: %s: duration */ sprintf( __( '%s ago', 'pack-vault' ), text );
};

export const formatDuration = ( seconds ) => {
	if ( ! isFinite( seconds ) || seconds < 0 ) {
		return '—';
	}
	const s = Math.round( seconds );
	if ( s < 60 ) {
		return `${ s }s`;
	}
	const m = Math.floor( s / 60 );
	if ( m < 60 ) {
		return `${ m }m ${ s % 60 }s`;
	}
	return `${ Math.floor( m / 60 ) }h ${ m % 60 }m`;
};

export const hostOf = ( url ) => {
	try {
		return new URL( url ).host;
	} catch ( e ) {
		return url || '';
	}
};

export const classNames = ( ...args ) =>
	args
		.flatMap( ( arg ) =>
			arg && typeof arg === 'object'
				? Object.keys( arg ).filter( ( key ) => arg[ key ] )
				: [ arg ]
		)
		.filter( Boolean )
		.join( ' ' );
