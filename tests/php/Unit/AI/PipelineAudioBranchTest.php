<?php
/**
 * Unit tests for Pipeline::process_audio_single().
 *
 * The method is private, so it is exercised via ReflectionMethod. The
 * description_provider property is swapped out via ReflectionProperty so no
 * real AI calls are made. The enhancement_provider is set to null.
 *
 * @package Agnosis\Tests\Unit\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\AI;

use Agnosis\AI\Pipeline;
use Agnosis\AI\ProviderInterface;
use PHPUnit\Framework\TestCase;

class PipelineAudioBranchTest extends TestCase {

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
	private function call_audio_single(
		Pipeline $pipeline,
		string $audio_data,
		string $mime_type,
		string $filename,
		string $artist_context
	): array {
		$ref = new \ReflectionMethod( Pipeline::class, 'process_audio_single' );
		$ref->setAccessible( true );
		return $ref->invoke( $pipeline, $audio_data, $mime_type, $filename, $artist_context );
	}

	private function valid_json_response(): string {
		return json_encode( [
			'title'    => 'Resonance',
			'excerpt'  => 'A meditative soundscape.',
			'body'     => '<p>The piece unfolds slowly.</p>',
			'tags'     => [ 'ambient', 'sound art' ],
			'alt_text' => 'A deep drone composition',
			'medium'   => 'sound',
		] );
	}

	// -------------------------------------------------------------------------
	// Transcription routing
	// -------------------------------------------------------------------------

	public function test_transcribe_is_called_when_provider_supports_audio(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'supports_audio' )->willReturn( true );
		$provider->expects( $this->once() )
			->method( 'transcribe' )
			->with( 'binary', 'audio/mpeg' )
			->willReturn( 'This is a transcript.' );
		$provider->method( 'chat' )->willReturn( $this->valid_json_response() );

		$this->call_audio_single(
			$this->make_pipeline( $provider ),
			'binary', 'audio/mpeg', 'track.mp3', ''
		);
	}

	public function test_transcribe_is_not_called_when_provider_does_not_support_audio(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'supports_audio' )->willReturn( false );
		$provider->expects( $this->never() )->method( 'transcribe' );
		$provider->method( 'chat' )->willReturn( $this->valid_json_response() );

		$this->call_audio_single(
			$this->make_pipeline( $provider ),
			'binary', 'audio/mpeg', 'track.mp3', 'Artist note.'
		);
	}

	public function test_transcript_included_in_chat_prompt(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'supports_audio' )->willReturn( true );
		$provider->method( 'transcribe' )->willReturn( 'Haunting tones fill the space.' );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->stringContains( 'Haunting tones fill the space.' ) )
			->willReturn( $this->valid_json_response() );

		$this->call_audio_single(
			$this->make_pipeline( $provider ),
			'binary', 'audio/mpeg', 'track.mp3', ''
		);
	}

	public function test_artist_context_included_in_chat_prompt(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'supports_audio' )->willReturn( false );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->stringContains( 'Email subject: Summer Drone' ) )
			->willReturn( $this->valid_json_response() );

		$this->call_audio_single(
			$this->make_pipeline( $provider ),
			'binary', 'audio/mpeg', 'track.mp3', 'Email subject: Summer Drone'
		);
	}

	// -------------------------------------------------------------------------
	// Happy path — result shape
	// -------------------------------------------------------------------------

	public function test_valid_json_maps_to_correct_result_fields(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'supports_audio' )->willReturn( false );
		$provider->method( 'chat' )->willReturn( $this->valid_json_response() );

		$result = $this->call_audio_single(
			$this->make_pipeline( $provider ),
			'binary', 'audio/mpeg', 'track.mp3', 'Artist context.'
		);

		$this->assertTrue( $result['description_ok'] );
		$this->assertSame( 'audio', $result['media_type'] );
		$this->assertSame( 'Resonance', $result['title'] );
		$this->assertSame( 'A meditative soundscape.', $result['excerpt'] );
		$this->assertSame( '<p>The piece unfolds slowly.</p>', $result['body'] );
		$this->assertSame( [ 'ambient', 'sound art' ], $result['tags'] );
		$this->assertSame( 'A deep drone composition', $result['alt_text'] );
		$this->assertSame( 'track.mp3', $result['filename'] );
		$this->assertFalse( $result['enhanced'] );
		$this->assertSame( 0, $result['photo_quality_score'] );
		$this->assertSame( [], $result['photo_quality_issues'] );
		// enhanced_data mirrors original_data for audio (never enhanced) — see
		// Pipeline::process_audio_single(), so merge_gallery() can always
		// upload from 'enhanced_data' uniformly across media types.
		$this->assertSame( 'binary', $result['enhanced_data'] );
	}

	/**
	 * Regression test for the 2026-07-21 fix: process_audio_single()'s result
	 * array never included the AI-detected medium at all, even though the
	 * chat() prompt has always asked for one — see valid_json_response()'s
	 * 'medium' => 'sound' fixture, unused by any assertion until now. The
	 * prompt itself also used to invent its own vocabulary ("sound", "music",
	 * "spoken word"...) that never matched the live agnosis_medium taxonomy,
	 * so even a well-formed answer would have been silently dropped by
	 * PostCreator's hallucination guard downstream. This test only covers the
	 * missing-array-key half of that bug (unit-level, no live taxonomy here);
	 * the vocabulary half is covered by asserting the prompt text below.
	 */
	public function test_result_carries_medium_field(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'supports_audio' )->willReturn( false );
		$provider->method( 'chat' )->willReturn( $this->valid_json_response() );

		$result = $this->call_audio_single(
			$this->make_pipeline( $provider ),
			'binary', 'audio/mpeg', 'track.mp3', 'Artist context.'
		);

		$this->assertArrayHasKey( 'medium', $result, "process_audio_single()'s result must include the AI-detected medium, not silently drop it." );
		$this->assertSame( 'sound', $result['medium'] );
	}

	/**
	 * Regression test for the other half of the same 2026-07-21 fix: the
	 * chat() prompt must ask against the live agnosis_medium vocabulary
	 * (PromptConfig::medium_terms(), which falls back to CANONICAL_MEDIUMS
	 * when the taxonomy is unregistered — the case here, since this is a
	 * plain-unit test with no WordPress taxonomy loaded), not a bespoke,
	 * non-matching list invented just for audio.
	 */
	public function test_chat_prompt_uses_live_medium_vocabulary_not_invented_terms(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'supports_audio' )->willReturn( false );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->logicalAnd(
				$this->stringContains( 'Oil Painting' ),
				$this->logicalNot( $this->stringContains( 'field recording' ) )
			) )
			->willReturn( $this->valid_json_response() );

		$this->call_audio_single(
			$this->make_pipeline( $provider ),
			'binary', 'audio/mpeg', 'track.mp3', 'Artist context.'
		);
	}

	public function test_strips_markdown_fences_from_chat_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'supports_audio' )->willReturn( false );
		$provider->method( 'chat' )->willReturn( "```json\n" . $this->valid_json_response() . "\n```" );

		$result = $this->call_audio_single(
			$this->make_pipeline( $provider ),
			'binary', 'audio/mpeg', 'track.mp3', 'Context.'
		);

		$this->assertTrue( $result['description_ok'] );
		$this->assertSame( 'Resonance', $result['title'] );
	}

	// -------------------------------------------------------------------------
	// Failure paths
	// -------------------------------------------------------------------------

	public function test_empty_chat_response_returns_failure_result(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'supports_audio' )->willReturn( false );
		$provider->method( 'chat' )->willReturn( '' );

		$result = $this->call_audio_single(
			$this->make_pipeline( $provider ),
			'binary', 'audio/mpeg', 'track.mp3', 'Context.'
		);

		$this->assertFalse( $result['description_ok'] );
		$this->assertSame( 'audio', $result['media_type'] );
		$this->assertNotEmpty( $result['error'] );
	}

	public function test_invalid_json_from_chat_returns_failure_result(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'supports_audio' )->willReturn( false );
		$provider->method( 'chat' )->willReturn( 'not valid json' );

		$result = $this->call_audio_single(
			$this->make_pipeline( $provider ),
			'binary', 'audio/mpeg', 'track.mp3', 'Context.'
		);

		$this->assertFalse( $result['description_ok'] );
		$this->assertNotEmpty( $result['error'] );
	}

	public function test_no_context_and_empty_transcript_returns_failure_without_chat_call(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'supports_audio' )->willReturn( true );
		$provider->method( 'transcribe' )->willReturn( '' );
		$provider->expects( $this->never() )->method( 'chat' );

		$result = $this->call_audio_single(
			$this->make_pipeline( $provider ),
			'binary', 'audio/mpeg', 'track.mp3', '' // no artist context
		);

		$this->assertFalse( $result['description_ok'] );
		$this->assertSame( 'audio', $result['media_type'] );
	}
}
