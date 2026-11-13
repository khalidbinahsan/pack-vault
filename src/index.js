/**
 * Packvault admin dashboard.
 *
 * @author Khalid Bin Ahsan <https://khalidbinahsan.com>
 */
import { createRoot, render } from '@wordpress/element';
import App from './App';
import './admin.scss';

const mount = () => {
	const el = document.getElementById( 'packvault-root' );
	if ( ! el ) {
		return;
	}
	if ( createRoot ) {
		createRoot( el ).render( <App /> );
	} else {
		render( <App />, el );
	}
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
