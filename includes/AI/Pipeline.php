<?php
/**
 * AI Pipeline orchestrator.
 *
 * Receives a parsed submission and routes each attachment through the correct
 * branch based on its media type (resolved by MediaAdapter):
 *
 *   image/*         → describe → optionally enhance  (process_single)
 *   application/pdf → rasterise pages → image branch  (MediaAdapter)
 *   video/*         → extract first frame → image branch  (MediaAdapter)
 *   audio/*         → transcribe → chat describe  (process_audio_single)
 *
 * Provider selection is independent: an operator can use Anthropic for text
 * and OpenAI for image enhancement (and audio transcription), or WordPress AI
 * for text with no enhancement, etc.
 *
 * @package Agnosis\AI
 */

declare(strict_types=1);

namespace Agnosis\AI;

use Agnosis\AI\Providers\Anthropic;
use Agnosis\AI\Providers\OpenAI;
use Agnosis\AI\Providers\WordPressAI;

class Pipeline {

	private ProviderInterface $description_provider;
	private ?ProviderInterface $enhancement_provider;
	private PromptConfig $config;

	public function __construct() {
		$this->config               = PromptConfig::from_options();
		$this->description_provider = $this->resolve_description_provider();
		$this->enhancement_provider = $this->resolve_enhancement_provider();
	}

	/**
	 * Process all attachments in a submission.
	 *
	 * Steps:
	 *   1. Translate subject + body to the site's primary language (Lingua Forge
	 *      primary language → WP site locale → English) so the AI always
	 *      receives — and produces — content in the correct language.
	 *   2. Expand attachments through MediaAdapter (PDF pages, video frames, audio).
	 *   3. Route each adapted entry to the image or audio processor.
	 *
	 * @param array<string, mixed> $submission Parsed submission from Parser.
	 * @return array<int, array<string, mixed>> One result per (adapted) attachment.
	 */
	public function process( array $submission ): array {
		$results = [];

		// Step 1 — Translate artist text to the site's primary language.
		$translator = new SubmissionTranslator( $this->description_provider );
		$submission = $translator->translate( $submission );

		$artist_context = $this->build_artist_context( $submission );

		$adapted = MediaAdapter::adapt( $submission['attachments'] ?? [] );

		foreach ( $adapted as $attachment ) {
			if ( ( $attachment['media_type'] ?? 'image' ) === 'audio' ) {
				$results[] = $this->process_audio_single(
					$attachment['data'],
					$attachment['mime'],
					$attachment['filename'],
					$artist_context
				);
			} else {
				$results[] = $this->process_single(
					$attachment['data'],
					$attachment['mime'],
					$attachment['filename'],
					$artist_context
				);
			}
		}

		return $results;
	}

	/**
	 * Build a structured context string from the full email submission.
	 *
	 * Includes the subject line (which often hints at a title) and the email
	 * body (the artist's own words). Both are passed to the AI so it can weigh
	 * them together with the image.
	 *
	 * @param array<string, mixed> $submission Parsed email submission.
	 */
	private function build_artist_context( array $submission ): string {
		$parts = [];

		$subject = trim( (string) ( $submission['subject'] ?? '' ) );
		if ( ! empty( $subject ) ) {
			$parts[] = 'Email subject: ' . $subject;
		}

		$body = trim( (string) ( $submission['description'] ?? '' ) );
		if ( ! empty( $body ) ) {
			$label   = ! empty( $subject ) ? "Artist's message:" : "Artist's note:";
			$parts[] = $label . "\n" . $body;
		}

		return implode( "\n\n", $parts );
	}

	// -------------------------------------------------------------------------

	/** @return array<string, mixed> */
	private function process_single(
		string $image_data,
		string $mime_type,
		string $filename,
		string $artist_prompt
	): array {
		// Step 1 — Describe (also assesses photo quality as part of the same vision call).
		$description = $this->description_provider->describe( $image_data, $mime_type, $artist_prompt );

		$quality_score  = $description->photo_quality_score;
		$quality_issues = $description->photo_quality_issues;

		// Step 2 — Conditionally enhance.
		// Enhancement only runs when:
		//   • A provider is configured.
		//   • Description succeeded (we need the body for contextual instructions).
		//   • The photo quality score is below the configured threshold (default 7).
		//     Score 0 means the provider could not assess quality (e.g. text-only) — skip.
		$threshold     = $this->config->quality_threshold;
		$needs_enhance = null !== $this->enhancement_provider
			&& $description->success
			&& $quality_score > 0
			&& $quality_score < $threshold;

		$enhanced_data = $image_data;
		$enhanced_mime = $mime_type;
		$enhanced      = false;

		if ( $needs_enhance ) {
			// Build instructions targeted at the specific issues detected, with the
			// base enhancement constraints always present as the final section.
			$instructions = $this->config->build_targeted_enhancement_instructions( $quality_issues );

			// Append the AI-generated body as additional visual context for the enhancer.
			if ( $description->body ) {
				$instructions .= "\n\n" . $description->body;
			}

			$enhancement = $this->enhancement_provider->enhance( $image_data, $mime_type, $instructions );

			if ( $enhancement->success && ! empty( $enhancement->image_data ) ) {
				$enhanced_data = $enhancement->image_data;
				$enhanced_mime = $enhancement->mime_type;
				$enhanced      = true;
			}
		}

		return [
			'filename'             => $filename,
			'original_data'        => $image_data,
			'enhanced_data'        => $enhanced_data,
			'mime_type'            => $enhanced_mime,
			'title'                => $description->title,
			'excerpt'              => $description->excerpt,
			'body'                 => $description->body,
			'tags'                 => $description->tags,
			'alt_text'             => $description->alt_text,
			'description_ok'       => $description->success,
			'error'                => $description->error,
			'photo_quality_score'  => $quality_score,
			'photo_quality_issues' => $quality_issues,
			'enhanced'             => $enhanced,
		];
	}

