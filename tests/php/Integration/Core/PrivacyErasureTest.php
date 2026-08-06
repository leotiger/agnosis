<?php
/**
 * Integration tests — Core\Privacy's erasure paths (GDPR Art. 17).
 *
 * `PrivacyTest` covers the two groups the sixteenth audit's G-1/G-5 added:
 * contact messages and replies. Everything else in this 533-statement class —
 * the application, governance and newsletter erasers — had no coverage at all,
 * which is why the file sat at 36%.
 *
 * **Why erasure specifically, ahead of the exporters.** An export that is wrong
 * can be re-run. An erasure cannot: it destroys data in place, and G-1 in this
 * very audit was exactly that failure — `erase_contact_messages()` keyed on
 * `visitor_email` alone and so blanked the *artist's* reply text, someone
 * else's words, irreversibly. That was found by reading code, not by a test.
 * These are the remaining erasers of the same shape.
 *
 * The assertions are therefore mostly about **what must survive**:
 *
 *   erase_application() — the four-way status contract:
 *     - An ACTIVE application (unverified/pending/waitlisted/admitted) is
 *       retained, not erased, and says how to actually leave
 *     - A BANNED row keeps its email — erasing it would let a banned artist
 *       reapply immediately — while still erasing bio/statement/portfolio
 *     - A RESOLVED row (rejected/withdrawn/left) is fully anonymized
 *     - An unknown address is a clean no-op
 *
 *   anonymize_application_row():
 *     - Writes the REDACTED_MARKER that Admission's automatic sweep uses to
 *       recognise an already-redacted row, and keeps id/status so
 *       admission-history and community-cap accounting still add up
 *
 *   erase_governance():
 *     - Redacts only this voucher's own messages, never another voucher's
 *     - Keeps the vote values — a vote is a community record, and silently
 *       changing a settled decision would be worse than retaining it
 *
 *   erase_newsletter_subscription() / erase_newsletter_history():
 *     - Touch only the requester's rows, and rotate the unsubscribe token so
 *       an anonymized delivery record can't be linked back
 *
 *   The shared paging contract: every eraser short-circuits on page > 1.
 *
 * @package Agnosis\Tests\Integration\Core
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Core;

use Agnosis\Core\Privacy;

class PrivacyErasureTest extends \WP_UnitTestCase {

	private Privacy $privacy;

	private const SUBJECT = 'subject@example.com';
	private const OTHER   = 'someone-else@example.com';

	protected function setUp(): void {
		parent::setUp();
		$this->privacy = new Privacy();
	}

	// -------------------------------------------------------------------------
	// Fixtures
	// -------------------------------------------------------------------------

	private function seed_application( string $email, string $status ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		$wpdb->insert(
			$wpdb->prefix . 'agnosis_applications',
			[
				'email'         => $email,
				'display_name'  => 'Camille Claudel',
				'bio'           => 'I sculpt in marble.',
				'portfolio_url' => 'https://example.com/portfolio',
				'statement'     => 'Please let me in.',
				'status'        => $status,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		return (int) $wpdb->insert_id;
	}

	/** @return object{email: string, display_name: string, bio: string, portfolio_url: string, statement: string, status: string} */
	private function application_row( int $id ): object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT email, display_name, bio, portfolio_url, statement, status
			 FROM {$wpdb->prefix}agnosis_applications WHERE id = %d",
			$id
		) );
	}

	// -------------------------------------------------------------------------
	// erase_application() — the status contract
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function active_status_provider(): array {
		return [
			'unverified' => [ 'unverified' ],
			'pending'    => [ 'pending' ],
			'waitlisted' => [ 'waitlisted' ],
			'admitted'   => [ 'admitted' ],
		];
	}

	/**
	 * @dataProvider active_status_provider
	 */
	public function test_erase_application_retains_an_active_application( string $status ): void {
		$id = $this->seed_application( self::SUBJECT, $status );

		$result = $this->privacy->erase_application( self::SUBJECT );

		$this->assertFalse( $result['items_removed'], "A '{$status}' application must not be silently erased." );
		$this->assertTrue( $result['items_retained'] );
		$this->assertNotEmpty( $result['messages'], 'The requester must be told how to actually leave.' );

		$row = $this->application_row( $id );
		$this->assertSame( self::SUBJECT, $row->email, 'Nothing may be redacted while the membership is live.' );
		$this->assertSame( 'I sculpt in marble.', $row->bio );
	}

	/**
	 * A banned row keeps its email on purpose: it is the only thing that lets
	 * the site enforce the ban, and erasing it would let a banned artist
	 * reapply immediately. Everything else about them still goes.
	 */
	public function test_erase_application_keeps_a_banned_rows_email_but_erases_the_rest(): void {
		$id = $this->seed_application( self::SUBJECT, 'banned' );

		$result = $this->privacy->erase_application( self::SUBJECT );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'], 'Keeping the email is a retention and must be reported as one.' );

		$row = $this->application_row( $id );
		$this->assertSame( self::SUBJECT, $row->email, 'Erasing a banned row\'s email would hand it a way around the ban.' );
		$this->assertSame( Privacy::REDACTED_MARKER, $row->display_name );
		$this->assertSame( '', $row->bio );
		$this->assertSame( '', $row->statement );
		$this->assertSame( '', $row->portfolio_url );
	}

	public function test_erase_application_fully_anonymizes_a_resolved_row(): void {
		$id = $this->seed_application( self::SUBJECT, 'rejected' );

		$result = $this->privacy->erase_application( self::SUBJECT );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );

		$row = $this->application_row( $id );
		$this->assertNotSame( self::SUBJECT, $row->email );
		$this->assertStringContainsString( '@erased.invalid', $row->email );
		$this->assertSame( Privacy::REDACTED_MARKER, $row->display_name );
	}

	public function test_erase_application_is_a_clean_no_op_for_an_unknown_address(): void {
		$result = $this->privacy->erase_application( 'nobody@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_erase_application_leaves_another_applicants_row_untouched(): void {
		$mine   = $this->seed_application( self::SUBJECT, 'rejected' );
		$theirs = $this->seed_application( self::OTHER, 'rejected' );

		$this->privacy->erase_application( self::SUBJECT );

		$this->assertSame( self::OTHER, $this->application_row( $theirs )->email );
		$this->assertSame( 'Camille Claudel', $this->application_row( $theirs )->display_name );
		$this->assertNotSame( self::SUBJECT, $this->application_row( $mine )->email );
	}

	/**
	 * The marker is deliberately untranslated: Admission's automatic retention
	 * sweep tests for this exact string to know a row is already redacted. If
	 * anonymize_application_row() stopped writing it, the sweep would reprocess
	 * every redacted row forever.
	 */
	public function test_anonymize_application_row_keeps_the_row_and_its_status(): void {
		$id = $this->seed_application( self::SUBJECT, 'left' );

		Privacy::anonymize_application_row( $id );

		$row = $this->application_row( $id );
		$this->assertNotNull( $row, 'The row itself is kept — admission history and cap accounting depend on it.' );
		$this->assertSame( 'left', $row->status, 'Status must survive redaction.' );
		$this->assertSame( Privacy::REDACTED_MARKER, $row->display_name );
	}

	public function test_anonymize_application_row_can_preserve_the_email(): void {
		$id = $this->seed_application( self::SUBJECT, 'banned' );

		Privacy::anonymize_application_row( $id, false );

		$this->assertSame( self::SUBJECT, $this->application_row( $id )->email );
		$this->assertSame( '', $this->application_row( $id )->bio );
	}

	// -------------------------------------------------------------------------
	// erase_governance() — redact messages, keep votes
	// -------------------------------------------------------------------------

	public function test_erase_governance_redacts_only_this_vouchers_own_messages(): void {
		global $wpdb;

		$mine   = self::factory()->user->create( [ 'user_email' => self::SUBJECT ] );
		$theirs = self::factory()->user->create( [ 'user_email' => self::OTHER ] );
		$this->assertIsInt( $mine );
		$this->assertIsInt( $theirs );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		$wpdb->insert( $wpdb->prefix . 'agnosis_application_vouches', [
			'application_id' => 1, 'voucher_id' => $mine, 'vote' => 'yes', 'message' => 'I know their work.',
		], [ '%d', '%d', '%s', '%s' ] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
		$wpdb->insert( $wpdb->prefix . 'agnosis_application_vouches', [
			'application_id' => 1, 'voucher_id' => $theirs, 'vote' => 'no', 'message' => 'Not convinced.',
		], [ '%d', '%d', '%s', '%s' ] );

		$result = $this->privacy->erase_governance( self::SUBJECT );

		$this->assertTrue( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'], 'Votes are kept, so this is always a partial erasure.' );

		$this->assertNotSame( 'I know their work.', $this->vouch_row( $mine )->message, "The requester's own message must be redacted." );
		$this->assertSame( 'Not convinced.', $this->vouch_row( $theirs )->message, "Another voucher's words are not the requester's to erase." );
		$this->assertSame( 'yes', $this->vouch_row( $mine )->vote, 'A vote is a community record — erasing it would silently rewrite a settled decision.' );
		$this->assertSame( 'no', $this->vouch_row( $theirs )->vote );
	}

	/**
	 * One vouch row, keyed on voucher_id rather than row order — see the note in
	 * the newsletter test about this table not being guaranteed empty.
	 *
	 * A named method rather than a closure so the shape can be declared: a
	 * closure returning bare `object` leaves PHPStan unable to see ->vote or
	 * ->message, which is the same annotation gap `application_row()` above
	 * already solves this way.
	 *
	 * @return object{vote: string, message: string}
	 */
	private function vouch_row( int $voucher_id ): object {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT vote, message FROM {$wpdb->prefix}agnosis_application_vouches WHERE voucher_id = %d",
			$voucher_id
		) );
	}

	public function test_erase_governance_is_a_no_op_when_no_account_matches(): void {
		$result = $this->privacy->erase_governance( 'nobody@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	// -------------------------------------------------------------------------
	// Newsletter
	// -------------------------------------------------------------------------

	public function test_erase_newsletter_subscription_deletes_only_the_requesters_row(): void {
		global $wpdb;

		foreach ( [ self::SUBJECT, self::OTHER ] as $email ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
			$wpdb->insert( $wpdb->prefix . 'agnosis_newsletter_subscribers', [
				'email' => $email, 'status' => 'confirmed', 'token' => wp_generate_password( 20, false ),
			], [ '%s', '%s', '%s' ] );
		}

		$result = $this->privacy->erase_newsletter_subscription( self::SUBJECT );

		$this->assertTrue( $result['items_removed'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		$remaining = $wpdb->get_col( "SELECT email FROM {$wpdb->prefix}agnosis_newsletter_subscribers" );
		$this->assertSame( [ self::OTHER ], $remaining );
	}

	public function test_erase_newsletter_history_anonymizes_only_the_requesters_deliveries_and_rotates_the_token(): void {
		global $wpdb;

		$token = 'original-unsubscribe-token';
		foreach ( [ self::SUBJECT, self::OTHER ] as $email ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test fixture.
			$wpdb->insert( $wpdb->prefix . 'agnosis_newsletter_queue', [
				'issue_id' => 1, 'recipient_email' => $email, 'recipient_type' => 'public',
				'unsubscribe_token' => $token, 'status' => 'sent',
			], [ '%d', '%s', '%s', '%s', '%s' ] );
		}

		$result = $this->privacy->erase_newsletter_history( self::SUBJECT );

		$this->assertTrue( $result['items_removed'] );
		$this->assertNotEmpty( $result['messages'], 'Keeping per-issue counts is a retention worth stating.' );

		// Assert by address, never by row position: this table is not guaranteed
		// empty at the start of a test. ActivatorTest runs dbDelta(), and DDL
		// forces an implicit COMMIT in MySQL, which ends WP_UnitTestCase's
		// isolating transaction and leaves that suite's fixture rows behind for
		// everything that runs after it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		$mine = $wpdb->get_row( $wpdb->prepare(
			"SELECT recipient_email, unsubscribe_token FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE unsubscribe_token != %s AND recipient_email = %s",
			$token,
			'erased@erased.invalid'
		) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		$theirs = $wpdb->get_row( $wpdb->prepare(
			"SELECT recipient_email, unsubscribe_token FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE recipient_email = %s",
			self::OTHER
		) );

		$this->assertNotNull( $mine, "The requester's delivery row must have been anonymized." );
		$this->assertNotSame( $token, $mine->unsubscribe_token, 'A live token would still link the anonymized row back to the person.' );

		$this->assertNotNull( $theirs, "Another recipient's delivery record is not the requester's to erase." );
		$this->assertSame( $token, $theirs->unsubscribe_token, "Another recipient's token must not be rotated." );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test assertion.
		$still_named = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}agnosis_newsletter_queue WHERE recipient_email = %s",
			self::SUBJECT
		) );
		$this->assertSame( 0, $still_named, "No delivery row may still carry the requester's address." );
	}

	// -------------------------------------------------------------------------
	// Shared paging contract
	// -------------------------------------------------------------------------

	/**
	 * Core calls an eraser repeatedly until it reports done. Every eraser here
	 * does all its work on page 1 and must report a clean, empty result
	 * afterwards — a second pass that erased again would be at best wasted work
	 * and at worst a double redaction over someone else's later data.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function eraser_provider(): array {
		return [
			'application'  => [ 'erase_application' ],
			'governance'   => [ 'erase_governance' ],
			'subscription' => [ 'erase_newsletter_subscription' ],
			'history'      => [ 'erase_newsletter_history' ],
			'replies'      => [ 'erase_replies' ],
			'contact'      => [ 'erase_contact_messages' ],
			'preferences'  => [ 'erase_preferences' ],
		];
	}

	/**
	 * @dataProvider eraser_provider
	 */
	public function test_every_eraser_short_circuits_beyond_page_one( string $method ): void {
		$this->seed_application( self::SUBJECT, 'rejected' );

		$result = $this->privacy->{$method}( self::SUBJECT, 2 );

		$this->assertFalse( $result['items_removed'], "{$method}() must do nothing on page 2." );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	public function test_every_group_is_registered_with_both_core_tools(): void {
		$exporters = $this->privacy->register_exporters( [] );
		$erasers   = $this->privacy->register_erasers( [] );

		foreach ( [ 'agnosis-application', 'agnosis-governance', 'agnosis-newsletter-subscription', 'agnosis-newsletter-history', 'agnosis-contact-messages', 'agnosis-preferences', 'agnosis-replies' ] as $group ) {
			$this->assertArrayHasKey( $group, $exporters, "Group '{$group}' is missing from the exporter list." );
			$this->assertArrayHasKey( $group, $erasers, "Group '{$group}' is missing from the eraser list — exportable data that cannot be erased is an Art. 17 gap." );
			$this->assertIsCallable( $exporters[ $group ]['callback'] );
			$this->assertIsCallable( $erasers[ $group ]['callback'] );
		}
	}
}
