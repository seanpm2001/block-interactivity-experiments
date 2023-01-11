import { createContext } from 'preact';

if (typeof window.themeContext === 'undefined') {
	window.themeContext = window.wp.element
		? window.wp.element.createContext('initial')
		: createContext('initial');

	window.themeContext.displayName = 'ThemeContext';
}

export default window.themeContext;