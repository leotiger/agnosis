<?php
/**
 * Public newsletter popover — agnosis/newsletter-popover dynamic block.
 *
 * Renders the icon trigger button, the full-viewport popover panel, and the
 * agnosis/newsletter-signup form inside it, as one self-contained block.
 *
 * Moved out of agnosis-theme (0.5.3) — the trigger + popover chrome used to
 * live as theme markup (a wp:html button, a wp:group anchored
 * "lf-newsletter-popover", plus a render_block_core/group filter in the
 * theme's functions.php that added the `popover="auto"` attribute by regex
 * since a plain Group block has no such attribute of its own). That meant a
 * different theme built against this plugin would have had to reimplement
 * all of it from scratch to get a working subscribe popover. Now any theme
 * gets the full behavior from a single `<!-- wp:agnosis/newsletter-popover /-->`,
 * and `popover="auto"` is just written directly in this class's own markup —
 * no content-filter hack needed at all.
 *
 * @package Agnosis\Newsletter
 */

declare(strict_types=1);

namespace Agnosis\Newsletter;

class PopoverBlock {

	/**
	 * Trigger-icon options, keyed by the `icon` block attribute value.
	 *
	 * Each entry is the raw inner markup (path/polygon elements only) for an
	 * 24×24 viewBox, stroke="currentColor" SVG — the outer <svg> wrapper in
	 * render_block() supplies the shared attributes so every option renders
	 * at the same size/weight regardless of which is picked. All four are
	 * plain Feather-style line icons, matching the close button's stroke.
	 *
	 * @var array<string, string>
	 */
	private const ICONS = [
		'bell'     => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path>',
		'envelope' => '<rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="m2 6 10 7 10-7"></path>',
		'star'     => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>',
		'zap'      => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>',
	];

	/**
	 * Register the agnosis/newsletter-popover dynamic block.
	 *
	 * block.json lives in blocks/newsletter-popover/ relative to the plugin root.
	 */
	public function register_block(): void {
		register_block_type(
			\AGNOSIS_DIR . 'blocks/newsletter-popover',
			[ 'render_callback' => [ $this, 'render_block' ] ]
		);
	}

	/**
	 * PHP render_callback for the agnosis/newsletter-popover block.
	 *
	 * Renders nothing at all when the public newsletter is disabled — same
	 * gate SignupBlock::render_block() uses, checked here too so the trigger
	 * button doesn't appear pointing at a form that won't render.
	 *
	 * @param array<string, mixed> $attributes Block attributes — supports 'icon'
	 *                                          (one of self::ICONS' keys, default 'bell').
	 * @return string
	 */
	public function render_block( array $attributes = [] ): string {
		if ( ! get_option( 'agnosis_newsletter_public_enabled' ) ) {
			return '';
		}

		$this->enqueue_assets();

		$icon        = (string) ( $attributes['icon'] ?? 'bell' );
		$icon_markup = self::ICONS[ $icon ] ?? self::ICONS['bell'];

		// Standard WP block rendering rather than instantiating SignupBlock
		// directly, so agnosis/newsletter-signup renders exactly as it would
		// anywhere else — through the normal render_block filter pipeline —
		// rather than bypassing it via a direct method call.
		$signup_form = render_block( [
			'blockName'    => 'agnosis/newsletter-signup',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		] );

		/**
		 * Filters the intro copy shown above the signup form inside the popover.
		 *
		 * @param string $intro Default intro text.
		 */
		$intro = (string) apply_filters(
			'agnosis_newsletter_popover_intro',
			__( 'Get new artworks, artist admissions, and community updates in your inbox.', 'agnosis' )
		);

		ob_start();
		?>
		<button
			type="button"
			class="lf-icon-btn lf-newsletter-trigger"
			popovertarget="lf-newsletter-popover"
			popovertargetaction="show"
			aria-label="<?php esc_attr_e( 'Subscribe to the newsletter', 'agnosis' ); ?>"
		>
			<svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">
				<?php echo $icon_markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed, hand-authored markup from self::ICONS, never user input. ?>
			</svg>
		</button>

		<div id="lf-newsletter-popover" popover="auto">
			<button
				type="button"
				class="lf-icon-btn lf-popover-close"
				popovertarget="lf-newsletter-popover"
				popovertargetaction="hide"
				aria-label="<?php esc_attr_e( 'Close', 'agnosis' ); ?>"
			>
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">
					<path d="M4 4l16 16M20 4 4 20"></path>
				</svg>
			</button>

			<div class="agnosis-newsletter-popover__inner">
				<p class="agnosis-newsletter-popover__intro"><?php echo esc_html( $intro ); ?></p>
				<?php echo $signup_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_block() output is already escaped by the newsletter-signup block itself. ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	private function enqueue_assets(): void {
		wp_enqueue_style(
			'agnosis-newsletter-popover',
			\AGNOSIS_URL . 'blocks/newsletter-popover/frontend.css',
			[],
			\AGNOSIS_VERSION
		);
	}
}
