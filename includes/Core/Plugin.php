<?php
/**
 * Main plugin orchestrator.
 *
 * Singleton that wires every service together via WordPress hooks.
 * Contains no business logic — all behavior lives in the service classes.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

use Agnosis\Admin\ContactMessagesPage;
use Agnosis\Admin\Dashboards;
use Agnosis\Admin\InboxPage;
use Agnosis\Admin\QueueController;
use Agnosis\Admin\Settings;
use Agnosis\Admin\ArtworkMediumSync;
use Agnosis\Admin\ArtworkRetag;
use Agnosis\Admin\MediumProposals;
use Agnosis\Admin\TagProposals;
use Agnosis\Admin\TaxonomyLanguageFilter;
use Agnosis\Artist\Admission;
use Agnosis\Artist\AdmissionNotification;
use Agnosis\Artist\ApplicationBiography;
use Agnosis\Artist\BiographyTitle;
use Agnosis\Artist\CommunityCap;
use Agnosis\Artist\CommunityCapVote;
use Agnosis\Artist\CommunityCapNotification;
use Agnosis\Artist\ContactForm;
use Agnosis\Artist\ContactFormBlock;
use Agnosis\Artist\ContentEditor;
use Agnosis\Artist\AdmissionConfirm;
use Agnosis\Artist\Departure;
use Agnosis\Artist\DepartureNotification;
use Agnosis\Artist\FrontendAccess;
use Agnosis\Artist\JoinPage;
use Agnosis\Artist\NotificationPreferences;
use Agnosis\Artist\RemovalVoteConfirm;
use Agnosis\Artist\VoteDigest;
use Agnosis\Artist\VouchConfirm;
use Agnosis\Artist\Profile;
use Agnosis\Compat\LinguaForge;
use Agnosis\Email\Inbox;
use Agnosis\Email\Webhook;
use Agnosis\Network\ActivityPub;
use Agnosis\Network\FederationSettlement;
use Agnosis\Network\Node;
use Agnosis\Network\SubdomainNavigation;
use Agnosis\Newsletter\Archive;
use Agnosis\Newsletter\InteractionGateway;
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

		// WordPress core privacy (GDPR DSAR) integration — Tools → Export/Erase
		// Personal Data exporters/erasers plus Privacy Policy Guide content
		// (seventh audit §4a). Registers its own hooks directly (admin_init +
		// two filters that only ever fire from wp-admin's own privacy tools),
		// so it's safe to wire up unconditionally like the other self-registering
		// classes below.
		$privacy = new Privacy();
		$privacy->register_hooks();

		// Custom image sizes.
		$image_sizes = new ImageSizes();
		$this->loader->add_action( 'after_setup_theme', $image_sizes, 'register' );

		// Admin.
		if ( is_admin() ) {
			// Self-hosted update checker — Agnosis isn't on WordPress.org, so
			// this is what surfaces the "Update available" badge and drives
			// the one-click updater. See Updater's own docblock and
			// docs/agnosis-update-manifest.php for the manifest it polls.
			$updater = new Updater();
			$updater->register_hooks();

			// Inbox page — top-level Agnosis menu.
			$inbox_page = new InboxPage();
			$this->loader->add_action( 'admin_menu',            $inbox_page, 'register_menu' );
			$this->loader->add_action( 'admin_enqueue_scripts', $inbox_page, 'enqueue_assets' );
			$this->loader->add_action( 'admin_post_agnosis_test_inbox', $inbox_page, 'handle_test_inbox' );

			// Contact messages page — submenu under Agnosis, reviewing every
			// row Artist\ContactForm has stored (sent and rejected alike).
			$contact_messages_page = new ContactMessagesPage();
			$this->loader->add_action( 'admin_menu', $contact_messages_page, 'register_menu' );
			$this->loader->add_action( 'admin_post_agnosis_delete_contact_message', $contact_messages_page, 'handle_delete_message' );

			// Configuration page — submenu under Agnosis.
			$settings = new Settings();
			$this->loader->add_action( 'admin_menu',            $settings, 'register_menu' );
			$this->loader->add_action( 'admin_init',            $settings, 'register_settings' );
			$this->loader->add_action( 'admin_enqueue_scripts', $settings, 'enqueue_assets' );
			$this->loader->add_action( 'admin_post_agnosis_clear_debug_files', $settings, 'handle_clear_debug_files' );
			$this->loader->add_action( 'admin_post_agnosis_clear_term_translations_cache', $settings, 'handle_clear_term_translations_cache' );

			$biography_title_cache = new Dashboards\BiographyTitleCache();
			$this->loader->add_action( 'admin_post_agnosis_save_biography_title_translation', $biography_title_cache, 'handle_save' );
			$this->loader->add_action( 'admin_post_agnosis_retranslate_biography_title',       $biography_title_cache, 'handle_retranslate' );
			$this->loader->add_action( 'admin_post_agnosis_clear_biography_title_cache',        $biography_title_cache, 'handle_clear_all' );

			$relay_manager = new Dashboards\RelayManager();
			$this->loader->add_action( 'admin_post_agnosis_add_relay',    $relay_manager, 'handle_add' );
			$this->loader->add_action( 'admin_post_agnosis_toggle_relay', $relay_manager, 'handle_toggle' );
			$this->loader->add_action( 'admin_post_agnosis_remove_relay', $relay_manager, 'handle_remove' );

			// Partner Nodes (Rhizome tab) — RN1, RHIZOME-NETWORK-ROADMAP.md §8.
			$rhizome_manager = new Dashboards\RhizomeManager();
			$this->loader->add_action( 'admin_post_agnosis_rhizome_approve',         $rhizome_manager, 'handle_approve' );
			$this->loader->add_action( 'admin_post_agnosis_rhizome_block',           $rhizome_manager, 'handle_block' );
			$this->loader->add_action( 'admin_post_agnosis_rhizome_unblock',         $rhizome_manager, 'handle_unblock' );
			$this->loader->add_action( 'admin_post_agnosis_rhizome_remove',          $rhizome_manager, 'handle_remove' );
			$this->loader->add_action( 'admin_post_agnosis_rhizome_set_trust_scope', $rhizome_manager, 'handle_set_trust_scope' );
			$this->loader->add_action( 'admin_post_agnosis_rhizome_check_reciprocity', $rhizome_manager, 'handle_check_reciprocity' );
			$this->loader->add_action( 'admin_post_agnosis_rhizome_add_manual',      $rhizome_manager, 'handle_add_manual' );

			// Tags/Mediums admin screens: language filter dropdown, on-demand
			// per-term "Sync translations" row action, and a one-click "Sync
			// all translations" button — all apply to BOTH taxonomies, see
			// TaxonomyLanguageFilter's own docblock for why the 746-tag/
			// 38-medium overcrowded screens needed this.
			$taxonomy_language_filter = new TaxonomyLanguageFilter();
			$this->loader->add_filter( 'wp_list_table_class_name', $taxonomy_language_filter, 'maybe_swap_list_table_class', 10, 2 );
			$this->loader->add_action( 'load-edit-tags.php',       $taxonomy_language_filter, 'maybe_register_scoping' );
			$this->loader->add_filter( 'agnosis_medium_row_actions', $taxonomy_language_filter, 'add_sync_row_action', 10, 2 );
			$this->loader->add_filter( 'post_tag_row_actions',     $taxonomy_language_filter, 'add_sync_row_action', 10, 2 );
			$this->loader->add_action( 'admin_post_agnosis_sync_term',      $taxonomy_language_filter, 'handle_sync_term' );
			$this->loader->add_action( 'admin_post_agnosis_sync_all_terms', $taxonomy_language_filter, 'handle_sync_all_terms' );
			$this->loader->add_action( 'admin_notices',            $taxonomy_language_filter, 'maybe_render_sync_notice' );

			// Medium-PROPOSAL review queue (distinct from both of the above:
			// those manage terms/assignments that already exist; this surfaces
			// AI-proposed medium values that DIDN'T match the live vocabulary at
			// classification time — see Admin\MediumProposals's own docblock)
			// — a notice/table on the same Artwork → Mediums screen, with
			// Approve/Reject admin-post actions.
			$medium_proposals = new MediumProposals();
			$this->loader->add_action( 'admin_notices',                          $medium_proposals, 'maybe_render_notice' );
			$this->loader->add_action( 'admin_post_agnosis_approve_medium_proposal', $medium_proposals, 'handle_approve' );
			$this->loader->add_action( 'admin_post_agnosis_reject_medium_proposal',  $medium_proposals, 'handle_reject' );

			// Tag-PROPOSAL review queue — TAG-REDESIGN.md T2's Admin\TagProposals,
			// the tag-side analogue of MediumProposals immediately above (see its
			// own class docblock for the multi-value-meta / cross-CPT / normalized-
			// reuse differences). A notice/table on the Tags taxonomy list screen,
			// Approve/Reject admin-post actions. Its TTL-sweep cron is registered
			// further below, OUTSIDE this is_admin() block — see that comment for
			// why (wp-cron.php requests are not admin requests).
			$tag_proposals = new TagProposals();
			$this->loader->add_action( 'admin_notices',                       $tag_proposals, 'maybe_render_notice' );
			$this->loader->add_action( 'admin_post_agnosis_approve_tag_proposal', $tag_proposals, 'handle_approve' );
			$this->loader->add_action( 'admin_post_agnosis_reject_tag_proposal',  $tag_proposals, 'handle_reject' );

			// On-demand medium/tags-ASSIGNMENT sync (distinct from the TERM
			// sync above: that ensures a translated medium/tag term exists
			// at all, this pushes a specific artwork's current medium/tags
			// onto its already-translated siblings) — a per-artwork
			// edit-screen button plus a bulk sweep, see ArtworkMediumSync's
			// own docblock for why the automatic-only version wasn't
			// enough, and for the tags option added alongside medium
			// (TAG-REDESIGN.md T3(c)).
			$artwork_medium_sync = new ArtworkMediumSync();
			$this->loader->add_action( 'load-post.php',     $artwork_medium_sync, 'register_edit_screen_scoping' );
			$this->loader->add_action( 'load-post-new.php', $artwork_medium_sync, 'register_edit_screen_scoping' );
			$this->loader->add_action( 'load-edit.php',     $artwork_medium_sync, 'register_list_screen_scoping' );
			$this->loader->add_action( 'add_meta_boxes',       $artwork_medium_sync, 'register_meta_box' );
			$this->loader->add_action( 'admin_post_agnosis_sync_medium_assignment',      $artwork_medium_sync, 'handle_sync' );
			$this->loader->add_action( 'admin_post_agnosis_sync_all_medium_assignments', $artwork_medium_sync, 'handle_sync_all' );
			$this->loader->add_action( 'admin_post_agnosis_sync_tag_assignment',         $artwork_medium_sync, 'handle_sync_tags' );
			$this->loader->add_action( 'admin_post_agnosis_sync_all_tag_assignments',    $artwork_medium_sync, 'handle_sync_all_tags' );
			$this->loader->add_action( 'restrict_manage_posts', $artwork_medium_sync, 'render_bulk_sync_button' );
			$this->loader->add_action( 'admin_notices',         $artwork_medium_sync, 'maybe_render_single_notice' );
			$this->loader->add_action( 'admin_notices',         $artwork_medium_sync, 'maybe_render_bulk_notice' );
			$this->loader->add_action( 'admin_notices',         $artwork_medium_sync, 'maybe_render_single_tags_notice' );
			$this->loader->add_action( 'admin_notices',         $artwork_medium_sync, 'maybe_render_bulk_tags_notice' );

			// Re-tag button (TAG-REDESIGN.md T3(e)) — per-artwork meta box
			// re-running the whole tag pipeline (Publishing\Retag::run())
			// for one post, mirroring ArtworkMediumSync's own per-artwork
			// button pattern; see ArtworkRetag's own class docblock for why
			// this is a separate class rather than folded into that one.
			$artwork_retag = new ArtworkRetag();
			$this->loader->add_action( 'add_meta_boxes',      $artwork_retag, 'register_meta_box' );
			$this->loader->add_action( 'admin_post_agnosis_retag', $artwork_retag, 'handle_retag' );
			$this->loader->add_action( 'admin_notices',       $artwork_retag, 'maybe_render_notice' );

			// Settings tab clusters (2026-07-17 god-class refactor, AUDIT-1.0.0.md
			// §4d) — each dashboard/card is now its own class under Admin\Dashboards,
			// with its own admin-post/AJAX handler(s), rather than everything routed
			// through Settings itself.
			$logs_tab = new Dashboards\LogsTab();
			$this->loader->add_action( 'admin_post_agnosis_clear_logs', $logs_tab, 'handle_clear_logs' );

			$ai_test_tools = new Dashboards\AiTestTools();
			$this->loader->add_action( 'wp_ajax_agnosis_test_ai', $ai_test_tools, 'handle_test_ai' );

			$admission_dashboard = new Dashboards\AdmissionDashboard();
			$this->loader->add_action( 'admin_post_agnosis_admit_application',  $admission_dashboard, 'handle_admit_application' );
			$this->loader->add_action( 'admin_post_agnosis_reject_application', $admission_dashboard, 'handle_reject_application' );

			$members_dashboard = new Dashboards\MembersDashboard();
			$this->loader->add_action( 'admin_post_agnosis_ban_artist',            $members_dashboard, 'handle_ban_artist' );
			$this->loader->add_action( 'admin_post_agnosis_delete_artist',         $members_dashboard, 'handle_delete_artist' );
			$this->loader->add_action( 'admin_post_agnosis_initiate_removal_vote', $members_dashboard, 'handle_initiate_removal_vote' );

			$newsletter_dashboard = new Dashboards\NewsletterDashboard();
			$this->loader->add_action( 'admin_post_agnosis_send_newsletter_now',   $newsletter_dashboard, 'handle_send_newsletter_now' );
			$this->loader->add_action( 'admin_post_agnosis_send_newsletter_test',  $newsletter_dashboard, 'handle_send_newsletter_test' );
			$this->loader->add_action( 'admin_post_agnosis_retry_failed_newsletter_recipients', $newsletter_dashboard, 'handle_retry_failed_newsletter_recipients' );

			$invitation_card = new Dashboards\InvitationCard();
			$this->loader->add_action( 'admin_post_agnosis_send_invitation',      $invitation_card, 'handle_send_invitation' );
			$this->loader->add_action( 'admin_post_agnosis_send_invitation_test', $invitation_card, 'handle_send_invitation_test' );

			$deliverability_card = new Dashboards\DeliverabilityCard();
			$this->loader->add_action( 'admin_post_agnosis_send_deliverability_test', $deliverability_card, 'handle_send_deliverability_test' );

			$branding_test_form = new Dashboards\BrandingTestForm();
			$this->loader->add_action( 'admin_post_agnosis_send_branding_test', $branding_test_form, 'handle_send_branding_test' );

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

		// agnosis_medium "sensitive by default" term meta (audit §3f).
		$this->loader->add_action( 'agnosis_medium_add_form_fields', $profile, 'render_sensitive_add_field' );
		$this->loader->add_action( 'agnosis_medium_edit_form_fields', $profile, 'render_sensitive_edit_field' );
		$this->loader->add_action( 'created_agnosis_medium', $profile, 'save_sensitive_term_meta' );
		$this->loader->add_action( 'edited_agnosis_medium', $profile, 'save_sensitive_term_meta' );

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
		// Double opt-in housekeeping (security audit §3a): prune abandoned
		// never-confirmed applications, piggybacked on the same daily cron.
		$this->loader->add_action( 'agnosis_check_admissions', $admission, 'expire_stale_unverified' );
		// Retention/anonymization for resolved applications (legal audit
		// §4c), piggybacked on the same daily cron.
		$this->loader->add_action( 'agnosis_check_admissions', $admission, 'anonymize_resolved_applications' );
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

		// Settings → General → "Preset biography title" — overrides every
		// agnosis_biography post's title when configured; see that class's
		// docblock for why this is one wp_insert_post_data hook rather than
		// patching each title-setting path (including this one) separately.
		$biography_title = new BiographyTitle();
		$biography_title->register_hooks();

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

		// Join application double opt-in email-link shim — processes
		// ?agnosis_admission=1&action=confirm&token=… (mirrors VouchConfirm's
		// own pattern; security audit §3a/§4a).
		$admission_confirm = new AdmissionConfirm( $admission );
		$admission_confirm->register_hooks();

		// Daily vote-email digest for artists who've switched to digest mode
		// (security audit §5b/§4a) — see VoteDigest's own docblock.
		$vote_digest = new VoteDigest();
		$vote_digest->register_hooks();

		// Tokenized, unauthenticated "manage notification preferences" front
		// end — processes ?agnosis_prefs=1&artist=…&token=… (mirrors
		// VouchConfirm/AdmissionConfirm's stateless-HMAC pattern; security
		// audit §5b).
		$notification_preferences = new NotificationPreferences();
		$notification_preferences->register_hooks();

		// Visitor-to-artist contact form — REST endpoint behind the
		// breadcrumb's contact popover (SubdomainNavigation, blocks/contact-form).
		$contact_form = new ContactForm();
		$this->loader->add_action( 'rest_api_init', $contact_form, 'register_routes' );
		// Retention sweep (security audit §4b) — piggybacked on the existing
		// daily inbox-cleanup cron rather than a new scheduled event; see
		// ContactForm::prune_old_messages()'s own docblock.
		$this->loader->add_action( 'agnosis_cleanup_inbox', $contact_form, 'prune_old_messages' );
		// Contact-thread reply gateway (CONTACT-FORM-TRANSLATION-ROADMAP.md
		// §3, CF5/CF6) — called directly, not via $this->loader, since it
		// internally calls add_action() itself (same convention as
		// ActivityPub::register_reply_moderation_handler() just below).
		$contact_form->register_reply_gateway_handler();
		// CF7 drain cron — translates and emails onward every reply row
		// still awaiting delivery (see ContactForm::drain_reply_queue()'s
		// own docblock).
		$this->loader->add_action( 'agnosis_drain_contact_reply_queue', $contact_form, 'drain_reply_queue' );

		$contact_form_block = new ContactFormBlock();
		$this->loader->add_action( 'init', $contact_form_block, 'register_block' );

		$join = new JoinPage();
		$this->loader->add_action( 'init', $join, 'register_block' );

		// Tag-proposal TTL sweep — must be registered unconditionally, not
		// inside the is_admin() block above (that block only registers the
		// notice/admin-post handlers): wp-cron.php requests are not admin
		// requests, so an 'init' handler gated on is_admin() would never run
		// during an actual cron execution and the sweep would silently never
		// fire. A second, throwaway TagProposals instance — the class is
		// stateless, so this costs nothing beyond the one extra object.
		$tag_proposal_sweep = new TagProposals();
		$this->loader->add_action( 'init',                          $tag_proposal_sweep, 'schedule_ttl_sweep' );
		$this->loader->add_action( 'agnosis_tag_proposal_ttl_sweep', $tag_proposal_sweep, 'sweep_expired' );

		// Medium-proposal TTL sweep (TAG-REDESIGN.md T5(b)) — same
		// unconditional-registration reasoning as the tag sweep just above,
		// and the same shared `agnosis_proposal_ttl` setting.
		$medium_proposal_sweep = new MediumProposals();
		$this->loader->add_action( 'init',                             $medium_proposal_sweep, 'schedule_ttl_sweep' );
		$this->loader->add_action( 'agnosis_medium_proposal_ttl_sweep', $medium_proposal_sweep, 'sweep_expired' );

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
		// 2026-07-14: a remove@ subject matching more than one post (e.g. an
		// artwork and an event sharing a title) gets its own email listing
		// every match with an individual confirm link — see
		// PostCreator::handle_removal_request()'s own docblock.
		$this->loader->add_action( 'agnosis_removal_requested_multiple', $notification, 'on_removal_requested_multiple', 10, 3 );
		$this->loader->add_action( 'agnosis_submission_rejected',  $notification, 'on_submission_rejected',  10, 4 );
		// Manual discard (artist/admin clicks "discard" on the review screen) is
		// a DIFFERENT event from the automatic AI photo-quality gate above —
		// 'agnosis_submission_rejected' carries a real detected score and only
		// ever fires from PostCreator's own quality check; reusing it for a
		// manual discard (as ReviewEndpoints::reject() used to) sent every
		// discarded draft the same "photo quality too low, 0/10" email
		// regardless of post type or the artist's actual reason (2026-07-21 fix).
		$this->loader->add_action( 'agnosis_submission_discarded', $notification, 'on_submission_discarded', 10, 3 );
		$this->loader->add_action( 'agnosis_submission_no_attachment', $notification, 'on_submission_no_attachment', 10, 2 );
		$this->loader->add_action( 'agnosis_submission_looks_like_reply', $notification, 'on_submission_looks_like_reply', 10, 2 );
		// fifth audit §2b/§2c — remove@/promote@ artist feedback.
		$this->loader->add_action( 'agnosis_removal_target_not_found', $notification, 'on_removal_target_not_found', 10, 6 );
		$this->loader->add_action( 'agnosis_promotion_result',          $notification, 'on_promotion_result',        10, 5 );

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
		// Interaction-surface roadmap, Phase 1 (2026-07-24): agnosis/interaction-counts,
		// the on-site like/boost count display — see ActivityPub::register_interaction_counts_block().
		$this->loader->add_action( 'init',                   $activitypub, 'register_interaction_counts_block' );
		// Interaction-surface roadmap, Phase 2 (2026-07-25): federated replies.
		// agnosis/reply-overlay (the "N replies" popover trigger) and the
		// stateless one-click Approve/Reject email-link handler — see
		// ActivityPub::register_reply_overlay_block()/register_reply_moderation_handler()'s
		// own docblocks. The translation-queue drain is wired further below,
		// alongside FederationSettlement's own cron wiring.
		$this->loader->add_action( 'init',                   $activitypub, 'register_reply_overlay_block' );
		$activitypub->register_reply_moderation_handler();
		// Follow amplification (2026-07-30): agnosis/follow-overlay, the
		// "Follow" popover next to reply-overlay's own "Reply" trigger — see
		// ActivityPub::register_follow_overlay_block()'s own docblock.
		$this->loader->add_action( 'init',                   $activitypub, 'register_follow_overlay_block' );
		// Interaction-surface roadmap, Phase 3, WP4 (2026-07-27): local
		// (visitor) replies. POST content/{id}/replies is registered inside
		// register_routes() above (already wired); this is only the admin
		// Comments-screen filter-dropdown convenience — see
		// ActivityPub::add_reply_type_filters()'s own docblock.
		$this->loader->add_filter( 'admin_comment_types_dropdown', $activitypub, 'add_reply_type_filters' );
		// TAG-REDESIGN.md F3 (§6c): broadcast() (the Create) no longer fires
		// directly on 'agnosis_post_published' (that action still fires for
		// EVERY publish, but now only drives Lingua Forge's own language-meta/
		// translation-scheduling listeners) — it fires on the new
		// 'agnosis_federation_settled' action instead, which
		// Network\FederationSettlement raises only once a post's tags/medium
		// have actually settled (or timed out). See FederationSettlement's own
		// class docblock for the full trigger design.
		$this->loader->add_action( 'agnosis_federation_settled', $activitypub, 'broadcast', 10, 1 );
		// Audit §3c: artwork object ids must dereference to ActivityStreams
		// JSON — content-negotiate on the artwork permalink itself.
		$this->loader->add_action( 'template_redirect',      $activitypub, 'serve_artwork_activity_json' );
		// Audit §3e: federate the full artwork lifecycle, not just publish.
		// Leaving publish (trash — the removal-vote flow — or unpublish)
		// federates Delete+Tombstone; wp_delete_post() (Departure's force
		// delete) never fires a status transition, hence before_delete_post;
		// edits federate Update (post row via post_updated, replaced photo
		// via the _thumbnail_id meta hooks, since set_post_thumbnail() fires
		// neither).
		$this->loader->add_action( 'transition_post_status', $activitypub, 'federate_status_transition', 10, 3 );
		$this->loader->add_action( 'before_delete_post',     $activitypub, 'federate_force_delete', 10, 1 );
		$this->loader->add_action( 'post_updated',           $activitypub, 'federate_update', 10, 3 );
		$this->loader->add_action( 'updated_post_meta',      $activitypub, 'federate_thumbnail_update', 10, 3 );
		$this->loader->add_action( 'added_post_meta',        $activitypub, 'federate_thumbnail_update', 10, 3 );
		// Audit §3g note iv: a delivery that failed once is retried on a
		// backoff schedule rather than lost after one fire-and-forget attempt.
		// The cron event itself is scheduled in Activator::schedule_events()
		// (every_five_minutes — the same interval Inbox::register_interval()
		// already registers on every request).
		$this->loader->add_action( 'agnosis_ap_retry_deliveries', $activitypub, 'process_delivery_retry_queue' );
		// Interaction-surface roadmap, Phase 2 — async reply-translation drain
		// (ActivityPub::drain_reply_translation_queue()'s own docblock covers
		// why this runs off the signed inbox() request path).
		$this->loader->add_action( 'agnosis_drain_reply_translation_queue', $activitypub, 'drain_reply_translation_queue' );
		// Interaction-surface roadmap, Phase 3, WP2 — daily anonymous-like
		// salt rotation (ActivityPub::rotate_like_salt()'s own docblock).
		$this->loader->add_action( 'agnosis_rotate_like_salt', $activitypub, 'rotate_like_salt' );
		// Interaction-surface roadmap, Phase 3, WP6 (2026-07-27): federating
		// artist replies outward. store_artist_gateway_reply() already
		// triggers the outbound Create{Note} inline (no hook needed there —
		// see that method's own docblock); these two cover the Delete{Note}
		// side, since an admin can remove a federated artist reply via
		// wp-admin even though the artist who authored it never logs in —
		// see maybe_federate_reply_removal()'s own docblock for why both
		// hooks are needed (trash vs. a hard/force delete that bypasses it).
		$this->loader->add_action( 'transition_comment_status', $activitypub, 'handle_reply_status_transition', 10, 3 );
		$this->loader->add_action( 'delete_comment',             $activitypub, 'handle_reply_hard_delete', 10, 1 );
		// Found 2026-07-28: WordPress core's own native comment-notification
		// emails (unbranded, untranslated, fired synchronously at insert
		// time) were reaching artists on top of our own branded, translated
		// notify_artist_of_reply() — see suppress_native_reply_notifications()'s
		// own docblock.
		$this->loader->add_filter( 'comment_notification_recipients', $activitypub, 'suppress_native_reply_notifications', 10, 2 );
		$this->loader->add_filter( 'comment_moderation_recipients',   $activitypub, 'suppress_native_reply_notifications', 10, 2 );

		// Reply-language-mirroring roadmap (agnosis-audit/
		// REPLY-LANGUAGE-MIRRORING-ROADMAP.md), RLM4/RLM9 — built once
		// Ulises answered §4's open questions (2026-07-29). RLM2/RLM3's own
		// 'transition_comment_status'/'delete_comment' hooks are already
		// registered just above; these two cover the two NEW trigger points:
		// editing an already-mirrored canonical reply's text (RLM4), and a
		// brand-new Lingua Forge sibling appearing that lets an
		// already-approved reply's mirror finally be created (RLM9,
		// backfill_reply_mirrors_for_new_sibling()'s own docblock).
		// Harmless to register unconditionally even when Lingua Forge is
		// inactive — 'linguaforge_translation_complete' simply never fires.
		$this->loader->add_action( 'edit_comment',                       $activitypub, 'handle_reply_content_edit', 10, 1 );
		$this->loader->add_action( 'linguaforge_translation_complete',   $activitypub, 'backfill_reply_mirrors_for_new_sibling', 10, 3 );

		// Federation settlement (TAG-REDESIGN.md F3, §6c) — the tag-settled
		// state machine that decides WHEN a post is ready to fire
		// 'agnosis_federation_settled' (wired to broadcast() above).
		// Registered unconditionally (not inside the is_admin() block below)
		// for the same reason the tag/medium-proposal TTL sweeps are: a
		// resolve hook can fire from an admin-post request, but the fallback
		// cron fires from wp-cron.php, which is never an admin request.
		$federation_settlement = new FederationSettlement();
		// TagProposals::approve/reject/sweep_expired and MediumProposals'
		// three equivalents all resolve through these two actions (fired by
		// Publishing\TagGate::clear_proposal() for tags — the one choke
		// point all three tag paths already share — and directly at each of
		// MediumProposals' three resolve call sites, since medium has no
		// equivalent shared clear method).
		$this->loader->add_action( 'agnosis_tag_proposal_resolved',    $federation_settlement, 'maybe_settle', 10, 1 );
		$this->loader->add_action( 'agnosis_medium_proposal_resolved', $federation_settlement, 'maybe_settle', 10, 1 );
		// Priority 20 — AFTER Compat\LinguaForge::sync_translated_terms()'s
		// own priority-10 registration on this same action — so a sibling's
		// terms are already synced (real hashtags) by the time this decides
		// whether to federate it.
		$this->loader->add_action( 'linguaforge_translation_complete', $federation_settlement, 'on_translation_complete', 20, 3 );
		// Safety valve — force-settles any published artwork still waiting
		// on a tag/medium proposal past agnosis_federation_tag_wait hours.
		$this->loader->add_action( 'init',                              $federation_settlement, 'schedule_fallback_sweep' );
		$this->loader->add_action( 'agnosis_federation_tag_wait_sweep', $federation_settlement, 'sweep_timed_out' );

		// Subdomain navigation — artist-breadcrumb block, plus pointing the Site
		// Logo/Site Title links back at the main site from an artist subdomain.
		// Lives in the plugin (not a theme) so any Agnosis-compatible theme gets
		// both just by using core's Site Logo/Site Title blocks.
		//
		// register_block() (agnosis/artist-breadcrumb) is kept registered
		// alongside the two newer blocks below purely for backward
		// compatibility — see register_artist_name_link_block()'s docblock.
		$subdomain_nav = new SubdomainNavigation();
		$this->loader->add_action( 'init',                       $subdomain_nav, 'register_block' );
		$this->loader->add_action( 'init',                       $subdomain_nav, 'register_artist_name_link_block' );
		// Follow amplification (2026-07-30): agnosis/site-copyright, the
		// footer copyright line carrying the site's or artist's Fediverse
		// handle in parentheses — see SubdomainNavigation::register_copyright_block()'s
		// own docblock.
		$this->loader->add_action( 'init',                       $subdomain_nav, 'register_copyright_block' );
		$this->loader->add_action( 'init',                       $subdomain_nav, 'register_breadcrumb_icon_link_block' );
		$this->loader->add_filter( 'render_block_core/group',       $subdomain_nav, 'hide_empty_breadcrumb_group', 10, 2 );
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

		// Interaction-surface roadmap, Phase 3, WP3 — the no-login newsletter
		// like/boost gateway. Same GET-renders/POST-acts template_redirect
		// shim convention as ReviewConfirm just above.
		$interaction_gateway = new InteractionGateway();
		$this->loader->add_action( 'template_redirect', $interaction_gateway, 'handle_confirm', 1 );
		$this->loader->add_action( 'template_redirect', $interaction_gateway, 'handle_result',  1 );

		// Lingua Forge integration — boots itself only when LF is present;
		// registers the compat admin notice unconditionally.
		new LinguaForge();

		// Debounced permalink-flush cron callback — see RewriteFlush's own
		// docblock. Registered unconditionally (not gated on LF being active):
		// ReviewEndpoints::finalize_publish() schedules a flush on every
		// approval regardless of whether Lingua Forge ends up doing any
		// fan-out translation for that particular post.
		RewriteFlush::register();
	}
}
