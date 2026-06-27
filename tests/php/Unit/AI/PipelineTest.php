<?php
/**
 * Unit tests for the AI Pipeline orchestrator.
 *
 * Provider instances are injected via reflection so we can test the pipeline
 * logic without making real HTTP calls or needing valid API keys.
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

class PipelineTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Create a Pipeline with injected providers, bypassing option resolution.
	 */
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

	private function make_submission( int $attachment_count = 1 ): array {
		$attachments = [];
		for ( $i = 0; $i < $attachment_count; $i++ ) {
			$attachments[] = [
				'data'     => 'fake-image-binary-' . $i,
				'mime'     => 'image/jpeg',
				'filename' => "artwork-$i.jpg",
			];
		}
		return [
			'description' => 'A painting of the sea at dawn.',
			'attachments' => $attachments,
		];
	}

	private function make_description_mock( bool $success = true ): ProviderInterface {
		$mock = $this->createMock( ProviderInterface::class );
		$mock->method( 'describe' )->willReturn(
			$success
				? new DescriptionResult(
					title:               'Mocked Title',
					excerpt:             'Mocked excerpt.',
					body:                '<p>Mocked body.</p>',
					tags:                [ 'art', 'mock' ],
					alt_text:            'A mocked artwork image.',
					success:             true,
					photo_quality_score: 5, // Below default threshold (7) and above 0 — triggers enhancement.
				)
				: DescriptionResult::failure( 'Mocked provider error.' )
		);
		$mock->method( 'supports_enhancement' )->willReturn( false );
		$mock->method( 'enhance' )->willReturn( EnhancementResult::failure( 'not supported' ) );
		return $mock;
	}

	// -------------------------------------------------------------------------
	// Tests
	// -------------------------------------------------------------------------

	public function test_process_returns_one_result_per_attachment(): void {
		$pipeline = $this->make_pipeline( $this->make_description_mock() );

		$results = $pipeline->process( $this->make_submission( 3 ) );

		$this->assertCount( 3, $results );
	}

	public function test_process_result_carries_description_fields(): void {
		$pipeline = $this->make_pipeline( $this->make_description_mock() );

		$results = $pipeline->process( $this->make_submission() );

		$this->assertSame( 'Mocked Title', $results[0]['title'] );
		$this->assertSame( 'Mocked excerpt.', $results[0]['excerpt'] );
		$this->assertSame( '<p>Mocked body.</p>', $results[0]['body'] );
		$this->assertSame( [ 'art', 'mock' ], $results[0]['tags'] );
		$this->assertSame( 'A mocked artwork image.', $results[0]['alt_text'] );
		$this->assertTrue( $results[0]['description_ok'] );
	}

	public function test_process_uses_original_image_when_no_enhancement_provider(): void {
		$pipeline = $this->make_pipeline(
			$this->make_description_mock(),
			null // no enhancement provider
		);

		$results = $pipeline->process( $this->make_submission() );

		// Without enhancement, enhanced_data should equal the original.
		$this->assertSame( 'fake-image-binary-0', $results[0]['enhanced_data'] );
	}

	public function test_process_uses_enhanced_image_when_provider_succeeds(): void {
		$description_mock = $this->make_description_mock();

		$enhancement_mock = $this->createMock( ProviderInterface::class );
		$enhancement_mock->method( 'enhance' )->willReturn(
			new EnhancementResult(
				image_data: 'enhanced-binary-data',
				mime_type:  'image/webp',
				success:    true,
			)
		);
		$enhancement_mock->method( 'supports_enhancement' )->willReturn( true );
		$enhancement_mock->method( 'describe' )->willReturn( DescriptionResult::failure( 'n/a' ) );

		$pipeline = $this->make_pipeline( $description_mock, $enhancement_mock );
		$results  = $pipeline->process( $this->make_submission() );

		$this->assertSame( 'enhanced-binary-data', $results[0]['enhanced_data'] );
		$this->assertSame( 'image/webp', $results[0]['mime_type'] );
	}

	public function test_process_skips_enhancement_when_description_fails(): void {
		$description_mock = $this->make_description_mock( success: false );

		$enhancement_mock = $this->createMock( ProviderInterface::class );
		// enhance() should never be called when description failed.
		$enhancement_mock->expects( $this->never() )->method( 'enhance' );
		$enhancement_mock->method( 'supports_enhancement' )->willReturn( true );
		$enhancement_mock->method( 'describe' )->willReturn( DescriptionResult::failure( 'n/a' ) );

		$pipeline = $this->make_pipeline( $description_mock, $enhancement_mock );
		$pipeline->process( $this->make_submission() );
	}

	public function test_process_falls_back_to_original_when_enhancement_fails(): void {
		$description_mock = $this->make_description_mock();

		$enhancement_mock = $this->createMock( ProviderInterface::class );
		$enhancement_mock->method( 'enhance' )->willReturn(
			EnhancementResult::failure( 'Enhancement service down.' )
		);
		$enhancement_mock->method( 'supports_enhancement' )->willReturn( true );
		$enhancement_mock->method( 'describe' )->willReturn( DescriptionResult::failure( 'n/a' ) );

		$pipeline = $this->make_pipeline( $description_mock, $enhancement_mock );
		$results  = $pipeline->process( $this->make_submission() );

		// Original data used as fallback.
		$this->assertSame( 'fake-image-binary-0', $results[0]['enhanced_data'] );
	}

	public function test_process_without_api_keys_returns_graceful_failure(): void {
		// Without injecting providers, Pipeline resolves real providers with empty keys.
		// Each provider returns a failure result without making HTTP calls.
		$pipeline = new Pipeline();

		$results = $pipeline->process( $this->make_submission() );

		$this->assertCount( 1, $results );
		$this->assertFalse( $results[0]['description_ok'] );
		$this->assertNotEmpty( $results[0]['error'] );
	}

	public function test_process_result_includes_filename(): void {
		$pipeline = $this->make_pipeline( $this->make_description_mock() );
		$results  = $pipeline->process( $this->make_submission() );

		$this->assertSame( 'artwork-0.jpg', $results[0]['filename'] );
	}

	public function test_process_empty_attachments_returns_empty_array(): void {
		$pipeline = $this->make_pipeline( $this->make_description_mock() );

		$results = $pipeline->process( [ 'description' => 'test', 'attachments' => [] ] );

		$this->assertSame( [], $results );
	}
}
