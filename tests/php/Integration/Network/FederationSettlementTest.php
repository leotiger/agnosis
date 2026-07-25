<?php
/**
 * Integration tests for Network\FederationSettlement — TAG-REDESIGN.md F3
 * (§6c), the tag-settled federation trigger.
 *
 * Covers the class's own methods directly (settlement check, the primary +
 * existing-sibling federate() sweep, the late-arriving-sibling
 * linguaforge_translation_complete listener, idempotency, the timeout
 * cron), plus the real hook wiring end to end: Publishing\TagGate::
 * clear_proposal() and Admin\MediumProposals' three resolve call sites each
 * fire a `*_proposal_resolved` action that Core\Plugin wires directly to
 * FederationSettlement::maybe_settle() — since integration tests run the
 * real plugin bootstrap (the same reason ActivityPubTest's wp_trash_post()
 * calls trigger a real federated Delete), those actions are genuinely live
 * here too, not something this file has to re-wire itself.
 *
 * @package Agnosis\Tests\Integration\Network
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Network;

use Agnosis\Admin\MediumProposals;
use Agnosis\Admin\TagProposals;
use Agnosis\Artist\Profile;
use Agnosis\Network\FederationSettlement;
use Agnosis\Publishing\ReviewEndpoints;
use Agnosis\Publishing\TagGate;
use Agnosis\Tests\Integration\Support\FakeLinguaForge;

require_once __DIR__ . '/../Compat/Stubs/lf_global_stubs.php';

class FederationSettlementTest extends \WP_UnitTestCase {

	private int $artist_id;
	private FederationSettlement $settlement;

	protected function setUp(): void {
		parent::setUp();

		if ( ! taxonomy_exists( 'agnosis_medium' ) ) {
			( new Profile() )->register_taxonomy();
		}

		FakeLinguaForge::reset();
		$this->artist_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->settlement = new FederationSettlement();
	}

	protected function tearDown(): void {
		foreach ( get_terms( [ 'taxonomy' => 'post_tag', 'hide_empty' => false ] ) as $term ) {
			wp_delete_term( $term->term_id, 'post_tag' );
		}
		if ( taxonomy_exists( 'agnosis_medium' ) ) {
			foreach ( get_terms( [ 'taxonomy' => 'agnosis_medium', 'hide_empty' => false ] ) as $term ) {
				wp_delete_term( $term->term_id, 'agnosis_medium' );
			}
		}
		delete_option( 'agnosis_federation_tag_wait' );
		delete_option( 'agnosis_federate_languages' );
		FakeLinguaForge::reset();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_artwork( string $status = 'publish', string $post_type = 'agnosis_artwork' ): int {
		return (int) self::factory()->post->create( [
			'post_type'   => $post_type,
			'post_status' => $status,
			'post_author' => $this->artist_id,
			'post_title'  => 'Test Artwork',
		] );
	}

	// -------------------------------------------------------------------------
	// is_tag_settled()
	// -------------------------------------------------------------------------

	public function test_is_tag_settled_true_with_no_pending_proposals(): void {
		$post_id = $this->make_artwork();
		$this->assertTrue( FederationSettlement::is_tag_settled( $post_id ) );
	}

	public function test_is_tag_settled_false_with_pending_tag_proposal(): void {
		$post_id = $this->make_artwork();
		TagGate::replace_proposals( $post_id, [ 'Impressionism' ] );

		$this->assertFalse( FederationSettlement::is_tag_settled( $post_id ) );
	}

	public function test_is_tag_settled_false_with_pending_medium_proposal(): void {
		$post_id = $this->make_artwork();
		update_post_meta( $post_id, '_agnosis_medium_proposal', 'Linocut' );

		$this->assertFalse( FederationSettlement::is_tag_settled( $post_id ) );
	}

	// -------------------------------------------------------------------------
	// maybe_settle()
	// -------------------------------------------------------------------------

	public function test_maybe_settle_federates_immediately_when_already_settled(): void {
		$post_id = $this->make_artwork();

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$this->settlement->maybe_settle( $post_id );

		$this->assertSame( FederationSettlement::STATE_FEDERATED, get_post_meta( $post_id, FederationSettlement::STATE_META, true ) );
		$this->assertSame( [ $post_id ], $fired, 'A post with nothing pending must federate at once, on its first maybe_settle() call.' );
	}

	public function test_maybe_settle_marks_pending_when_tag_proposal_exists(): void {
		$post_id = $this->make_artwork();
		TagGate::replace_proposals( $post_id, [ 'Impressionism' ] );

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$this->settlement->maybe_settle( $post_id );

		$this->assertSame( FederationSettlement::STATE_PENDING, get_post_meta( $post_id, FederationSettlement::STATE_META, true ) );
		$this->assertNotSame( '', get_post_meta( $post_id, FederationSettlement::PENDING_SINCE_META, true ), 'A pending post must record when it started waiting, for the timeout sweep.' );
		$this->assertSame( [], $fired, 'A post with an unresolved proposal must not federate yet.' );
	}

	public function test_maybe_settle_is_idempotent_once_federated(): void {
		$post_id = $this->make_artwork();
		$this->settlement->maybe_settle( $post_id );

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$this->settlement->maybe_settle( $post_id ); // Second call — already federated.

		$this->assertSame( [], $fired, 'Once federated, a repeat call must be a pure no-op — never a second Create.' );
	}

	public function test_maybe_settle_ignores_non_artwork_post_types(): void {
		$post_id = $this->make_artwork( 'publish', 'agnosis_biography' );

		$this->settlement->maybe_settle( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, FederationSettlement::STATE_META, true ), 'Only agnosis_artwork federates — a biography must never get federation state written at all.' );
	}

	public function test_maybe_settle_ignores_unpublished_post(): void {
		$post_id = $this->make_artwork( 'draft' );

		$this->settlement->maybe_settle( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, FederationSettlement::STATE_META, true ) );
	}

	// -------------------------------------------------------------------------
	// federate() — sweep of already-existing ready siblings (via maybe_settle()).
	// -------------------------------------------------------------------------

	public function test_settling_sweeps_existing_published_siblings_but_not_drafts(): void {
		update_option( 'agnosis_federate_languages', 'all' ); // F4: siblings are gated out by default — see that section below.

		$primary_id            = $this->make_artwork();
		$published_sibling_id = $this->make_artwork();
		$draft_sibling_id      = $this->make_artwork( 'draft' );

		FakeLinguaForge::link( $primary_id, 'de', $published_sibling_id );
		FakeLinguaForge::link( $primary_id, 'fr', $draft_sibling_id );

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$this->settlement->maybe_settle( $primary_id );

		$this->assertContains( $primary_id, $fired );
		$this->assertContains( $published_sibling_id, $fired, 'An already-published sibling must federate its own Create the moment the primary settles.' );
		$this->assertNotContains( $draft_sibling_id, $fired, 'A draft sibling is not ready yet — it federates later via on_translation_complete() once it publishes/translates.' );
		$this->assertCount( 2, $fired );
	}

	// -------------------------------------------------------------------------
	// on_translation_complete() — late-arriving sibling.
	// -------------------------------------------------------------------------

	public function test_on_translation_complete_federates_late_sibling_when_primary_already_settled(): void {
		update_option( 'agnosis_federate_languages', 'all' ); // F4: siblings are gated out by default — see that section below.

		$primary_id = $this->make_artwork();
		$this->settlement->maybe_settle( $primary_id ); // Settles with zero siblings yet.

		$late_sibling_id = $this->make_artwork();

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$this->settlement->on_translation_complete( $late_sibling_id, $primary_id, 'de' );

		$this->assertSame( [ $late_sibling_id ], $fired );
	}

	public function test_on_translation_complete_noop_when_primary_not_yet_settled(): void {
		$primary_id = $this->make_artwork();
		TagGate::replace_proposals( $primary_id, [ 'Impressionism' ] );
		$this->settlement->maybe_settle( $primary_id ); // Stays pending — new tag proposed.

		$sibling_id = $this->make_artwork();

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$this->settlement->on_translation_complete( $sibling_id, $primary_id, 'de' );

		$this->assertSame( [], $fired, 'A sibling arriving before its own primary has settled must wait — federate() sweeps it once settlement actually happens.' );
	}

	public function test_on_translation_complete_does_not_redeliver_already_delivered_sibling(): void {
		update_option( 'agnosis_federate_languages', 'all' ); // F4: siblings are gated out by default — see that section below.

		$primary_id  = $this->make_artwork();
		$sibling_id  = $this->make_artwork();
		FakeLinguaForge::link( $primary_id, 'de', $sibling_id );

		$this->settlement->maybe_settle( $primary_id ); // Sweeps and delivers the sibling once already.

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$this->settlement->on_translation_complete( $sibling_id, $primary_id, 'de' ); // Re-fired, e.g. a retranslation.

		$this->assertSame( [], $fired, 'DELIVERED_META must make a repeat linguaforge_translation_complete firing a no-op — never a duplicate Create.' );
	}

	// -------------------------------------------------------------------------
	// F4 (§6b) — agnosis_federate_languages rollout valve.
	// -------------------------------------------------------------------------

	public function test_default_setting_federates_primary_but_not_siblings(): void {
		// No update_option() call — exercising the real default.
		$primary_id = $this->make_artwork();
		$sibling_id = $this->make_artwork();
		FakeLinguaForge::link( $primary_id, 'de', $sibling_id );

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$this->settlement->maybe_settle( $primary_id );

		$this->assertSame( [ $primary_id ], $fired, 'Default agnosis_federate_languages is primary-only — a sibling must never federate until the operator opts in.' );
	}

	public function test_explicit_primary_only_setting_matches_the_default(): void {
		update_option( 'agnosis_federate_languages', 'primary-only' );

		$primary_id = $this->make_artwork();
		$sibling_id = $this->make_artwork();
		FakeLinguaForge::link( $primary_id, 'de', $sibling_id );

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$this->settlement->maybe_settle( $primary_id );

		$this->assertSame( [ $primary_id ], $fired );
	}

	public function test_all_setting_federates_the_sibling_too(): void {
		update_option( 'agnosis_federate_languages', 'all' );

		$primary_id = $this->make_artwork();
		$sibling_id = $this->make_artwork();
		FakeLinguaForge::link( $primary_id, 'de', $sibling_id );

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$this->settlement->maybe_settle( $primary_id );

		$this->assertContains( $primary_id, $fired );
		$this->assertContains( $sibling_id, $fired );
	}

	public function test_on_translation_complete_also_honors_primary_only(): void {
		$primary_id = $this->make_artwork();
		$this->settlement->maybe_settle( $primary_id ); // Settles under the default primary-only.

		$late_sibling_id = $this->make_artwork();

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$this->settlement->on_translation_complete( $late_sibling_id, $primary_id, 'de' );

		$this->assertSame( [], $fired, 'A newly-arriving sibling still must not federate under primary-only, regardless of how it arrived.' );
	}

	public function test_flipping_to_all_does_not_retroactively_deliver_a_pre_existing_skipped_sibling(): void {
		// The artwork settles under primary-only — its sibling is gated out
		// and, per deliver_if_new()'s own design, never recorded in
		// DELIVERED_META (nothing to "undo" later).
		$primary_id = $this->make_artwork();
		$sibling_id = $this->make_artwork();
		FakeLinguaForge::link( $primary_id, 'de', $sibling_id );
		$this->settlement->maybe_settle( $primary_id );

		// Operator flips the switch afterward.
		update_option( 'agnosis_federate_languages', 'all' );

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		// Nothing re-sweeps this already-federated primary — maybe_settle()
		// is a pure no-op once STATE_META is already 'federated', and
		// nothing else in this class proactively rescans existing posts
		// when the option changes.
		$this->settlement->maybe_settle( $primary_id );

		$this->assertSame( [], $fired, 'Flipping the setting must never retroactively mass-deliver a backlog of siblings skipped while primary-only was active.' );
	}

	public function test_flipping_to_all_does_federate_a_genuinely_new_sibling_afterward(): void {
		// Same starting point as the backlog test above — settled under
		// primary-only, one sibling already skipped.
		$primary_id = $this->make_artwork();
		$old_sibling_id = $this->make_artwork();
		FakeLinguaForge::link( $primary_id, 'de', $old_sibling_id );
		$this->settlement->maybe_settle( $primary_id );

		update_option( 'agnosis_federate_languages', 'all' );

		// A genuinely NEW language sibling is created/translated afterward —
		// not backlog, forward-going content — and arrives via the ordinary
		// linguaforge_translation_complete path.
		$new_sibling_id = $this->make_artwork();

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$this->settlement->on_translation_complete( $new_sibling_id, $primary_id, 'fr' );

		$this->assertSame( [ $new_sibling_id ], $fired, 'A sibling created after the flip is new content, not backlog — it federates under the now-current setting.' );
	}

	// -------------------------------------------------------------------------
	// sweep_timed_out() — safety valve.
	// -------------------------------------------------------------------------

	public function test_sweep_timed_out_force_settles_past_the_wait_hours(): void {
		update_option( 'agnosis_federation_tag_wait', 24 );

		$post_id = $this->make_artwork();
		TagGate::replace_proposals( $post_id, [ 'Impressionism' ] );
		$this->settlement->maybe_settle( $post_id ); // pending-tags.
		update_post_meta( $post_id, FederationSettlement::PENDING_SINCE_META, time() - ( 25 * HOUR_IN_SECONDS ) );

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$this->settlement->sweep_timed_out();

		$this->assertSame( [ $post_id ], $fired );
		$this->assertSame( FederationSettlement::STATE_FEDERATED, get_post_meta( $post_id, FederationSettlement::STATE_META, true ) );
	}

	public function test_sweep_timed_out_leaves_recent_pending_untouched(): void {
		update_option( 'agnosis_federation_tag_wait', 24 );

		$post_id = $this->make_artwork();
		TagGate::replace_proposals( $post_id, [ 'Impressionism' ] );
		$this->settlement->maybe_settle( $post_id );
		update_post_meta( $post_id, FederationSettlement::PENDING_SINCE_META, time() - ( 1 * HOUR_IN_SECONDS ) );

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$this->settlement->sweep_timed_out();

		$this->assertSame( [], $fired );
		$this->assertSame( FederationSettlement::STATE_PENDING, get_post_meta( $post_id, FederationSettlement::STATE_META, true ) );
	}

	public function test_sweep_timed_out_respects_custom_setting(): void {
		update_option( 'agnosis_federation_tag_wait', 2 ); // 2-hour wait, not the default 24.

		$post_id = $this->make_artwork();
		TagGate::replace_proposals( $post_id, [ 'Impressionism' ] );
		$this->settlement->maybe_settle( $post_id );
		update_post_meta( $post_id, FederationSettlement::PENDING_SINCE_META, time() - ( 3 * HOUR_IN_SECONDS ) );

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$this->settlement->sweep_timed_out();

		$this->assertSame( [ $post_id ], $fired, 'A shorter configured wait must be honored, not the 24h default.' );
	}

	// -------------------------------------------------------------------------
	// Real hook wiring — resolve actions actually reach maybe_settle().
	// -------------------------------------------------------------------------

	public function test_resolving_the_last_tag_proposal_settles_via_the_real_wiring(): void {
		$post_id = $this->make_artwork();
		TagGate::replace_proposals( $post_id, [ 'Impressionism' ] );
		$this->settlement->maybe_settle( $post_id ); // pending-tags.

		TagGate::clear_proposal( $post_id, 'Impressionism' ); // Real resolve — approve/reject/sweep all funnel through this.

		$this->assertSame(
			FederationSettlement::STATE_FEDERATED,
			get_post_meta( $post_id, FederationSettlement::STATE_META, true ),
			'Core\\Plugin wires agnosis_tag_proposal_resolved directly to FederationSettlement::maybe_settle() — resolving the only pending proposal must settle the post through that real hook, not just when called directly.'
		);
	}

	public function test_resolving_a_medium_proposal_settles_via_the_real_wiring(): void {
		$post_id    = $this->make_artwork();
		$controller = new MediumProposals();
		update_post_meta( $post_id, '_agnosis_medium_proposal', 'Linocut' );
		update_post_meta( $post_id, MediumProposals::CREATED_META_KEY, time() );
		$this->settlement->maybe_settle( $post_id ); // pending-tags (medium proposal pending).

		$ref = new \ReflectionMethod( MediumProposals::class, 'reject_proposal' );
		$ref->setAccessible( true );
		$ref->invoke( $controller, 'Linocut' );

		$this->assertSame(
			FederationSettlement::STATE_FEDERATED,
			get_post_meta( $post_id, FederationSettlement::STATE_META, true )
		);
	}

	// -------------------------------------------------------------------------
	// End to end — a real approval, per TAG-REDESIGN.md's own §6c acceptance
	// criteria: "an all-matched approval federates immediately; a new-tag
	// approval federates only after the proposal resolves."
	// -------------------------------------------------------------------------

	public function test_approval_with_all_matched_tags_federates_immediately(): void {
		wp_insert_term( 'Sunset', 'post_tag' );

		$post_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'draft',
			'post_author' => $this->artist_id,
			'post_title'  => 'Test Artwork',
		] );
		update_post_meta( $post_id, '_agnosis_tag_candidates', wp_json_encode( [ 'Sunset' ] ) );
		update_post_meta( $post_id, '_agnosis_review_token', 'test-token' );
		update_post_meta( $post_id, '_agnosis_review_expiry', time() + DAY_IN_SECONDS );

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$req = new \WP_REST_Request( 'POST', '/agnosis/v1/review/' . $post_id . '/approve' );
		$req->set_param( 'id', $post_id );
		$req->set_param( 'token', 'test-token' );
		( new ReviewEndpoints() )->approve( $req );

		$this->assertSame( [ $post_id ], $fired, 'Every candidate already matched the vocabulary — nothing to wait for, so approval must federate at once.' );
		$this->assertSame( FederationSettlement::STATE_FEDERATED, get_post_meta( $post_id, FederationSettlement::STATE_META, true ) );
	}

	public function test_approval_with_a_new_tag_defers_federation_until_resolved(): void {
		$post_id = (int) wp_insert_post( [
			'post_type'   => 'agnosis_artwork',
			'post_status' => 'draft',
			'post_author' => $this->artist_id,
			'post_title'  => 'Test Artwork',
		] );
		update_post_meta( $post_id, '_agnosis_tag_candidates', wp_json_encode( [ 'Brand New Tag' ] ) );
		update_post_meta( $post_id, '_agnosis_review_token', 'test-token' );
		update_post_meta( $post_id, '_agnosis_review_expiry', time() + DAY_IN_SECONDS );

		$fired = [];
		add_action(
			'agnosis_federation_settled',
			function ( int $id ) use ( &$fired ): void {
				$fired[] = $id;
			}
		);

		$req = new \WP_REST_Request( 'POST', '/agnosis/v1/review/' . $post_id . '/approve' );
		$req->set_param( 'id', $post_id );
		$req->set_param( 'token', 'test-token' );
		( new ReviewEndpoints() )->approve( $req );

		$this->assertSame( [], $fired, 'A genuinely new tag proposal must gate federation — the Note would otherwise arrive with no hashtag for it.' );
		$this->assertSame( FederationSettlement::STATE_PENDING, get_post_meta( $post_id, FederationSettlement::STATE_META, true ) );

		// Approving the proposal resolves it — settlement follows via the real wiring.
		$tag_controller = new TagProposals();
		$ref            = new \ReflectionMethod( TagProposals::class, 'approve_proposal' );
		$ref->setAccessible( true );
		$ref->invoke( $tag_controller, 'Brand New Tag' );

		$this->assertSame( [ $post_id ], $fired, 'Once the last pending proposal resolves, the post must federate — riding the same real hook chain a fresh approval uses.' );
	}
}
