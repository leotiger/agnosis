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

use Agnosis\Admin\Settings;
use Agnosis\Artist\Admission;
use Agnosis\Artist\Profile;
use Agnosis\Compat\LinguaForge;
use Agnosis\Email\Inbox;
use Agnosis\Email\Webhook;
use Agnosis\Network\ActivityPub;
use Agnosis\Network\Node;
use Agnosis\Publishing\PostCreator;

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
	// Private helpers
	// -------------------------------------------------------------------------

	private function load_textdomain(): void {
		// Required for self-hosted distributions. WP.org auto-load only works
		// for plugins listed in the wordpress.org repository.
		load_plugin_textdomain(
			'agnosis',
			false,
			dirname( AGNOSIS_BASENAME ) . '/languages/'
		);
	}

	private function register_services(): void {

		// Admin.
		if ( is_admin() ) {
			$settings = new Settings();
			$this->loader->add_action( 'admin_menu',         $settings, 'register_menu' );
			$this->loader->add_action( 'admin_init',         $settings, 'register_settings' );
			$this->loader->add_action( 'admin_enqueue_scripts', $settings, 'enqueue_assets' );
		}

		// Custom roles — registered on init so they're always available, even in
		// test environments where the activation hook has not been explicitly run.
		$this->loader->add_action( 'init', $this, 'ensure_roles' );

		// Custom post types & taxonomies.
		$profile = new Profile();
		$this->loader->add_action( 'init', $profile, 'register_post_type' );
		$this->loader->add_action( 'init', $profile, 'register_taxonomy' );

		// Artist admission (vouching).
		$admission = new Admission();
		$this->loader->add_action( 'rest_api_init', $admission, 'register_routes' );

		// Email ingestion — IMAP scheduled poll.
		$inbox = new Inbox();
		$this->loader->add_filter( 'cron_schedules',      $inbox, 'register_interval' );
		$this->loader->add_action( 'agnosis_poll_inbox',  $inbox, 'poll' );
		$this->loader->add_action( 'init',                $inbox, 'schedule_poll' );

		// Email ingestion — webhook endpoint.
		$webhook = new Webhook();
		$this->loader->add_action( 'rest_api_init', $webhook, 'register_routes' );

		// Publishing.
		$publisher = new PostCreator();
		$this->loader->add_action( 'agnosis_publish_submission', $publisher, 'handle', 10, 1 );

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
