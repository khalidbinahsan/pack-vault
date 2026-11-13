import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import Modal from './Modal';
import Icon from '../icons';
import { config } from '../api';
import { formatBytes, formatDate, hostOf } from '../utils';

const WORD = 'RESTORE';

const ConfirmRestore = ( { backup, onCancel, onConfirm } ) => {
	const [ typed, setTyped ] = useState( '' );
	const [ starting, setStarting ] = useState( false );
	const migrating = backup.site_url && hostOf( backup.site_url ) !== hostOf( config.homeUrl );

	return (
		<Modal title={ __( 'Restore this backup?', 'pack-vault' ) } onClose={ onCancel }>
			<div className="sa-confirm">
				<div className="sa-confirm__file">
					<Icon name="archive" size={ 22 } />
					<div>
						<strong>{ backup.name }</strong>
						<span>
							{ formatDate( backup.created ) } · { formatBytes( backup.size ) }
						</span>
					</div>
				</div>

				{ migrating && (
					<div className="sa-callout is-info">
						<Icon name="globe" size={ 18 } />
						<div>
							<strong>{ __( 'Migration detected', 'pack-vault' ) }</strong>
							<p>
								{ hostOf( backup.site_url ) } → { hostOf( config.homeUrl ) }.{ ' ' }
								{ __( 'All URLs and paths will be updated automatically, including inside serialized data.', 'pack-vault' ) }
							</p>
						</div>
					</div>
				) }

				<div className="sa-callout is-warning">
					<Icon name="alert" size={ 18 } />
					<div>
						<strong>{ __( 'This overwrites the current site', 'pack-vault' ) }</strong>
						<ul>
							<li>{ __( 'Database content (posts, pages, users, settings) is replaced.', 'pack-vault' ) }</li>
							<li>{ __( 'Files in wp-content from the backup overwrite existing ones.', 'pack-vault' ) }</li>
							<li>{ __( 'You will need to log in again with the users from the backup.', 'pack-vault' ) }</li>
						</ul>
						<p>{ __( 'Tip: create a fresh backup of the current site first.', 'pack-vault' ) }</p>
					</div>
				</div>

				<label className="sa-field">
					<span className="sa-field__label">
						{ __( 'Type', 'pack-vault' ) } <code>{ WORD }</code> { __( 'to confirm', 'pack-vault' ) }
					</span>
					<input
						className="sa-input"
						value={ typed }
						onChange={ ( e ) => setTyped( e.target.value ) }
						placeholder={ WORD }
						autoComplete="off"
					/>
				</label>

				<div className="sa-row sa-row--end">
					<button className="sa-btn sa-btn--ghost" onClick={ onCancel }>
						{ __( 'Cancel', 'pack-vault' ) }
					</button>
					<button
						className="sa-btn sa-btn--danger"
						disabled={ typed.trim().toUpperCase() !== WORD || starting }
						onClick={ () => {
							setStarting( true );
							onConfirm();
						} }
					>
						<Icon name="restore" size={ 18 } />
						{ __( 'Restore now', 'pack-vault' ) }
					</button>
				</div>
			</div>
		</Modal>
	);
};

export default ConfirmRestore;
