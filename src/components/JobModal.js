import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import Modal from './Modal';
import Icon from '../icons';
import { config, jobRequest, sleep } from '../api';
import { formatBytes, formatDuration, classNames } from '../utils';

const STEPS = {
	backup: [
		{ id: 'scan', label: __( 'Scan files', 'pack-vault' ) },
		{ id: 'database', label: __( 'Export database', 'pack-vault' ) },
		{ id: 'archive', label: __( 'Build .packvault file', 'pack-vault' ) },
		{ id: 'done', label: __( 'Finish', 'pack-vault' ) },
	],
	restore: [
		{ id: 'extract', label: __( 'Restore files', 'pack-vault' ) },
		{ id: 'import', label: __( 'Import database', 'pack-vault' ) },
		{ id: 'replace', label: __( 'Update URLs', 'pack-vault' ) },
		{ id: 'swap', label: __( 'Activate', 'pack-vault' ) },
		{ id: 'done', label: __( 'Finish', 'pack-vault' ) },
	],
};

const MAX_FAILURES = 12;

const Ring = ( { value, status } ) => {
	const r = 54;
	const c = 2 * Math.PI * r;
	return (
		<div className={ `sa-ring is-${ status }` }>
			<svg viewBox="0 0 120 120" aria-hidden="true">
				<circle className="sa-ring__track" cx="60" cy="60" r={ r } />
				<circle
					className="sa-ring__bar"
					cx="60"
					cy="60"
					r={ r }
					strokeDasharray={ c }
					strokeDashoffset={ c - ( c * value ) / 100 }
				/>
				{ status === 'running' && (
					// Continuous sweep so the ring visibly moves between updates.
					<g className="sa-ring__sweep">
						<circle
							cx="60"
							cy="60"
							r={ r }
							strokeDasharray={ `${ c * 0.12 } ${ c * 0.88 }` }
						/>
					</g>
				) }
			</svg>
			<div className="sa-ring__label">
				{ status === 'done' && <Icon name="check" size={ 40 } strokeWidth={ 2.5 } /> }
				{ ( status === 'failed' || status === 'cancelled' ) && (
					<Icon name="x" size={ 40 } strokeWidth={ 2.5 } />
				) }
				{ status === 'running' && <span>{ value }%</span> }
			</div>
		</div>
	);
};

