<?php
/**
 * Stability AI provider — image enhancement only (Stable Diffusion upscale / refine).
 *
 * Description is not supported here; supports_enhancement() → true.
 *
 * @package Agnosis\AI\Providers
 */

declare(strict_types=1);

namespace Agnosis\AI\Providers;

use Agnosis\AI\DescriptionResult;
use Agnosis\AI\EnhancementResult;
use Agnosis\AI\ProviderInterface;

class StabilityAI implements ProviderInterface {

	// Stability AI v2beta — Conservative Upscale endpoint.
	private const UPSCALE_URL = 'https://api.stability.ai/v2beta/stable-image/upscale/conservative';

	public function __construct( private readonly string $api_key ) {}

	public function describe( string $image_data, string $mime_type, string $artist_prompt ): DescriptionResult {
		return DescriptionResult::failure( 'StabilityAI provider does not support description.' );
	}

	public function enhance( string $image_data, string $mime_type, string $instructions ): EnhancementResult {
		if ( empty( $this->api_key ) ) {
			return EnhancementResult::failure( 'Stability AI API key not configured.' );
		}

		$boundary = wp_generate_password( 24, false );
		$body     = $this->build_multipart( $boundary, $image_data, $mime_type, $instructions );

		$response = wp_remote_post( self::UPSCALE_URL, [
			'timeout' => 120,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->api_key,
				'Accept'        => 'image/*',
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			],
			'body'    => $body,
		] );

		if ( is_wp_error( $response ) ) {
			return EnhancementResult::failure( $response->get_error_message() );
		}

		$code         = wp_remote_retrieve_response_code( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );
		$body_raw     = wp_remote_retrieve_body( $response );

		if ( $code !== 200 ) {
			$error = json_decode( $body_raw, true );
			return EnhancementResult::failure( 'Stability AI error: ' . ( $error['message'] ?? $body_raw ) );
		}

		// Stability returns binary image directly.
		$ct_string   = is_array( $content_type ) ? ( $content_type[0] ?? '' ) : $content_type;
		$output_mime = strstr( $ct_string, ';', true ) ?: 'image/webp';

		return new EnhancementResult(
			image_data: $body_raw,
			mime_type:  $output_mime,
			success:    true,
		);
	}

	public function supports_enhancement(): bool {
		return true;
	}

	// -------------------------------------------------------------------------

	private function build_multipart( string $boundary, string $image_data, string $mime, string $prompt ): string {
		$eol  = "\r\n";
		$body = '';

		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="image"; filename="artwork.jpg"' . $eol;
		$body .= 'Content-Type: ' . $mime . $eol . $eol;
		$body .= $image_data . $eol;

		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="prompt"' . $eol . $eol;
		$body .= 'High-quality web-ready version of this artwork. ' . $prompt . $eol;

		$body .= '--' . $boundary . $eol;
		$body .= 'Content-Disposition: form-data; name="output_format"' . $eol . $eol;
		$body .= 'webp' . $eol;

		$body .= '--' . $boundary . '--' . $eol;

		return $body;
	}
}
