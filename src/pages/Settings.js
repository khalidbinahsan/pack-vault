import { __ } from '@wordpress/i18n';

import BackupOptions, { pickBackupOptions } from '../components/BackupOptions';
import { useApp } from '../context';
import useSettings from './useSettings';
import { formatBytes } from '../utils';

const Settings = () => {
	const { status } = useApp();
	const { draft, set, setDraft, save, saving, dirty } = useSettings();
	const sys = status.system;

	const rows = [
		[ __( 'Packvault', 'pack-vault' ), window.packvaultData?.version ],
		[ __( 'WordPress', 'pack-vault' ), sys.wp ],
		[ __( 'PHP', 'pack-vault' ), sys.php + ( sys.int_size < 8 ? ' (32-bit — files over 2 GB unsupported)' : ' (64-bit)' ) ],
		[ __( 'Database', 'pack-vault' ), sys.db ],
		[ __( 'Max execution time', 'pack-vault' ), sys.max_execution ? `${ sys.max_execution }s` : __( 'Unlimited', 'pack-vault' ) ],
		[ __( 'Memory limit', 'pack-vault' ), sys.memory_limit ],
		[ __( 'upload_max_filesize / post_max_size', 'pack-vault' ), `${ sys.upload_max } / ${ sys.post_max }` ],
		[ __( 'Upload chunk size', 'pack-vault' ), formatBytes( sys.chunk_size ) ],
		[ __( 'Backup folder', 'pack-vault' ), status.storage.path ],
		[ __( 'Folder writable', 'pack-vault' ), status.storage.writable ? __( 'Yes', 'pack-vault' ) : __( 'No', 'pack-vault' ) ],
	];

	return (
		<div className="sa-page">
			<div className="sa-page__head">
				<div>
					<h1>{ __( 'Settings', 'pack-vault' ) }</h1>
					<p className="sa-muted">{ __( 'Defaults used for every new backup, including scheduled ones.', 'pack-vault' ) }</p>
				</div>
				<button className="sa-btn sa-btn--primary" onClick={ save } disabled={ ! dirty || saving }>
					{ saving ? __( 'Saving…', 'pack-vault' ) : __( 'Save settings', 'pack-vault' ) }
				</button>
			</div>

			<section className="sa-card">
				<div className="sa-card__head">
					<h2>{ __( 'Backup contents', 'pack-vault' ) }</h2>
				</div>
				<BackupOptions
					value={ pickBackupOptions( draft ) }
					onChange={ ( opts ) => setDraft( ( d ) => ( { ...d, ...opts } ) ) }
				/>
			</section>

			<section className="sa-card">
				<div className="sa-card__head">
					<h2>{ __( 'Exclusions', 'pack-vault' ) }</h2>
				</div>
				<div className="sa-form__row">
					<label className="sa-field">
						<span className="sa-field__label">{ __( 'Exclude folders or files', 'pack-vault' ) }</span>
						<textarea
							className="sa-input sa-input--mono"
							rows={ 5 }
							value={ draft.exclude_paths }
							onChange={ ( e ) => set( 'exclude_paths', e.target.value ) }
							placeholder={ 'uploads/big-videos\nplugins/some-plugin/cache' }
						/>
						<span className="sa-field__help">
							{ __( 'One path per line, relative to wp-content. Cache and other backup-plugin folders are always skipped.', 'pack-vault' ) }
						</span>
					</label>
					<label className="sa-field">
						<span className="sa-field__label">{ __( 'Exclude file extensions', 'pack-vault' ) }</span>
						<textarea
							className="sa-input sa-input--mono"
							rows={ 5 }
							value={ draft.exclude_extensions }
							onChange={ ( e ) => set( 'exclude_extensions', e.target.value ) }
							placeholder={ 'log\nzip\nmp4' }
						/>
						<span className="sa-field__help">{ __( 'One per line or comma-separated, without the dot.', 'pack-vault' ) }</span>
					</label>
				</div>
			</section>

			<section className="sa-card">
				<div className="sa-card__head">
					<h2>{ __( 'Performance', 'pack-vault' ) }</h2>
				</div>
				<label className="sa-field">
					<span className="sa-field__label">
						{ __( 'Work per request', 'pack-vault' ) }: <strong>{ draft.time_budget }s</strong>
					</span>
					<input
						type="range"
						min="5"
						max="60"
						step="1"
						className="sa-range"
						value={ draft.time_budget }
						onChange={ ( e ) => set( 'time_budget', parseInt( e.target.value, 10 ) ) }
					/>
					<span className="sa-field__help">
						{ __( 'Packvault splits long jobs into short steps so they never hit PHP time limits. Lower this on very restrictive hosts.', 'pack-vault' ) }
					</span>
				</label>
			</section>

			<section className="sa-card">
				<div className="sa-card__head">
					<h2>{ __( 'System information', 'pack-vault' ) }</h2>
				</div>
				<table className="sa-table">
					<tbody>
						{ rows.map( ( [ k, v ] ) => (
							<tr key={ k }>
								<th>{ k }</th>
								<td>
									<code>{ v }</code>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</section>
		</div>
	);
};

export default Settings;