const JobModal = ( { initialJob, onClose } ) => {
	const [ job, setJob ] = useState( initialJob );
	const [ warning, setWarning ] = useState( '' );
	const [ showLog, setShowLog ] = useState( true );
	const [ cancelling, setCancelling ] = useState( false );
	const [ , setTick ] = useState( 0 );
	const startedAt = useRef( Date.now() );
	const firstSample = useRef( null );
	const stopped = useRef( false );
	const logRef = useRef( null );
	const token = initialJob.token;
	const running = job.status === 'running';

	// Tick every second so the elapsed time keeps counting between server replies.
	useEffect( () => {
		if ( ! running ) {
			return;
		}
		const id = setInterval( () => setTick( ( t ) => t + 1 ), 1000 );
		return () => clearInterval( id );
	}, [ running ] );

	// Keep the log scrolled to the newest line.
	const logLength = job.log?.length || 0;
	useEffect( () => {
		if ( logRef.current ) {
			logRef.current.scrollTop = logRef.current.scrollHeight;
		}
	}, [ logLength, showLog ] );

	useEffect( () => {
		stopped.current = false;
		const loop = async () => {
			let current = { ...initialJob, token };
			let failures = 0;
			while ( ! stopped.current && current.status === 'running' ) {
				try {
					const next = await jobRequest( current, 'run' );
					current = { ...next, token };
					failures = 0;
					setWarning( '' );
					if ( ! stopped.current ) {
						setJob( current );
					}
				} catch ( e ) {
					if ( e.fatal ) {
						setJob( { ...current, status: 'failed', message: e.message } );
						return;
					}
					failures++;
					if ( failures >= MAX_FAILURES ) {
						setJob( {
							...current,
							status: 'failed',
							message: __(
								'Lost contact with the server. Reload the page to resume — the job continues where it stopped.',
								'pack-vault'
							),
						} );
						return;
					}
					setWarning(
						__( 'The server is busy, retrying…', 'pack-vault' ) +
							` (${ failures }/${ MAX_FAILURES })`
					);
					await sleep( Math.min( 15000, 1500 * failures ) );
					try {
						const fresh = await jobRequest( current );
						current = { ...fresh, token };
					} catch ( err ) {
						// Keep retrying the run call.
					}
				}
				await sleep( 250 );
			}
		};
		loop();
		return () => {
			stopped.current = true;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const cancel = async () => {
		const question =
			job.type === 'backup'
				? __( 'Cancel this backup? The partial backup file will be deleted.', 'pack-vault' )
				: __( 'Cancel this restore? Your site may be left partially restored.', 'pack-vault' );
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( question ) ) {
			return;
		}
		setCancelling( true );
		stopped.current = true;
		try {
			const result = await jobRequest( { ...job, token }, 'cancel' );
			setJob( { ...result, token } );
		} catch ( e ) {
			setJob( { ...job, status: 'cancelled', message: e.message } );
		}
		setCancelling( false );
	};

	const isBackup = job.type === 'backup';
	const steps = STEPS[ job.type ] || [];
	const currentIndex = steps.findIndex( ( s ) => s.id === job.step );
	const now = Date.now();
	const elapsed = ( now - startedAt.current ) / 1000;

	// Rough time-left estimate from the progress rate since we first saw progress.
	const progress = job.progress || 0;
	if ( running && progress > 0 && ! firstSample.current ) {
		firstSample.current = { t: now, p: progress };
	}
	let remaining = null;
	if ( running && firstSample.current ) {
		const dp = progress - firstSample.current.p;
		const dt = ( now - firstSample.current.t ) / 1000;
		if ( dp >= 2 && dt >= 10 ) {
			remaining = ( dt / dp ) * ( 100 - progress );
		}
	}

	let title = isBackup
		? __( 'Creating backup', 'pack-vault' )
		: __( 'Restoring backup', 'pack-vault' );
	if ( job.status === 'done' ) {
		title = isBackup
			? __( 'Backup ready', 'pack-vault' )
			: __( 'Restore complete', 'pack-vault' );
	} else if ( job.status === 'failed' ) {
		title = __( 'Something went wrong', 'pack-vault' );
	} else if ( job.status === 'cancelled' ) {
		title = __( 'Cancelled', 'pack-vault' );
	}

	return (
		<Modal title={ title } size="md" dismissible={ ! running } onClose={ onClose }>
			<div className="sa-job">
				<Ring value={ job.progress || 0 } status={ job.status } />
				<p className="sa-job__message">{ job.message }</p>
				{ running && (
					<p className="sa-job__meta">
						<span className="sa-live" aria-hidden="true" />
						{ __( 'Working', 'pack-vault' ) } · { __( 'Elapsed', 'pack-vault' ) }{ ' ' }
						{ formatDuration( elapsed ) }
						{ remaining !== null && (
							<>
								{ ' · ' }
								{ __( 'About', 'pack-vault' ) } { formatDuration( remaining ) }{ ' ' }
								{ __( 'left', 'pack-vault' ) }
							</>
						) }
						{ ' · ' }
						{ __( 'Keep this tab open.', 'pack-vault' ) }
					</p>
				) }
				{ warning && <p className="sa-job__warning">{ warning }</p> }

				<ol className="sa-steps">
					{ steps.map( ( step, i ) => (
						<li
							key={ step.id }
							className={ classNames( 'sa-steps__item', {
								'is-done':
									job.status === 'done' || ( currentIndex > -1 && i < currentIndex ),
								'is-current': running && i === currentIndex,
							} ) }
						>
							<span className="sa-steps__dot">
								<Icon name="check" size={ 12 } strokeWidth={ 3 } />
							</span>
							{ step.label }
						</li>
					) ) }
				</ol>

				{ job.status === 'done' && isBackup && job.result && (
					<div className="sa-job__result">
						<div>
							<strong>{ job.result.name }</strong>
							<span>{ formatBytes( job.result.size ) }</span>
						</div>
						<button
							className="sa-btn sa-btn--primary"
							onClick={ () => {
								onClose();
								window.location.hash = '#/backups';
							} }
						>
							<Icon name="archive" size={ 18 } />
							{ __( 'View & download', 'pack-vault' ) }
						</button>
					</div>
				) }

				{ job.status === 'done' && ! isBackup && (
					<div className="sa-job__result is-restore">
						<p>
							{ job.result?.database
								? __(
										'Your site has been restored. Because the database was replaced, please log in again with the credentials from the backup.',
										'pack-vault'
								  )
								: __( 'Your files have been restored.', 'pack-vault' ) }
						</p>
						<a
							className="sa-btn sa-btn--primary"
							href={
								job.result?.database
									? job.result.login_url || config.loginUrl
									: window.location.href
							}
							onClick={ () => {
								if ( ! job.result?.database ) {
									window.location.reload();
								}
							} }
						>
							{ job.result?.database
								? __( 'Log in again', 'pack-vault' )
								: __( 'Reload', 'pack-vault' ) }
						</a>
					</div>
				) }

				<div className="sa-job__actions">
					{ job.log?.length > 0 && (
						<button className="sa-link" onClick={ () => setShowLog( ! showLog ) }>
							{ showLog ? __( 'Hide log', 'pack-vault' ) : __( 'Show log', 'pack-vault' ) }
						</button>
					) }
					<span className="sa-spacer" />
					{ running ? (
						<button className="sa-btn sa-btn--danger" onClick={ cancel } disabled={ cancelling }>
							<Icon name="x" size={ 16 } strokeWidth={ 2.2 } />
							{ cancelling
								? __( 'Cancelling…', 'pack-vault' )
								: isBackup
								? __( 'Cancel backup', 'pack-vault' )
								: __( 'Cancel restore', 'pack-vault' ) }
						</button>
					) : (
						! ( job.status === 'done' && ! isBackup && job.result?.database ) && (
							<button className="sa-btn" onClick={ onClose }>
								{ __( 'Close', 'pack-vault' ) }
							</button>
						)
					) }
				</div>
				{ showLog && job.log?.length > 0 && (
					<pre className="sa-log" ref={ logRef }>
						{ job.log.join( '\n' ) }
						{ running && <span className="sa-log__cursor" aria-hidden="true" /> }
					</pre>
				) }
			</div>
		</Modal>
	);
};

export default JobModal;
