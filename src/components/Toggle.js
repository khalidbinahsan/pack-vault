import Icon from '../icons';

const Toggle = ( { checked, onChange, label, help, icon, disabled } ) => (
	<label className={ `sa-toggle ${ disabled ? 'is-disabled' : '' }` }>
		{ icon && (
			<span className="sa-toggle__icon">
				<Icon name={ icon } size={ 18 } />
			</span>
		) }
		<span className="sa-toggle__text">
			<span className="sa-toggle__label">{ label }</span>
			{ help && <span className="sa-toggle__help">{ help }</span> }
		</span>
		<input
			type="checkbox"
			checked={ !! checked }
			disabled={ disabled }
			onChange={ ( e ) => onChange( e.target.checked ) }
		/>
		<span className="sa-switch" aria-hidden="true" />
	</label>
);

export default Toggle;
