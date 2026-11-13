import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import Icon from '../icons';

const Modal = ( { title, children, onClose, size = 'md', dismissible = true } ) => {
	useEffect( () => {
		if ( ! dismissible ) {
			return undefined;
		}
		const onKey = ( e ) => e.key === 'Escape' && onClose && onClose();
		document.addEventListener( 'keydown', onKey );
		return () => document.removeEventListener( 'keydown', onKey );
	}, [ dismissible, onClose ] );

	return (
		<div className="sa-modal-backdrop">
			<div
				className={ `sa-modal sa-modal--${ size }` }
				role="dialog"
				aria-modal="true"
				aria-label={ title }
			>
				<div className="sa-modal__head">
					<h2>{ title }</h2>
					{ dismissible && onClose && (
						<button
							className="sa-icon-btn"
							onClick={ onClose }
							aria-label={ __( 'Close', 'pack-vault' ) }
						>
							<Icon name="x" size={ 18 } />
						</button>
					) }
				</div>
				<div className="sa-modal__body">{ children }</div>
			</div>
		</div>
	);
};

export default Modal;
