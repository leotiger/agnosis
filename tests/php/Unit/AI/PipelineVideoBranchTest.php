<?php
/**
 * Unit tests for Pipeline::process_video_single()'s poster-frame branch and
 * describe_video_from_context()'s text-only fallback branch.
 *
 * Both methods are private, so they're exercised via ReflectionMethod, same
 * technique as PipelineAudioBranchTest. As of 2026-07-21 both branches are
 * covered: the poster-frame path's missing 'medium' key was fixed alongside
 * process_single()'s identical bug (see PipelineTest), and
 * describe_video_from_context()'s own invented, non-matching medium
 * vocabulary plus its own missing 'medium' key are fixed here, mirroring the
 * identical fix applied to process_audio_single().
 *
 * @package Agnosis\Tests\Unit\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\AI;

use Agnosis\AI\DescriptionResult;
use Agnosis\AI\Pipeline;
use Agnosis\AI\ProviderInterface;
use PHPUnit\Framework\TestCase;

class PipelineVideoBranchTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function make_pipeline( ProviderInterface $provider ): Pipeline {
		$pipeline = new Pipeline();

		$desc = new \ReflectionProperty( Pipeline::class, 'description_provider' );
		$desc->setAccessible( true );
		$desc->setValue( $pipeline, $provider );

		$enh = new \ReflectionProperty( Pipeline::class, 'enhancement_provider' );
		$enh->setAccessible( true );
		$enh->setValue( $pipeline, null );

		return $pipeline;
	}

	/** @return array<string, mixed> */
	private function call_video_single(
		Pipeline $pipeline,
		string $video_data,
		string $mime_type,
		string $filename,
		string $poster_data,
		string $poster_mime,
		string $artist_context
	): array {
		$ref = new \ReflectionMethod( Pipeline::class, 'process_video_single' );
		$ref->setAccessible( true );
		return $ref->invoke( $pipeline, $video_data, $mime_type, $filename, $poster_data, $poster_mime, $artist_context );
	}

	/** @return array<string, mixed> */
	private function call_video_from_context(
		Pipeline $pipeline,
		string $video_data,
		string $mime_type,
		string $filename,
		string $poster_data,
		string $poster_mime,
		string $artist_context
	): array {
		$ref = new \ReflectionMethod( Pipeline::class, 'describe_video_from_context' );
		$ref->setAccessible( true );
		return $ref->invoke( $pipeline, $video_data, $mime_type, $filename, $poster_data, $poster_mime, $artist_context );
	}

	/**
	 * Regression test for the 2026-07-21 fix: when a poster frame is
	 * available, process_video_single() calls the same describe() vision
	 * call the main image path uses — against the same live agnosis_medium
	 * vocabulary — so $description->medium is a real, valid term. It was
	 * computed correctly but never included in this branch's returned array,
	 * so a video submission's medium silently never reached PostCreator
	 * either, exactly like the image path's own bug.
	 */
	public function test_poster_frame_branch_carries_medium_field(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'describe' )->willReturn( new DescriptionResult(
			title:               'Tidal',
			excerpt:             'Waves in motion.',
			body:                '<p>A study of the shoreline.</p>',
			tags:                [ 'video', 'coastal' ],
			alt_text:            'Waves breaking on a rocky shore',
			success:             true,
			medium:              'Video Art',
		) );

		$result = $this->call_video_single(
			$this->make_pipeline( $provider ),
			'video-binary', 'video/mp4', 'clip.mp4',
			'poster-binary', 'image/jpeg',
			'Artist context.'
		);

		$this->assertArrayHasKey( 'medium', $result, 'process_video_single()\'s poster-frame branch must include the AI-detected medium, not silently drop it.' );
		$this->assertSame( 'Video Art', $result['medium'] );
	}

	public function test_poster_frame_branch_carries_description_fields(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'describe' )->willReturn( new DescriptionResult(
			title:    'Tidal',
			excerpt:  'Waves in motion.',
			body:     '<p>A study of the shoreline.</p>',
			tags:     [ 'video', 'coastal' ],
			alt_text: 'Waves breaking on a rocky shore',
			success:  true,
		) );

		$result = $this->call_video_single(
			$this->make_pipeline( $provider ),
			'video-binary', 'video/mp4', 'clip.mp4',
			'poster-binary', 'image/jpeg',
			'Artist context.'
		);

		$this->assertSame( 'video', $result['media_type'] );
		$this->assertTrue( $result['description_ok'] );
		$this->assertSame( 'Tidal', $result['title'] );
		$this->assertSame( 'video-binary', $result['original_data'] );
		$this->assertSame( 'video-binary', $result['enhanced_data'], 'Video is never enhanced — enhanced_data must mirror original_data.' );
		$this->assertFalse( $result['enhanced'] );
	}

	// -------------------------------------------------------------------------
	// describe_video_from_context() — text-only fallback branch
	// -------------------------------------------------------------------------

	private function valid_video_context_json_response(): string {
		return json_encode( [
			'title'    => 'Undertow',
			'excerpt'  => 'A meditation on the tide.',
			'body'     => '<p>Described from context alone.</p>',
			'tags'     => [ 'video', 'tide' ],
			'alt_text' => 'No image available for this submission',
			'medium'   => 'Photography',
		] );
	}

	/**
	 * Regression test for the 2026-07-21 fix: describe_video_from_context()'s
	 * result array never included the AI-detected medium at all, same gap as
	 * process_audio_single()'s identical bug.
	 */
	public function test_context_only_branch_carries_medium_field(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( $this->valid_video_context_json_response() );

		$result = $this->call_video_from_context(
			$this->make_pipeline( $provider ),
			'video-binary', 'video/mp4', 'clip.mp4',
			'', '', // no poster frame — this is the text-only path
			'Artist context describing the piece.'
		);

		$this->assertArrayHasKey( 'medium', $result, "describe_video_from_context()'s result must include the AI-detected medium, not silently drop it." );
		$this->assertSame( 'Photography', $result['medium'] );
	}

	/**
	 * Regression test for the other half of the same fix: the chat() prompt
	 * must ask against the live agnosis_medium vocabulary
	 * (PromptConfig::medium_terms(), falling back to CANONICAL_MEDIUMS with no
	 * WordPress taxonomy loaded here), not describe_video_from_context()'s own
	 * previously-invented, non-matching list.
	 */
	public function test_context_only_branch_prompt_uses_live_medium_vocabulary_not_invented_terms(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->logicalAnd(
				$this->stringContains( 'Oil Painting' ),
				$this->logicalNot( $this->stringContains( 'documentary' ) )
			) )
			->willReturn( $this->valid_video_context_json_response() );

		$this->call_video_from_context(
			$this->make_pipeline( $provider ),
			'video-binary', 'video/mp4', 'clip.mp4',
			'', '',
			'Artist context describing the piece.'
		);
	}
}
