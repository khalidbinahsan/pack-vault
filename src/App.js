import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { api } from './api';
import { AppContext } from './context';
import Header from './components/Header';
import JobModal from './components/JobModal';
import Toasts from './components/Toasts';
import ConfirmRestore from './components/ConfirmRestore';
import Dashboard from './pages/Dashboard';
import Backups from './pages/Backups';
import Import from './pages/Import';
import Schedule from './pages/Schedule';
import Remote from './pages/Remote';
import Settings from './pages/Settings';
import About from './pages/About';

const PAGES = {
	dashboard: Dashboard,
	backups: Backups,
	import: Import,
	schedule: Schedule,
	remote: Remote,
	settings: Settings,
	about: About,
};

const currentRoute = () => {
	const hash = window.location.hash.replace( /^#\/?/, '' ).split( '?' )[ 0 ];
	return PAGES[ hash ] ? hash : 'dashboard';
};

const App = () => {
	const [ route, setRoute ] = useState( currentRoute );
	const [ status, setStatus ] = useState( null );
	const [ loadError, setLoadError ] = useState( null );
	const [ job, setJob ] = useState( null );
	const [ restoreTarget, setRestoreTarget ] = useState( null );
	const [ toasts, setToasts ] = useState( [] );

	const notify = useCallback( ( message, type = 'success' ) => {
		const id = Date.now() + Math.random();
		setToasts( ( list ) => [ ...list, { id, message, type } ] );
		setTimeout(
			() => setToasts( ( list ) => list.filter( ( t ) => t.id !== id ) ),
			5000
		);
	}, [] );

	const refresh = useCallback( async () => {
		try {
			const data = await api( '/status' );
			setStatus( data );
			setLoadError( null );
			if ( data.active_job ) {
				setJob( ( current ) => current || data.active_job );
			}
			return data;
		} catch ( e ) {
			setLoadError( e.message || __( 'Could not load Packvault.', 'pack-vault' ) );
			return null;
		}
	}, [] );

	useEffect( () => {
		refresh();
		const onHash = () => {
			setRoute( currentRoute() );
			window.scrollTo( { top: 0 } );
		};
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [ refresh ] );

	const navigate = ( to ) => {
		window.location.hash = '#/' + to;
	};

	const startBackup = async ( options = {} ) => {
		try {
			const created = await api( '/backups', {
				method: 'POST',
				data: { options },
			} );
			setJob( created );
		} catch ( e ) {
			notify( e.message, 'error' );
		}
	};

	const startRestore = async ( name ) => {
		try {
			const created = await api(
				`/backups/${ encodeURIComponent( name ) }/restore`,
				{ method: 'POST' }
			);
			setRestoreTarget( null );
			setJob( created );
		} catch ( e ) {
			notify( e.message, 'error' );
		}
	};

	const context = {
		status,
		refresh,
		notify,
		navigate,
		startBackup,
		requestRestore: setRestoreTarget,
		busy: !! job,
	};

	const Page = PAGES[ route ];

	return (
		<AppContext.Provider value={ context }>
			<div className="sa-app">
				<Header route={ route } />
				<main className="sa-main">
					{ loadError && ! status && (
						<div className="sa-card sa-error-card">
							<strong>{ __( 'Packvault could not load.', 'pack-vault' ) }</strong>
							<p>{ loadError }</p>
							<button className="sa-btn" onClick={ refresh }>
								{ __( 'Try again', 'pack-vault' ) }
							</button>
						</div>
					) }
					{ ! status && ! loadError && <div className="sa-skeleton" /> }
					{ status && <Page /> }
				</main>
			</div>
			{ restoreTarget && (
				<ConfirmRestore
					backup={ restoreTarget }
					onCancel={ () => setRestoreTarget( null ) }
					onConfirm={ () => startRestore( restoreTarget.name ) }
				/>
			) }
			{ job && (
				<JobModal
					initialJob={ job }
					onClose={ () => {
						setJob( null );
						refresh();
					} }
				/>
			) }
			<Toasts toasts={ toasts } />
		</AppContext.Provider>
	);
};

export default App;
