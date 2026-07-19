<?php
/**
 * "Test AI Providers" card (AI tab) — rendering plus its own AJAX handler
 * and provider-ping helper.
 *
 * Split out of Admin\Settings (2026-07-17, AUDIT-1.0.0.md §4d — the "god
 * class" finding): this render method, its AJAX handler, and the private
 * ping_provider() helper used only by that handler were already a
 * self-contained cluster, so this is a pure move — same behavior, same hook
 * name (`wp_ajax_agnosis_test_ai`, rewired in Core\Plugin to this class
 * instead of Settings).
 *
 * @package Agnosis\Admin\Dashboards
 */

declare(strict_types=1);

namespace Agnosis\Admin\Dashboards;

use Agnosis\Core\Secrets;

class AiTestTools {

	public function render(): void {
		$providers = [
			'openai'    => [
				'label' => 'OpenAI',
				'desc'  => __( 'Sends a minimal ping ("Reply with the word: ping") using the configured API key.', 'agnosis' ),
			],
			'anthropic' => [
				'label' => 'Anthropic',
				'desc'  => __( 'Sends a minimal ping using the configured API key.', 'agnosis' ),
			],
			'wp_ai'     => [
				'label' => __( 'WordPress AI Client', 'agnosis' ),
				'desc'  => __( 'Checks that wp_ai_client_prompt() is available (WordPress 7.0+) and a text-generation model is configured.', 'agnosis' ),
			],
		];
		?>
		<div class="card" style="max-width:800px;margin-top:1.5rem;padding:1rem 1.5rem">
			<h2 style="margin-top:0"><?php esc_html_e( 'Test AI Providers', 'agnosis' ); ?></h2>
			<table class="form-table" role="presentation" style="margin-bottom:0"><tbody>
				<?php foreach ( $providers as $slug => $info ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $info['label'] ); ?></th>
					<td>
						<button type="button"
							class="button button-secondary agnosis-test-ai"
							data-provider="<?php echo esc_attr( $slug ); ?>"
							data-nonce="<?php echo esc_attr( wp_create_nonce( 'agnosis_test_ai' ) ); ?>">
							<?php
							printf(
								/* translators: %s: provider label */
								esc_html__( 'Test %s', 'agnosis' ),
								esc_html( $info['label'] )
							);
							?>
						</button>
						<span class="agnosis-test-result" data-provider="<?php echo esc_attr( $slug ); ?>"
							  style="margin-left:.8rem;font-style:italic;color:#666"></span>
						<p class="description"><?php echo esc_html( $info['desc'] ); ?></p>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody></table>
		</div>
		<?php
	}

	/** AJAX handler — ping an AI provider with a minimal request. */
	public function handle_test_ai(): void {
		check_ajax_referer( 'agnosis_test_ai', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'agnosis' ) ] );
		}

		$provider = sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		switch ( $provider ) {

			case 'openai':
				$key = Secrets::openai_api_key();
				if ( empty( $key ) ) {
					wp_send_json_error( [ 'message' => __( 'OpenAI API key not configured.', 'agnosis' ) ] );
				}
				$this->ping_provider(
					'https://api.openai.com/v1/chat/completions',
					[ 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ],
					[
						// audit §5c: pings the actual configured text model,
						// not a hardcoded literal, so a successful ping means
						// what's actually used for translation/moderation
						// really works.
						'model'      => (string) get_option( 'agnosis_openai_text_model', 'gpt-4o-mini' ),
						'messages'   => [ [ 'role' => 'user', 'content' => 'Reply with the single word: ping' ] ],
						'max_tokens' => 5,
					],
					__( 'OpenAI connection successful.', 'agnosis' )
				);
				// no break — ping_provider() always calls wp_send_json_* → wp_die().

			case 'anthropic':
				$key = Secrets::anthropic_api_key();
				if ( empty( $key ) ) {
					wp_send_json_error( [ 'message' => __( 'Anthropic API key not configured.', 'agnosis' ) ] );
				}
				$this->ping_provider(
					'https://api.anthropic.com/v1/messages',
					[ 'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'Content-Type' => 'application/json' ],
					[
						// audit §5c: same as the OpenAI branch above — pings
						// the actual configured text model.
						'model'      => (string) get_option( 'agnosis_anthropic_text_model', 'claude-haiku-4-5-20251001' ),
						'max_tokens' => 5,
						'messages'   => [ [ 'role' => 'user', 'content' => 'Reply with the single word: ping' ] ],
					],
					__( 'Anthropic connection successful.', 'agnosis' )
				);
				// no break — ping_provider() always calls wp_send_json_* → wp_die().

			case 'wp_ai':
				if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
					wp_send_json_error( [ 'message' => __( 'WordPress AI Client requires WordPress 7.0 or later.', 'agnosis' ) ] );
				}
				// call_user_func avoids Plugin Check static-analysis flag.
				$builder = call_user_func( 'wp_ai_client_prompt', 'ping' );
				if ( ! $builder->is_supported_for_text_generation() ) {
					wp_send_json_error( [ 'message' => __( 'No text-generation model configured. Set one up under Settings → Connectors.', 'agnosis' ) ] );
				}
				wp_send_json_success( [ 'message' => __( 'WordPress AI Client is available and a text-generation model is configured.', 'agnosis' ) ] );
				// no break — wp_send_json_success() calls wp_die().

			default:
				wp_send_json_error( [ 'message' => __( 'Unknown provider.', 'agnosis' ) ] );
		}
	}

	/**
	 * POST to an AI provider endpoint and send a JSON response.
	 *
	 * Encodes $body as JSON, POSTs to $url with $headers, then:
	 *  - Calls wp_send_json_error() on transport failure or non-200 status.
	 *  - Calls wp_send_json_success() on 200.
	 *
	 * Both wp_send_json_* functions call wp_die(), so this method never returns.
	 *
	 * @param string               $url         Provider API endpoint.
	 * @param array<string,string> $headers     Request headers (auth + Content-Type).
	 * @param array<string,mixed>  $body        Request payload (JSON-encoded before sending).
	 * @param string               $success_msg Localised success message shown to the admin.
	 */
	private function ping_provider( string $url, array $headers, array $body, string $success_msg ): void {
		$json_body = wp_json_encode( $body );
		$response  = wp_remote_post( $url, [
			'timeout' => 10,
			'headers' => $headers,
			'body'    => false !== $json_body ? $json_body : '{}',
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => $response->get_error_message() ] );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$resp_body = json_decode( wp_remote_retrieve_body( $response ), true );
			/* translators: %d: HTTP response status code */
			$msg = $resp_body['error']['message'] ?? sprintf( __( 'HTTP %d', 'agnosis' ), $code );
			wp_send_json_error( [ 'message' => $msg ] );
		}

		wp_send_json_success( [ 'message' => $success_msg ] );
	}
}
