import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Icon from '../icons';
import { useApp } from '../context';
import { config } from '../api';
import BackupList from '../components/BackupList';
import BackupOptions, { pickBackupOptions } from '../components/BackupOptions';
import { formatBytes, timeAgo, formatDate } from '../utils';

const Stat = ( { icon, label, value, hint, tone = 'indigo', onClick } ) => (
	<button type="button" className={ `sa-stat is-${ tone }` } onClick={ onClick } disabled={ ! onClick }>
		<span className="sa-stat__icon">
			<Icon name={ icon } size={ 20 } />
		</span>
		<span className="sa-stat__body">
			<span className="sa-stat__label">{ label }</span>
			<span className="sa-stat__value">{ value }</span>
			{ hint && <span className="sa-stat__hint">{ hint }</span> }
		</span>
	</button>
);

const Dashboard = () => {
	const { status, startBackup, navigate, busy } = useApp();
	const [ customize, setCustomize ] = useState( false );
	const [ options, setOptions ] = useState( () => pickBackupOptions( status.settings ) );
	const [ note, setNote ] = useState( '' );

	const { storage, backups, settings } = status;
	const last = backups[ 0 ];

	const go = () => startBackup( { ...options, note } );

	return (
		<div className="sa-page">
			<section className="sa-hero">
				<div className="sa-hero__content">
					<span className="sa-eyebrow">
						{ sprintf(
							/* translators: %s: user name */
							__( 'Welcome back, %s', 'pack-vault' ),
							config.userName || __( 'friend', 'pack-vault' )
						) }
					</span>
					<h1>{ __( 'Your whole site, safe in a single file.', 'pack-vault' ) }</h1>
					<p>
						{ __(
							'Packvault packs your database, media, plugins and themes into one portable .packvault file — download it, move it to another host, and restore it in a few clicks. No size limits.',
							'pack-vault'
						) }
					</p>
					<div className="sa-hero__actions">
						<button className="sa-btn sa-btn--light sa-btn--lg" onClick={ go } disabled={ busy }>
							<Icon name="vault" size={ 20 } />
							{ __( 'Back up now', 'pack-vault' ) }
						</button>
						<button className="sa-btn sa-btn--glass sa-btn--lg" onClick={ () => navigate( 'import' ) }>
							<Icon name="upload" size={ 20 } />
							{ __( 'Import / Restore', 'pack-vault' ) }
						</button>
						<button className="sa-link sa-link--light" onClick={ () => setCustomize( ! customize ) }>
							{ customize ? __( 'Hide options', 'pack-vault' ) : __( 'Customize this backup', 'pack-vault' ) }
						</button>
					</div>
				</div>
				<div className="sa-hero__art" aria-hidden="true">
					<div className="sa-hero__file">
						<Icon name="archive" size={ 38 } />
						<span>.packvault</span>
					</div>
				</div>
			</section>

			{ customize && (
				<section className="sa-card">
					<div className="sa-card__head">
						<h2>{ __( 'What goes into this backup', 'pack-vault' ) }</h2>
						<span className="sa-muted">{ __( 'Defaults can be changed in Settings', 'pack-vault' ) }</span>
					</div>
					<BackupOptions value={ options } onChange={ setOptions } />
					<label className="sa-field">
						<span className="sa-field__label">{ __( 'Label (optional)', 'pack-vault' ) }</span>
						<input
							className="sa-input"
							value={ note }
							maxLength={ 120 }
							onChange={ ( e ) => setNote( e.target.value ) }
							placeholder={ __( 'e.g. Before WooCommerce update', 'pack-vault' ) }
						/>
					</label>
				</section>
			) }

			<section className="sa-stats">
				<Stat
					icon="archive"
					label={ __( 'Backups', 'pack-vault' ) }
					value={ storage.count }
					hint={ formatBytes( storage.used ) + ' ' + __( 'used', 'pack-vault' ) }
					onClick={ () => navigate( 'backups' ) }
				/>
				<Stat
					icon="clock"
					tone="teal"
					label={ __( 'Last backup', 'pack-vault' ) }
					value={ last ? timeAgo( last.created ) : __( 'Never', 'pack-vault' ) }
					hint={ last ? formatBytes( last.size ) : __( 'Create one now', 'pack-vault' ) }
				/>
				<Stat
					icon="calendar"
					tone="violet"
					label={ __( 'Next scheduled', 'pack-vault' ) }
					value={ settings.schedule_enabled && status.next_run ? timeAgo( status.next_run ) : __( 'Off', 'pack-vault' ) }
					hint={
						settings.schedule_enabled && status.next_run
							? formatDate( status.next_run )
							: __( 'Set up daily backups', 'pack-vault' )
					}
					onClick={ () => navigate( 'schedule' ) }
				/>
				<Stat
					icon="server"
					tone="amber"
					label={ __( 'Free disk space', 'pack-vault' ) }
					value={ storage.free === null ? __( 'Unknown', 'pack-vault' ) : formatBytes( storage.free ) }
					hint={ storage.writable ? __( 'Storage is writable', 'pack-vault' ) : __( 'Folder not writable!', 'pack-vault' ) }
				/>
			</section>

			{ status.last_job && status.last_job.status === 'failed' && (
				<div className="sa-callout is-error">
					<Icon name="alert" size={ 18 } />
					<div>
						<strong>{ __( 'The last job failed', 'pack-vault' ) }</strong>
						<p>{ status.last_job.message }</p>
					</div>
				</div>
			) }

			<section className="sa-card">
				<div className="sa-card__head">
					<h2>{ __( 'Recent backups', 'pack-vault' ) }</h2>
					{ backups.length > 0 && (
						<button className="sa-link" onClick={ () => navigate( 'backups' ) }>
							{ __( 'View all', 'pack-vault' ) } →
						</button>
					) }
				</div>
				<BackupList backups={ backups } limit={ 5 } />
			</section>
		</div>
	);
};

export default Dashboard;
