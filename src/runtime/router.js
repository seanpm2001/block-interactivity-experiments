import { hydrate, render } from 'preact';
import toVdom from './vdom';
import { createRootFragment } from './utils';
import { startTransition } from './transitions';

// The root to render the vdom (document.body).
let rootFragment;

// The cache of visited and prefetched pages.
const pages = new Map();
const stylesheets = new Map();

// Helper to remove domain and hash from the URL. We are only interesting in
// caching the path and the query.
const cleanUrl = (url) => {
	const u = new URL(url, 'http://a.bc');
	return u.pathname + u.search;
};

// Fetch styles of a new page.
const fetchHead = async (head) => {
	const sheets = await Promise.all(
		[].map.call(head.querySelectorAll("link[rel='stylesheet']"), (link) => {
			const href = link.getAttribute('href');
			if (!stylesheets.has(href))
				stylesheets.set(
					href,
					fetch(href).then((r) => r.text())
				);
			return stylesheets.get(href);
		})
	);
	const stylesFromSheets = sheets.map((sheet) => {
		const style = document.createElement('style');
		style.textContent = sheet;
		return style;
	});
	return [
		head.querySelector('title'),
		...head.querySelectorAll('style'),
		...stylesFromSheets,
	];
};

// Fetch a new page and convert it to a static virtual DOM.
const fetchPage = async (url) => {
	const html = await window.fetch(url).then((r) => r.text());
	const dom = new window.DOMParser().parseFromString(html, 'text/html');
	const head = await fetchHead(dom.head);
	return { head, body: toVdom(dom.body) };
};

// Prefetch a page. We store the promise to avoid triggering a second fetch for
// a page if a fetching has already started.
export const prefetch = (url) => {
	url = cleanUrl(url);
	if (!pages.has(url)) {
		pages.set(url, fetchPage(url));
	}
};

// Navigate to a new page.
export const navigate = async (href) => {
	const url = cleanUrl(href);
	prefetch(url);
	const { body, head } = await pages.get(url);
	await startTransition(url, () => {
		document.head.replaceChildren(...head);
		render(body, rootFragment);
	});
	window.history.pushState({ wp: { clientNavigation: true } }, '', href);
};

// Listen to the back and forward buttons and restore the page if it's in the
// cache.
window.addEventListener('popstate', async () => {
	const url = cleanUrl(window.location); // Remove hash.
	if (pages.has(url)) {
		const { body, head } = await pages.get(url);
		await startTransition(url, () => {
			document.head.replaceChildren(...head);
			render(body, rootFragment);
		});
	} else {
		window.location.reload();
	}
});

// Initialize the router with the initial DOM.
export const init = async () => {
	const url = cleanUrl(window.location); // Remove hash.

	// Create the root fragment to hydrate everything.
	rootFragment = createRootFragment(document.documentElement, document.body);

	const body = toVdom(document.body);
	hydrate(body, rootFragment);
	const head = await fetchHead(document.head);
	pages.set(url, Promise.resolve({ body, head }));
};
