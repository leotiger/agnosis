<?php
/**
 * Class stub for Lingua Forge's `LinguaForge\AI\Providers\WorkerConfig`.
 *
 * Stands in for LF's real, immutable WorkerConfig
 * (ai/includes/Providers/WorkerConfig.php) so
 * `Compat\LinguaForge::force_quality_translation_model()` (hooked on LF's
 * `linguaforge_translation_worker_config` filter, gated behind
 * `class_exists( WorkerConfig::class ) && class_exists( Config::class )`) can
 * be exercised without the real LF plugin installed. Same constructor shape,
 * same readonly properties, so force_quality_translation_model()'s "carry
 * $config->max_tokens/$config->response_schema over unchanged" behavior can
 * be asserted on a real instance rather than a mock.
 *
 * Split from the companion Config stub (lf_ai_core_config_stub.php) — each
 * namespace/object structure gets its own file per
 * Universal.Namespaces.OneDeclarationPerFile /
 * Generic.Files.OneObjectStructurePerFile.
 *
 * Once loaded, this class exists for the rest of the PHPUnit process (PHP
 * has no mechanism to undefine a class) — same caveat lf_global_stubs.php
 * documents for its own global function stubs. Nothing elsewhere in this
 * test suite asserts this class is ABSENT.
 *
 * Required by: LinguaForgeCompatTest.php
 *
 * @package Agnosis\Tests\Integration\Compat\Stubs
 */

declare(strict_types=1);

namespace LinguaForge\AI\Providers;

if ( ! class_exists( __NAMESPACE__ . '\WorkerConfig' ) ) {
	class WorkerConfig {
		public function __construct(
			public readonly string $model,
			public readonly int $max_tokens = 1024,
			public readonly float $temperature = 0.4,
			/** @var array<string, mixed>|null */
			public readonly ?array $response_schema = null,
		) {}
	}
}
