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
use Agnosis\Artist\ApplicationBiography;
use Agnosis\Artist\CommunityCap;
use Agnosis\Artist\CommunityCapVote;
use Agnosis\Artist\CommunityCapNotification;
use Agnosis\Artist\ContentEditor;
use Agnosis\Artist\Departure;
use Agnosis\Artist\DepartureNotification;
use Agnosis\Artist\FrontendAccess;
use Agnosis\Artist\JoinPage;
use Agnosis\Artist\RemovalVoteConfirm;
use Agnosis\Artist\VouchConfirm;
use Agnosis\Artist\Profile;
use Agnosis\Compat\LinguaForge;
use Agnosis\Email\Inbox;
use Agnosis\Email\Webhook;
use Agnosis\Network\ActivityPub;
use Agnosis\Network\Node;
use Agnosis\Network\SubdomainNavigation;
use Agnosis\Newsletter\Archive;
use Agnosis\Newsletter\PopoverBlock;
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
			$this->loader->add_action( 'admin_post_agnosis_clear_debug_files', $settings, 'handle_clear_debug_files' );
			$this->loader->add_action( 'wp_ajax_agnosis_test_ai',              $settings, 'handle_test_ai' );
			$this->loader->add_action( 'admin_post_agnosis_admit_application',    $settings, 'handle_admit_application' );
			$this->loader->add_action( 'admin_post_agnosis_reject_application',   $settings, 'handle_reject_application' );
			$this->loader->add_action( 'admin_post_agnosis_ban_artist',            $settings, 'handle_ban_artist' );
			$this->loader->add_action( 'admin_post_agnosis_delete_artist',         $settings, 'handle_delete_artist' );
			$this->loader->add_action( 'admin_post_agnosis_initiate_removal_vote', $settings, 'handle_initiate_removal_vote' );
			$this->loader->add_action( 'admin_post_agnosis_send_newsletter_now',   $settings, 'handle_send_newsletter_now' );
			$this->loader->add_action( 'admin_post_agnosis_send_newsletter_test', $settings, 'handle_send_newsletter_test' );
			$this->loader->add_action( 'admin_post_agnosis_send_invitation',      $settings, 'handle_send_invitation' );
			$this->loader->add_action( 'admin_post_agnosis_send_invitation_test', $settings, 'handle_send_invitation_test' );

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

		// Basic wp-login.php branding — the "Forgot your password?" / reset-password
		// screens are the one part of the login flow SubmissionsPage's own themed
		// inline form can't cover, since they necessarily leave the front-end page.
		$login_branding = new LoginBranding();
		$this->loader->add_filter( 'login_headerurl',        $login_branding, 'header_url', 10, 1 );
		$this->loader->add_filter( 'login_headertext',       $login_branding, 'header_text', 10, 1 );
		$this->loader->add_action( 'login_enqueue_scripts',  $login_branding, 'enqueue_styles' );

		// Custom post types & taxonomies.
		$profile = new Profile();
		$this->loader->add_action( 'init', $profile, 'register_post_type' );
		$this->loader->add_action( 'init', $profile, 'register_biography_post_type' );
		$this->loader->add_action( 'init', $profile, 'register_event_post_type' );
		$this->loader->add_action( 'init', $profile, 'register_taxonomy' );
		$this->loader->add_action( 'init', $profile, 'register_blocks' );
		$this->loader->add_action( 'pre_get_posts', $profile, 'order_events_archive' );
		$this->loader->add_filter( 'query_loop_block_query_vars', $profile, 'scope_more_works_query', 10, 1 );

		// Locale-natural dates: every core/post-date block (artwork, biography,
		// event pages, the newsletter archive, etc.) renders through this filter
		// site-wide instead of a fixed date() format string that never actually
		// adapts its structure per language — see DateFormatter's own docblock.
		$date_formatter = new DateFormatter();
		$this->loader->add_filter( 'render_block_core/post-date', $date_formatter, 'filter_post_date_block', 10, 3 );

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

		// Auto-creates a first biography draft from the application's own
		// bio/statement/portfolio_url the moment an artist is admitted — see
		// ApplicationBiography's class docblock.
		$application_biography = new ApplicationBiography();
		$application_biography->register_hooks();

		$departure = new Departure();
		$this->loader->add_action( 'rest_api_init',              $departure, 'register_routes' );
		$this->loader->add_action( 'agnosis_check_bans',         $departure, 'check_expired_bans' );
		$this->loader->add_action( 'agnosis_check_removal_votes', $departure, 'check_expired_removal_votes' );
		// Self-removal confirmation shim — processes ?agnosis_departure=1&token=…
		$this->loader->add_action( 'template_redirect',          $departure, 'handle_departure_confirm', 1 );

		$departure_notification = new DepartureNotification();
		$departure_notification->register_hooks();

		// Community removal-vote email-link shim — processes ?agnosis_removal_vote=1&…
		// (mirrors VouchConfirm's admission-vote pattern; see security audit §2e).
		$removal_vote_confirm = new RemovalVoteConfirm( $departure );
		$removal_vote_confirm->register_hooks();

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
		$this->loader->add_action( 'agnosis_submission_no_attachment', $notification, 'on_submission_no_attachment', 10, 2 );

		$review = new ReviewEndpoints();
		$this->loader->add_action( 'rest_api_init', $review, 'register_routes' );

		$removal = new RemovalEndpoints();
		$this->loader->add_action( 'rest_api_init', $removal, 'register_routes' );

		// Frontend shim for email action links — processes token server-side
		// and redirects to a clean URL so tokens never land in browser history.
		$review_confirm = new ReviewConfirm();
		$this->loader->add_action( 'template_redirect', $review_confirm, 'handle_confirm', 1 );
		$this->loader->add_action( 'template_redirect', $review_confirm, 'handle_result',  1 );

		// Front-end correction for artists (audit §7, Phase 1 — 0.8.0).
		$content_editor = new ContentEditor();
		$this->loader->add_action( 'rest_api_init',    $content_editor, 'register_routes' );
		$this->loader->add_action( 'wp_enqueue_scripts', $content_editor, 'maybe_enqueue_assets' );
		$this->loader->add_filter( 'the_content', $content_editor, 'decorate_content', 20, 1 );
		$this->loader->add_filter( 'the_excerpt', $content_editor, 'decorate_excerpt', 20, 1 );
		$this->loader->add_filter( 'post_thumbnail_html', $content_editor, 'decorate_thumbnail', 20, 3 );
		$this->loader->add_filter( 'the_title', $content_editor, 'decorate_title', 20, 2 );

		$submissions = new SubmissionsPage();
		$this->loader->add_action( 'init',                 $submissions, 'register_shortcode' );
		$this->loader->add_action( 'init',                 $submissions, 'register_block' );
		$this->loader->add_action( 'rest_api_init',        $submissions, 'register_routes' );
		$this->loader->add_filter( 'block_categories_all', $submissions, 'add_block_category', 10, 1 );
		// Turnstile on the "log in to view your submissions" form. Priority 30
		// runs after WP's own username/password check (20); no-ops for every
		// login that isn't this specific form — see authenticate_turnstile().
		$this->loader->add_filter( 'authenticate',         $submissions, 'authenticate_turnstile', 30, 3 );

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

		// Subdomain navigation — artist-breadcrumb block, plus pointing the Site
		// Logo/Site Title links back at the main site from an artist subdomain.
		// Lives in the plugin (not a theme) so any Agnosis-compatible theme gets
		// both just by using core's Site Logo/Site Title blocks.
		$subdomain_nav = new SubdomainNavigation();
		$this->loader->add_action( 'init',                       $subdomain_nav, 'register_block' );
		$this->loader->add_filter( 'render_block_core/site-logo',  $subdomain_nav, 'link_to_portal' );
		$this->loader->add_filter( 'render_block_core/site-title', $subdomain_nav, 'link_to_portal' );

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

		$popover_block = new PopoverBlock();
		$this->loader->add_action( 'init', $popover_block, 'register_block' );

		// Public newsletter archive — "view in browser" per-issue permalinks
		// plus a paginated /newsletter/ index; see Newsletter\Archive.
		$newsletter_archive = new Archive();
		$this->loader->add_action( 'init', $newsletter_archive, 'register_routes' );

		// Lingua Forge integration — boots itself only when LF is present;
		// registers the compat admin notice unconditionally.
		new LinguaForge();
	}
}
