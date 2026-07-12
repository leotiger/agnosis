<?php
/**
 * Integration tests for AI\CallCounter (seventh audit G-2).
 *
 * Covered indirectly, end to end, via ReviewEndpointsNativeLanguagePipelineTest
 * (the native->primary call site) and LinguaForgeCompatTest (the LF fan-out
 * call site, including the synthetic-native-sibling-firing exclusion). This
 * file pins the class's own contract directly: what get_total() returns
 * before/after record(), what postmeta key it uses, and that a log entry is
 * actually written — independent of either caller.
 *
 * @package Agnosis\Tests\Integration\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\AI;

use Agnosis\AI\CallCounter;
use Agnosis\Core\Logger;

class CallCounterTest extends \WP_UnitTestCase {

	private int $post_id;

	protected function setUp(): void {
		parent::setUp();
		$this->post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );
	}

	public function test_get_total_is_zero_for_a_post_with_no_recorded_calls(): void {
		$this->assertSame( 0, CallCounter::get_total( $this->post_id ) );
	}

	public function test_record_increments_the_total(): void {
		CallCounter::record( $this->post_id, 'native_to_primary' );

		$this->assertSame( 1, CallCounter::get_total( $this->post_id ) );
	}

	public function test_record_accumulates_across_multiple_calls_and_labels(): void {
		CallCounter::record( $this->post_id, 'native_to_primary' );
		CallCounter::record( $this->post_id, 'lf_fanout' );
		CallCounter::record( $this->post_id, 'lf_fanout' );

		$this->assertSame( 3, CallCounter::get_total( $this->post_id ) );
	}

	public function test_record_persists_as_post_meta(): void {
		CallCounter::record( $this->post_id, 'native_to_primary' );

		$this->assertSame( '1', get_post_meta( $this->post_id, '_agnosis_ai_translation_calls', true ) );
	}

	public function test_record_is_scoped_per_post(): void {
		$other_post_id = self::factory()->post->create( [ 'post_type' => 'agnosis_artwork', 'post_status' => 'publish' ] );

		CallCounter::record( $this->post_id, 'native_to_primary' );

		$this->assertSame( 1, CallCounter::get_total( $this->post_id ) );
		$this->assertSame( 0, CallCounter::get_total( $other_post_id ) );
	}

	public function test_record_ignores_a_non_positive_post_id(): void {
		CallCounter::record( 0, 'native_to_primary' );
		CallCounter::record( -5, 'native_to_primary' );

		$this->assertSame( 0, CallCounter::get_total( 0 ) );
	}

	public function test_record_writes_a_log_entry(): void {
		Logger::clear();

		CallCounter::record( $this->post_id, 'native_to_primary' );

		$entries = Logger::get_entries( 10 );
		$match   = array_filter( $entries, static fn( array $e ) => 'ai-calls' === $e['context'] );

		$this->assertNotEmpty( $match, 'record() must write a log entry under the ai-calls context so operators can find calls-per-submission activity in Settings -> Logs.' );
		$entry = array_values( $match )[0];
		$this->assertStringContainsString( 'native_to_primary', $entry['message'] );
		$this->assertStringContainsString( (string) $this->post_id, $entry['message'] );
	}
}
