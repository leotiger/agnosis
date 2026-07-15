<?php
/**
 * WordPress core privacy (GDPR DSAR) integration.
 *
 * Registers this plugin's personal data with WordPress's own Tools →
 * Export/Erase Personal Data tools, and appends a suggested-content block to
 * the Privacy Policy Guide — closing seventh audit §4a ("No WordPress
 * privacy integration: a data-subject access or erasure request cannot be
 * fulfilled through the standard tools"). Before this class existed, an
 * operator serving an Art. 15/17 GDPR request had no supported way to
 * satisfy it: this plugin's personal data lives in custom tables (and a
 * handful of user meta flags) that core's own exporter/eraser — which only
 * ever covers comments — cannot see.
 *
 * Every group below is resolved by the requester's email address, the same
 * identifier WordPress's Personal Data request tools key on throughout. Two
 * different lookup shapes are needed because this plugin stores personal
 * data both directly by email (application, newsletter, contact-form rows)
 * and indirectly via a WP user account (governance participation,
 * transactions, per-artist preference flags) — the latter group resolves
 * the email to a user ID with `get_user_by( 'email', … )` first and returns
 * nothing for that group when no matching account exists.
 *
 * Erasure is deliberately *not* "delete every row that mentions this
 * email" — a few categories have a legitimate reason to keep a redacted
 * placeholder rather than disappear outright, and this class follows the
 * same "anonymize, don't destroy referential/statistical integrity" pattern
 * WooCommerce and other mature plugins use for their own erasers:
 *
 * - `agnosis_applications`: free-text/identifying fields (name, bio,
 *   statement, portfolio, email) are redacted to a per-row-unique
 *   placeholder; the status/timestamps stay, so admission history and
 *   community-cap accounting aren't silently corrupted by a vanished row.
 *   An application still in an active state (pending/waitlisted/admitted)
 *   is left alone with an explanatory message — an active membership's core
 *   data is the "leave the network" flow's job (`Artist\Departure`), not a
 *   DSAR eraser's.
 * - Governance rows (`agnosis_vouches`, `agnosis_application_vouches`,
 *   `agnosis_removal_votes`, `agnosis_cap_votes`): only the free-text
 *   `message` field is redacted. The yes/no vote itself is a community
 *   governance record, not personal data belonging to the voter to erase —
 *   silently mutating a settled vote tally would be worse than the privacy
 *   gain.
 * - `agnosis_newsletter_subscribers`: no legitimate reason to keep a
 *   redacted row once someone asks to be forgotten, so the row is deleted
 *   outright.
 * - `agnosis_newsletter_queue`: historical delivery rows are anonymized
 *   (email + token replaced), not deleted, so per-issue delivery counts
 *   stay accurate.
 * - `agnosis_contact_messages`: visitor identity + message text is
 *   redacted; the artist-facing status/reason/timestamp is kept for the
 *   same audit-trail reason `Admin\ContactMessagesPage` exists at all.
 * - `agnosis_transactions`: export-only, never erased — financial records
 *   are subject to a legal retention obligation (GDPR Art. 17(3)(b)), and
 *   the eraser reports that explicitly via `items_retained` rather than
 *   silently doing nothing.
 *
 * @package Agnosis\Core
 */

declare(strict_types=1);

namespace Agnosis\Core;

class Privacy {

	/** User meta keys read/written by Artist\NotificationPreferences and Newsletter\SubscriptionConfirm. */
	private const PREFERENCE_META_KEYS = [
		'_agnosis_broadcast_optout',
		'_agnosis_vote_email_mode',
		'_agnosis_contact_optout',
		'_agnosis_newsletter_optout',
	];

	/** Application statuses that are NOT terminal — erasure declines and points to the departure flow instead. */
	private const ACTIVE_APPLICATION_STATUSES = [ 'unverified', 'pending', 'waitlisted', 'admitted' ];

	/**
	 * Internal, deliberately untranslated marker written to a redacted row's
	 * `display_name` by anonymize_application_row() — used as the
	 * already-redacted signal `Admission::anonymize_resolved_applications()`
	 * checks for so it never reprocesses a row this class (or that sweep)
	 * already anonymized.
	 */
	public const REDACTED_MARKER = '(erased)';

	// -------------------------------------------------------------------------
	// Hook registration
	// -------------------------------------------------------------------------

	public function register_hooks(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_erasers' ] );
		add_action( 'admin_init', [ $this, 'add_privacy_policy_content' ] );
	}

