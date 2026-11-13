import { __ } from '@wordpress/i18n';
import Icon from '../icons';
import { config } from '../api';

const PROVIDERS = [
	{ id: 'gdrive', name: 'Google Drive', color: '#1fa463', letter: 'G' },
	{ id: 'dropbox', name: 'Dropbox', color: '#0061fe', letter: 'D' },
	{ id: 'onedrive', name: 'Microsoft OneDrive', color: '#0078d4', letter: 'O' },
	{ id: 's3', name: 'Amazon S3 & S3-compatible', color: '#ff9900', letter: 'S3' },
	{ id: 'b2', name: 'Backblaze B2', color: '#e21e29', letter: 'B2' },
	{ id: 'ftp', name: 'FTP / SFTP', color: '#475569', letter: 'FTP' },
];

const Remote = () => (
	<div className="sa-page">
		<div className="sa-page__head">
			<div>
				<h1>{ __( 'Remote storage', 'pack-vault' ) }</h1>
				<p className="sa-muted">
					{ __( 'Send every backup off-site automatically, so a server failure never takes your backups with it.', 'pack-vault' ) }
				</p>
			</div>
		</div>

		<section className="sa-card sa-card--accent">
			<div className="sa-remote-local">
				<span className="sa-stat__icon">
					<Icon name="folder" size={ 20 } />
				</span>
				<div>
					<strong>{ __( 'Local storage', 'pack-vault' ) }</strong>
					<p className="sa-muted">
						<code>wp-content/packvault-backups</code> — { __( 'active and protected', 'pack-vault' ) }
					</p>
				</div>
				<span className="sa-badge is-schedule">{ __( 'Active', 'pack-vault' ) }</span>
			</div>
		</section>

		<section className="sa-grid sa-grid--3">
			{ PROVIDERS.map( ( p ) => (
				<div key={ p.id } className="sa-provider">
					<span className="sa-provider__logo" style={ { background: p.color } }>
						{ p.letter }
					</span>
					<h3>{ p.name }</h3>
					<span className="sa-badge">{ __( 'Coming soon', 'pack-vault' ) }</span>
				</div>
			) ) }
		</section>

		<p className="sa-footnote">
			<Icon name="heart" size={ 14 } />
			{ __( 'Which destination do you need first?', 'pack-vault' ) }{ ' ' }
			<a href={ `mailto:${ config.author?.email }?subject=Packvault%20remote%20storage` }>
				{ __( 'Tell the developer', 'pack-vault' ) }
			</a>
		</p>
	</div>
);

export default Remote;
