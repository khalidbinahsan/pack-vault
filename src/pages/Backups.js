import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import Icon from '../icons';
import { useApp } from '../context';
import BackupList from '../components/BackupList';
import { formatBytes } from '../utils';

const Backups = () => {
	const { status, refresh, navigate, startBackup, busy } = useApp();
	const [ query, setQuery ] = useState( '' );
	const [ refreshing, setRefreshing ] = useState( false );

	const q = query.trim().toLowerCase();
	const backups = q
		? status.backups.filter( ( b ) =>
				[ b.name, b.note, b.site_url ].join( ' ' ).toLowerCase().includes( q )
		  )
		: status.backups;

	return (
		<div className="sa-page">
			<div className="sa-page__head">
				<div>
					<h1>{ __( 'Backups', 'pack-vault' ) }</h1>
					<p className="sa-muted">
						{ status.storage.count } { __( 'backups', 'pack-vault' ) } · { formatBytes( status.storage.used ) } ·{ ' ' }
						<code>wp-content/packvault-backups</code>
					</p>
				</div>
				<div className="sa-row">
					<button className="sa-btn" onClick={ () => navigate( 'import' ) }>
						<Icon name="upload" size={ 18 } />
						{ __( 'Upload backup', 'pack-vault' ) }
					</button>
					<button className="sa-btn sa-btn--primary" onClick={ () => startBackup() } disabled={ busy }>
						<Icon name="vault" size={ 18 } />
						{ __( 'Back up now', 'pack-vault' ) }
					</button>
				</div>
			</div>

			<section className="sa-card">
				<div className="sa-card__head">
					<label className="sa-search">
						<Icon name="search" size={ 16 } />
						<input
							type="search"
							value={ query }
							onChange={ ( e ) => setQuery( e.target.value ) }
							placeholder={ __( 'Search backups…', 'pack-vault' ) }
						/>
					</label>
					<button
						className="sa-icon-btn"
						title={ __( 'Refresh', 'pack-vault' ) }
						aria-label={ __( 'Refresh', 'pack-vault' ) }
						disabled={ refreshing }
						onClick={ async () => {
							setRefreshing( true );
							await refresh();
							setRefreshing( false );
						} }
					>
						<Icon name="refresh" size={ 16 } className={ refreshing ? 'is-spinning' : '' } />
					</button>
				</div>
				<BackupList backups={ backups } emptyAction={ ! q } />
			</section>

			<p className="sa-footnote">
				<Icon name="shield" size={ 14 } />
				{ __(
					'Backups are stored in a folder that blocks direct web access, and downloads require an administrator login.',
					'pack-vault'
				) }
			</p>
		</div>
	);
};

export default Backups;
