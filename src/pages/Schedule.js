import { __ } from '@wordpress/i18n';

import Icon from '../icons';
import Toggle from '../components/Toggle';
import { useApp } from '../context';
import useSettings from './useSettings';
import { formatDate, timeAgo } from '../utils';

const FREQUENCIES = [
	{ id: 'hourly', label: __( 'Hourly', 'pack-vault' ) },
	{ id: 'twicedaily', label: __( 'Twice daily', 'pack-vault' ) },
	{ id: 'daily', label: __( 'Daily', 'pack-vault' ) },
	{ id: 'weekly', label: __( 'Weekly', 'pack-vault' ) },
];

const Schedule = () => {
	const { status } = useApp();
	const { draft, set, save, saving, dirty } = useSettings();
	const enabled = !! draft.schedule_enabled;

	return (
		<div className="sa-page">
			<div className="sa-page__head">
				<div>
					<h1>{ __( 'Automatic backups', 'pack-vault' ) }</h1>
					<p className="sa-muted">
						{ __( 'Set it once and Packvault backs up your site in the background — no browser tab needed.', 'pack-vault' ) }
					</p>
				</div>
				<button className="sa-btn sa-btn--primary" onClick={ save } disabled={ ! dirty || saving }>
					{ saving ? __( 'Saving…', 'pack-vault' ) : __( 'Save schedule', 'pack-vault' ) }
				</button>
			</div>

			<section className="sa-card">
				<Toggle
					icon="calendar"
					label={ __( 'Enable scheduled backups', 'pack-vault' ) }
					help={
						status.settings.schedule_enabled && status.next_run
							? `${ __( 'Next run', 'pack-vault' ) }: ${ formatDate( status.next_run ) } (${ timeAgo( status.next_run ) })`
							: __( 'Currently off', 'pack-vault' )
					}
					checked={ enabled }
					onChange={ ( v ) => set( 'schedule_enabled', v ) }
				/>

				<div className={ `sa-form ${ enabled ? '' : 'is-muted' }` }>
					<div className="sa-field">
						<span className="sa-field__label">{ __( 'How often', 'pack-vault' ) }</span>
						<div className="sa-segment" role="radiogroup">
							{ FREQUENCIES.map( ( f ) => (
								<button
									key={ f.id }
									type="button"
									role="radio"
									aria-checked={ draft.schedule_frequency === f.id }
									className={ draft.schedule_frequency === f.id ? 'is-active' : '' }
									onClick={ () => set( 'schedule_frequency', f.id ) }
									disabled={ ! enabled }
								>
									{ f.label }
								</button>
							) ) }
						</div>
					</div>

					<div className="sa-form__row">
						<label className="sa-field">
							<span className="sa-field__label">{ __( 'Start time', 'pack-vault' ) }</span>
							<input
								type="time"
								className="sa-input"
								value={ draft.schedule_time }
								disabled={ ! enabled }
								onChange={ ( e ) => set( 'schedule_time', e.target.value ) }
							/>
							<span className="sa-field__help">
								{ __( 'Site timezone:', 'pack-vault' ) } { status.system.timezone }
							</span>
						</label>
						<label className="sa-field">
							<span className="sa-field__label">{ __( 'Keep the latest', 'pack-vault' ) }</span>
							<div className="sa-input-group">
								<input
									type="number"
									min="0"
									max="1000"
									className="sa-input"
									value={ draft.retention }
									onChange={ ( e ) => set( 'retention', parseInt( e.target.value || '0', 10 ) ) }
								/>
								<span>{ __( 'backups', 'pack-vault' ) }</span>
							</div>
							<span className="sa-field__help">
								{ __( 'Older backups are deleted automatically. 0 keeps everything.', 'pack-vault' ) }
							</span>
						</label>
					</div>

					<label className="sa-field">
						<span className="sa-field__label">{ __( 'Email me the result (optional)', 'pack-vault' ) }</span>
						<input
							type="email"
							className="sa-input"
							value={ draft.schedule_email }
							disabled={ ! enabled }
							placeholder="you@example.com"
							onChange={ ( e ) => set( 'schedule_email', e.target.value ) }
						/>
					</label>
				</div>
			</section>

			{ status.system.cron_disabled && (
				<div className="sa-callout is-info">
					<Icon name="clock" size={ 18 } />
					<div>
						<strong>{ __( 'WP-Cron is disabled on this site', 'pack-vault' ) }</strong>
						<p>
							{ __( 'DISABLE_WP_CRON is set, so make sure a real server cron calls wp-cron.php regularly, otherwise scheduled backups will not run.', 'pack-vault' ) }
						</p>
					</div>
				</div>
			) }
		</div>
	);
};

export default Schedule;
