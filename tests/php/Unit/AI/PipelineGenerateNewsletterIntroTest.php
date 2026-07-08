<?php
/**
 * Unit tests for Pipeline::generate_newsletter_intro().
 *
 * Delegates to the description provider's chat() method with a prompt built
 * from a structured digest context (Newsletter\Digest::build_intro_context()).
 * Returns '' (treated as "nothing to propose" by the caller, Scheduler) when
 * there is nothing to summarise or the provider fails — never invents content.
 *
 * @package Agnosis\Tests\Unit\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\AI;

use Agnosis\AI\Pipeline;
use Agnosis\AI\ProviderInterface;
use PHPUnit\Framework\TestCase;

class PipelineGenerateNewsletterIntroTest extends TestCase {

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

	/** @return array{artworks: array<int, array{title: string, excerpt: string, tags: string[], medium?: string[]}>, events: array<int, array{title: string, excerpt: string, tags: string[]}>} */
	private function context_with_one_artwork(): array {
		return [
			'artworks' => [
				[ 'title' => 'Blue Hour', 'excerpt' => 'A quiet study of dusk.', 'tags' => [ 'blue' ], 'medium' => [ 'Oil Painting' ] ],
			],
			'events' => [],
		];
	}

	// -------------------------------------------------------------------------
	// Nothing to summarise — short-circuits without calling the provider
	// -------------------------------------------------------------------------

	public function test_returns_empty_string_and_skips_provider_when_context_is_entirely_empty(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->never() )->method( 'chat' );

		$result = $this->make_pipeline( $provider )->generate_newsletter_intro(
			'public',
			'Agnosis',
			[ 'artworks' => [], 'events' => [] ]
		);

		$this->assertSame( '', $result );
	}

	// -------------------------------------------------------------------------
	// Happy path
	// -------------------------------------------------------------------------

	public function test_returns_provider_output(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( 'A wonderful new piece just landed.' );

		$result = $this->make_pipeline( $provider )->generate_newsletter_intro( 'public', 'Agnosis', $this->context_with_one_artwork() );

		$this->assertSame( 'A wonderful new piece just landed.', $result );
	}

	public function test_prompt_includes_site_name_and_artwork_facts(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->logicalAnd(
				$this->stringContains( 'Agnosis' ),
				$this->stringContains( 'Blue Hour' ),
				$this->stringContains( 'A quiet study of dusk.' ),
				$this->stringContains( 'Oil Painting' )
			) )
			->willReturn( 'Drafted intro.' );

		$this->make_pipeline( $provider )->generate_newsletter_intro( 'public', 'Agnosis', $this->context_with_one_artwork() );
	}

	public function test_prompt_instructs_not_to_invent_facts(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->stringContains( 'Never invent' ) )
			->willReturn( 'Drafted intro.' );

		$this->make_pipeline( $provider )->generate_newsletter_intro( 'public', 'Agnosis', $this->context_with_one_artwork() );
	}

	public function test_artist_and_public_types_use_different_audience_framing(): void {
		$captured = [];
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturnCallback( function ( $prompt ) use ( &$captured ) {
			$captured[] = $prompt;
			return 'Drafted.';
		} );

		$pipeline = $this->make_pipeline( $provider );
		$pipeline->generate_newsletter_intro( 'artist', 'Agnosis', $this->context_with_one_artwork() );
		$pipeline->generate_newsletter_intro( 'public', 'Agnosis', $this->context_with_one_artwork() );

		$this->assertNotSame( $captured[0], $captured[1], 'artist vs public prompts must differ in audience framing.' );
	}

	// -------------------------------------------------------------------------
	// New members / open votes alone are enough to generate (artist type)
	// -------------------------------------------------------------------------

	public function test_new_members_alone_is_enough_to_generate(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )
			->method( 'chat' )
			->with( $this->stringContains( 'Nova Artist' ) )
			->willReturn( 'Welcome our newest artist!' );

		$result = $this->make_pipeline( $provider )->generate_newsletter_intro(
			'artist',
			'Agnosis',
			[ 'artworks' => [], 'events' => [], 'new_members' => [ 'Nova Artist' ], 'open_votes' => 0 ]
		);

		$this->assertSame( 'Welcome our newest artist!', $result );
	}

	public function test_open_votes_alone_is_enough_to_generate(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->expects( $this->once() )->method( 'chat' )->willReturn( 'A vote is open.' );

		$result = $this->make_pipeline( $provider )->generate_newsletter_intro(
			'artist',
			'Agnosis',
			[ 'artworks' => [], 'events' => [], 'new_members' => [], 'open_votes' => 2 ]
		);

		$this->assertSame( 'A vote is open.', $result );
	}

	// -------------------------------------------------------------------------
	// Provider failure — same "empty means failure" convention as polish()/merge_biography()
	// -------------------------------------------------------------------------

	public function test_returns_empty_string_when_provider_fails(): void {
		$provider = $this->createMock( ProviderInterface::class );
		$provider->method( 'chat' )->willReturn( '' );

		$result = $this->make_pipeline( $provider )->generate_newsletter_intro( 'public', 'Agnosis', $this->context_with_one_artwork() );

		$this->assertSame( '', $result );
	}
}
