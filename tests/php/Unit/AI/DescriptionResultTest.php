<?php
/**
 * Unit tests for DescriptionResult value object.
 *
 * @package Agnosis\Tests\Unit\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\AI;

use Agnosis\AI\DescriptionResult;
use PHPUnit\Framework\TestCase;

class DescriptionResultTest extends TestCase {

	public function test_constructor_stores_all_properties(): void {
		$result = new DescriptionResult(
			title:    'Whispers in Blue',
			excerpt:  'A meditation on solitude and the sea.',
			body:     '<p>Vast and unrelenting.</p>',
			tags:     [ 'seascape', 'oil', 'blue' ],
			alt_text: 'Abstract blue oil painting with horizon line.',
			success:  true,
		);

		$this->assertSame( 'Whispers in Blue', $result->title );
		$this->assertSame( 'A meditation on solitude and the sea.', $result->excerpt );
		$this->assertSame( '<p>Vast and unrelenting.</p>', $result->body );
		$this->assertSame( [ 'seascape', 'oil', 'blue' ], $result->tags );
		$this->assertSame( 'Abstract blue oil painting with horizon line.', $result->alt_text );
		$this->assertTrue( $result->success );
		$this->assertSame( '', $result->error );
	}

	public function test_constructor_accepts_custom_error_message(): void {
		$result = new DescriptionResult(
			title:    '',
			excerpt:  '',
			body:     '',
			tags:     [],
			alt_text: '',
			success:  false,
			error:    'API timeout.',
		);

		$this->assertFalse( $result->success );
		$this->assertSame( 'API timeout.', $result->error );
	}

	public function test_failure_factory_marks_success_false(): void {
		$result = DescriptionResult::failure( 'Something went wrong.' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'Something went wrong.', $result->error );
	}

	public function test_failure_factory_returns_empty_fields(): void {
		$result = DescriptionResult::failure( 'err' );

		$this->assertSame( '', $result->title );
		$this->assertSame( '', $result->excerpt );
		$this->assertSame( '', $result->body );
		$this->assertSame( '', $result->alt_text );
		$this->assertSame( [], $result->tags );
	}

	public function test_tags_is_array_of_strings(): void {
		$result = new DescriptionResult(
			title:    'Test',
			excerpt:  'Test',
			body:     'Test',
			tags:     [ 'abstract', 'watercolour', 'landscape' ],
			alt_text: 'Test',
			success:  true,
		);

		$this->assertIsArray( $result->tags );
		$this->assertContainsOnly( 'string', $result->tags );
	}

	public function test_properties_are_readonly(): void {
		$result = DescriptionResult::failure( 'err' );

		$this->expectException( \Error::class );
		/** @phpstan-ignore-next-line */
		$result->title = 'mutated';
	}
}