	// -------------------------------------------------------------------------

	/**
	 * Process a single audio attachment.
	 *
	 * Two-step approach:
	 *   1. Transcribe — if the description provider supports audio (Whisper), get
	 *      a transcript from the audio binary. Falls back to the artist context alone.
	 *   2. Describe — send the transcript + artist context to the text model
	 *      (chat()) and ask it to produce the same structured JSON that image
	 *      description returns (title, excerpt, body, tags, alt_text, medium).
	 *
	 * Enhancement is always skipped for audio — there is no image to enhance.
	 * The result includes media_type = 'audio' so PostCreator can skip upload_image().
	 *
	 * @return array<string, mixed>
	 */
	private function process_audio_single(
		string $audio_data,
		string $mime_type,
		string $filename,
		string $artist_context
	): array {
		// Step 1 — Transcribe.
		$transcript = '';
		if ( $this->description_provider->supports_audio() ) {
			$transcript = $this->description_provider->transcribe( $audio_data, $mime_type );
		}

		// Build a combined context string for the text description call.
		$combined = '';
		if ( $artist_context ) {
			$combined .= $artist_context;
		}
		if ( $transcript ) {
			$combined .= ( $combined ? "\n\n" : '' ) . "Audio transcript:\n" . $transcript;
		}

		if ( empty( trim( $combined ) ) ) {
			// Nothing to work from — return a minimal failure result.
			return $this->audio_failure_result( $filename, 'No transcript or artist context available for audio file.' );
		}

		// Step 2 — Structured text description via chat().
		$prompt = "You are writing metadata for an artist's audio work that will be published on an art platform.\n\n"
			. "Context about the work:\n"
			. "---\n"
			. "{$combined}\n"
			. "---\n\n"
			. "Produce a JSON object with these keys:\n"
			. "- \"title\":    Short, evocative title for the work (string).\n"
			. "- \"excerpt\":  One-sentence teaser (string).\n"
			. "- \"body\":     Two or three paragraphs of editorial description (string, may contain basic HTML).\n"
			. "- \"tags\":     3–6 descriptive tags as an array of lowercase strings.\n"
			. "- \"alt_text\": A brief accessible description of what the audio conveys (string).\n"
			. "- \"medium\":   One of: \"sound\", \"music\", \"spoken word\", \"field recording\", \"performance\", or \"\" if unclear.\n\n"
			. 'Return ONLY the JSON object. No markdown fences. No preamble.';

		$response = $this->description_provider->chat( $prompt );

		if ( empty( $response ) ) {
			return $this->audio_failure_result( $filename, 'AI returned no response for audio description.' );
		}

		// Strip markdown fences if present.
		$json_str = trim( (string) preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $response ) ) );
		$json     = json_decode( $json_str, true );

		if ( ! is_array( $json ) ) {
			return $this->audio_failure_result( $filename, 'AI returned non-JSON response for audio description.' );
		}

		return [
			'filename'             => $filename,
			'original_data'        => $audio_data,
			'enhanced_data'        => '', // no image data for audio
			'mime_type'            => $mime_type,
			'media_type'           => 'audio',
			'title'                => sanitize_text_field( $json['title']    ?? '' ),
			'excerpt'              => sanitize_text_field( $json['excerpt']  ?? '' ),
			'body'                 => wp_kses_post( $json['body']            ?? '' ),
			'tags'                 => array_map( 'sanitize_text_field', (array) ( $json['tags'] ?? [] ) ),
			'alt_text'             => sanitize_text_field( $json['alt_text'] ?? '' ),
			'description_ok'       => true,
			'error'                => '',
			'photo_quality_score'  => 0, // not applicable for audio
			'photo_quality_issues' => [],
			'enhanced'             => false,
		];
	}

	/** @return array<string, mixed> */
	private function audio_failure_result( string $filename, string $error ): array {
		return [
			'filename'             => $filename,
			'original_data'        => '',
			'enhanced_data'        => '',
			'mime_type'            => 'audio/mpeg',
			'media_type'           => 'audio',
			'title'                => '',
			'excerpt'              => '',
			'body'                 => '',
			'tags'                 => [],
			'alt_text'             => '',
			'description_ok'       => false,
			'error'                => $error,
			'photo_quality_score'  => 0,
			'photo_quality_issues' => [],
			'enhanced'             => false,
		];
	}

	/**
	 * Extract structured fields from an event email.
	 *
	 * Uses a cheap text-model pass to pull the event location (venue, city,
	 * address) from the artist's email body and subject.  Returns an array with
	 * a 'location' key; value is an empty string when no location is found.
	 *
	 * @param array<string, mixed> $submission Parsed email submission.
	 * @return array{location: string}
	 */
	public function extract_event_fields( array $submission ): array {
		$body    = trim( (string) ( $submission['description'] ?? '' ) );
		$subject = trim( (string) ( $submission['subject'] ?? '' ) );

		if ( empty( $body ) && empty( $subject ) ) {
			return [ 'location' => '' ];
		}

		$email_text = empty( $subject ) ? $body : "Subject: {$subject}\n\n{$body}";

		$prompt = 'You are extracting structured data from an artist\'s event announcement email.' . "\n\n"
			. "Email content:\n---\n{$email_text}\n---\n\n"
			. 'Extract the event location — venue name, city, address, or any place information mentioned. '
			. 'Return ONLY a JSON object with a single key "location" (string). '
			. 'If no location is mentioned, return {"location": ""}. '
			. 'No markdown fences. No preamble. Just the JSON object.';

		$response = $this->description_provider->chat( $prompt );

		if ( empty( $response ) ) {
			return [ 'location' => '' ];
		}

		// Tolerate a stray markdown fence or surrounding whitespace.
		$json    = trim( (string) preg_replace( '/^```(?:json)?\s*|\s*```$/', '', trim( $response ) ) );
		$decoded = json_decode( $json, true );

		if ( is_array( $decoded ) && array_key_exists( 'location', $decoded ) ) {
			return [ 'location' => sanitize_text_field( (string) $decoded['location'] ) ];
		}

		return [ 'location' => '' ];
	}

	/**
	 * Plain-text chat — delegates to the description provider's cheap text model.
	 *
	 * Used for lightweight classification (e.g. duplicate detection) that does
	 * not require image input. Returns '' on failure.
	 */
	public function chat( string $prompt ): string {
		return $this->description_provider->chat( $prompt );
	}

	/**
	 * Polish a block of text — fix spelling and grammar, make minimal
	 * improvements without changing meaning or adding content.
	 *
	 * Returns the improved text, or the original unchanged if the call fails.
	 */
	public function polish( string $text ): string {
		if ( empty( trim( $text ) ) ) {
			return $text;
		}
		$prompt   = "Fix spelling and grammar in the following text. Make only minimal improvements — do not change the meaning, tone, or add any content. Return only the corrected text, nothing else.\n\n" . $text;
		$polished = $this->description_provider->chat( $prompt );
		return $polished ?: $text;
	}

	// -------------------------------------------------------------------------
	// Provider resolution
	// -------------------------------------------------------------------------

	private function resolve_description_provider(): ProviderInterface {
		$provider = get_option( 'agnosis_description_provider', 'openai' );

		switch ( $provider ) {
			case 'anthropic':
				$key   = (string) get_option( 'agnosis_anthropic_api_key', '' );
				$model = (string) get_option( 'agnosis_anthropic_model', 'claude-opus-4-8' );
				return new Anthropic( $key, $this->config, $model );

			case 'wp_ai':
				return new WordPressAI( $this->config );

			case 'openai':
			default:
				$key   = (string) get_option( 'agnosis_openai_api_key', '' );
				$model = (string) get_option( 'agnosis_openai_description_model', 'gpt-4o' );
				return new OpenAI( $key, $this->config, $model );
		}
	}

	private function resolve_enhancement_provider(): ?ProviderInterface {
		$provider = get_option( 'agnosis_enhancement_provider', 'auto' );

		if ( 'none' === $provider ) {
			return null;
		}

		if ( 'openai' === $provider ) {
			$key = (string) get_option( 'agnosis_openai_api_key', '' );
			if ( ! empty( $key ) ) {
				$image_model = (string) get_option( 'agnosis_openai_image_model', 'gpt-image-1' );
				return new OpenAI( $key, $this->config, 'gpt-4o', $image_model );
			}
		}

		// 'auto' — use OpenAI if a key is configured; otherwise no enhancement.
		$openai_key = (string) get_option( 'agnosis_openai_api_key', '' );
		if ( ! empty( $openai_key ) ) {
			$image_model = (string) get_option( 'agnosis_openai_image_model', 'gpt-image-1' );
			return new OpenAI( $openai_key, $this->config, 'gpt-4o', $image_model );
		}

		return null; // No enhancement — original image used.
	}
}