	// -------------------------------------------------------------------------
	// Exporters
	// -------------------------------------------------------------------------

	/**
	 * @param array<string, array{exporter_friendly_name: string, callback: callable}> $exporters
	 * @return array<string, array{exporter_friendly_name: string, callback: callable}>
	 */
	public function register_exporters( array $exporters ): array {
		$exporters['agnosis-application'] = [
			'exporter_friendly_name' => __( 'Agnosis Membership Application', 'agnosis' ),
			'callback'                => [ $this, 'export_application' ],
		];
		$exporters['agnosis-governance'] = [
			'exporter_friendly_name' => __( 'Agnosis Governance Participation', 'agnosis' ),
			'callback'                => [ $this, 'export_governance' ],
		];
		$exporters['agnosis-newsletter-subscription'] = [
			'exporter_friendly_name' => __( 'Agnosis Newsletter Subscription', 'agnosis' ),
			'callback'                => [ $this, 'export_newsletter_subscription' ],
		];
		$exporters['agnosis-newsletter-history'] = [
			'exporter_friendly_name' => __( 'Agnosis Newsletter Delivery History', 'agnosis' ),
			'callback'                => [ $this, 'export_newsletter_history' ],
		];
		$exporters['agnosis-contact-messages'] = [
			'exporter_friendly_name' => __( 'Agnosis Contact Messages Sent', 'agnosis' ),
			'callback'                => [ $this, 'export_contact_messages' ],
		];
		$exporters['agnosis-transactions'] = [
			'exporter_friendly_name' => __( 'Agnosis Transactions', 'agnosis' ),
			'callback'                => [ $this, 'export_transactions' ],
		];
		$exporters['agnosis-preferences'] = [
			'exporter_friendly_name' => __( 'Agnosis Notification Preferences', 'agnosis' ),
			'callback'                => [ $this, 'export_preferences' ],
		];
		return $exporters;
	}

