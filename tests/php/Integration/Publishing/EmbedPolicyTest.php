<?php
/**
 * Integration tests — Publishing\EmbedPolicy.
 *
 * Covers the three-tier trust model:
 *   - Community trust bypass (opt-in via agnosis_embed_trust_community, off by
 *     default): skips the allowlist AND AI review entirely — any link embeds
 *     immediately, no fetch, no AI call.
 *   - Trusted-host fast path (settings-driven via agnosis_embed_trusted_hosts,
 *     replacing the old hardcoded PostCreator::ALLOWED_EMBED_HOSTS constant) —
 *     exact/subdomain matching, still filterable via agnosis_embed_host_allowlist,
 *     never touches the network or calls the AI.
 *   - AI review escalation for non-trusted hosts (opt-in via
 *     agnosis_embed_ai_vetting_enabled, off by default): fetch the destination
 *     page (mocked via pre_http_request — no real network access), classify via
 *     Pipeline::classify_link() (mocked via a Pipeline stub), fail closed on any
 *     error (feature disabled, fetch failure, empty/unparseable AI response) —
 *     the one exception is no disallowed categories configured, which approves,
 *     since there's nothing to check the link against.
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Publishing\EmbedPolicy;

class EmbedPolicyTest extends \WP_UnitTestCase {

	/** The pre_http_request filter closure registered for the current test, if any. */
	private ?\Closure $http_filter = null;

	protected function tearDown(): void {
		$this->remove_http_mock();
		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Short-circuit wp_safe_remote_get() with a canned successful HTML response — no real network access. */
	private function mock_http_success( string $html = '<html><head><title>Some Page</title></head><body>Body text.</body></html>' ): void {
		$this->http_filter = function () use ( $html ) {
			return [
				'headers'  => [],
				'body'     => $html,
				'response' => [ 'code' => 200, 'message' => 'OK' ],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	/** Short-circuit wp_safe_remote_get() with a fetch failure (WP_Error). */
	private function mock_http_failure(): void {
		$this->http_filter = function () {
			return new \WP_Error( 'http_request_failed', 'Could not resolve host' );
		};
		add_filter( 'pre_http_request', $this->http_filter, 10, 3 );
	}

	private function remove_http_mock(): void {
		if ( $this->http_filter ) {
			remove_filter( 'pre_http_request', $this->http_filter, 10 );
			$this->http_filter = null;
		}
	}

	/** Pipeline stub whose classify_link() always returns a fixed value — no provider resolution, no real AI call. */
	private function stub_pipeline( ?bool $verdict ): Pipeline {
		return new class( $verdict ) extends Pipeline {
			private ?bool $fixed_verdict;
			public function __construct( ?bool $verdict ) {
				$this->fixed_verdict = $verdict;
			}
			public function classify_link( string $title, string $description, string $snippet, array $disallowed_categories ): ?bool {
				return $this->fixed_verdict;
			}
		};
	}

	// -------------------------------------------------------------------------
	// Community trust bypass — skips the allowlist AND AI review entirely
	// -------------------------------------------------------------------------

	public function test_community_trust_bypasses_allowlist_and_ai_with_no_network(): void {
		update_option( 'agnosis_embed_trust_community', 1 );

		// A stub that would reject, and no HTTP mock installed at all — reaching
		// TRUE here proves the bypass short-circuits before any fetch or AI call.
		$policy = new EmbedPolicy( $this->stub_pipeline( false ) );

		$this->assertTrue( $policy->is_allowed( 'https://a-random-personal-site.example/gallery' ) );
	}

	public function test_community_trust_off_by_default(): void {
		// No option set — a non-trusted host with AI vetting also untouched
		// (disabled by default) must still be rejected.
		$policy = new EmbedPolicy( $this->stub_pipeline( true ) );

		$this->assertFalse( $policy->is_allowed( 'https://a-random-personal-site.example/gallery' ) );
	}

	public function test_community_trust_still_requires_a_parseable_host(): void {
		update_option( 'agnosis_embed_trust_community', 1 );

		$policy = new EmbedPolicy( $this->stub_pipeline( null ) );

		$this->assertFalse( $policy->is_allowed( 'not-a-url' ) );
	}

	// -------------------------------------------------------------------------
	// Trusted-host fast path
	// -------------------------------------------------------------------------

	public function test_trusted_host_is_allowed_with_no_network_or_ai(): void {
		// No HTTP mock installed at all — reaching TRUE here proves the fast
		// path never attempts a fetch. If it did, this would hit the real
		// network (and likely fail/hang in a sandboxed test runner).
		$policy = new EmbedPolicy( $this->stub_pipeline( null ) );
		$this->assertTrue( $policy->is_allowed( 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' ) );
	}

	public function test_bandcamp_subdomain_is_trusted(): void {
		$policy = new EmbedPolicy( $this->stub_pipeline( null ) );
		$this->assertTrue( $policy->is_allowed( 'https://aphextwin.bandcamp.com/album/x' ) );
	}

	public function test_trusted_hosts_are_configurable_via_option(): void {
		update_option( 'agnosis_embed_trusted_hosts', "example-trusted.test\n" );

		$this->assertTrue( EmbedPolicy::is_trusted_host( 'example-trusted.test' ) );
		$this->assertFalse(
			EmbedPolicy::is_trusted_host( 'youtube.com' ),
			'Overwriting the option replaces the default curated list entirely.'
		);
	}

	public function test_trusted_hosts_extensible_via_filter(): void {
		$filter = fn( array $hosts ) => array_merge( $hosts, [ 'peertube.example.org' ] );
		add_filter( 'agnosis_embed_host_allowlist', $filter );

		$this->assertTrue( EmbedPolicy::is_trusted_host( 'peertube.example.org' ) );

		remove_filter( 'agnosis_embed_host_allowlist', $filter );

		// And the filter's effect doesn't leak once removed.
		$this->assertFalse( EmbedPolicy::is_trusted_host( 'peertube.example.org' ) );
	}

	public function test_lookalike_host_is_not_trusted_by_substring(): void {
		$this->assertFalse( EmbedPolicy::is_trusted_host( 'notyoutube.com' ) );
		$this->assertFalse( EmbedPolicy::is_trusted_host( 'youtube.com.evil.example' ) );
	}

	// -------------------------------------------------------------------------
	// AI vetting disabled (the default) — non-trusted hosts are rejected outright
	// -------------------------------------------------------------------------

	public function test_non_trusted_host_rejected_when_ai_vetting_disabled(): void {
		update_option( 'agnosis_embed_ai_vetting_enabled', 0 );

		// Even a stub that would approve must not matter — AI review must never
		// run at all while the feature is off, so no network access either.
		$policy = new EmbedPolicy( $this->stub_pipeline( true ) );

		$this->assertFalse( $policy->is_allowed( 'https://www.some-random-site.example/page' ) );
	}

	// -------------------------------------------------------------------------
	// AI vetting enabled — fetch + classify
	// -------------------------------------------------------------------------

	public function test_non_trusted_host_allowed_when_ai_approves(): void {
		update_option( 'agnosis_embed_ai_vetting_enabled', 1 );
		update_option( 'agnosis_embed_block_adult', 1 );

		$this->mock_http_success();
		$policy = new EmbedPolicy( $this->stub_pipeline( true ) );

		$this->assertTrue( $policy->is_allowed( 'https://a-random-personal-site.example/gallery' ) );
	}

	public function test_non_trusted_host_blocked_when_ai_rejects(): void {
		update_option( 'agnosis_embed_ai_vetting_enabled', 1 );
		update_option( 'agnosis_embed_block_adult', 1 );

		$this->mock_http_success();
		$policy = new EmbedPolicy( $this->stub_pipeline( false ) );

		$this->assertFalse( $policy->is_allowed( 'https://a-random-personal-site.example/gallery' ) );
	}

	public function test_fails_closed_when_fetch_fails(): void {
		update_option( 'agnosis_embed_ai_vetting_enabled', 1 );
		update_option( 'agnosis_embed_block_adult', 1 );

		$this->mock_http_failure();
		// Even a stub that would approve must not matter — the fetch failed first.
		$policy = new EmbedPolicy( $this->stub_pipeline( true ) );

		$this->assertFalse( $policy->is_allowed( 'https://unreachable-site.example/page' ) );
	}

	public function test_fails_closed_when_ai_response_is_inconclusive(): void {
		update_option( 'agnosis_embed_ai_vetting_enabled', 1 );
		update_option( 'agnosis_embed_block_adult', 1 );

		$this->mock_http_success();
		$policy = new EmbedPolicy( $this->stub_pipeline( null ) ); // Unparseable/empty AI response.

		$this->assertFalse( $policy->is_allowed( 'https://a-random-personal-site.example/gallery' ) );
	}

	public function test_approves_when_ai_vetting_enabled_but_no_categories_configured(): void {
		update_option( 'agnosis_embed_ai_vetting_enabled', 1 );
		foreach ( [ 'agnosis_embed_block_adult', 'agnosis_embed_block_commercial', 'agnosis_embed_block_gambling' ] as $opt ) {
			update_option( $opt, 0 );
		}
		update_option( 'agnosis_embed_block_custom', '' );

		// No HTTP mock installed — reaching TRUE here proves the "nothing
		// configured to block" branch short-circuits before any fetch happens.
		$policy = new EmbedPolicy( $this->stub_pipeline( null ) );

		$this->assertTrue( $policy->is_allowed( 'https://a-random-personal-site.example/gallery' ) );
	}

	public function test_rejects_url_with_no_host(): void {
		$policy = new EmbedPolicy( $this->stub_pipeline( null ) );
		$this->assertFalse( $policy->is_allowed( 'not-a-url' ) );
	}

	// -------------------------------------------------------------------------
	// Disallowed-category configuration reaches the classifier
	// -------------------------------------------------------------------------

	public function test_custom_category_text_is_passed_to_classifier(): void {
		update_option( 'agnosis_embed_ai_vetting_enabled', 1 );
		update_option( 'agnosis_embed_block_adult', 0 );
		update_option( 'agnosis_embed_block_commercial', 0 );
		update_option( 'agnosis_embed_block_gambling', 0 );
		update_option( 'agnosis_embed_block_custom', 'Sites promoting violent extremism' );

		$this->mock_http_success();

		$pipeline = new class() extends Pipeline {
			/** @var string[]|null */
			public ?array $captured = null;
			public function __construct() {}
			public function classify_link( string $title, string $description, string $snippet, array $disallowed_categories ): ?bool {
				$this->captured = $disallowed_categories;
				return true;
			}
		};

		( new EmbedPolicy( $pipeline ) )->is_allowed( 'https://a-random-personal-site.example/gallery' );

		$this->assertNotNull( $pipeline->captured );
		$this->assertContains( 'Sites promoting violent extremism', $pipeline->captured );
		$this->assertCount( 1, $pipeline->captured, 'Only the one enabled category (custom text) should have been sent.' );
	}

	public function test_extracted_title_is_passed_to_classifier(): void {
		update_option( 'agnosis_embed_ai_vetting_enabled', 1 );
		update_option( 'agnosis_embed_block_adult', 1 );

		$this->mock_http_success( '<html><head><title>Independent Artist Gallery</title></head><body>Fine art prints.</body></html>' );

		$pipeline = new class() extends Pipeline {
			public ?string $captured_title = null;
			public function __construct() {}
			public function classify_link( string $title, string $description, string $snippet, array $disallowed_categories ): ?bool {
				$this->captured_title = $title;
				return true;
			}
		};

		( new EmbedPolicy( $pipeline ) )->is_allowed( 'https://a-random-personal-site.example/gallery' );

		$this->assertSame( 'Independent Artist Gallery', $pipeline->captured_title );
	}
}
