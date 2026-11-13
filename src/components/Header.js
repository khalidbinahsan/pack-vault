import { __ } from '@wordpress/i18n';
import Icon from '../icons';
import { config } from '../api';
import { classNames } from '../utils';

const NAV = [
	{ id: 'dashboard', icon: 'dashboard', label: __( 'Dashboard', 'pack-vault' ) },
	{ id: 'backups', icon: 'archive', label: __( 'Backups', 'pack-vault' ) },
	{ id: 'import', icon: 'upload', label: __( 'Import', 'pack-vault' ) },
	{ id: 'schedule', icon: 'calendar', label: __( 'Schedule', 'pack-vault' ) },
	{ id: 'remote', icon: 'cloud', label: __( 'Remote Storage', 'pack-vault' ) },
	{ id: 'settings', icon: 'settings', label: __( 'Settings', 'pack-vault' ) },
	{ id: 'about', icon: 'user', label: __( 'About', 'pack-vault' ) },
];

export const Logo = ( { size = 40 } ) => (
	<span className="sa-logo" style={ { width: size, height: size } }>
		<Icon name="vault" size={ size * 0.6 } strokeWidth={ 2 } />
	</span>
);

const Header = ( { route } ) => (
	<header className="sa-header">
		<div className="sa-header__top">
			<a className="sa-brand" href="#/dashboard">
				<Logo />
				<span className="sa-brand__text">
					<span className="sa-brand__name">
						Packvault <span className="sa-pill">v{ config.version }</span>
					</span>
					<span className="sa-brand__tag">
						{ __( 'Backup · Restore · Migrate', 'pack-vault' ) }
					</span>
				</span>
			</a>
			<a
				className="sa-byline"
				href={ config.author?.url }
				target="_blank"
				rel="noopener noreferrer"
			>
				{ __( 'by', 'pack-vault' ) } <strong>{ config.author?.name }</strong>
			</a>
		</div>
		<nav className="sa-nav" aria-label={ __( 'Packvault sections', 'pack-vault' ) }>
			{ NAV.map( ( item ) => (
				<a
					key={ item.id }
					href={ `#/${ item.id }` }
					className={ classNames( 'sa-nav__item', {
						'is-active': route === item.id,
					} ) }
					aria-current={ route === item.id ? 'page' : undefined }
				>
					<Icon name={ item.icon } size={ 18 } />
					<span>{ item.label }</span>
				</a>
			) ) }
		</nav>
	</header>
);

export default Header;
