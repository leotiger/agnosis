<?php
/**
 * Value object — result of an AI image enhancement pass.
 *
 * @package Agnosis\AI
 */

declare(strict_types=1);

namespace Agnosis\AI;

class EnhancementResult {

	public function __construct(
		public readonly string $image_data,   // Raw binary of the enhanced image.
		public readonly string $mime_type,
		public readonly bool   $success,
		public readonly string $error = '',
	) {}

	public static function failure( string $error ): self {
		return new self(
			image_data: '',
			mime_type:  '',
			success:    false,
			error:      $error,
		);
	}
}
