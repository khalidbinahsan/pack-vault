import Icon from '../icons';

const Toasts = ( { toasts } ) => (
	<div className="sa-toasts" role="status" aria-live="polite">
		{ toasts.map( ( toast ) => (
			<div key={ toast.id } className={ `sa-toast is-${ toast.type }` }>
				<Icon name={ toast.type === 'error' ? 'alert' : 'check' } size={ 18 } />
				<span>{ toast.message }</span>
			</div>
		) ) }
	</div>
);

export default Toasts;
