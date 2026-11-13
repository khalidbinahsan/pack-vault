import { __ } from '@wordpress/i18n';
import Toggle from './Toggle';

/**
 * Include/exclude switches. Values are "exclude_*" flags; the switches show
 * "include" so they read naturally.
 *
 * @param {Object}   props
 * @param {Object}   props.value    Exclude flags.
 * @param {Function} props.onChange Change handler.
 */
const BackupOptions = ( { value, onChange } ) => {
	const set = ( key, include ) => onChange( { ...value, [ key ]: ! include } );
	return (
		<div className="sa-options">
			<Toggle
				icon="database"
				label={ __( 'Database', 'pack-vault' ) }
				help={ __( 'Posts, pages, users and settings', 'pack-vault' ) }
				checked={ ! value.exclude_database }
				onChange={ ( v ) => set( 'exclude_database', v ) }
			/>
			<Toggle
				icon="image"
				label={ __( 'Media library', 'pack-vault' ) }
				help={ __( 'wp-content/uploads', 'pack-vault' ) }
				checked={ ! value.exclude_media }
				onChange={ ( v ) => set( 'exclude_media', v ) }
			/>
			<Toggle
				icon="plug"
				label={ __( 'Plugins', 'pack-vault' ) }
				help={ __( 'Including must-use plugins', 'pack-vault' ) }
				checked={ ! value.exclude_plugins }
				onChange={ ( v ) => set( 'exclude_plugins', v ) }
			/>
			<Toggle
				icon="palette"
				label={ __( 'Themes', 'pack-vault' ) }
				help={ __( 'wp-content/themes', 'pack-vault' ) }
				checked={ ! value.exclude_themes }
				onChange={ ( v ) => set( 'exclude_themes', v ) }
			/>
			<Toggle
				icon="shield"
				label={ __( 'Skip spam comments', 'pack-vault' ) }
				checked={ !! value.exclude_spam_comments }
				onChange={ ( v ) => onChange( { ...value, exclude_spam_comments: v } ) }
			/>
			<Toggle
				icon="refresh"
				label={ __( 'Skip post revisions', 'pack-vault' ) }
				checked={ !! value.exclude_post_revisions }
				onChange={ ( v ) => onChange( { ...value, exclude_post_revisions: v } ) }
			/>
		</div>
	);
};

export const pickBackupOptions = ( settings = {} ) => ( {
	exclude_database: !! settings.exclude_database,
	exclude_media: !! settings.exclude_media,
	exclude_plugins: !! settings.exclude_plugins,
	exclude_themes: !! settings.exclude_themes,
	exclude_spam_comments: !! settings.exclude_spam_comments,
	exclude_post_revisions: !! settings.exclude_post_revisions,
} );

export default BackupOptions;
