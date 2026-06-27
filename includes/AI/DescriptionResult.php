<?php
/**
 * Value object — result of an AI description pass.
 *
 * @package Agnosis\AI
 */

declare(strict_types=1);

namespace Agnosis\AI;

class DescriptionResult {

	/**
	 * @param array<string> $tags
	 * @param array<string> $photo_quality_issues
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $excerpt,
		public readonly string $body,
		public readonly array $tags,
		public readonly string $alt_text,
		public readonly bool $success,
		public readonly string $error = '',
		public readonly int $photo_quality_score = 0,
		public readonly array $photo_quality_issues = [],
		public readonly string $medium = '',
	) {}

	public static function failure( string $error ): self {
		return new self(
			title:     '',
			excerpt:   '',
			body:      '',
			tags:      [],
			alt_text:  '',
			success:   false,
			error:     $error,
		);
	}
}
