/**
 * Inline SVG icon set (stroke icons, 24px grid).
 */
const paths = {
	// Brand mark: a vault door.
	vault: (
		<>
			<rect x="3" y="4" width="18" height="15" rx="2.5" />
			<circle cx="12" cy="11.5" r="3.5" />
			<path d="M12 8v1.2M12 13.8V15M8.5 11.5h1.2M14.3 11.5h1.2" />
			<path d="M7 19v1.5M17 19v1.5" />
		</>
	),
	dashboard: (
		<>
			<rect x="3" y="3" width="7" height="9" rx="2" />
			<rect x="14" y="3" width="7" height="5" rx="2" />
			<rect x="14" y="12" width="7" height="9" rx="2" />
			<rect x="3" y="16" width="7" height="5" rx="2" />
		</>
	),
	archive: (
		<>
			<rect x="3" y="4" width="18" height="5" rx="1.5" />
			<path d="M5 9v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9" />
			<path d="M10 13h4" />
		</>
	),
	upload: (
		<>
			<path d="M12 16V4" />
			<path d="m7 9 5-5 5 5" />
			<path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" />
		</>
	),
	download: (
		<>
			<path d="M12 4v12" />
			<path d="m7 11 5 5 5-5" />
			<path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" />
		</>
	),
	restore: (
		<>
			<path d="M3 12a9 9 0 1 0 3-6.7" />
			<path d="M3 4v5h5" />
			<path d="M12 8v4l3 2" />
		</>
	),
	trash: (
		<>
			<path d="M4 7h16" />
			<path d="M10 11v6M14 11v6" />
			<path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12" />
			<path d="M9 7V4h6v3" />
		</>
	),
	clock: (
		<>
			<circle cx="12" cy="12" r="9" />
			<path d="M12 7v5l3 2" />
		</>
	),
	calendar: (
		<>
			<rect x="3" y="5" width="18" height="16" rx="2" />
			<path d="M16 3v4M8 3v4M3 10h18" />
		</>
	),
	cloud: (
		<path d="M7 18a5 5 0 0 1-.6-9.96A6 6 0 0 1 18 9a4.5 4.5 0 0 1-.5 9H7Z" />
	),
	settings: (
		<>
			<circle cx="12" cy="12" r="3" />
			<path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z" />
		</>
	),
	user: (
		<>
			<circle cx="12" cy="8" r="4" />
			<path d="M4 21a8 8 0 0 1 16 0" />
		</>
	),
	database: (
		<>
			<ellipse cx="12" cy="5" rx="8" ry="3" />
			<path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5" />
			<path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3" />
		</>
	),
	image: (
		<>
			<rect x="3" y="3" width="18" height="18" rx="2" />
			<circle cx="9" cy="9" r="2" />
			<path d="m21 15-5-5L5 21" />
		</>
	),
	plug: (
		<>
			<path d="M9 2v6M15 2v6" />
			<path d="M6 8h12v4a6 6 0 0 1-12 0V8Z" />
			<path d="M12 18v4" />
		</>
	),
	palette: (
		<>
			<path d="M12 3a9 9 0 0 0 0 18c1.1 0 2-.9 2-2 0-.5-.2-1-.5-1.3-.3-.4-.5-.8-.5-1.3 0-1.1.9-2 2-2h2.3A4.7 4.7 0 0 0 22 9.7C22 5.9 17.5 3 12 3Z" />
			<circle cx="7.5" cy="11.5" r="1" />
			<circle cx="10.5" cy="7.5" r="1" />
			<circle cx="15.5" cy="7.5" r="1" />
		</>
	),
	check: <path d="m5 12 5 5L20 7" />,
	x: <path d="M18 6 6 18M6 6l12 12" />,
	alert: (
		<>
			<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z" />
			<path d="M12 9v4M12 17h.01" />
		</>
	),
	shield: (
		<>
			<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z" />
			<path d="m9 12 2 2 4-4" />
		</>
	),
	globe: (
		<>
			<circle cx="12" cy="12" r="9" />
			<path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18" />
		</>
	),
	mail: (
		<>
			<rect x="3" y="5" width="18" height="14" rx="2" />
			<path d="m3 7 9 6 9-6" />
		</>
	),
	heart: (
		<path d="M19.5 12.6 12 20l-7.5-7.4A5 5 0 0 1 12 6a5 5 0 0 1 7.5 6.6Z" />
	),
	star: (
		<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1 6.2L12 17.3 6.5 20.2l1-6.2L3 9.6l6.2-.9L12 3Z" />
	),
	pause: <path d="M8 5v14M16 5v14" />,
	play: <path d="m7 4 13 8-13 8V4Z" />,
	server: (
		<>
			<rect x="3" y="4" width="18" height="7" rx="2" />
			<rect x="3" y="13" width="18" height="7" rx="2" />
			<path d="M7 7.5h.01M7 16.5h.01" />
		</>
	),
	folder: (
		<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7Z" />
	),
	external: (
		<>
			<path d="M14 4h6v6" />
			<path d="M20 4 10 14" />
			<path d="M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4" />
		</>
	),
	refresh: (
		<>
			<path d="M20 11a8 8 0 0 0-14.9-3.9L3 9" />
			<path d="M3 4v5h5" />
			<path d="M4 13a8 8 0 0 0 14.9 3.9L21 15" />
			<path d="M21 20v-5h-5" />
		</>
	),
	search: (
		<>
			<circle cx="11" cy="11" r="7" />
			<path d="m20 20-3.5-3.5" />
		</>
	),
};

const Icon = ( { name, size = 20, className = '', strokeWidth = 1.8 } ) => (
	<svg
		className={ `sa-icon ${ className }` }
		width={ size }
		height={ size }
		viewBox="0 0 24 24"
		fill="none"
		stroke="currentColor"
		strokeWidth={ strokeWidth }
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
		focusable="false"
	>
		{ paths[ name ] || null }
	</svg>
);

export default Icon;
