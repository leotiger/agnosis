<?php
/**
 * Unit tests for Pipeline::extract_event_fields().
 *
 * The method sends a chat prompt to the description provider and parses the
 * JSON response to extract an event location. Tests verify correct parsing,
 * fallback on empty/malformed responses, and short-circuit on empty input.
 *
 * @package Agnosis\Tests\Unit\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\AI;

use Agnosis\AI\Pipeline;
use Agnosis\AI\ProviderInterface;
use PHPUnit\Framework\TestCase;

class PipelineExtractEventFieldsTest extends TestCase {

	// -------------------------------------------------------------------------
	// Helper
	// -------------------------------------------------------------------------

	private function make_pipeline( ProviderInterface $provider ): Pipeline {
		$pipeline = new Pipeline();

		$ref = new \ReflectionProperty( Pipeline::class, 'description_provider' );
		$ref->setAccessible( true );
		$ref->setValue( $pipeline, $provider );

		return $pipeline;
	}

	// -------------------------------------------------------------------------
	// Happy path
	// -------------------------------------------------------------------------

	public function test_returns_location_from_valid_json(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"location":"Gallery Mitte, Berlin","event_date":""}' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => 'Join us at Gallery Mitte, Berlin for the opening night.',
		] );

		$this->assertSame( 'Gallery Mitte, Berlin', $result['location'] );
	}

	public function test_includes_subject_and_body_in_prompt(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->stringContains( 'Subject: Opening Night' ) )
			->willReturn( '{"location":"","event_date":""}' );

		$this->make_pipeline( $provider )->extract_event_fields( [
			'subject'     => 'Opening Night',
			'description' => 'Come celebrate with us.',
		] );
	}

	public function test_returns_empty_location_when_none_mentioned(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"location":"","event_date":""}' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => 'Come celebrate with us.',
		] );

		$this->assertSame( '', $result['location'] );
	}

	// -------------------------------------------------------------------------
	// event_date extraction
	// -------------------------------------------------------------------------

	public function test_returns_date_only_event_date(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"location":"Gallery X","event_date":"2026-08-15"}' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => 'Exhibition opens 15 August 2026 at Gallery X.',
		] );

		$this->assertSame( '2026-08-15', $result['event_date'] );
	}

	public function test_returns_datetime_event_date(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"location":"","event_date":"2026-08-15T19:00"}' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => 'Opening night is 15 August at 7 pm.',
		] );

		$this->assertSame( '2026-08-15T19:00', $result['event_date'] );
	}

	public function test_returns_empty_event_date_when_none_mentioned(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"location":"Gallery X","event_date":""}' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => 'Come see my work at Gallery X.',
		] );

		$this->assertSame( '', $result['event_date'] );
	}

	public function test_rejects_non_iso_event_date(): void {
		// AI returned a natural-language string instead of ISO 8601 — must be discarded.
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '{"location":"","event_date":"August 15th"}' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => 'Opening August 15th.',
		] );

		$this->assertSame( '', $result['event_date'] );
	}

	// -------------------------------------------------------------------------
	// Malformed / unusual provider responses
	// -------------------------------------------------------------------------

	public function test_strips_markdown_fences_from_response(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( "```json\n{\"location\":\"Tate Modern\",\"event_date\":\"\"}\n```" );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => 'Exhibition at Tate Modern.',
		] );

		$this->assertSame( 'Tate Modern', $result['location'] );
	}

	public function test_returns_empty_fields_on_invalid_json(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( 'not json at all' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => 'Some text.',
		] );

		$this->assertSame( '', $result['location'] );
		$this->assertSame( '', $result['event_date'] );
	}

	public function test_returns_empty_fields_when_provider_returns_empty_string(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => 'Some text.',
		] );

		$this->assertSame( '', $result['location'] );
		$this->assertSame( '', $result['event_date'] );
	}

	// -------------------------------------------------------------------------
	// Short-circuit on empty submission
	// -------------------------------------------------------------------------

	public function test_does_not_call_provider_when_submission_is_empty(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [] );

		$this->assertSame( '', $result['location'] );
		$this->assertSame( '', $result['event_date'] );
	}

	public function test_does_not_call_provider_when_body_and_subject_are_blank(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$result = $this->make_pipeline( $provider )->extract_event_fields( [
			'description' => '   ',
			'subject'     => '',
		] );

		$this->assertSame( '', $result['location'] );
		$this->assertSame( '', $result['event_date'] );
	}
}
