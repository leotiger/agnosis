<?php
/**
 * Frontend submissions review page.
 *
 * Provides three things:
 *
 *   1. Block agnosis/submissions — FSE-compatible dynamic block. Register on any
 *      page or Site Editor template. Renders server-side via render_block().
 *
 *   2. Shortcode [agnosis_submissions] — classic fallback for themes that don't
 *      use FSE. Calls the same render() method as the block.
 *
 *   3. REST endpoint GET /agnosis/v1/submissions/mine — returns the current
 *      user's pending drafts as JSON (used by the card JS for live updates).
 *
 * @package Agnosis\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Publishing;

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Core\Turnstile;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_User;

class SubmissionsPage {

	/** Shortcode tag. */
	private const SHORTCODE = 'agnosis_submissions';

	public function register_shortcode(): void {
		add_shortcode( self::SHORTCODE, [ $this, 'render_shortcode' ] );
	}

	/**
	 * Register the agnosis/submissions dynamic block.
	 *
	 * block.json lives in blocks/submissions/ relative to the plugin root.
	 * WordPress auto-registers the editorScript and editorStyle declared there.
	 * The render_callback supplies the frontend HTML server-side.
	 */
	public function register_block(): void {
		register_block_type(
			AGNOSIS_DIR . 'blocks/submissions',
			[ 'render_callback' => [ $this, 'render_block' ] ]
		);
	}

	/**
	 * Add the 'agnosis' block category so all Agnosis blocks group together
	 * in the block inserter.
	 *
	 * @param array<int, array<string, mixed>> $categories Existing block categories.
	 * @return array<int, array<string, mixed>>
	 */
	public function add_block_category( array $categories ): array {
		array_unshift( $categories, [
			'slug'  => 'agnosis',
			'title' => __( 'Agnosis', 'agnosis' ),
			'icon'  => null,
		] );
		return $categories;
	}

	public function register_routes(): void {
		register_rest_route( 'agnosis/v1', '/submissions/mine', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_mine' ],
			'permission_callback' => [ $this, 'require_logged_in' ],
		] );
	}

	// -------------------------------------------------------------------------
	// Shortcode + Block render
	// -------------------------------------------------------------------------

	/**
	 * Render the [agnosis_submissions] shortcode.
	 * Delegates to the shared render() method.
	 *
	 * @return string HTML output.
	 */
	public function render_shortcode(): string {
		return $this->render();
	}

	/**
	 * PHP render_callback for the agnosis/submissions block.
	 * WordPress passes ($attributes, $content, $block) — all unused here.
	 *
	 * @param array<string, mixed> $attributes Block attributes (none defined).
	 * @return string HTML output.
	 */
	public function render_block( array $attributes = [] ): string {
		return $this->render();
	}

	// -------------------------------------------------------------------------
	// Shared renderer
	// -------------------------------------------------------------------------

	/**
	 * Core rendering logic — used by both the shortcode and the block.
	 *
	 * @return string HTML output.
	 */
	private function render(): string {
		if ( ! is_user_logged_in() ) {
			return $this->render_login_form();
		}

		$drafts = $this->fetch_drafts( get_current_user_id() );

		ob_start();
		$this->enqueue_assets();
		?>
		<div id="agnosis-submissions" class="agnosis-submissions">

			<?php if ( empty( $drafts ) ) : ?>
				<p class="agnosis-empty"><?php esc_html_e( 'No submissions awaiting review.', 'agnosis' ); ?></p>
			<?php else : ?>
				<?php foreach ( $drafts as $post ) : ?>
					<?php $this->render_card( $post ); ?>
				<?php endforeach; ?>
			<?php endif; ?>

		</div><!-- #agnosis-submissions -->
		<?php
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// REST callback
	// -------------------------------------------------------------------------

	public function get_mine( WP_REST_Request $request ): WP_REST_Response {
		$drafts = $this->fetch_drafts( get_current_user_id() );
		$data   = [];

		foreach ( $drafts as $post ) {
			$thumb     = get_post_thumbnail_id( $post->ID );
			$thumb_url = $thumb ? wp_get_attachment_image_url( $thumb, 'agnosis-thumb' ) : '';

			$data[] = [
				'id'        => $post->ID,
				'title'     => $post->post_title,
				'excerpt'   => $post->post_excerpt,
				'thumb_url' => $thumb_url ?: '',
				'edit_url'  => get_edit_post_link( $post->ID, 'raw' ) ?: '',
			];
		}

		return new WP_REST_Response( $data, 200 );
	}

	public function require_logged_in(): bool|WP_Error {
		return is_user_logged_in()
			? true
			: new WP_Error( 'agnosis_auth', __( 'You must be logged in.', 'agnosis' ), [ 'status' => 401 ] );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Fetch draft agnosis_artwork posts authored by a given user.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return \WP_Post[]
	 */
	private function fetch_drafts( int $user_id ): array {
		return get_posts( [
			'post_type'      => 'agnosis_artwork',
			'post_status'    => 'draft',
			'author'         => $user_id,
			'posts_per_page' => 50,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		] );
	}

	/**
	 * Render a single submission card with inline editing.
	 *
	 * The card shows the artwork and three actions:
	 *   • Publish — approve as-is.
	 *   • Make changes — expands an inline form (title, excerpt, body, tags).
	 *   • Discard — trash the draft.
	 *
	 * No link to WP admin — artists never need to go there.
	 *
	 * @param \WP_Post $post Draft post.
	 */
	private function render_card( \WP_Post $post ): void {
		$thumb_id  = get_post_thumbnail_id( $post->ID );
		$thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'agnosis-thumb' ) : '';
		$nonce     = wp_create_nonce( 'wp_rest' );

		// Strip block markup to get the raw body text for the edit textarea.
		$body_text = wp_strip_all_tags( preg_replace( '/<!--[^>]+-->/', '', $post->post_content ) ?? '' );
		$body_text = trim( $body_text );

		// Tags for the edit field.
		$tags     = get_the_tags( $post->ID );
		$tags_csv = $tags ? implode( ', ', array_column( (array) $tags, 'name' ) ) : '';

		// Same reasoning as ReviewConfirm::render_approve_confirm()'s identical
		// lookup: title/excerpt/body here are the artist's own draft, always in
		// one specific known language, not the page-chrome language — so an
		// explicit `lang` attribute lets the browser spellcheck it correctly
		// regardless of what language the artist's own browser/OS is set to.
		// Falls back to the artist's resolved language (SubmissionTranslator's
		// same "what language does this artist write in" single source of
		// truth used at intake) for the rare draft predating this meta field.
		$native_lang = (string) get_post_meta( $post->ID, '_agnosis_native_lang', true );
		if ( '' === $native_lang ) {
			$native_lang = SubmissionTranslator::resolve_artist_lang( (int) $post->post_author );
		}
		$lang_attr = '' !== $native_lang ? ' lang="' . esc_attr( $native_lang ) . '"' : '';
		?>
		<div class="agnosis-card" id="agnosis-card-<?php echo esc_attr( (string) $post->ID ); ?>" data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">

			<?php if ( $thumb_url ) : ?>
				<div class="agnosis-card__thumb">
					<img src="<?php echo esc_url( $thumb_url ); ?>" alt="">
				</div>
			<?php endif; ?>

			<div class="agnosis-card__body">
				<!-- Preview mode -->
				<div class="agnosis-card__preview">
					<h3 class="agnosis-card__title"><?php echo esc_html( $post->post_title ); ?></h3>

					<?php if ( $post->post_excerpt ) : ?>
						<p class="agnosis-card__excerpt"><?php echo esc_html( $post->post_excerpt ); ?></p>
					<?php endif; ?>

					<div class="agnosis-card__actions">
						<button class="agnosis-btn agnosis-btn--approve"
								data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
								data-nonce="<?php echo esc_attr( $nonce ); ?>">
							✓ <?php esc_html_e( 'Publish it', 'agnosis' ); ?>
						</button>
						<button class="agnosis-btn agnosis-btn--edit-toggle"
								data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
							✎ <?php esc_html_e( 'Make changes', 'agnosis' ); ?>
						</button>
						<button class="agnosis-btn agnosis-btn--discard"
								data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
								data-nonce="<?php echo esc_attr( $nonce ); ?>">
							✕ <?php esc_html_e( 'Discard', 'agnosis' ); ?>
						</button>
					</div>
				</div>

				<!-- Inline edit form (hidden until "Make changes" is clicked) -->
				<form class="agnosis-card__edit-form" hidden
					  data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
					  data-nonce="<?php echo esc_attr( $nonce ); ?>">

					<label class="agnosis-label" for="agnosis-title-<?php echo esc_attr( (string) $post->ID ); ?>">
						<?php esc_html_e( 'Title', 'agnosis' ); ?>
					</label>
					<input class="agnosis-input" type="text"
						   id="agnosis-title-<?php echo esc_attr( (string) $post->ID ); ?>"
						   name="title"
						   value="<?php echo esc_attr( $post->post_title ); ?>"
						   <?php echo $lang_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_attr()'d above. ?>>

					<label class="agnosis-label" for="agnosis-excerpt-<?php echo esc_attr( (string) $post->ID ); ?>">
						<?php esc_html_e( 'One-line description', 'agnosis' ); ?>
					</label>
					<textarea class="agnosis-input" rows="2"
							  id="agnosis-excerpt-<?php echo esc_attr( (string) $post->ID ); ?>"
							  name="excerpt"
							  <?php echo $lang_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_attr()'d above. ?>><?php echo esc_textarea( $post->post_excerpt ); ?></textarea>

					<label class="agnosis-label" for="agnosis-body-<?php echo esc_attr( (string) $post->ID ); ?>">
						<?php esc_html_e( 'Description', 'agnosis' ); ?>
					</label>
					<textarea class="agnosis-input" rows="6"
							  id="agnosis-body-<?php echo esc_attr( (string) $post->ID ); ?>"
							  name="body"
							  <?php echo $lang_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already esc_attr()'d above. ?>><?php echo esc_textarea( $body_text ); ?></textarea>

					<label class="agnosis-label" for="agnosis-tags-<?php echo esc_attr( (string) $post->ID ); ?>">
						<?php esc_html_e( 'Tags (comma-separated)', 'agnosis' ); ?>
					</label>
					<input class="agnosis-input" type="text"
						   id="agnosis-tags-<?php echo esc_attr( (string) $post->ID ); ?>"
						   name="tags"
						   value="<?php echo esc_attr( $tags_csv ); ?>">

					<div class="agnosis-card__actions agnosis-card__edit-actions">
						<button type="submit" class="agnosis-btn agnosis-btn--save-publish" data-publish="true">
							✓ <?php esc_html_e( 'Save &amp; Publish', 'agnosis' ); ?>
						</button>
						<button type="button" class="agnosis-btn agnosis-btn--cancel">
							<?php esc_html_e( 'Cancel', 'agnosis' ); ?>
						</button>
					</div>
				</form>

				<p class="agnosis-card__status" aria-live="polite"></p>
			</div>

		</div>
		<?php
	}

	/**
	 * Output inline CSS + JS for the submissions page.
	 * Only added when the shortcode is actually rendered.
	 */
	/**
	 * Output inline CSS + JS for the submissions page.
	 * Only added when the shortcode or block is actually rendered.
	 */
	private function enqueue_assets(): void {
		$review_base = esc_url( rest_url( 'agnosis/v1/review/' ) );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- rest_url already escaped; inline style/script blocks contain no user data.
		echo '<style>
.agnosis-submissions { font-family: Georgia, serif; max-width: 720px; }
.agnosis-empty { color: #888; font-style: italic; }

/* Card */
.agnosis-card { border: 1px solid #e0e0e0; border-radius: 10px; padding: 24px; margin-bottom: 24px; background: #fff; transition: opacity .3s; }
.agnosis-card--done { opacity: .35; pointer-events: none; }
.agnosis-card__thumb img { width: 100%; max-height: 280px; object-fit: cover; border-radius: 6px; margin-bottom: 16px; }
.agnosis-card__title { margin: 0 0 8px; font-size: 20px; font-weight: 700; }
.agnosis-card__excerpt { margin: 0 0 20px; font-size: 15px; color: #555; font-style: italic; }

/* Buttons */
.agnosis-card__actions { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 4px; }
.agnosis-btn { display: inline-block; padding: 10px 20px; border-radius: 6px; font-size: 15px; font-weight: 600; cursor: pointer; border: none; text-decoration: none; font-family: inherit; line-height: 1; }
.agnosis-btn--approve { background: #7c6af7; color: #fff; }
.agnosis-btn--edit-toggle { background: #f0f0f0; color: #333; }
.agnosis-btn--discard { background: #fff; color: #c0392b; border: 1px solid #c0392b; }
.agnosis-btn--save-publish { background: #7c6af7; color: #fff; }
.agnosis-btn--cancel { background: #f0f0f0; color: #333; }
.agnosis-btn:disabled { opacity: .5; cursor: default; }

/* Inline edit form */
.agnosis-card__edit-form { margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; }
.agnosis-label { display: block; font-size: 13px; font-weight: 600; color: #444; margin: 14px 0 4px; text-transform: uppercase; letter-spacing: .04em; }
.agnosis-label:first-child { margin-top: 0; }
.agnosis-input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 15px; font-family: Georgia, serif; box-sizing: border-box; }
.agnosis-input:focus { outline: none; border-color: #7c6af7; box-shadow: 0 0 0 2px #ede9fe; }
.agnosis-card__edit-actions { margin-top: 16px; }

/* Status */
.agnosis-card__status { font-size: 13px; color: #888; margin: 10px 0 0; min-height: 18px; }
</style>';

		echo '<script>
(function () {
	var reviewBase = ' . wp_json_encode( $review_base ) . ';

	// ---- Helpers ----

	function setStatus(card, msg) {
		var el = card.querySelector(".agnosis-card__status");
		if (el) el.textContent = msg;
	}

	function disableCard(card) {
		card.querySelectorAll("button").forEach(function(b) { b.disabled = true; });
	}

	function restoreCard(card) {
		card.querySelectorAll("button").forEach(function(b) { b.disabled = false; });
	}

	function markDone(card, msg) {
		setStatus(card, msg);
		card.classList.add("agnosis-card--done");
	}

	// ---- Approve (publish as-is) ----

	function doApprove(postId, nonce, card) {
		disableCard(card);
		setStatus(card, ' . wp_json_encode( __( 'Publishing…', 'agnosis' ) ) . ');
		fetch(reviewBase + postId + "/approve", {
			method: "POST",
			headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce }
		})
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.status === "published") {
				markDone(card, ' . wp_json_encode( __( 'Published ✓', 'agnosis' ) ) . ');
			} else {
				setStatus(card, data.message || ' . wp_json_encode( __( 'Something went wrong.', 'agnosis' ) ) . ');
				restoreCard(card);
			}
		})
		.catch(function() {
			setStatus(card, ' . wp_json_encode( __( 'Could not connect. Please try again.', 'agnosis' ) ) . ');
			restoreCard(card);
		});
	}

	// ---- Discard ----

	function doDiscard(postId, nonce, card) {
		if (!confirm(' . wp_json_encode( __( 'Discard this submission? This cannot be undone.', 'agnosis' ) ) . ')) return;
		disableCard(card);
		setStatus(card, ' . wp_json_encode( __( 'Discarding…', 'agnosis' ) ) . ');
		fetch(reviewBase + postId + "/reject", {
			method: "POST",
			headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce }
		})
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.status === "rejected") {
				markDone(card, ' . wp_json_encode( __( 'Discarded.', 'agnosis' ) ) . ');
			} else {
				setStatus(card, data.message || ' . wp_json_encode( __( 'Something went wrong.', 'agnosis' ) ) . ');
				restoreCard(card);
			}
		})
		.catch(function() {
			setStatus(card, ' . wp_json_encode( __( 'Could not connect. Please try again.', 'agnosis' ) ) . ');
			restoreCard(card);
		});
	}

	// ---- Inline edit form ----

	function showEditForm(card) {
		var preview = card.querySelector(".agnosis-card__preview");
		var form    = card.querySelector(".agnosis-card__edit-form");
		if (preview) preview.hidden = true;
		if (form) form.hidden = false;
	}

	function hideEditForm(card) {
		var preview = card.querySelector(".agnosis-card__preview");
		var form    = card.querySelector(".agnosis-card__edit-form");
		if (preview) preview.hidden = false;
		if (form) form.hidden = true;
	}

	function doSaveAndPublish(form, card) {
		var nonce  = form.dataset.nonce;
		var postId = form.dataset.postId;
		var title   = (form.querySelector("[name=title]")   || {}).value || "";
		var excerpt = (form.querySelector("[name=excerpt]") || {}).value || "";
		var body    = (form.querySelector("[name=body]")    || {}).value || "";
		var tags    = (form.querySelector("[name=tags]")    || {}).value || "";

		disableCard(card);
		setStatus(card, ' . wp_json_encode( __( 'Saving and publishing…', 'agnosis' ) ) . ');

		fetch(reviewBase + postId, {
			method: "PUT",
			headers: { "Content-Type": "application/json", "X-WP-Nonce": nonce },
			body: JSON.stringify({ title: title, excerpt: excerpt, body: body, tags: tags, publish: true })
		})
		.then(function(r) { return r.json(); })
		.then(function(data) {
			if (data.status === "published") {
				markDone(card, ' . wp_json_encode( __( 'Published ✓', 'agnosis' ) ) . ');
			} else {
				setStatus(card, data.message || ' . wp_json_encode( __( 'Something went wrong.', 'agnosis' ) ) . ');
				restoreCard(card);
			}
		})
		.catch(function() {
			setStatus(card, ' . wp_json_encode( __( 'Could not connect. Please try again.', 'agnosis' ) ) . ');
			restoreCard(card);
		});
	}

	// ---- Event delegation ----

	document.addEventListener("click", function(e) {
		var btn  = e.target.closest("button[class*=agnosis-btn]");
		if (!btn) return;
		var card = btn.closest(".agnosis-card");
		if (!card) return;

		if (btn.classList.contains("agnosis-btn--approve")) {
			doApprove(btn.dataset.postId, btn.dataset.nonce, card);

		} else if (btn.classList.contains("agnosis-btn--discard")) {
			doDiscard(btn.dataset.postId, btn.dataset.nonce, card);

		} else if (btn.classList.contains("agnosis-btn--edit-toggle")) {
			showEditForm(card);

		} else if (btn.classList.contains("agnosis-btn--cancel")) {
			hideEditForm(card);
		}
	});

	document.addEventListener("submit", function(e) {
		var form = e.target.closest(".agnosis-card__edit-form");
		if (!form) return;
		e.preventDefault();
		var card = form.closest(".agnosis-card");
		doSaveAndPublish(form, card);
	});

}());
</script>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	// -------------------------------------------------------------------------
	// Login form
	// -------------------------------------------------------------------------

	/**
	 * Render an inline login form so artists never need to visit wp-login.php.
	 *
	 * wp_login_form() posts credentials to wp-login.php and redirects back to
	 * the current page on success. With COOKIE_DOMAIN set to the root domain
	 * (e.g. .agnosis.art) the session cookie is valid on all subdomains, so
	 * logging in here works identically on the main domain or any artist subdomain.
	 *
	 * When Turnstile is configured (Settings → General), the widget is appended
	 * inside wp_login_form()'s own <form> via the login_form_bottom filter —
	 * that filter is added immediately before the call and removed immediately
	 * after, scoped to just this one render, so it can never affect any other
	 * wp_login_form() call elsewhere on the site.
	 */
	private function render_login_form(): string {
		$redirect       = get_permalink() ?: home_url( '/my-submissions/' );
		$turnstile_used = Turnstile::is_enabled();

		if ( $turnstile_used ) {
			Turnstile::enqueue_script();
			add_filter( 'login_form_bottom', [ $this, 'append_turnstile_to_login_form' ] );
		}

		ob_start();
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS, no user data.
		echo '<style>
.agnosis-submissions--login { max-width: 28rem; }
.agnosis-submissions--login form#loginform { display: flex; flex-direction: column; gap: 1.6rem; margin-top: 1.5rem; }
.agnosis-submissions--login p.login-username,
.agnosis-submissions--login p.login-password { display: flex; flex-direction: column; gap: .5rem; margin: 0; }
.agnosis-submissions--login label { font-size: .9rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; }
.agnosis-submissions--login input[type="text"],
.agnosis-submissions--login input[type="email"],
.agnosis-submissions--login input[type="password"] {
	width: 100%; padding: .9rem 1rem; border: 1px solid currentColor; border-radius: 0;
	background: transparent; color: inherit; font-family: inherit; font-size: 1.1rem;
	line-height: 1.5; box-sizing: border-box;
}
.agnosis-submissions--login input:focus { outline: 2px solid currentColor; outline-offset: 2px; }
.agnosis-submissions--login p.login-remember { margin: 0; }
.agnosis-submissions--login p.login-remember label {
	display: flex; align-items: center; gap: .5rem; text-transform: none;
	font-weight: 400; letter-spacing: normal; font-size: .95rem;
}
.agnosis-submissions--login .agnosis-turnstile { margin: .5rem 0 0; }
.agnosis-submissions--login p.login-submit { margin: 0; display: flex; align-items: center; gap: 1rem; }
.agnosis-submissions--login #wp-submit {
	padding: .85rem 2.2rem; border: 1px solid currentColor; border-radius: 0;
	background: var(--wp--preset--color--foreground, #000); color: var(--wp--preset--color--background, #fff);
	font-family: inherit; font-size: .95rem; font-weight: 600; letter-spacing: .05em;
	text-transform: uppercase; cursor: pointer; transition: opacity .15s ease;
}
.agnosis-submissions--login #wp-submit:hover { opacity: .75; }
.agnosis-submissions--login__lostpassword { margin: 1.2rem 0 0; font-size: .9rem; }
.agnosis-submissions--login__lostpassword a { color: inherit; text-decoration: underline; }
</style>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
		<div class="agnosis-submissions agnosis-submissions--login">
			<h2><?php esc_html_e( 'Log in to view your submissions', 'agnosis' ); ?></h2>
			<?php
			wp_login_form(
				[
					'redirect'       => esc_url( $redirect ),
					'label_username' => __( 'Username or email address', 'agnosis' ),
					'label_password' => __( 'Password', 'agnosis' ),
					'label_log_in'   => __( 'Log in', 'agnosis' ),
					'remember'       => true,
					'label_remember' => __( 'Remember me', 'agnosis' ),
				]
			);
			?>
			<p class="agnosis-submissions--login__lostpassword">
				<a href="<?php echo esc_url( wp_lostpassword_url( $redirect ) ); ?>">
					<?php esc_html_e( 'Forgot your password?', 'agnosis' ); ?>
				</a>
			</p>
		</div>
		<?php
		if ( $turnstile_used ) {
			remove_filter( 'login_form_bottom', [ $this, 'append_turnstile_to_login_form' ] );
		}
		return (string) ob_get_clean();
	}

	/**
	 * Appends the Turnstile widget, plus a hidden marker field, just before
	 * `</form>` inside wp_login_form()'s generated markup (login_form_bottom
	 * filter). The marker is how authenticate_turnstile() recognises this
	 * specific front-end form — see that method's docblock.
	 *
	 * @param string $content Existing login_form_bottom content (empty by default).
	 * @return string
	 */
	public function append_turnstile_to_login_form( string $content ): string {
		return $content
			. Turnstile::render_widget()
			. '<input type="hidden" name="agnosis_login_source" value="submissions" />';
	}

	/**
	 * Enforce Turnstile verification on this specific login form only.
	 *
	 * Hooked on `authenticate` at priority 30 — after WordPress's own
	 * username/password check (priority 20) has already resolved $user. Every
	 * other login path on the site (wp-admin, REST/XML-RPC auth, application
	 * passwords, a different theme's own login form) never submits the
	 * `agnosis_login_source` marker this form's Turnstile widget is paired
	 * with, so this is a complete no-op for all of them regardless of whether
	 * Turnstile is enabled — only a request that actually came from this form
	 * is ever subject to the check.
	 *
	 * @param WP_User|WP_Error|null $user     Result of prior authenticate callbacks.
	 * @param string                $username Submitted username (unused).
	 * @param string                $password Submitted password (unused).
	 * @return WP_User|WP_Error|null
	 */
	public function authenticate_turnstile( $user, string $username, string $password ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- wp-login.php's own core handler owns this POST request; this marker only identifies which front-end form submitted (routing, not a security boundary) — Turnstile::verify() below is the actual security check.
		$source = isset( $_POST['agnosis_login_source'] ) ? sanitize_key( wp_unslash( (string) $_POST['agnosis_login_source'] ) ) : '';

		if ( 'submissions' !== $source || ! Turnstile::is_enabled() || $user instanceof WP_Error ) {
			return $user;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see note above; the token itself is what Turnstile::verify() validates against Cloudflare.
		$token  = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['cf-turnstile-response'] ) ) : '';
		$result = Turnstile::verify( $token );

		return is_wp_error( $result ) ? $result : $user;
	}
}
