import { useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Icon from '../icons';
import { useApp } from '../context';
import { api, sleep } from '../api';
import { formatBytes, formatDuration, classNames } from '../utils';

const MAX_RETRIES = 10;

const Import = () => {
	const { status, refresh, requestRestore, notify, busy } = useApp();
	const [ drag, setDrag ] = useState( false );
	const [ upload, setUpload ] = useState( null ); // { file, id, received, speed, state, error, result }
	const control = useRef( { paused: false, aborted: false } );
	const inputRef = useRef();

	const patch = ( data ) => setUpload( ( u ) => ( u ? { ...u, ...data } : u ) );

	const start = async ( file ) => {
		if ( ! file ) {
			return;
		}
		if ( ! /\.packvault$/i.test( file.name ) ) {
			notify( __( 'Please choose a .packvault backup file.', 'pack-vault' ), 'error' );
			return;
		}
		control.current = { paused: false, aborted: false };
		setUpload( { file, received: 0, speed: 0, state: 'uploading' } );

		let session;
		try {
			session = await api( '/uploads', {
				method: 'POST',
				data: { name: file.name, size: file.size },
			} );
		} catch ( e ) {
			patch( { state: 'error', error: e.message } );
			return;
		}
		patch( { id: session.id } );

		let offset = session.received || 0;
		let retries = 0;
		const chunk = session.chunk_size;
		const samples = [];

		while ( offset < file.size ) {
			if ( control.current.aborted ) {
				return;
			}
			if ( control.current.paused ) {
				await sleep( 300 );
				continue;
			}
			const begin = performance.now();
			const blob = file.slice( offset, Math.min( file.size, offset + chunk ) );
			try {
				const res = await api( `/uploads/${ session.id }/chunk?offset=${ offset }`, {
					method: 'POST',
					body: blob,
					headers: { 'Content-Type': 'application/octet-stream' },
				} );
				const sent = res.received - offset;
				offset = res.received;
				retries = 0;
				if ( sent > 0 ) {
					samples.push( sent / ( ( performance.now() - begin ) / 1000 ) );
					if ( samples.length > 6 ) {
						samples.shift();
					}
				}
				const speed = samples.reduce( ( a, b ) => a + b, 0 ) / ( samples.length || 1 );
				patch( { received: offset, speed, retrying: false } );
			} catch ( e ) {
				if ( e.code === 'packvault_error' ) {
					patch( { state: 'error', error: e.message } );
					return;
				}
				retries++;
				if ( retries > MAX_RETRIES ) {
					patch( { state: 'error', error: e.message || __( 'Upload failed.', 'pack-vault' ) } );
					return;
				}
				patch( { retrying: true } );
				await sleep( Math.min( 20000, 1000 * 2 ** retries ) );
			}
		}

		patch( { state: 'finishing' } );
		try {
			const result = await api( `/uploads/${ session.id }/complete`, { method: 'POST' } );
			patch( { state: 'done', result } );
			const data = await refresh();
			const stored = data?.backups?.find( ( b ) => b.name === result.name );
			if ( stored ) {
				patch( { backup: stored } );
			}
		} catch ( e ) {
			patch( { state: 'error', error: e.message } );
		}
	};

	const abort = async () => {
		control.current.aborted = true;
		if ( upload?.id ) {
			try {
				await api( `/uploads/${ upload.id }`, { method: 'DELETE' } );
			} catch ( e ) {
				// Ignore.
			}
		}
		setUpload( null );
	};

	const togglePause = () => {
		control.current.paused = ! control.current.paused;
		patch( { state: control.current.paused ? 'paused' : 'uploading' } );
	};

	const percent = upload ? Math.floor( ( 100 * upload.received ) / ( upload.file.size || 1 ) ) : 0;
	const eta = upload && upload.speed ? ( upload.file.size - upload.received ) / upload.speed : Infinity;
	const active = upload && [ 'uploading', 'paused', 'finishing' ].includes( upload.state );

	return (
		<div className="sa-page">
			<div className="sa-page__head">
				<div>
					<h1>{ __( 'Import & Restore', 'pack-vault' ) }</h1>
					<p className="sa-muted">
						{ __( 'Upload a .packvault file from this or any other site. Any size works — the file is sent in small resumable chunks.', 'pack-vault' ) }
					</p>
				</div>
			</div>

			{ ! upload && (
				<div
					className={ classNames( 'sa-dropzone', { 'is-drag': drag } ) }
					onDragOver={ ( e ) => {
						e.preventDefault();
						setDrag( true );
					} }
					onDragLeave={ () => setDrag( false ) }
					onDrop={ ( e ) => {
						e.preventDefault();
						setDrag( false );
						start( e.dataTransfer.files[ 0 ] );
					} }
					onClick={ () => inputRef.current?.click() }
					onKeyDown={ ( e ) => ( e.key === 'Enter' || e.key === ' ' ) && inputRef.current?.click() }
					role="button"
					tabIndex={ 0 }
				>
					<span className="sa-dropzone__icon">
						<Icon name="upload" size={ 34 } />
					</span>
					<h2>{ __( 'Drop your .packvault file here', 'pack-vault' ) }</h2>
					<p>{ __( 'or click to browse your computer', 'pack-vault' ) }</p>
					<span className="sa-pill sa-pill--soft">
						{ __( 'No upload size limit', 'pack-vault' ) } ·{ ' ' }
						{ sprintf(
							/* translators: %s: chunk size */
							__( '%s chunks', 'pack-vault' ),
							formatBytes( status.system.chunk_size, 0 )
						) }
					</span>
					<input
						ref={ inputRef }
						type="file"
						accept=".packvault"
						hidden
						onChange={ ( e ) => {
							start( e.target.files[ 0 ] );
							e.target.value = '';
						} }
					/>
				</div>
			) }

			{ upload && (
				<section className="sa-card sa-upload">
					<div className="sa-upload__head">
						<span className="sa-backup__icon">
							<Icon name="archive" size={ 20 } />
						</span>
						<div className="sa-upload__info">
							<strong>{ upload.file.name }</strong>
							<span className="sa-muted">
								{ formatBytes( upload.received ) } / { formatBytes( upload.file.size ) }
								{ upload.state === 'uploading' && upload.speed > 0 && (
									<>
										{ ' · ' }
										{ formatBytes( upload.speed ) }/s · { formatDuration( eta ) }{ ' ' }
										{ __( 'left', 'pack-vault' ) }
									</>
								) }
								{ upload.retrying && ' · ' + __( 'connection hiccup, retrying…', 'pack-vault' ) }
							</span>
						</div>
						<span className="sa-upload__percent">{ percent }%</span>
					</div>
					<div className={ `sa-progress is-${ upload.state }` }>
						<span style={ { width: `${ percent }%` } } />
					</div>

					{ active && (
						<div className="sa-row sa-row--end">
							{ upload.state !== 'finishing' && (
								<button className="sa-btn" onClick={ togglePause }>
									<Icon name={ upload.state === 'paused' ? 'play' : 'pause' } size={ 16 } />
									{ upload.state === 'paused' ? __( 'Resume', 'pack-vault' ) : __( 'Pause', 'pack-vault' ) }
								</button>
							) }
							<button className="sa-btn sa-btn--ghost" onClick={ abort }>
								{ __( 'Cancel', 'pack-vault' ) }
							</button>
						</div>
					) }

					{ upload.state === 'error' && (
						<div className="sa-callout is-error">
							<Icon name="alert" size={ 18 } />
							<div>
								<strong>{ __( 'Upload failed', 'pack-vault' ) }</strong>
								<p>{ upload.error }</p>
								<button className="sa-btn sa-btn--sm" onClick={ abort }>
									{ __( 'Try another file', 'pack-vault' ) }
								</button>
							</div>
						</div>
					) }

					{ upload.state === 'done' && (
						<div className="sa-callout is-success">
							<Icon name="check" size={ 18 } />
							<div>
								<strong>{ __( 'Upload complete', 'pack-vault' ) }</strong>
								<p>
									{ __( 'The backup is now stored on this site as', 'pack-vault' ) } <code>{ upload.result.name }</code>
								</p>
								<div className="sa-row">
									<button
										className="sa-btn sa-btn--primary"
										disabled={ busy || ! upload.backup }
										onClick={ () => requestRestore( upload.backup ) }
									>
										<Icon name="restore" size={ 18 } />
										{ __( 'Restore now', 'pack-vault' ) }
									</button>
									<button className="sa-btn" onClick={ () => setUpload( null ) }>
										{ __( 'Upload another', 'pack-vault' ) }
									</button>
								</div>
							</div>
						</div>
					) }
				</section>
			) }

			<section className="sa-grid sa-grid--3">
				<div className="sa-feature">
					<Icon name="upload" size={ 22 } />
					<h3>{ __( 'Resumable uploads', 'pack-vault' ) }</h3>
					<p>{ __( 'Pause any time. Dropped connections are retried automatically from the last chunk.', 'pack-vault' ) }</p>
				</div>
				<div className="sa-feature">
					<Icon name="globe" size={ 22 } />
					<h3>{ __( 'Painless migration', 'pack-vault' ) }</h3>
					<p>{ __( 'Moving domains? URLs and server paths are rewritten safely, even inside serialized data.', 'pack-vault' ) }</p>
				</div>
				<div className="sa-feature">
					<Icon name="shield" size={ 22 } />
					<h3>{ __( 'Safe database swap', 'pack-vault' ) }</h3>
					<p>{ __( 'The database is imported into temporary tables and swapped in at the end, so your site keeps running.', 'pack-vault' ) }</p>
				</div>
			</section>
		</div>
	);
};

export default Import;
