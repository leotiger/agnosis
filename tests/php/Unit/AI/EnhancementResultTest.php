<?php
/**
 * Unit tests for EnhancementResult value object.
 *
 * @package Agnosis\Tests\Unit\AI
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\AI;

use Agnosis\AI\EnhancementResult;
use PHPUnit\Framework\TestCase;

class EnhancementResultTest extends TestCase {

	public function test_constructor_stores_all_properties(): void {
		$binary = "\x89PNG\r\n\x1a\n";

		$result = new EnhancementResult(
			image_data: $binary,
			mime_type:  'image/png',
			success:    true,
		);

		$this->assertSame( $binary, $result->image_data );
		$this->assertSame( 'image/png', $result->mime_type );
		$this->assertTrue( $result->success );
		$this->assertSame( '', $result->error );
	}

	public function test_constructor_accepts_error_message(): void {
		$result = new EnhancementResult(
			image_data: '',
			mime_type:  '',
			success:    false,
			error:      'Quota exceeded.',
		);

		$this->assertFalse( $result->success );
		$this->assertSame( 'Quota exceeded.', $result->error );
	}

	public function test_failure_factory_marks_success_false(): void {
		$result = EnhancementResult::failure( 'Provider unavailable.' );

		$this->assertFalse( $result->success );
		$this->assertSame( 'Provider unavailable.', $result->error );
	}

	public function test_failure_factory_returns_empty_image_data(): void {
		$result = EnhancementResult::failure( 'err' );

		$this->assertSame( '', $result->image_data );
		$this->assertSame( '', $result->mime_type );
	}

	public function test_properties_are_readonly(): void {
		$result = EnhancementResult::failure( 'err' );

		$this->expectException( \Error::class );
		/** @phpstan-ignore-next-line */
		$result->image_data = 'mutated';
	}
}
