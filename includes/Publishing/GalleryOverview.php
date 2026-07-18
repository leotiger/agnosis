<?php
/**
 * Gallery overview block registration, featured-artwork meta, and meta box.
 *
 * Registers the agnosis/gallery-overview SSR block.
 * Registers the _agnosis_featured post meta (boolean, REST-exposed).
 * Adds a "Feature this artwork" checkbox to the agnosis_artwork editor sidebar.
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

class GalleryOverview {

	// ──────────────────────────────────────────────────────────────────────────
	// Block
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Flush the artist-IDs object-cache entry whenever an artwork is published
	 * or trashed, so the gallery never serves a stale empty list.
	 *
	 * Hooked to 'transition_post_status'.
	 *
	 * @param string   $new_status New post status.
	 * @param string   $old_status Old post status.
	 * @param \WP_Post $post       Post object.
	 */
	public function flush_artist_cache( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'agnosis_artwork' !== $post->post_type ) {
			return;
		}
		// Only act when publish/trash boundary is crossed.
		$affected = [ 'publish', 'trash' ];
		if ( ! in_array( $new_status, $affected, true ) && ! in_array( $old_status, $affected, true ) ) {
			return;
		}
		wp_cache_delete( 'agnosis_gallery_artist_ids', 'agnosis_gallery' );
	}

	/**
	 * Register the agnosis/gallery-overview block.
	 * Called on 'init'.
	 */
	public function register_block(): void {
		// view.js reaches the Interactivity Router only via a dynamic
		// `import('@wordpress/interactivity-router')` inside its own actions —
		// but the browser can only resolve that bare specifier through
		// WordPress's printed `<script type="importmap">`, and WordPress only
		// adds an entry to that import map for a module declared as a
		// DEPENDENCY of something actually enqueued, not for a module merely
		// enqueued standalone on its own. (Confirmed live: enqueuing
		// '@wordpress/interactivity-router' directly from render_block() did
		// print its own `<script type="module">` tag, but produced no import
		// map entry at all — clicking a filter pill threw "Module name,
		// '@wordpress/interactivity-router' does not resolve to a valid URL".)
		//
		// `wp-scripts` build tooling normally detects a dynamic import inside
		// view.js and auto-registers exactly this dependency on the block's
		// own view script module — this plugin has no build step, so it has
		// to be declared by hand here: register view.js as its own script
		// module with the router listed as a dynamic-import dependency, then
		// point block.json's "viewScriptModule" at this handle (a pre-
		// registered id is a valid alternative to "file:./view.js" there)
		// instead of letting WordPress auto-register it with no dependencies.
		wp_register_script_module(
			'agnosis/gallery-overview-view',
			\AGNOSIS_URL . 'blocks/gallery-overview/view.js',
			[
				// The bundled PHPStan stub types every entry in $dependencies as
				// array{id: string, import?: string} — it doesn't accept the bare-
				// string shorthand WP core also supports, so both dependencies are
				// spelled out in full here. Functionally identical either way:
				// 'import' defaults to 'static' when omitted, same as a bare string.
				[ 'id' => '@wordpress/interactivity' ],
				[
					'id'     => '@wordpress/interactivity-router',
					'import' => 'dynamic',
				],
			],
			\AGNOSIS_VERSION
		);

		register_block_type(
			\AGNOSIS_DIR . 'blocks/gallery-overview',
			[ 'render_callback' => [ $this, 'render_block' ] ]
		);
	}

	/**
	 * Server-side render callback for the agnosis/gallery-overview block.
	 *
	 * Includes render.php in an output buffer and returns the captured HTML.
	 * Using a PHP render_callback (rather than block.json "render": "file:...")
	 * avoids conflicts with WordPress's own output-buffer wrapper and works
	 * across all supported WordPress versions.
	 *
	 * @param array<string, mixed> $attributes Block attributes from the editor.
	 * @return string Rendered HTML.
	 */
	public function render_block( array $attributes ): string {
		// Medium-filter pills + pagination: client-side navigation via the WP
		// Interactivity API Router (block.json's "interactivity" support +
		// "viewScriptModule" — blocks/gallery-overview/view.js). view.js itself
		// needs no manual enqueue — WordPress auto-enqueues a block's declared
		// viewScriptModule whenever the block actually renders, the same way it
		// already does for editorScript. The router module it dynamically
		// imports at click-time is declared as a dependency of that view script
		// module in register_block() above (not enqueued standalone here) — see
		// that method's docblock for why a standalone enqueue doesn't work.

		// Enqueue the WP Interactivity API view module that powers the core/image
		// lightbox store (showLightbox action, setButtonStyles callback, etc.).
		wp_enqueue_script_module( '@wordpress/block-library/image/view' );

		// Enqueue the core image block stylesheet — provides .wp-lightbox-container,
		// .lightbox-trigger button, and .wp-lightbox-overlay styles.
		wp_enqueue_style( 'wp-block-image' );

		// Print the WP lightbox overlay <div> into wp_footer exactly once.
		// block_core_image_print_lightbox_overlay() is defined by WP when the
		// core/image block is registered (wp-includes/blocks/image.php).
		if ( function_exists( 'block_core_image_print_lightbox_overlay' )
			&& ! has_action( 'wp_footer', 'block_core_image_print_lightbox_overlay' ) ) {
			add_action( 'wp_footer', 'block_core_image_print_lightbox_overlay' );
		}

		ob_start();
		include \AGNOSIS_DIR . 'blocks/gallery-overview/render.php';
		return (string) ob_get_clean();
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Meta
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Register the _agnosis_featured post meta.
	 * Called on 'init'.
	 */
	public function register_meta(): void {
		register_post_meta(
			'agnosis_artwork',
			'_agnosis_featured',
			[
				'show_in_rest'  => true,
				'single'        => true,
				'type'          => 'boolean',
				'default'       => false,
				'auth_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
			]
		);
	}

	// ──────────────────────────────────────────────────────────────────────────
	// Meta box
	// ──────────────────────────────────────────────────────────────────────────

	/**
	 * Register the "Feature this artwork" meta box.
	 * Called on 'add_meta_boxes'.
	 */
	public function register_meta_box(): void {
		add_meta_box(
			'agnosis_featured_artwork',
			__( 'Gallery overview', 'agnosis' ),
			[ $this, 'render_meta_box' ],
			'agnosis_artwork',
			'side',
			'high'
		);
	}

	/**
	 * Render the meta box HTML.
	 *
	 * @param \WP_Post $post Current post.
	 */
	public function render_meta_box( \WP_Post $post ): void {
		$is_featured = '1' === (string) get_post_meta( $post->ID, '_agnosis_featured', true );
		wp_nonce_field( 'agnosis_featured_' . $post->ID, 'agnosis_featured_nonce' );
		?>
		<p style="margin:0;">
			<label style="display:flex;align-items:flex-start;gap:0.5em;cursor:pointer;">
				<input
					type="checkbox"
					name="agnosis_featured"
					value="1"
					<?php checked( $is_featured ); ?>
					style="margin-top:3px;flex-shrink:0;"
				>
				<span><?php esc_html_e( 'Feature this artwork in the gallery overview', 'agnosis' ); ?></span>
			</label>
		</p>
		<p style="margin:0.75em 0 0;font-size:12px;color:#888;">
			<?php esc_html_e( 'Featured artworks are always chosen first when selecting one work per artist for the homepage overview.', 'agnosis' ); ?>
		</p>
		<?php
	}

	/**
	 * Save the meta box value.
	 * Called on 'save_post_agnosis_artwork'.
	 *
	 * @param int $post_id Post ID.
	 */
	public function save_meta_box( int $post_id ): void {
		if ( ! isset( $_POST['agnosis_featured_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_POST['agnosis_featured_nonce'] ) ),
			'agnosis_featured_' . $post_id
		) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$value = isset( $_POST['agnosis_featured'] ) ? '1' : '0';
		update_post_meta( $post_id, '_agnosis_featured', $value );
	}
}
