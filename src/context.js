import { createContext, useContext } from '@wordpress/element';

export const AppContext = createContext( {} );
export const useApp = () => useContext( AppContext );
