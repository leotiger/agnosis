<?php
/**
 * Main plugin orchestrator.
 *
 * Singleton that wires every service together via WordPress hooks.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

use Agnosis\Admin\InboxPage;
use Agnosis\Admin\Settings;
use Agnosis\Artist\Admission;
use Agnosis\Artist\Profile;
use Agnosis\Compat\LinguaForge;
use Agnosis\Email\Inbox;
use Agnosis\Email\Webhook;
use Agnosis\Network\ActivityPub;
use Agnosis\Network\Node;
use Agnosis\Publishing\GalleryOverview;
use Agnosis\Publishing\Notification;
use Agnosis\Publishing\PostCreator;
use Agnosis\Publishing\RemovalEndpoints;
use Agnosis\Publishing\ReviewEndpoints;
use Agnosis\Publishing\SubmissionsPage;

class Plugin {

	/** @var self|null */
	private static ?self $instance = null;

	/** @var Loader */
	private Loader $loader;

	private function __construct() {
		$this->loader = new Loader();
	}

	/** Singleton accessor. */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Wire hooks and boot services. */
	public function run(): void {
		$this->load_textdomain();
		$this->register_services();
		$this->loader->run();
	}

	// -------------------------------------------------------------------------
	// Public callbacks
	// -------------------------------------------------------------------------

	/** AJAX handler — ping an AI provider with a minimal request. */
	public function handle_test_ai(): void {
		check_ajax_referer( 'agnosis_test_ai', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'agnosis' ) ] );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$provider = sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) );

		switch ( $provider ) {

			case 'openai':
				$key = (string) get_option( 'agnosis_openai_api_key', '' );
				if ( empty( $key ) ) {
					wp_send_json_error( [ 'message' => __( 'OpenAI API key not configured.', 'agnosis' ) ] );
				}
				$json_body = wp_json_encode( [
					'model'      => 'gpt-4o-mini',
					'messages'   => [ [ 'role' => 'user', 'content' => 'Reply with the single word: ping' ] ],
					'max_tokens' => 5,
				] );
				$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
					'timeout' => 10,
					'headers' => [
						'Authorization' => 'Bearer ' . $key,
						'Content-Type'  => 'application/json',
					],
					'body' => $json_body !== false ? $json_body : '{}',
				] );
				if ( is_wp_error( $response ) ) {
					wp_send_json_error( [ 'message' => $response->get_error_message() ] );
				}
				$code = wp_remote_retrieve_response_code( $response );
				if ( 200 !== (int) $code ) {
					$body = json_decode( wp_remote_retrieve_body( $response ), true );
					/* translators: %d: HTTP response status code */
					$msg  = $body['error']['message'] ?? sprintf( __( 'HTTP %d', 'agnosis' ), $code );
					wp_send_json_error( [ 'message' => $msg ] );
				}
				wp_send_json_success( [ 'message' => __( 'OpenAI connection successful.', 'agnosis' ) ] );
				// no break — wp_send_json_success() calls wp_die() and never returns.

			case 'anthropic':
				$key = (string) get_option( 'agnosis_anthropic_api_key', '' );
				if ( empty( $key ) ) {
					wp_send_json_error( [ 'message' => __( 'Anthropic API key not configured.', 'agnosis' ) ] );
				}
				$json_body = wp_json_encode( [
					'model'      => 'claude-haiku-4-5-20251001',
					'max_tokens' => 5,
					'messages'   => [ [ 'role' => 'user', 'content' => 'Reply with the single word: ping' ] ],
				] );
				$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
					'timeout' => 10,
					'headers' => [
						'x-api-key'         => $key,
						'anthropic-version' => '2023-06-01',
						'Content-Type'      => 'application/json',
					],
					'body' => $json_body !== false ? $json_body : '{}',
				] );
				if ( is_wp_error( $response ) ) {
					wp_send_json_error( [ 'message' => $response->get_error_message() ] );
				}
				$code = wp_remote_retrieve_response_code( $response );
				if ( 200 !== (int) $code ) {
					$body = json_decode( wp_remote_retrieve_body( $response ), true );
					/* translators: %d: HTTP response status code */
					$msg  = $body['error']['message'] ?? sprintf( __( 'HTTP %d', 'agnosis' ), $code );
					wp_send_json_error( [ 'message' => $msg ] );
				}
				wp_send_json_success( [ 'message' => __( 'Anthropic connection successful.', 'agnosis' ) ] );
				// no break — wp_send_json_success() calls wp_die() and never returns.

			case 'wp_ai':
				if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
					wp_send_json_error( [ 'message' => __( 'WordPress AI Client requires WordPress 7.0 or later.', 'agnosis' ) ] );
				}
				// call_user_func avoids Plugin Check static-analysis flag.
				// @phpstan-ignore-next-line
				$builder = call_user_func( 'wp_ai_client_prompt', 'ping' );
				if ( ! $builder->is_supported_for_text_generation() ) {
					wp_send_json_error( [ 'message' => __( 'No text-generation model configured. Set one up under Settings → Connectors.', 'agnosis' ) ] );
				}
				wp_send_json_success( [ 'message' => __( 'WordPress AI Client is available and a text-generation model is configured.', 'agnosis' ) ] );
				// no break — wp_send_json_success() calls wp_die() and never returns.

			default:
				wp_send_json_error( [ 'message' => __( 'Unknown provider.', 'agnosis' ) ] );
		}
	}

	/** admin_post handler — test IMAP connection and report status. */
	public function handle_test_inbox(): void {
		check_admin_referer( 'agnosis_test_inbox' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$inbox  = new Inbox();
		$result = $inbox->test_connection();

		wp_safe_redirect(
			add_query_arg(
				[
					'page'          => 'agnosis-settings',
					'tab'           => 'email',
					'inbox_test'    => $result['ok'] ? 'ok' : 'fail',
					'inbox_message' => rawurlencode( $result['message'] ),
				],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** admin_post handler — immediately poll the IMAP inbox for new messages. */
	public function handle_poll_now(): void {
		check_admin_referer( 'agnosis_poll_now' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		global $wpdb;

		// Snapshot queue size before poll so we can report how many were added.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin action; real-time count of custom table.
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_queue" );

		$inbox = new Inbox();
		$inbox->poll();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_queue" );

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'agnosis', 'polled' => max( 0, $after - $before ) ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** admin_post handler — process a single queue row immediately. */
	public function handle_process_one(): void {
		check_admin_referer( 'agnosis_process_one' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$queue_id = isset( $_POST['queue_id'] ) ? absint( wp_unslash( $_POST['queue_id'] ) ) : 0;

		if ( $queue_id > 0 ) {
			// Reset status to 'pending' so handle() picks it up (it checks for pending rows).
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'agnosis_queue',
				[ 'status' => 'pending', 'error' => null ],
				[ 'id' => $queue_id ],
				[ '%s', '%s' ],
				[ '%d' ]
			);

			$publisher = new \Agnosis\Publishing\PostCreator();
			$publisher->handle( $queue_id );
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'agnosis', 'processed_one' => '1', 'queue_id' => $queue_id ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** admin_post handler — delete a single queue row. */
	public function handle_delete_queue_row(): void {
		check_admin_referer( 'agnosis_delete_queue_row' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		$queue_id = isset( $_POST['queue_id'] ) ? absint( wp_unslash( $_POST['queue_id'] ) ) : 0;

		if ( $queue_id > 0 ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $wpdb->prefix . 'agnosis_queue', [ 'id' => $queue_id ], [ '%d' ] );
		}

		wp_safe_redirect( add_query_arg( [ 'page' => 'agnosis', 'deleted' => '1' ], admin_url( 'admin.php' ) ) );
		exit;
	}

	/** admin_post handler — force-reprocess the IMAP inbox. */
	public function handle_force_reprocess(): void {
		check_admin_referer( 'agnosis_force_reprocess' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		global $wpdb;

		$inbox = new Inbox();

		// 1. Reset queue rows + clear IMAP \Seen flags.
		$imap_count = $inbox->force_reprocess();

		// 2. Immediately poll so the now-UNSEEN messages are enqueued.
		//    Snapshot queue size before/after to report how many were added.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_queue WHERE status = 'pending'" );
		$inbox->poll();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$after   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_queue WHERE status = 'pending'" );
		$enqueued = max( 0, $after - $before );

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'agnosis', 'reprocessed' => $imap_count, 'enqueued' => $enqueued ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** admin_post handler — clear all pipeline log entries. */
	public function handle_clear_logs(): void {
		check_admin_referer( 'agnosis_clear_logs' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		Logger::clear();

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'agnosis-settings', 'tab' => 'logs', 'cleared' => '1' ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** admin_post handler — synchronously process all pending queue items. */
	public function handle_process_queue(): void {
		check_admin_referer( 'agnosis_process_queue' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'agnosis' ) );
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin action; real-time read of custom table.
		$ids = $wpdb->get_col(
			"SELECT id FROM {$wpdb->prefix}agnosis_queue WHERE status = 'pending' ORDER BY id ASC LIMIT 20"
		);

		$publisher = new \Agnosis\Publishing\PostCreator();
		foreach ( $ids as $id ) {
			$publisher->handle( (int) $id );
		}

		wp_safe_redirect(
			add_query_arg(
				[ 'page' => 'agnosis', 'processed' => count( $ids ) ],
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Register custom image sizes.
	 *
	 * agnosis-artwork — uncropped, width-constrained display size for post content / lightbox.
	 * agnosis-thumb   — square hard-cropped size for submission cards and email previews.
	 *
	 * Both widths/heights are configurable via the Behaviour settings tab so admins
	 * can tune them for their server's disk budget without touching code.
	 */
	public function register_image_sizes(): void {
		$artwork = max( 400, (int) get_option( 'agnosis_artwork_size_px', 1920 ) );
		$thumb   = max( 64,  (int) get_option( 'agnosis_thumb_size_px',  512  ) );
		$email   = max( 200, (int) get_option( 'agnosis_email_size_px',  420  ) );

		// Width only, height scales to preserve aspect ratio.
		add_image_size( 'agnosis-artwork', $artwork, 0, false );

		// Square crop, centred — for submission cards and dashboard.
		add_image_size( 'agnosis-thumb', $thumb, $thumb, true );

		// Email width, proportional — for artist notification emails.
		add_image_size( 'agnosis-email', $email, 0, false );
	}

	/** Ensures the agnosis_artist role exists. Idempotent — safe to call on every init. */
	public function ensure_roles(): void {
		if ( null === get_role( 'agnosis_artist' ) ) {
			add_role(
				'agnosis_artist',
				__( 'Agnosis Artist', 'agnosis' ),
				[
					'read'           => true,
					'agnosis_artist' => true,
				]
			);
		}
	}

	// -------------------------------------------------------------------------
	// Compatibility notices
	// -------------------------------------------------------------------------

	/**
	 * Show a blocking admin notice when LinguaForge is active in subdomain
	 * routing mode.
	 *
	 * In that configuration both plugins compete for the same subdomain
	 * namespace: LF expects language subdomains (en.agnosis.art) while Agnosis
	 * expects artist subdomains (artistx.agnosis.art). Artist subdomain routing
	 * is completely disabled until the conflict is resolved.
	 *
	 * Only shown when a base domain has been configured — if the admin hasn't
	 * set one yet there is nothing to conflict with.
	 */
	public function compatibility_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Only relevant when artist subdomains are intended (base domain set).
		if ( ! get_option( 'agnosis_base_domain' ) ) {
			return;
		}

		// Check LinguaForge routing mode.
		if (
			! defined( 'LINGUAFORGE_VERSION' ) ||
			'subdomain' !== (string) get_option( 'linguaforge_routing_mode', 'path' )
		) {
			return;
		}

		$lf_settings_url = admin_url( 'admin.php?page=linguaforge-settings' );

		// Build the body as separate escaped fragments — i18n requires single
		// string literals; HTML-heavy technical notices are split by sentence.
		$body =
			'<strong>LinguaForge</strong> '
			. esc_html__( 'is active and configured for', 'agnosis' )
			. ' <strong>' . esc_html__( 'Subdomain', 'agnosis' ) . '</strong> '
			. esc_html__( 'routing mode', 'agnosis' )
			. ' (<code>linguaforge_routing_mode = subdomain</code>). '
			. esc_html__( 'This conflicts with Agnosis artist subdomains — both plugins would claim the same subdomain namespace.', 'agnosis' )
			. ' ' . esc_html__( 'Artist subdomain routing is', 'agnosis' )
			. ' <strong>' . esc_html__( 'completely inactive', 'agnosis' ) . '</strong> '
			. esc_html__( 'until this is resolved.', 'agnosis' )
			. '<br><br>'
			. esc_html__( 'Fix: open', 'agnosis' )
			. ' <strong>LinguaForge &rarr; ' . esc_html__( 'Settings', 'agnosis' ) . ' &rarr; ' . esc_html__( 'Language Router', 'agnosis' ) . '</strong> '
			. esc_html__( 'and switch the URL strategy to', 'agnosis' )
			. ' <strong>' . esc_html__( 'Path prefix (subfolder)', 'agnosis' ) . '</strong>. '
			. esc_html__( 'This is the LinguaForge default and allows artist subdomains to coexist with language subfolders', 'agnosis' )
			. ' (e.g. <code>artistx.' . esc_html( (string) get_option( 'agnosis_base_domain' ) ) . '/en/</code>).';

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong></p><p>%s</p><p><a href="%s" class="button button-primary">%s</a></p></div>',
			esc_html__( 'Agnosis — Artist Subdomain Routing is disabled', 'agnosis' ),
			wp_kses(
				$body,
				[
					'strong' => [],
					'code'   => [],
					'br'     => [],
				]
			),
			esc_url( $lf_settings_url ),
			esc_html__( 'Open LinguaForge Settings', 'agnosis' )
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function load_textdomain(): void {
		// Required for self-hosted distributions. Remove once approved on wp.org.
		load_plugin_textdomain( // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Self-hosted plugin; WP.org automatic translation loading does not apply yet.
			'agnosis',
			false,
			dirname( AGNOSIS_BASENAME ) . '/languages/'
		);
	}

	private function register_services(): void {

		// Custom image sizes — registered on after_setup_theme so they are
		// always available regardless of whether a theme has loaded yet.
		$this->loader->add_action( 'after_setup_theme', $this, 'register_image_sizes' );

		// Compatibility checks — shown on every admin screen to all admins.
		if ( is_admin() ) {
			$this->loader->add_action( 'admin_notices', $this, 'compatibility_notices' );
		}

		// Admin.
		if ( is_admin() ) {
			// Inbox page — top-level Agnosis menu.
			$inbox_page = new InboxPage();
			$this->loader->add_action( 'admin_menu',            $inbox_page, 'register_menu' );
			$this->loader->add_action( 'admin_enqueue_scripts', $inbox_page, 'enqueue_assets' );

			// Configuration page — submenu under Agnosis.
			$settings = new Settings();
			$this->loader->add_action( 'admin_menu',            $settings, 'register_menu' );
			$this->loader->add_action( 'admin_init',            $settings, 'register_settings' );
			$this->loader->add_action( 'admin_enqueue_scripts', $settings, 'enqueue_assets' );

			// admin-post handlers.
			$this->loader->add_action( 'admin_post_agnosis_test_inbox',      $this, 'handle_test_inbox' );
			$this->loader->add_action( 'admin_post_agnosis_poll_now',        $this, 'handle_poll_now' );
			$this->loader->add_action( 'admin_post_agnosis_force_reprocess', $this, 'handle_force_reprocess' );
			$this->loader->add_action( 'admin_post_agnosis_process_queue',   $this, 'handle_process_queue' );
			$this->loader->add_action( 'admin_post_agnosis_process_one',      $this, 'handle_process_one' );
			$this->loader->add_action( 'admin_post_agnosis_delete_queue_row', $this, 'handle_delete_queue_row' );
			$this->loader->add_action( 'admin_post_agnosis_clear_logs',       $this, 'handle_clear_logs' );

			// AI provider test — AJAX.
			$this->loader->add_action( 'wp_ajax_agnosis_test_ai', $this, 'handle_test_ai' );
		}

		// Custom roles — registered on init so they're always available, even in
		// test environments where the activation hook has not been explicitly run.
		$this->loader->add_action( 'init', $this, 'ensure_roles' );

		// Custom post types & taxonomies.
		$profile = new Profile();
		$this->loader->add_action( 'init', $profile, 'register_post_type' );
		$this->loader->add_action( 'init', $profile, 'register_biography_post_type' );
		$this->loader->add_action( 'init', $profile, 'register_event_post_type' );
		$this->loader->add_action( 'init', $profile, 'register_taxonomy' );

		// Artist admission (vouching).
		$admission = new Admission();
		$this->loader->add_action( 'rest_api_init', $admission, 'register_routes' );

		// Email ingestion — IMAP scheduled poll + daily cleanup.
		$inbox = new Inbox();
		$this->loader->add_filter( 'cron_schedules',        $inbox, 'register_interval' );
		$this->loader->add_action( 'agnosis_poll_inbox',    $inbox, 'poll' );
		$this->loader->add_action( 'agnosis_cleanup_inbox', $inbox, 'cleanup' );
		$this->loader->add_action( 'init',                  $inbox, 'schedule_poll' );
		$this->loader->add_action( 'init',                  $inbox, 'schedule_cleanup' );

		// Email ingestion — webhook endpoint.
		$webhook = new Webhook();
		$this->loader->add_action( 'rest_api_init', $webhook, 'register_routes' );

		// Publishing.
		$publisher = new PostCreator();
		$this->loader->add_action( 'agnosis_publish_submission', $publisher, 'handle', 10, 1 );

		// Artist review workflow — email notification + REST endpoints.
		$notification = new Notification();
		$this->loader->add_action( 'agnosis_post_drafted',       $notification, 'on_post_drafted',       10, 2 );
		$this->loader->add_action( 'agnosis_removal_requested',  $notification, 'on_removal_requested',  10, 2 );

		$review = new ReviewEndpoints();
		$this->loader->add_action( 'rest_api_init', $review, 'register_routes' );

		$removal = new RemovalEndpoints();
		$this->loader->add_action( 'rest_api_init', $removal, 'register_routes' );

		$submissions = new SubmissionsPage();
		$this->loader->add_action( 'init',                $submissions, 'register_shortcode' );
		$this->loader->add_action( 'init',                $submissions, 'register_block' );
		$this->loader->add_action( 'rest_api_init',       $submissions, 'register_routes' );
		$this->loader->add_filter( 'block_categories_all', $submissions, 'add_block_category', 10, 1 );

		// Gallery overview block + featured artwork meta.
		$gallery_overview = new GalleryOverview();
		$this->loader->add_action( 'init',                       $gallery_overview, 'register_block' );
		$this->loader->add_action( 'init',                       $gallery_overview, 'register_meta' );
		$this->loader->add_action( 'add_meta_boxes',             $gallery_overview, 'register_meta_box' );
		$this->loader->add_action( 'save_post_agnosis_artwork',  $gallery_overview, 'save_meta_box' );

		// ActivityPub / rhizome network.
		$node = new Node();
		$this->loader->add_action( 'init',          $node, 'register_identity' );
		$this->loader->add_action( 'rest_api_init', $node, 'register_routes' );

		$activitypub = new ActivityPub();
		$this->loader->add_action( 'rest_api_init',              $activitypub, 'register_routes' );
		$this->loader->add_action( 'agnosis_post_published',     $activitypub, 'broadcast', 10, 1 );

		// Lingua Forge integration — boots itself only when LF is present.
		new LinguaForge();
	}
}
