import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import Icon from '../icons';
import { api, config } from '../api';
import { useApp } from '../context';
import { formatBytes, formatDate, timeAgo, hostOf } from '../utils';

const SOURCE_LABELS = {
	manual: __( 'Manual', 'pack-vault' ),
	schedule: __( 'Scheduled', 'pack-vault' ),
	upload: __( 'Uploaded', 'pack-vault' ),
};

const ContentChips = ( { contents } ) => {
	if ( ! contents || ! Object.keys( contents ).length ) {
		return null;
	}
	const items = [
		[ 'database', 'database', __( 'DB', 'pack-vault' ) ],
		[ 'media', 'image', __( 'Media', 'pack-vault' ) ],
		[ 'plugins', 'plug', __( 'Plugins', 'pack-vault' ) ],
		[ 'themes', 'palette', __( 'Themes', 'pack-vault' ) ],
	];
	return (
		<span className="sa-chips">
			{ items.map( ( [ key, icon, label ] ) => (
				<span
					key={ key }
					className={ `sa-chip ${ contents[ key ] ? '' : 'is-off' }` }
					title={ contents[ key ] ? label : sprintf( /* translators: %s: part */ __( '%s excluded', 'pack-vault' ), label ) }
				>
					<Icon name={ icon } size={ 12 } />
					{ label }
				</span>
			) ) }
		</span>
	);
};

const BackupList = ( { backups, limit, emptyAction = true } ) => {
	const { refresh, notify, requestRestore, navigate, startBackup, busy } = useApp();
	const [ deleting, setDeleting ] = useState( null );
	const list = limit ? backups.slice( 0, limit ) : backups;

	const remove = async ( backup ) => {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( sprintf( /* translators: %s: file name */ __( 'Delete %s permanently?', 'pack-vault' ), backup.name ) ) ) {
			return;
		}
		setDeleting( backup.name );
		try {
			await api( `/backups/${ encodeURIComponent( backup.name ) }`, { method: 'DELETE' } );
			notify( __( 'Backup deleted.', 'pack-vault' ) );
			await refresh();
		} catch ( e ) {
			notify( e.message, 'error' );
		}
		setDeleting( null );
	};

	if ( ! list.length ) {
		return (
			<div className="sa-empty">
				<span className="sa-empty__icon">
					<Icon name="archive" size={ 28 } />
				</span>
				<h3>{ __( 'No backups yet', 'pack-vault' ) }</h3>
				<p>{ __( 'Create your first backup, or import a .packvault file from another site.', 'pack-vault' ) }</p>
				{ emptyAction && (
					<div className="sa-row sa-row--center">
						<button className="sa-btn sa-btn--primary" onClick={ () => startBackup() } disabled={ busy }>
							<Icon name="vault" size={ 18 } />
							{ __( 'Back up now', 'pack-vault' ) }
						</button>
						<button className="sa-btn" onClick={ () => navigate( 'import' ) }>
							<Icon name="upload" size={ 18 } />
							{ __( 'Import', 'pack-vault' ) }
						</button>
					</div>
				) }
			</div>
		);
	}

	const sameSite = ( url ) => ! url || hostOf( url ) === hostOf( config.homeUrl );

	return (
		<ul className="sa-backups">
			{ list.map( ( backup ) => (
				<li key={ backup.name } className="sa-backup">
					<span className="sa-backup__icon">
						<Icon name="archive" size={ 20 } />
					</span>
					<div className="sa-backup__info">
						<div className="sa-backup__title">
							<span className="sa-backup__name" title={ backup.name }>
								{ backup.note || formatDate( backup.created ) }
							</span>
							<span className={ `sa-badge is-${ backup.source }` }>
								{ SOURCE_LABELS[ backup.source ] || backup.source }
							</span>
							{ ! backup.valid && (
								<span className="sa-badge is-error">{ __( 'Unreadable', 'pack-vault' ) }</span>
							) }
						</div>
						<div className="sa-backup__meta">
							<span title={ formatDate( backup.created ) }>{ timeAgo( backup.created ) }</span>
							<span>{ formatBytes( backup.size ) }</span>
							{ backup.site_url && (
								<span className={ sameSite( backup.site_url ) ? '' : 'sa-text-accent' }>
									<Icon name="globe" size={ 12 } /> { hostOf( backup.site_url ) }
								</span>
							) }
							<ContentChips contents={ backup.contents } />
						</div>
						<div className="sa-backup__file">{ backup.name }</div>
					</div>
					<div className="sa-backup__actions">
						<a className="sa-btn sa-btn--sm" href={ backup.download }>
							<Icon name="download" size={ 16 } />
							<span>{ __( 'Download', 'pack-vault' ) }</span>
						</a>
						<button
							className="sa-btn sa-btn--sm sa-btn--soft"
							onClick={ () => requestRestore( backup ) }
							disabled={ busy || ! backup.valid }
						>
							<Icon name="restore" size={ 16 } />
							<span>{ __( 'Restore', 'pack-vault' ) }</span>
						</button>
						<button
							className="sa-icon-btn is-danger"
							onClick={ () => remove( backup ) }
							disabled={ deleting === backup.name }
							aria-label={ __( 'Delete', 'pack-vault' ) }
							title={ __( 'Delete', 'pack-vault' ) }
						>
							<Icon name="trash" size={ 16 } />
						</button>
					</div>
				</li>
			) ) }
		</ul>
	);
};

export default BackupList;
