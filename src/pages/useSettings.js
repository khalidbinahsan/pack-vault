import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { api } from '../api';
import { useApp } from '../context';

/**
 * Local editable copy of the settings with a save() helper.
 */
const useSettings = () => {
	const { status, refresh, notify } = useApp();
	const [ draft, setDraft ] = useState( status.settings );
	const [ saving, setSaving ] = useState( false );

	const set = ( key, value ) => setDraft( ( d ) => ( { ...d, [ key ]: value } ) );
	const dirty = JSON.stringify( draft ) !== JSON.stringify( status.settings );

	const save = async () => {
		setSaving( true );
		try {
			const res = await api( '/settings', { method: 'POST', data: draft } );
			setDraft( res.settings );
			await refresh();
			notify( __( 'Settings saved.', 'pack-vault' ) );
		} catch ( e ) {
			notify( e.message, 'error' );
		}
		setSaving( false );
	};

	return { draft, set, setDraft, save, saving, dirty };
};

export default useSettings;
