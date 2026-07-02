<?php
/**
 * Main plugin orchestrator.
 *
 * Singleton that wires every service together via WordPress hooks.
 * Contains no business logic — all behaviour lives in the service classes.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

use Agnosis\Admin\InboxPage;
use Agnosis\Admin\QueueController;
use Agnosis\Admin\Settings;
use Agnosis\Artist\Admission;
use Agnosis\Artist\AdmissionNotification;
use Agnosis\Artist\CommunityCap;
use Agnosis\Artist\CommunityCapVote;
use Agnosis\Artist\CommunityCapNotification;
use Agnosis\Artist\Departure;
use Agnosis\Artist\DepartureNotification;
use Agnosis\Artist\FrontendAccess;
use Agnosis\Artist\JoinPage;
use Agnosis\Artist\VouchConfirm;
use Agnosis\Artist\Profile;
use Agnosis\Compat\LinguaForge;
use Agnosis\Email\Inbox;
use Agnosis\Email\Webhook;
use Agnosis\Network\ActivityPub;
use Agnosis\Network\Node;
use Agnosis\Newsletter\QueueProcessor;
use Agnosis\Newsletter\Scheduler;
use Agnosis\Newsletter\SignupBlock;
use Agnosis\Newsletter\Subscription;
use Agnosis\Newsletter\SubscriptionConfirm;
use Agnosis\Publishing\GalleryOverview;
use Agnosis\Publishing\Notification;
use Agnosis\Publishing\PostCreator;
use Agnosis\Publishing\RemovalEndpoints;
use Agnosis\Publishing\ReviewConfirm;
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

		// Custom image sizes.
		$image_sizes = new ImageSizes();
		$this->loader->add_action( 'after_setup_theme', $image_sizes, 'register' );

		// Admin.
		if ( is_admin() ) {
			// Inbox page — top-level Agnosis menu.
			$inbox_page = new InboxPage();
			$this->loader->add_action( 'admin_menu',            $inbox_page, 'register_menu' );
			$this->loader->add_action( 'admin_enqueue_scripts', $inbox_page, 'enqueue_assets' );
			$this->loader->add_action( 'admin_post_agnosis_test_inbox', $inbox_page, 'handle_test_inbox' );

			// Configuration page — submenu under Agnosis.
			$settings = new Settings();
			$this->loader->add_action( 'admin_menu',            $settings, 'register_menu' );
			$this->loader->add_action( 'admin_init',            $settings, 'register_settings' );
			$this->loader->add_action( 'admin_enqueue_scripts', $settings, 'enqueue_assets' );
			$this->loader->add_action( 'admin_post_agnosis_clear_logs',         $settings, 'handle_clear_logs' );
			$this->loader->add_action( 'wp_ajax_agnosis_test_ai',              $settings, 'handle_test_ai' );
			$this->loader->add_action( 'admin_post_agnosis_admit_application',    $settings, 'handle_admit_application' );
			$this->loader->add_action( 'admin_post_agnosis_reject_application',   $settings, 'handle_reject_application' );
			$this->loader->add_action( 'admin_post_agnosis_ban_artist',            $settings, 'handle_ban_artist' );
			$this->loader->add_action( 'admin_post_agnosis_delete_artist',         $settings, 'handle_delete_artist' );
			$this->loader->add_action( 'admin_post_agnosis_initiate_removal_vote', $settings, 'handle_initiate_removal_vote' );
			$this->loader->add_action( 'admin_post_agnosis_send_newsletter_now',   $settings, 'handle_send_newsletter_now' );
			$this->loader->add_action( 'admin_post_agnosis_send_newsletter_test', $settings, 'handle_send_newsletter_test' );

			// Queue management handlers.
			$queue = new QueueController();
			$this->loader->add_action( 'admin_post_agnosis_poll_now',        $queue, 'handle_poll_now' );
			$this->loader->add_action( 'admin_post_agnosis_force_reprocess', $queue, 'handle_force_reprocess' );
			$this->loader->add_action( 'admin_post_agnosis_process_queue',   $queue, 'handle_process_queue' );
			$this->loader->add_action( 'admin_post_agnosis_process_one',     $queue, 'handle_process_one' );
			$this->loader->add_action( 'admin_post_agnosis_delete_queue_row', $queue, 'handle_delete_queue_row' );
		}

		// Custom roles.
		$roles = new Roles();
		$this->loader->add_action( 'init', $roles, 'ensure' );

		// Frontend-only access: block artists from wp-admin, hide admin bar,
		// redirect to front page after login. Runs outside is_admin() so the
		// show_admin_bar and login_redirect filters fire on every request.
		$frontend_access = new FrontendAccess();
		$this->loader->add_action( 'admin_init',    $frontend_access, 'block_admin_access' );
		$this->loader->add_filter( 'show_admin_bar', $frontend_access, 'hide_admin_bar', 10, 1 );
		$this->loader->add_filter( 'login_redirect', $frontend_access, 'redirect_after_login', 10, 3 );

		// Custom post types & taxonomies.
		$profile = new Profile();
		$this->loader->add_action( 'init', $profile, 'register_post_type' );
		$this->loader->add_action( 'init', $profile, 'register_biography_post_type' );
		$this->loader->add_action( 'init', $profile, 'register_event_post_type' );
		$this->loader->add_action( 'init', $profile, 'register_taxonomy' );
		$this->loader->add_action( 'init', $profile, 'register_blocks' );

		// Artist admission (vouching) + public join form block.
		$admission = new Admission();
		$this->loader->add_action( 'rest_api_init',           $admission, 'register_routes' );
		$this->loader->add_action( 'agnosis_check_admissions', $admission, 'check_expired_applications' );
		// Community size cap: re-evaluate the advanced application when a slot opens.
		$this->loader->add_action( 'agnosis_waitlist_advanced', $admission, 'reconsider' );

		// Community size cap: when a member permanently leaves, advance the next
		// waitlisted applicant ("open a slot, fill a slot"). All three signals fire
		// after the agnosis_artist role has been removed, so the slot is already free.
		$community_cap = new CommunityCap();
		$this->loader->add_action( 'agnosis_artist_left',             $community_cap, 'advance_waitlist' );
		$this->loader->add_action( 'agnosis_artist_deleted_by_admin', $community_cap, 'advance_waitlist' );
		$this->loader->add_action( 'agnosis_removal_vote_passed',     $community_cap, 'advance_waitlist' );

		$admission_notification = new AdmissionNotification();
		$admission_notification->register_hooks();

		$departure = new Departure();
		$this->loader->add_action( 'rest_api_init',              $departure, 'register_routes' );
		$this->loader->add_action( 'agnosis_check_bans',         $departure, 'check_expired_bans' );
		$this->loader->add_action( 'agnosis_check_removal_votes', $departure, 'check_expired_removal_votes' );
		// Self-removal confirmation shim — processes ?agnosis_departure=1&token=…
		$this->loader->add_action( 'template_redirect',          $departure, 'handle_departure_confirm', 1 );

		$departure_notification = new DepartureNotification();
		$departure_notification->register_hooks();

		// Member-governed community size-cap vote (Phase 2).
		$cap_vote = new CommunityCapVote();
		$this->loader->add_action( 'rest_api_init',           $cap_vote, 'register_routes' );
		$this->loader->add_action( CommunityCapVote::CRON_HOOK, $cap_vote, 'check_expired_cap_votes' );

		$cap_notification = new CommunityCapNotification();
		$cap_notification->register_hooks();

		$vouch_confirm = new VouchConfirm( $admission );
		$vouch_confirm->register_hooks();

		$join = new JoinPage();
		$this->loader->add_action( 'init', $join, 'register_block' );

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
		$this->loader->add_action( 'agnosis_post_drafted',         $notification, 'on_post_drafted',         10, 2 );
		$this->loader->add_action( 'agnosis_removal_requested',    $notification, 'on_removal_requested',    10, 2 );
		$this->loader->add_action( 'agnosis_submission_rejected',  $notification, 'on_submission_rejected',  10, 4 );

		$review = new ReviewEndpoints();
		$this->loader->add_action( 'rest_api_init', $review, 'register_routes' );

		$removal = new RemovalEndpoints();
		$this->loader->add_action( 'rest_api_init', $removal, 'register_routes' );

		// Frontend shim for email action links — processes token server-side
		// and redirects to a clean URL so tokens never land in browser history.
		$review_confirm = new ReviewConfirm();
		$this->loader->add_action( 'template_redirect', $review_confirm, 'handle_confirm', 1 );
		$this->loader->add_action( 'template_redirect', $review_confirm, 'handle_result',  1 );

		$submissions = new SubmissionsPage();
		$this->loader->add_action( 'init',                 $submissions, 'register_shortcode' );
		$this->loader->add_action( 'init',                 $submissions, 'register_block' );
		$this->loader->add_action( 'rest_api_init',        $submissions, 'register_routes' );
		$this->loader->add_filter( 'block_categories_all', $submissions, 'add_block_category', 10, 1 );

		// Gallery overview block + featured artwork meta.
		$gallery_overview = new GalleryOverview();
		$this->loader->add_action( 'init',                      $gallery_overview, 'register_block' );
		$this->loader->add_action( 'init',                      $gallery_overview, 'register_meta' );
		$this->loader->add_action( 'add_meta_boxes',            $gallery_overview, 'register_meta_box' );
		$this->loader->add_action( 'save_post_agnosis_artwork', $gallery_overview, 'save_meta_box' );
		$this->loader->add_action( 'transition_post_status',    $gallery_overview, 'flush_artist_cache', 10, 3 );

		// ActivityPub / rhizome network.
		$node = new Node();
		$this->loader->add_action( 'init',          $node, 'register_identity' );
		$this->loader->add_action( 'rest_api_init', $node, 'register_routes' );

		$activitypub = new ActivityPub();
		$this->loader->add_action( 'rest_api_init',          $activitypub, 'register_routes' );
		$this->loader->add_action( 'agnosis_post_published', $activitypub, 'broadcast', 10, 1 );

		// Newsletters — public signup block + double opt-in, self-hosted
		// scheduling (daily prepare) and batched sending (every 5 minutes,
		// reusing the interval Inbox already registers below).
		$subscription = new Subscription();
		$this->loader->add_action( 'rest_api_init', $subscription, 'register_routes' );

		$subscription_confirm = new SubscriptionConfirm();
		$subscription_confirm->register_hooks();

		$newsletter_scheduler = new Scheduler();
		$this->loader->add_action( 'agnosis_prepare_newsletters', $newsletter_scheduler, 'prepare' );

		$newsletter_queue = new QueueProcessor();
		$this->loader->add_action( 'agnosis_send_newsletter_queue', $newsletter_queue, 'process' );

		$signup_block = new SignupBlock();
		$this->loader->add_action( 'init', $signup_block, 'register_block' );

		// Lingua Forge integration — boots itself only when LF is present;
		// registers the compat admin notice unconditionally.
		new LinguaForge();
	}
}
