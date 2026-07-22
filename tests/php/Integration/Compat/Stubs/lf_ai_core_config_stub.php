<?php
/**
 * Class stub for Lingua Forge's `LinguaForge\AI\Core\Config`.
 *
 * Companion to lf_worker_config_stub.php — see that file's docblock for the
 * shared rationale (force_quality_translation_model()'s class_exists() gate,
 * why each stub class gets its own file, and the "exists for the rest of the
 * process" caveat).
 *
 * Only stubs the one static method force_quality_translation_model() itself
 * calls, Config::model(). $quality_model is a public static so tests can
 * both control and assert against the exact string it returns, without
 * needing to reproduce Config's own provider-detection logic here.
 *
 * Required by: LinguaForgeCompatTest.php
 *
 * @package Agnosis\Tests\Integration\Compat\Stubs
 */

declare(strict_types=1);

namespace LinguaForge\AI\Core;

if ( ! class_exists( __NAMESPACE__ . '\Config' ) ) {
	class Config {
		public static string $quality_model = 'stub-quality-model';

		public static function model( string $tier ): string {
			return 'quality' === $tier ? self::$quality_model : 'stub-light-model';
		}
	}
}