	/** @return array{data: array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>, done: bool} */
	public function export_application( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return $this->done();
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy exporter, single row lookup by unique email.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT display_name, bio, portfolio_url, statement, language, status, applied_at, resolved_at
				 FROM {$wpdb->prefix}agnosis_applications WHERE email = %s",
				$email_address
			)
		);

		if ( ! $row ) {
			return $this->done();
		}

		$data = [
			$this->item( __( 'Display name', 'agnosis' ), (string) $row->display_name ),
			$this->item( __( 'Biography', 'agnosis' ), (string) $row->bio ),
			$this->item( __( 'Portfolio URL', 'agnosis' ), (string) $row->portfolio_url ),
			$this->item( __( 'Application statement', 'agnosis' ), (string) $row->statement ),
			$this->item( __( 'Language', 'agnosis' ), (string) $row->language ),
			$this->item( __( 'Status', 'agnosis' ), (string) $row->status ),
			$this->item( __( 'Applied at', 'agnosis' ), (string) $row->applied_at ),
			$this->item( __( 'Resolved at', 'agnosis' ), (string) $row->resolved_at ),
		];

		return [
			'data' => [
				[
					'group_id'    => 'agnosis-application',
					'group_label' => __( 'Agnosis Membership Application', 'agnosis' ),
					'item_id'     => 'agnosis-application',
					'data'        => $data,
				],
			],
			'done' => true,
		];
	}

	/** @return array{data: array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>, done: bool} */
	public function export_governance( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return $this->done();
		}

		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return $this->done();
		}

		global $wpdb;
		$uid  = $user->ID;
		$data = [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy exporter, bounded to one voucher's own rows.
		$vouches = $wpdb->get_results( $wpdb->prepare(
			"SELECT candidate_id, message, created_at FROM {$wpdb->prefix}agnosis_vouches WHERE voucher_id = %d",
			$uid
		) );
		foreach ( $vouches as $i => $row ) {
			$data[] = [
				'group_id'    => 'agnosis-governance',
				'group_label' => __( 'Agnosis Governance Participation', 'agnosis' ),
				'item_id'     => 'agnosis-vouch-' . $i,
				'data'        => [
					$this->item( __( 'Type', 'agnosis' ), __( 'Community vouch given', 'agnosis' ) ),
					$this->item( __( 'Message', 'agnosis' ), (string) $row->message ),
					$this->item( __( 'Date', 'agnosis' ), (string) $row->created_at ),
				],
			];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy exporter, bounded to one voucher's own rows.
		$app_vouches = $wpdb->get_results( $wpdb->prepare(
			"SELECT application_id, message, created_at FROM {$wpdb->prefix}agnosis_application_vouches WHERE voucher_id = %d",
			$uid
		) );
		foreach ( $app_vouches as $i => $row ) {
			$data[] = [
				'group_id'    => 'agnosis-governance',
				'group_label' => __( 'Agnosis Governance Participation', 'agnosis' ),
				'item_id'     => 'agnosis-app-vouch-' . $i,
				'data'        => [
					$this->item( __( 'Type', 'agnosis' ), __( 'Admission vouch given', 'agnosis' ) ),
					$this->item( __( 'Message', 'agnosis' ), (string) $row->message ),
					$this->item( __( 'Date', 'agnosis' ), (string) $row->created_at ),
				],
			];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy exporter, bounded to one voter's own rows.
		$removal_votes = $wpdb->get_results( $wpdb->prepare(
			"SELECT request_id, vote, voted_at FROM {$wpdb->prefix}agnosis_removal_votes WHERE voter_id = %d",
			$uid
		) );
		foreach ( $removal_votes as $i => $row ) {
			$data[] = [
				'group_id'    => 'agnosis-governance',
				'group_label' => __( 'Agnosis Governance Participation', 'agnosis' ),
				'item_id'     => 'agnosis-removal-vote-' . $i,
				'data'        => [
					$this->item( __( 'Type', 'agnosis' ), __( 'Community removal vote', 'agnosis' ) ),
					$this->item( __( 'Vote', 'agnosis' ), (string) $row->vote ),
					$this->item( __( 'Date', 'agnosis' ), (string) $row->voted_at ),
				],
			];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy exporter, bounded to one voter's own rows.
		$cap_votes = $wpdb->get_results( $wpdb->prepare(
			"SELECT proposal_id, vote, voted_at FROM {$wpdb->prefix}agnosis_cap_votes WHERE voter_id = %d",
			$uid
		) );
		foreach ( $cap_votes as $i => $row ) {
			$data[] = [
				'group_id'    => 'agnosis-governance',
				'group_label' => __( 'Agnosis Governance Participation', 'agnosis' ),
				'item_id'     => 'agnosis-cap-vote-' . $i,
				'data'        => [
					$this->item( __( 'Type', 'agnosis' ), __( 'Community size-cap vote', 'agnosis' ) ),
					$this->item( __( 'Vote', 'agnosis' ), (string) $row->vote ),
					$this->item( __( 'Date', 'agnosis' ), (string) $row->voted_at ),
				],
			];
		}

		return [ 'data' => $data, 'done' => true ];
	}

	/** @return array{data: array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>, done: bool} */
	public function export_newsletter_subscription( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return $this->done();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy exporter, single row lookup by unique email.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT status, locale, created_at, confirmed_at, unsubscribed_at
			 FROM {$wpdb->prefix}agnosis_newsletter_subscribers WHERE email = %s",
			$email_address
		) );

		if ( ! $row ) {
			return $this->done();
		}

		return [
			'data' => [
				[
					'group_id'    => 'agnosis-newsletter-subscription',
					'group_label' => __( 'Agnosis Newsletter Subscription', 'agnosis' ),
					'item_id'     => 'agnosis-newsletter-subscription',
					'data'        => [
						$this->item( __( 'Status', 'agnosis' ), (string) $row->status ),
						$this->item( __( 'Locale', 'agnosis' ), (string) $row->locale ),
						$this->item( __( 'Subscribed at', 'agnosis' ), (string) $row->created_at ),
						$this->item( __( 'Confirmed at', 'agnosis' ), (string) $row->confirmed_at ),
						$this->item( __( 'Unsubscribed at', 'agnosis' ), (string) $row->unsubscribed_at ),
					],
				],
			],
			'done' => true,
		];
	}

	/** @return array{data: array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>, done: bool} */
	public function export_newsletter_history( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return $this->done();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy exporter, bounded to one recipient's own rows.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT issue_id, recipient_type, locale, status, created_at, resolved_at
			 FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE recipient_email = %s",
			$email_address
		) );

		$data = [];
		foreach ( $rows as $i => $row ) {
			$data[] = [
				'group_id'    => 'agnosis-newsletter-history',
				'group_label' => __( 'Agnosis Newsletter Delivery History', 'agnosis' ),
				'item_id'     => 'agnosis-newsletter-delivery-' . $i,
				'data'        => [
					$this->item( __( 'Issue', 'agnosis' ), (string) $row->issue_id ),
					$this->item( __( 'Recipient type', 'agnosis' ), (string) $row->recipient_type ),
					$this->item( __( 'Locale', 'agnosis' ), (string) $row->locale ),
					$this->item( __( 'Status', 'agnosis' ), (string) $row->status ),
					$this->item( __( 'Queued at', 'agnosis' ), (string) $row->created_at ),
					$this->item( __( 'Resolved at', 'agnosis' ), (string) $row->resolved_at ),
				],
			];
		}

		return [ 'data' => $data, 'done' => true ];
	}

	/** @return array{data: array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>, done: bool} */
	public function export_contact_messages( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return $this->done();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy exporter, bounded to one visitor's own rows.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT artist_id, visitor_name, message, translated_message, status, rejection_reason, ip, created_at
			 FROM {$wpdb->prefix}agnosis_contact_messages WHERE visitor_email = %s",
			$email_address
		) );

		$data = [];
		foreach ( $rows as $i => $row ) {
			$artist = get_userdata( (int) $row->artist_id );
			$data[] = [
				'group_id'    => 'agnosis-contact-messages',
				'group_label' => __( 'Agnosis Contact Messages Sent', 'agnosis' ),
				'item_id'     => 'agnosis-contact-message-' . $i,
				'data'        => [
					$this->item( __( 'Sent to artist', 'agnosis' ), $artist ? $artist->display_name : (string) $row->artist_id ),
					$this->item( __( 'Your name', 'agnosis' ), (string) $row->visitor_name ),
					$this->item( __( 'Message', 'agnosis' ), (string) $row->message ),
					$this->item( __( 'Translated message', 'agnosis' ), (string) $row->translated_message ),
					$this->item( __( 'Status', 'agnosis' ), (string) $row->status ),
					$this->item( __( 'Rejection reason', 'agnosis' ), (string) $row->rejection_reason ),
					$this->item( __( 'IP address', 'agnosis' ), (string) $row->ip ),
					$this->item( __( 'Sent at', 'agnosis' ), (string) $row->created_at ),
				],
			];
		}

		return [ 'data' => $data, 'done' => true ];
	}

	/** @return array{data: array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>, done: bool} */
	public function export_transactions( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return $this->done();
		}

		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return $this->done();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy exporter, bounded to one artist's own rows.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT type, amount, currency, fee, gateway, status, created_at
			 FROM {$wpdb->prefix}agnosis_transactions WHERE artist_id = %d",
			$user->ID
		) );

		$data = [];
		foreach ( $rows as $i => $row ) {
			$data[] = [
				'group_id'    => 'agnosis-transactions',
				'group_label' => __( 'Agnosis Transactions', 'agnosis' ),
				'item_id'     => 'agnosis-transaction-' . $i,
				'data'        => [
					$this->item( __( 'Type', 'agnosis' ), (string) $row->type ),
					$this->item( __( 'Amount', 'agnosis' ), (string) $row->amount ),
					$this->item( __( 'Currency', 'agnosis' ), (string) $row->currency ),
					$this->item( __( 'Fee', 'agnosis' ), (string) $row->fee ),
					$this->item( __( 'Gateway', 'agnosis' ), (string) $row->gateway ),
					$this->item( __( 'Status', 'agnosis' ), (string) $row->status ),
					$this->item( __( 'Date', 'agnosis' ), (string) $row->created_at ),
				],
			];
		}

		return [ 'data' => $data, 'done' => true ];
	}

	/** @return array{data: array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>, done: bool} */
	public function export_preferences( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return $this->done();
		}

		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return $this->done();
		}

		$data = [];
		foreach ( self::PREFERENCE_META_KEYS as $key ) {
			$value = get_user_meta( $user->ID, $key, true );
			if ( '' === $value ) {
				continue;
			}
			$data[] = $this->item( $key, (string) $value );
		}

		if ( ! $data ) {
			return $this->done();
		}

		return [
			'data' => [
				[
					'group_id'    => 'agnosis-preferences',
					'group_label' => __( 'Agnosis Notification Preferences', 'agnosis' ),
					'item_id'     => 'agnosis-preferences',
					'data'        => $data,
				],
			],
			'done' => true,
		];
	}

	// -------------------------------------------------------------------------
	// Erasers
	// -------------------------------------------------------------------------

	/**
	 * @param array<string, array{eraser_friendly_name: string, callback: callable}> $erasers
	 * @return array<string, array{eraser_friendly_name: string, callback: callable}>
	 */
	public function register_erasers( array $erasers ): array {
		$erasers['agnosis-application'] = [
			'eraser_friendly_name' => __( 'Agnosis Membership Application', 'agnosis' ),
			'callback'              => [ $this, 'erase_application' ],
		];
		$erasers['agnosis-governance'] = [
			'eraser_friendly_name' => __( 'Agnosis Governance Participation', 'agnosis' ),
			'callback'              => [ $this, 'erase_governance' ],
		];
		$erasers['agnosis-newsletter-subscription'] = [
			'eraser_friendly_name' => __( 'Agnosis Newsletter Subscription', 'agnosis' ),
			'callback'              => [ $this, 'erase_newsletter_subscription' ],
		];
		$erasers['agnosis-newsletter-history'] = [
			'eraser_friendly_name' => __( 'Agnosis Newsletter Delivery History', 'agnosis' ),
			'callback'              => [ $this, 'erase_newsletter_history' ],
		];
		$erasers['agnosis-contact-messages'] = [
			'eraser_friendly_name' => __( 'Agnosis Contact Messages Sent', 'agnosis' ),
			'callback'              => [ $this, 'erase_contact_messages' ],
		];
		$erasers['agnosis-transactions'] = [
			'eraser_friendly_name' => __( 'Agnosis Transactions', 'agnosis' ),
			'callback'              => [ $this, 'erase_transactions' ],
		];
		$erasers['agnosis-preferences'] = [
			'eraser_friendly_name' => __( 'Agnosis Notification Preferences', 'agnosis' ),
			'callback'              => [ $this, 'erase_preferences' ],
		];
		return $erasers;
	}

	/** @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} */
	public function erase_application( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return $this->erased();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy eraser, single row lookup by unique email.
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, status FROM {$wpdb->prefix}agnosis_applications WHERE email = %s",
			$email_address
		) );

		if ( ! $row ) {
			return $this->erased();
		}

		if ( in_array( $row->status, self::ACTIVE_APPLICATION_STATUSES, true ) ) {
			return [
				'items_removed'  => false,
				'items_retained' => true,
				'messages'       => [
					__( 'Your membership application/account is still active. To remove this data, use the "leave the network" link from any Agnosis email, or ask an administrator.', 'agnosis' ),
				],
				'done'           => true,
			];
		}

		// A banned row keeps its email even on an explicit erasure request —
		// seventh audit §4c's own fix text ("banned rows may justifiably
		// retain a minimal identifier to enforce the ban") applies equally
		// whether the redaction was triggered by this data-subject request or
		// by Admission::anonymize_resolved_applications()'s automatic sweep;
		// letting a banned artist erase their own email here would otherwise
		// let them bypass the ban immediately by reapplying.
		if ( 'banned' === $row->status ) {
			self::anonymize_application_row( (int) $row->id, false );

			return [
				'items_removed'  => true,
				'items_retained' => true,
				'messages'       => [
					__( 'Your biography, statement, and portfolio link were erased. Your email address was kept on file — your account is currently banned, and it\'s the only way this site can continue to enforce that.', 'agnosis' ),
				],
				'done'           => true,
			];
		}

		self::anonymize_application_row( (int) $row->id, true );

		return [
			'items_removed'  => true,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	/**
	 * Redact the personal fields of one `agnosis_applications` row in place.
	 * Shared by this on-demand DSAR eraser above and
	 * `Artist\Admission::anonymize_resolved_applications()` (the automatic
	 * retention sweep, seventh audit §4c) so both paths redact identically
	 * rather than drifting apart — the audit's own fix text for §4c asked
	 * for exactly this ("tie into §4a eraser").
	 *
	 * The row itself (id/status/timestamps) is always kept, preserving
	 * admission-history and community-cap accounting — only the free-text/
	 * identifying fields are cleared. `display_name` is set to a fixed,
	 * untranslated internal marker (deliberately not run through `__()`,
	 * same reasoning as the email placeholder below) so both call sites can
	 * reliably recognize an already-redacted row without re-processing it.
	 *
	 * @param int  $application_id Row ID in agnosis_applications.
	 * @param bool $redact_email   False for a still-banned row — its email is
	 *                             deliberately left untouched.
	 */
	public static function anonymize_application_row( int $application_id, bool $redact_email = true ): void {
		global $wpdb;

		$fields  = [
			'display_name'  => self::REDACTED_MARKER,
			'bio'           => '',
			'portfolio_url' => '',
			'statement'     => '',
		];
		$formats = [ '%s', '%s', '%s', '%s' ];

		if ( $redact_email ) {
			$fields['email'] = 'erased-' . $application_id . '@erased.invalid';
			$formats[]       = '%s';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- redacts one row by its own primary key; shared by the DSAR eraser and the automatic retention sweep.
		$wpdb->update(
			$wpdb->prefix . 'agnosis_applications',
			$fields,
			[ 'id' => $application_id ],
			$formats,
			[ '%d' ]
		);
	}

	/** @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} */
	public function erase_governance( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return $this->erased();
		}

		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return $this->erased();
		}

		global $wpdb;
		$uid     = $user->ID;
		$redacted = __( '[message removed]', 'agnosis' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy eraser, redacts only this voucher's own free-text messages.
		$wpdb->update( $wpdb->prefix . 'agnosis_vouches', [ 'message' => $redacted ], [ 'voucher_id' => $uid ], [ '%s' ], [ '%d' ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy eraser, redacts only this voucher's own free-text messages.
		$wpdb->update( $wpdb->prefix . 'agnosis_application_vouches', [ 'message' => $redacted ], [ 'voucher_id' => $uid ], [ '%s' ], [ '%d' ] );

		return [
			'items_removed'  => true,
			'items_retained' => true,
			'messages'       => [
				__( 'Vouch messages you wrote were redacted. Governance votes you cast were kept as-is — a vote is a community record, not personal data, and removing it would silently change a settled decision.', 'agnosis' ),
			],
			'done'           => true,
		];
	}

	/** @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} */
	public function erase_newsletter_subscription( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return $this->erased();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy eraser, deletes only this subscriber's own row.
		$deleted = $wpdb->delete( $wpdb->prefix . 'agnosis_newsletter_subscribers', [ 'email' => $email_address ], [ '%s' ] );

		return [
			'items_removed'  => (bool) $deleted,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	/** @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} */
	public function erase_newsletter_history( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return $this->erased();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy eraser, anonymizes only this recipient's own delivery rows.
		$updated = $wpdb->update(
			$wpdb->prefix . 'agnosis_newsletter_queue',
			[ 'recipient_email' => 'erased@erased.invalid', 'unsubscribe_token' => wp_generate_password( 32, false ) ],
			[ 'recipient_email' => $email_address ],
			[ '%s', '%s' ],
			[ '%s' ]
		);

		return [
			'items_removed'  => (bool) $updated,
			'items_retained' => false,
			'messages'       => $updated ? [ __( 'Past newsletter delivery records were anonymized; per-issue delivery counts were kept for statistics.', 'agnosis' ) ] : [],
			'done'           => true,
		];
	}

	/** @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} */
	public function erase_contact_messages( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return $this->erased();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy eraser, redacts only this visitor's own messages.
		$updated = $wpdb->update(
			$wpdb->prefix . 'agnosis_contact_messages',
			[
				'visitor_name'       => '',
				'visitor_email'      => 'erased@erased.invalid',
				'message'            => __( '[message removed]', 'agnosis' ),
				'translated_message' => '',
				'ip'                 => '',
			],
			[ 'visitor_email' => $email_address ],
			[ '%s', '%s', '%s', '%s', '%s' ],
			[ '%s' ]
		);

		return [
			'items_removed'  => (bool) $updated,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	/** @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} */
	public function erase_transactions( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return $this->erased();
		}

		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return $this->erased();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy eraser, existence check only, no data touched (retained for legal/accounting reasons).
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_transactions WHERE artist_id = %d",
			$user->ID
		) );

		if ( ! $count ) {
			return $this->erased();
		}

		return [
			'items_removed'  => false,
			'items_retained' => true,
			'messages'       => [
				__( 'Transaction (donation/sale) records were kept in full — financial records are subject to a legal retention obligation and are exempt from erasure under GDPR Art. 17(3)(b).', 'agnosis' ),
			],
			'done'           => true,
		];
	}

	/** @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} */
	public function erase_preferences( string $email_address, int $page = 1 ): array {
		if ( $page > 1 ) {
			return $this->erased();
		}

		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return $this->erased();
		}

		foreach ( self::PREFERENCE_META_KEYS as $key ) {
			delete_user_meta( $user->ID, $key );
		}

		return [
			'items_removed'  => true,
			'items_retained' => false,
			'messages'       => [],
			'done'           => true,
		];
	}

	// -------------------------------------------------------------------------
	// Privacy Policy Guide suggested content
	// -------------------------------------------------------------------------

	public function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p class="privacy-policy-tutorial">' . esc_html__( 'Agnosis is a federated art-publishing plugin. The following is a starting point for describing what it collects — review and adapt it to your site\'s actual configuration before publishing.', 'agnosis' ) . '</p>'
			. '<h2>' . esc_html__( 'What personal data we collect and why', 'agnosis' ) . '</h2>'
			. '<p>' . esc_html__( 'When you apply to join, we store your email address, display name, biography, statement, and portfolio link to run the community vouching process. When you email us artwork, a biography, or an event, the content of that email (including your address) is stored while it is processed and reviewed. When you subscribe to our newsletter or message an artist through their contact form, we store your email address (and, for contact messages, your IP address) to deliver that message or send you future issues.', 'agnosis' ) . '</p>'
			. '<h2>' . esc_html__( 'Who we share it with', 'agnosis' ) . '</h2>'
			. '<p>' . esc_html__( 'Submitted artwork descriptions and images may be sent to a third-party AI provider (OpenAI or Anthropic, depending on this site\'s configuration) to help write publication text and enhance images. If this site instead uses WordPress\'s own built-in AI Client (configured under Settings → Connectors), submitted content is sent to whichever AI service that connector points to instead. Approved posts are also broadcast publicly over ActivityPub (the Fediverse) as part of this plugin\'s core federation feature.', 'agnosis' ) . '</p>'
			. '<h2>' . esc_html__( 'How long we retain your data', 'agnosis' ) . '</h2>'
			. '<p>' . esc_html__( 'Retention varies by data type; see this site\'s administrator for specifics. You can request a copy of, or the erasure of, the personal data described above at any time using this site\'s standard WordPress data request tools.', 'agnosis' ) . '</p>';

		wp_add_privacy_policy_content( __( 'Agnosis', 'agnosis' ), wp_kses_post( $content ) );
	}

	// -------------------------------------------------------------------------
	// Front-end consent notices (seventh audit §4d)
	// -------------------------------------------------------------------------

	/**
	 * Short, ready-to-echo privacy notice for the join and contact forms —
	 * the one piece of §4d the Privacy Policy Guide content above doesn't
	 * cover on its own: that content only ever surfaces on whatever page an
	 * operator links to it, so a visitor filling out either form previously
	 * saw no disclosure at all before submitting. Links to
	 * `get_privacy_policy_url()` when the operator has set one; degrades to
	 * plain text (no dangling/empty link) when they haven't.
	 *
	 * Already-escaped HTML — callers `echo` this directly.
	 *
	 * @param string $context 'join' (mentions AI processing and that
	 *                        approved posts are published publicly and
	 *                        federated) or 'contact' (mentions automated
	 *                        content review and that the message is sent to
	 *                        the artist).
	 */
	public static function consent_notice_html( string $context ): string {
		$text = 'join' === $context
			? __( 'Submitted text and images may be processed by a third-party AI provider. Approved posts are published publicly and shared across the Fediverse — this cannot be fully undone later.', 'agnosis' )
			: __( 'Your message may be reviewed by an automated content filter before being sent to the artist.', 'agnosis' );

		$html = esc_html( $text );

		$policy_url = function_exists( 'get_privacy_policy_url' ) ? get_privacy_policy_url() : '';
		if ( $policy_url ) {
			$html .= ' ' . sprintf(
				/* translators: %s: link to the site's Privacy Policy page */
				esc_html__( 'See our %s for details.', 'agnosis' ),
				'<a href="' . esc_url( $policy_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'agnosis' ) . '</a>'
			);
		}

		return $html;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/** @return array{name: string, value: string} */
	private function item( string $name, string $value ): array {
		return [ 'name' => $name, 'value' => $value ];
	}

	/** @return array{data: array<int, mixed>, done: bool} */
	private function done(): array {
		return [ 'data' => [], 'done' => true ];
	}

	/** @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool} */
	private function erased(): array {
		return [ 'items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true ];
	}
}
