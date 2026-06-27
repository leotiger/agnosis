<?php
/**
 * HTTP Signature verification for incoming ActivityPub requests.
 *
 * Implements the subset of the HTTP Signatures draft spec used by Mastodon,
 * Pixelfed, and the broader Fediverse:
 *
 *   • Signature header fields: keyId, headers, signature (algorithm ignored —
 *     RSA-SHA256 is assumed, which all major Fediverse servers use).
 *   • Signed headers supported: (request-target), host, date, digest.
 *   • Body integrity: Digest: SHA-256=<base64> verified when included.
 *   • Replay protection: Date header must be within ±12 hours of server time.
 *   • Key caching: remote actor document is fetched once and cached for 24 h
 *     in a transient, so repeated requests from the same actor cost one HTTP
 *     round-trip per day rather than one per request.
 *
 * @package Agnosis\Network
 */

declare(strict_types=1);

namespace Agnosis\Network;

use WP_Error;
use WP_REST_Request;

class HttpSignature {

	/** Transient TTL for cached actor public keys. */
	private const KEY_CACHE_TTL = DAY_IN_SECONDS;

	/** Maximum signed-request age in seconds (±12 hours). */
	private const MAX_REQUEST_AGE = 43200;

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Verify the HTTP Signature on an incoming ActivityPub request.
	 *
	 * Error status codes are chosen to align with what Fediverse servers expect:
	 *   401 — signature absent or request date outside the freshness window
	 *   400 — signature header malformed or body digest does not match
	 *   403 — signature present but cryptographically invalid
	 *   501 — openssl extension not available on this server
	 *   502 — remote actor document could not be fetched
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return true|WP_Error
	 */
	public static function verify( WP_REST_Request $request ): true|WP_Error {
		if ( ! function_exists( 'openssl_verify' ) ) {
			return new WP_Error(
				'ap_sig_openssl_missing',
				__( 'OpenSSL is required for HTTP Signature verification.', 'agnosis' ),
				[ 'status' => 501 ]
			);
		}

		// ── 1. Parse the Signature header ─────────────────────────────────────
		$sig_header = (string) $request->get_header( 'signature' );
		if ( '' === $sig_header ) {
			return new WP_Error(
				'ap_sig_missing',
				__( 'Signature header is required.', 'agnosis' ),
				[ 'status' => 401 ]
			);
		}

		$params = self::parse_signature_header( $sig_header );
		if ( ! isset( $params['keyId'], $params['headers'], $params['signature'] ) ) {
			return new WP_Error(
				'ap_sig_malformed',
				__( 'Signature header is missing required fields (keyId, headers, signature).', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		// ── 2. Date freshness — prevents replay attacks ────────────────────────
		$date_header = (string) $request->get_header( 'date' );
		if ( '' !== $date_header ) {
			$request_time = strtotime( $date_header );
			if ( false === $request_time || abs( time() - $request_time ) > self::MAX_REQUEST_AGE ) {
				return new WP_Error(
					'ap_sig_stale',
					__( 'Request date is too old or too far in the future.', 'agnosis' ),
					[ 'status' => 401 ]
				);
			}
		}

		// ── 3. Body digest ────────────────────────────────────────────────────
		if ( str_contains( strtolower( $params['headers'] ), 'digest' ) ) {
			$digest_err = self::verify_digest( $request );
			if ( is_wp_error( $digest_err ) ) {
				return $digest_err;
			}
		}

		// ── 4. Fetch (and cache) the actor's RSA public key ───────────────────
		$public_key_pem = self::fetch_public_key( $params['keyId'] );
		if ( is_wp_error( $public_key_pem ) ) {
			return $public_key_pem;
		}

		// ── 5. Decode the signature value ─────────────────────────────────────
		$raw_signature = base64_decode( $params['signature'], true );
		if ( false === $raw_signature || '' === $raw_signature ) {
			return new WP_Error(
				'ap_sig_malformed',
				__( 'Signature value is not valid base64.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		// ── 6. Reconstruct signing string and verify RSA-SHA256 ───────────────
		$signing_string = self::build_signing_string( $request, $params['headers'] );
		$result         = openssl_verify( $signing_string, $raw_signature, $public_key_pem, OPENSSL_ALGO_SHA256 );

		if ( 1 !== $result ) {
			return new WP_Error(
				'ap_sig_invalid',
				__( 'HTTP Signature verification failed.', 'agnosis' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Parse the key=value pairs from a Signature header value.
	 *
	 * Mastodon format:
	 *   keyId="https://…#main-key",algorithm="rsa-sha256",headers="(request-target) host date digest",signature="…"
	 *
	 * @return array<string, string>
	 */
	private static function parse_signature_header( string $header ): array {
		$params = [];
		preg_match_all( '/(\w+)="([^"]*)"/', $header, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$params[ $match[1] ] = $match[2];
		}
		return $params;
	}

	/**
	 * Verify the Digest header against the raw request body.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	private static function verify_digest( WP_REST_Request $request ): true|WP_Error {
		$digest_header   = (string) $request->get_header( 'digest' );
		$expected_digest = 'SHA-256=' . base64_encode( hash( 'sha256', $request->get_body(), true ) );

		if ( ! hash_equals( $expected_digest, $digest_header ) ) {
			return new WP_Error(
				'ap_sig_digest',
				__( 'Digest header does not match the request body.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Fetch the actor's RSA public key PEM from the remote actor document.
	 *
	 * keyId is the actor URL with an optional fragment (e.g., #main-key).
	 * Results are cached as a transient to avoid hammering remote servers.
	 *
	 * @param  string $key_id Full keyId URL from the Signature header.
	 * @return string|WP_Error PEM string on success; WP_Error on failure.
	 */
	private static function fetch_public_key( string $key_id ): string|WP_Error {
		// The actor document lives at the base URL without the fragment.
		$actor_url = (string) strtok( $key_id, '#' );
		if ( empty( $actor_url ) ) {
			return new WP_Error(
				'ap_key_invalid_id',
				__( 'keyId in Signature header is not a valid URL.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		$cache_key = 'agnosis_ap_key_' . md5( $key_id );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get( $actor_url, [
			'headers' => [ 'Accept' => 'application/activity+json, application/ld+json' ],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'ap_key_fetch_failed',
				__( 'Failed to fetch actor document.', 'agnosis' ),
				[ 'status' => 502 ]
			);
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error(
				'ap_key_fetch_failed',
				__( 'Actor document returned a non-200 response.', 'agnosis' ),
				[ 'status' => 502 ]
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$pem  = $data['publicKey']['publicKeyPem'] ?? null;

		if ( ! is_string( $pem ) || '' === $pem ) {
			return new WP_Error(
				'ap_key_not_found',
				__( 'Actor document does not contain a publicKey.publicKeyPem field.', 'agnosis' ),
				[ 'status' => 502 ]
			);
		}

		set_transient( $cache_key, $pem, self::KEY_CACHE_TTL );
		return $pem;
	}

	/**
	 * Reconstruct the signing string from the request headers.
	 *
	 * The `headers` field lists space-separated, lowercase header names in the
	 * order they were included when the remote server signed the request.
	 * `(request-target)` is a pseudo-header: `post /wp-json/...`.
	 *
	 * @param WP_REST_Request $request     Incoming request.
	 * @param string          $headers_str Space-separated header names.
	 * @return string Newline-joined signing string.
	 */
	private static function build_signing_string( WP_REST_Request $request, string $headers_str ): string {
		$parts = [];

		foreach ( explode( ' ', strtolower( trim( $headers_str ) ) ) as $header_name ) {
			if ( '(request-target)' === $header_name ) {
				// Build the path as /<rest-prefix><route> so it works regardless of
				// whether pretty permalinks are enabled (rest_url() returns an index.php
				// query-string URL in plain-permalink environments).
				$path    = '/' . rest_get_url_prefix() . $request->get_route();
				$parts[] = '(request-target): post ' . $path;
			} else {
				$parts[] = $header_name . ': ' . (string) $request->get_header( $header_name );
			}
		}

		return implode( "\n", $parts );
	}
}
