/**
 * Agnosis Gallery Overview block — client-side navigation for the medium
 * filter and pagination.
 *
 * 2026-07-18: replaces an earlier pure client-side show/hide attempt
 * (frontend.js, same commit range — removed). That approach only ever
 * toggled visibility on whatever artworks were already rendered on the
 * current page, so a medium with matches on another page of a paginated
 * gallery silently showed nothing for it — an assumption ("the current
 * page's own items are enough") that doesn't hold once pagination is
 * actually in play, and was called out as such rather than shipped quietly.
 *
 * This uses WordPress's own Interactivity Router (`@wordpress/interactivity-
 * router`, 6.5+) instead: render.php already produces a fully correct,
 * paginated, medium-filtered page for any `?agnosis_medium_filter=&agnosis_
 * overview_page=` URL — every pill's and pagination link's `href` already
 * points straight at it. The router intercepts a click on one of those real links,
 * fetches that real URL's full server-rendered response (running the exact
 * same render.php, so genuinely correct — no separate code path to keep in
 * sync), and swaps only the DOM inside the matching `data-wp-router-region`
 * with what the server actually rendered for that request, instead of doing
 * a full page navigation. Pagination is therefore automatically correct: the
 * fetched page IS the real, complete answer for whatever medium/page
 * combination the visitor picked, not an assumption about what's on-screen.
 *
 * The router region wraps the filter nav + grid + pagination together (not
 * just the grid) so the pill row's own "active" state, the medium terms
 * offered, the grid contents, and the pagination links all update as one
 * consistent unit — exactly what a full navigation would have produced.
 *
 * Region ID is a static string rather than a generated unique ID
 * (contrast the multi-instance-safe pattern in most Interactivity Router
 * examples): this block is only ever placed once per page in this theme
 * (home.html's front-page gallery, or archive-agnosis_artwork.html's
 * archive gallery — never both on the same page), so a fixed ID is
 * sufficient. If a future template ever places two instances on one page,
 * each region's ID must become unique or the router won't know which one
 * to update — noted here rather than silently assumed away.
 */
// @wordpress/interactivity is a WordPress runtime external, supplied via WP's
// own browser import map when the block's viewScriptModule loads — never a
// real npm dependency actually bundled from node_modules (this plugin has no
// build step at all). eslint-plugin-import's resolver has no way to know
// that, so import/no-extraneous-dependencies and import/named — both aimed
// at catching genuine npm-dependency mistakes — don't apply here; see
// CONTRIBUTING.md's JS/CSS conventions for the fuller explanation.
// eslint-disable-next-line import/no-extraneous-dependencies, import/named
import { store, withSyncEvent } from '@wordpress/interactivity';

store( 'agnosis/gallery-overview', {
	actions: {
		// Prefetches the target page into the router's in-memory cache on
		// hover, so the actual click-triggered navigate() below usually
		// resolves instantly instead of waiting on a fresh fetch.
		prefetch: function* ( event ) {
			const { actions } = yield import( '@wordpress/interactivity-router' );
			yield actions.prefetch( event.target.href );
		},

		// withSyncEvent() is required here because event.preventDefault() must
		// run synchronously, before this generator's first yield hands control
		// back to the router's own async machinery.
		navigate: withSyncEvent( function* ( event ) {
			event.preventDefault();

			const { actions } = yield import( '@wordpress/interactivity-router' );
			yield actions.navigate( event.target.href );

			// The visitor is choosing a different medium or page of the SAME
			// gallery, not moving to a different part of the site — keep them
			// looking at the region that just updated instead of leaving the
			// scroll position wherever it happened to be (the router itself
			// does not manage scroll position; see the "Handling scroll and
			// focus" section of the Interactivity Router docs).
			const region = document.querySelector( '[data-wp-router-region="agnosis/gallery-overview"]' );
			if ( region ) {
				region.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		} ),
	},
} );
