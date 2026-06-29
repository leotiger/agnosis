<?php
/**
 * Unit tests for Pipeline::process() $skip_enhancement flag.
 *
 * When $skip_enhancement = true the enhancement provider must never be called,
 * the returned enhanced_data must equal the original image binary, and
 * $result['enhanced'] must be false.
 *
 * @package Agnosis\Tests\Unit\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\AI;

use Agnosis\AI\DescriptionResult;
use Agnosis\AI\EnhancementResult;
use Agnosis\AI\Pipeline;
use Agnosis\AI\ProviderInterface;
use PHPUnit\Framework\TestCase;

class PipelineSkipEnhancementTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_pipeline(
		ProviderInterface $description_provider,
		?ProviderInterface $enhancement_provider = null
	): Pipeline {
		$pipeline = new Pipeline();

		$ref_desc = new \ReflectionProperty( Pipeline::class, 'description_provider' );
		$ref_desc->setAccessible( true );
		$ref_desc->setValue( $pipeline, $description_provider );

		$ref_enh = new \ReflectionProperty( Pipeline::class, 'enhancement_provider' );
		$ref_enh->setAccessible( true );
		$ref_enh->setValue( $pipeline, $enhancement_provider );

		return $pipeline;
	}

	private function make_description_provider( int $quality_score = 4 ): ProviderInterface {
		$provider = $this->createMock( ProviderInterface::class );
		$result   = new DescriptionResult(
			title:                'Grain Study #1',
			excerpt:              'A deliberate study in grain.',
			body:                 '<p>A deliberate study in grain.</p>',
			tags:                 [ 'grain', 'photography' ],
			alt_text:             'A grainy photograph.',
			success:              true,
			photo_quality_score:  $quality_score,
			photo_quality_issues: [ 'high grain', 'underexposed' ],
		);

		$provider->method( 'describe' )->willReturn( $result );
		return $provider;
	}

	private function make_submission(): array {
		return [
			'attachments' => [
				[
					'data'     => 'original-binary-data',
					'mime'     => 'image/jpeg',
					'filename' => 'grain-study.jpg',
				],
			],
		];
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_skip_enhancement_prevents_enhancement_provider_call(): void {
		$enhancement = $this->createMock( ProviderInterface::class );
		$enhancement->expects( $this->never() )->method( 'enhance' );

		$pipeline = $this->make_pipeline( $this->make_description_provider( 4 ), $enhancement );

		$pipeline->process( $this->make_submission(), true );
	}

	public function test_skip_enhancement_returns_original_data_as_enhanced_data(): void {
		$enhancement = $this->createMock( ProviderInterface::class );
		$enhancement->expects( $this->never() )->method( 'enhance' );

		$pipeline = $this->make_pipeline( $this->make_description_provider( 4 ), $enhancement );
		$results  = $pipeline->process( $this->make_submission(), true );

		$this->assertCount( 1, $results );
		$this->assertSame( 'original-binary-data', $results[0]['enhanced_data'] );
		$this->assertSame( 'original-binary-data', $results[0]['original_data'] );
	}

	public function test_skip_enhancement_sets_enhanced_flag_false(): void {
		$enhancement = $this->createMock( ProviderInterface::class );
		$pipeline    = $this->make_pipeline( $this->make_description_provider( 2 ), $enhancement );

		$results = $pipeline->process( $this->make_submission(), true );

		$this->assertFalse( $results[0]['enhanced'] );
	}

	public function test_skip_enhancement_still_runs_description(): void {
		$pipeline = $this->make_pipeline( $this->make_description_provider( 4 ), null );
		$results  = $pipeline->process( $this->make_submission(), true );

		$this->assertSame( 'Grain Study #1', $results[0]['title'] );
		$this->assertSame( 'A deliberate study in grain.', $results[0]['excerpt'] );
		$this->assertContains( 'grain', $results[0]['tags'] );
	}

	public function test_without_skip_flag_enhancement_provider_is_called_for_low_quality(): void {
		$enhanced_result = new EnhancementResult(
			image_data: 'enhanced-binary-data',
			mime_type:  'image/jpeg',
			success:    true,
		);

		$enhancement = $this->createMock( ProviderInterface::class );
		$enhancement->expects( $this->once() )
			->method( 'enhance' )
			->willReturn( $enhanced_result );

		$pipeline = $this->make_pipeline( $this->make_description_provider( 4 ), $enhancement );

		// Default $skip_enhancement = false — low quality score should trigger enhancement.
		$results = $pipeline->process( $this->make_submission() );

		$this->assertTrue( $results[0]['enhanced'] );
		$this->assertSame( 'enhanced-binary-data', $results[0]['enhanced_data'] );
		$this->assertSame( 'original-binary-data', $results[0]['original_data'] );
	}

	public function test_skip_enhancement_false_is_the_default(): void {
		// Calling process() without the second argument must behave identically
		// to process($submission, false) — enhancement runs when quality is low.
		$enhanced_result = new EnhancementResult(
			image_data: 'enhanced-binary-data',
			mime_type:  'image/jpeg',
			success:    true,
		);

		$enhancement = $this->createMock( ProviderInterface::class );
		$enhancement->expects( $this->once() )->method( 'enhance' )->willReturn( $enhanced_result );

		$pipeline = $this->make_pipeline( $this->make_description_provider( 3 ), $enhancement );
		$pipeline->process( $this->make_submission() ); // no second argument
	}
}
