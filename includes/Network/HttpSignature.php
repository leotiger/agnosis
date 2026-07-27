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
 *     round-trip per day rather than one per request. Fetch FAILURES are also
 *     cached, separately and for 5 minutes, so a flood of requests bearing
 *     random/nonexistent keyIds can't force one outbound fetch per request
 *     (audit §3g note ii).
 *   • keyId↔actor binding: verify_actor_binding() ties the signing key's
 *     owner to the actor claimed inside the activity JSON (audit §3b).
 *   • verify_with_key() generalizes the same signature check for callers
 *     that already hold the caller-supplied public key inline (e.g. a peer
 *     node registration, audit §2d), instead of fetching it from a remote
 *     actor document.
 *
 * RFC 9421 HTTP Message Signatures — interaction-surface roadmap, Phase 3,
 * WP10 (§7 Q9a): verify() also accepts the newer `Signature-Input:` +
 * `Signature:` structured-field form a peer may send instead of (or, during
 * a real migration, alongside) the legacy header above — detected by the
 * presence of a `Signature-Input` header, which cavage never sends.
 * Deliberately scoped to `rsa-v1_5-sha256` against the SAME
 * `publicKey.publicKeyPem` fetch_public_key() already resolves: no actor
 * document in the deployed fediverse publishes an Ed25519 key today, and
 * verifying one is explicitly WP12/FASP's own concern, not this one — a
 * request signed with any other `alg` is rejected rather than silently
 * mis-verified. *Signing* stays cavage-only (§7 Q9a: that is what every
 * major implementation still speaks, and is not a defect); this is
 * inbound-only, so a peer that has migrated away from cavage is not
 * rejected the day it stops sending it.
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

	/**
	 * Transient TTL for cached key-fetch FAILURES (audit §3g note ii).
	 * fetch_public_key() previously cached only success — a flood of requests
	 * bearing random or nonexistent keyIds triggered one full outbound fetch
	 * each, an amplification vector against this server's own outbound
	 * request budget. Short relative to the success TTL: a remote actor
	 * document that's briefly unreachable shouldn't stay "poisoned" for long.
	 */
	private const KEY_NEGATIVE_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

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
	public static function verify( WP_REST_Request $request ): bool|WP_Error {
		// A `Signature-Input` header is unique to RFC 9421 — cavage never
		// sends one, so its mere presence is an unambiguous dispatch signal
		// (WP10, §7 Q9a).
		if ( '' !== (string) $request->get_header( 'signature-input' ) ) {
			return self::verify_rfc9421( $request );
		}

		$params = self::verify_preamble( $request );
		if ( is_wp_error( $params ) ) {
			return $params;
		}

		// ── 4. Fetch (and cache) the actor's RSA public key ───────────────────
		$public_key_pem = self::fetch_public_key( $params['keyId'] );
		if ( is_wp_error( $public_key_pem ) ) {
			return $public_key_pem;
		}

		return self::verify_signature( $request, $params, $public_key_pem );
	}

	/**
	 * Verify an HTTP Signature against a caller-supplied public key, rather
	 * than one fetched from a remote actor document.
	 *
	 * Runs the same parsing/freshness/digest checks as verify(), but skips
	 * step 4 (fetching a key from `keyId`) entirely — the caller already
	 * has the key because it arrived in the same request. Used by
	 * `Node::register_peer()` (audit §2d): a peer registration submits its
	 * own public key inline in the request body, so there is no remote
	 * actor document to dereference; the caller proves ownership of that
	 * exact key by signing the request with the matching private key. This
	 * is proof of possession only — a self-consistency check, not a
	 * domain-ownership proof — but it is exactly what closes the concrete
	 * abuse the audit named: a registration whose claimed key the requester
	 * does not actually hold.
	 *
	 * @param WP_REST_Request $request        Incoming request.
	 * @param string          $public_key_pem PEM-encoded RSA public key to verify against.
	 * @return true|WP_Error
	 */
	public static function verify_with_key( WP_REST_Request $request, string $public_key_pem ): bool|WP_Error {
		$params = self::verify_preamble( $request );
		if ( is_wp_error( $params ) ) {
			return $params;
		}

		return self::verify_signature( $request, $params, $public_key_pem );
	}

	/**
	 * Verify that the signing key belongs to the actor named in the activity.
	 *
	 * verify() proves the request was signed by the key at `keyId` — but
	 * nothing else ties that identity to the `actor` field inside the
	 * activity JSON the inbox then acts on. Without this binding, any
	 * fediverse account holder with a valid key on ANY server can send a
	 * correctly-signed request whose body claims someone else's actor id:
	 * a forged Follow subscribes the victim's inbox to every broadcast, and
	 * a forged Undo silently removes a real follower (audit §3b). Mastodon
	 * and friends guard exactly this by requiring the signing key's owner to
	 * match the activity's actor; this mirrors that rule.
	 *
	 * The keyId's base URL (fragment stripped) IS the actor document URL in
	 * every major implementation — Mastodon, Pleroma/Akkoma, GoToSocial, and
	 * Pixelfed all mint keyId as `<actor>#main-key` — so an exact match
	 * against the activity's actor id is both the simplest and the strictest
	 * correct comparison (the audit's "same-origin at minimum" floor would
	 * still allow same-server forgery; exact match closes that too).
	 *
	 * @param WP_REST_Request $request Incoming, already signature-verified request.
	 * @return true|WP_Error 401 WP_Error when the binding fails.
	 */
	public static function verify_actor_binding( WP_REST_Request $request ): bool|WP_Error {
		$key_owner = self::signing_key_owner( $request );

		// get_json_params() only parses when the Content-Type is a JSON media
		// type; fall back to the raw body so a peer sending a bare or unusual
		// Content-Type is still held to the same binding rule.
		$activity = $request->get_json_params();
		if ( ! is_array( $activity ) || [] === $activity ) {
			$activity = json_decode( $request->get_body(), true );
		}

		$actor = is_array( $activity ) ? ( $activity['actor'] ?? '' ) : '';
		if ( is_array( $actor ) ) {
			// The actor may legally be an embedded object; its id carries the claim.
			$actor = $actor['id'] ?? '';
		}

		if ( ! is_string( $actor ) || '' === $actor || '' === $key_owner || $key_owner !== $actor ) {
			return new WP_Error(
				'ap_actor_mismatch',
				__( 'Signature keyId does not belong to the activity actor.', 'agnosis' ),
				[ 'status' => 401 ]
			);
		}

		return true;
	}

	/**
	 * Extracts the base actor URL (fragment stripped) claimed by the incoming
	 * request's Signature header `keyId` — "who claims to have signed this,"
	 * independent of whether the signature actually verifies. '' when no
	 * Signature header (or no keyId within it) is present.
	 *
	 * Factored out of verify_actor_binding() so ActivityPub's audit §4a
	 * key-410 corroboration path (verify_inbox_signature()) can bind a
	 * claimed self-delete's actor to the SAME identity, without duplicating
	 * the parse — that binding is exactly as load-bearing there as it is
	 * here: it's what stops an attacker from pointing an unrelated,
	 * genuinely-410ing keyId at someone else's actor id in the activity body.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return string
	 */
	public static function signing_key_owner( WP_REST_Request $request ): string {
		$sig_input_header = (string) $request->get_header( 'signature-input' );
		if ( '' !== $sig_input_header ) {
			$parsed = self::parse_signature_input( $sig_input_header );
			return is_wp_error( $parsed ) ? '' : (string) strtok( (string) ( $parsed['params']['keyid'] ?? '' ), '#' );
		}

		$params = self::parse_signature_header( (string) $request->get_header( 'signature' ) );
		return (string) strtok( (string) ( $params['keyId'] ?? '' ), '#' );
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Shared steps 1–3 of signature verification: parse the Signature header,
	 * check date freshness, and verify the body digest when signed. Common to
	 * both verify() (fetches the key from keyId) and verify_with_key() (takes
	 * the key from the caller) — everything before "where does the public key
	 * come from" is identical between the two.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array<string, string>|WP_Error Parsed Signature-header params on success.
	 */
	private static function verify_preamble( WP_REST_Request $request ): array|WP_Error {
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

		// ── 1b. Signed-header strictness (audit §3g note i) ────────────────────
		// A signature legitimately minted over only "(request-target) host"
		// verifies cryptographically fine — but it silently defeats the
		// freshness check below (which only runs when a Date header happens to
		// be present) and the digest check in verify_preamble()'s step 3 (which
		// only runs when "digest" happens to be in the signed list). Mirror
		// Mastodon's own rule for symmetry with what we now send per §3a: every
		// signature must cover date (or its HTTP Signatures "(created)"
		// pseudo-header equivalent), and every POST's signature must also cover
		// digest, since that's the request class carrying a body worth binding
		// to the signature at all.
		$signed_headers = strtolower( $params['headers'] );
		if ( ! str_contains( $signed_headers, 'date' ) && ! str_contains( $signed_headers, '(created)' ) ) {
			return new WP_Error(
				'ap_sig_no_date',
				__( 'Signature must cover the Date header.', 'agnosis' ),
				[ 'status' => 401 ]
			);
		}
		if ( 'POST' === $request->get_method() && ! str_contains( $signed_headers, 'digest' ) ) {
			return new WP_Error(
				'ap_sig_no_digest',
				__( 'Signature on a POST request must cover the Digest header.', 'agnosis' ),
				[ 'status' => 401 ]
			);
		}

		// ── 2. Date freshness — prevents replay attacks ────────────────────────
		// Date is now required to be signed (above), so a missing Date header
		// is itself a rejection rather than a silent skip — previously a
		// signature naming "date" in its headers list but omitting the actual
		// header would fall through this check untested.
		$date_header  = (string) $request->get_header( 'date' );
		$request_time = '' !== $date_header ? strtotime( $date_header ) : false;
		if ( false === $request_time || abs( time() - $request_time ) > self::MAX_REQUEST_AGE ) {
			return new WP_Error(
				'ap_sig_stale',
				__( 'Request date is too old or too far in the future.', 'agnosis' ),
				[ 'status' => 401 ]
			);
		}

		// ── 3. Body digest ────────────────────────────────────────────────────
		if ( str_contains( strtolower( $params['headers'] ), 'digest' ) ) {
			$digest_err = self::verify_digest( $request );
			if ( is_wp_error( $digest_err ) ) {
				return $digest_err;
			}
		}

		return $params;
	}

	/**
	 * Shared steps 5–6: decode the signature value and verify it cryptographically
	 * against the given public key.
	 *
	 * @param WP_REST_Request      $request        Incoming request.
	 * @param array<string,string> $params         Parsed Signature-header params (from verify_preamble()).
	 * @param string               $public_key_pem PEM-encoded RSA public key to verify against.
	 * @return true|WP_Error
	 */
	private static function verify_signature( WP_REST_Request $request, array $params, string $public_key_pem ): bool|WP_Error {
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
	private static function verify_digest( WP_REST_Request $request ): bool|WP_Error {
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
	 * Sentinel stored in the negative cache (in place of an HTTP status) for
	 * a fetch that failed before any HTTP response existed at all — DNS,
	 * TLS, timeout, connection refused. Distinct from any real status code
	 * (which are always numeric), so a cached failure can always tell the
	 * two apart on a later read.
	 */
	private const NO_HTTP_RESPONSE = 'network';

	/**
	 * Fetch the actor's RSA public key PEM from the remote actor document.
	 *
	 * keyId is the actor URL with an optional fragment (e.g., #main-key).
	 * Results are cached as a transient to avoid hammering remote servers.
	 *
	 * A non-200 failure's WP_Error carries the real remote HTTP status as
	 * `remote_status` error data (audit §4a) — specifically so a caller can
	 * tell "the actor document returned 410 Gone" apart from any other
	 * failure reason. ActivityPub::verify_inbox_signature() uses this to
	 * corroborate a claimed self-Delete for an actor whose key can now never
	 * be fetched again: an attacker cannot forge a peer server's own 410
	 * response for a URL they don't control, so this is genuine evidence,
	 * not something a forged request body alone could fabricate. The
	 * negative cache (audit §3g note ii) stores the same status so a cached
	 * failure still carries it on a later read within the 5-minute window,
	 * rather than degrading to a generic "unknown reason" the second time.
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

		// Negative cache (audit §3g note ii) — checked before the outbound
		// fetch, distinct transient from the success cache above so a key that
		// starts resolving again isn't held back by a stale failure entry.
		$failure_cache_key = 'agnosis_ap_key_fail_' . md5( $key_id );
		$cached_failure    = get_transient( $failure_cache_key );
		if ( false !== $cached_failure ) {
			return new WP_Error(
				'ap_key_fetch_failed',
				__( 'Actor document could not be fetched (cached failure).', 'agnosis' ),
				[ 'status' => 502, 'remote_status' => self::cached_remote_status( $cached_failure ) ]
			);
		}

		// $actor_url is attacker-controlled (derived from the inbound Signature
		// header's keyId), so use the "safe" variant: it rejects private/
		// loopback/link-local/ULA targets, re-checked on every redirect hop
		// (audit §3b).
		$response = wp_safe_remote_get( $actor_url, [
			'headers' => [ 'Accept' => 'application/activity+json, application/ld+json' ],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			set_transient( $failure_cache_key, self::NO_HTTP_RESPONSE, self::KEY_NEGATIVE_CACHE_TTL );
			return new WP_Error(
				'ap_key_fetch_failed',
				__( 'Failed to fetch actor document.', 'agnosis' ),
				[ 'status' => 502, 'remote_status' => 0 ]
			);
		}

		$remote_status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $remote_status ) {
			set_transient( $failure_cache_key, (string) $remote_status, self::KEY_NEGATIVE_CACHE_TTL );
			return new WP_Error(
				'ap_key_fetch_failed',
				__( 'Actor document returned a non-200 response.', 'agnosis' ),
				[ 'status' => 502, 'remote_status' => $remote_status ]
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$pem  = $data['publicKey']['publicKeyPem'] ?? null;

		if ( ! is_string( $pem ) || '' === $pem ) {
			// A 200 with no usable key is never corroborating evidence of a
			// self-delete (the document demonstrably still exists) — cached
			// distinctly from a real non-200 status so it's never confused
			// with one on a later read.
			set_transient( $failure_cache_key, (string) $remote_status, self::KEY_NEGATIVE_CACHE_TTL );
			return new WP_Error(
				'ap_key_not_found',
				__( 'Actor document does not contain a publicKey.publicKeyPem field.', 'agnosis' ),
				[ 'status' => 502, 'remote_status' => $remote_status ]
			);
		}

		set_transient( $cache_key, $pem, self::KEY_CACHE_TTL );
		return $pem;
	}

	/**
	 * Parses a negative-cache transient value back into a `remote_status` for
	 * a cached-failure WP_Error — the numeric HTTP status when one was ever
	 * recorded, or 0 for NO_HTTP_RESPONSE (or anything else unexpected).
	 *
	 * @param mixed $cached_failure The transient's raw value.
	 * @return int
	 */
	private static function cached_remote_status( mixed $cached_failure ): int {
		return ( is_string( $cached_failure ) && ctype_digit( $cached_failure ) )
			? (int) $cached_failure
			: 0;
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

	// -------------------------------------------------------------------------
	// RFC 9421 (HTTP Message Signatures) — interaction-surface roadmap, WP10
	// -------------------------------------------------------------------------

	/** Only algorithm this class will verify under RFC 9421 — see the class docblock for why Ed25519 is deliberately out of scope here. */
	private const RFC9421_SUPPORTED_ALG = 'rsa-v1_5-sha256';

	/**
	 * Verify an RFC 9421 `Signature-Input:`/`Signature:` pair — the
	 * structured-field-based successor to the cavage draft verify()
	 * otherwise handles. Mirrors verify()'s own step order (parse → signed-
	 * component strictness → freshness → digest → fetch key → verify)
	 * exactly, just against RFC 9421's own header/parameter shapes.
	 *
	 * @param WP_REST_Request $request Incoming REST request.
	 * @return true|WP_Error
	 */
	private static function verify_rfc9421( WP_REST_Request $request ): bool|WP_Error {
		if ( ! function_exists( 'openssl_verify' ) ) {
			return new WP_Error(
				'ap_sig_openssl_missing',
				__( 'OpenSSL is required for HTTP Signature verification.', 'agnosis' ),
				[ 'status' => 501 ]
			);
		}

		$sig_input = self::parse_signature_input( (string) $request->get_header( 'signature-input' ) );
		if ( is_wp_error( $sig_input ) ) {
			return $sig_input;
		}

		$alg = $sig_input['params']['alg'] ?? '';
		if ( '' !== $alg && self::RFC9421_SUPPORTED_ALG !== $alg ) {
			return new WP_Error(
				'ap_sig_unsupported_alg',
				/* translators: %s: the unsupported signature algorithm name. */
				sprintf( __( 'Unsupported signature algorithm: %s.', 'agnosis' ), $alg ),
				[ 'status' => 400 ]
			);
		}

		$key_id = (string) ( $sig_input['params']['keyid'] ?? '' );
		if ( '' === $key_id ) {
			return new WP_Error(
				'ap_sig_malformed',
				__( 'Signature-Input header is missing keyid.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		// ── Freshness — the `created`/`expires` metadata parameters are RFC
		// 9421's own replacement for a signed Date header, same ±12h window
		// and same "must be present" strictness verify_preamble() enforces
		// for cavage's Date.
		$created = isset( $sig_input['params']['created'] ) && ctype_digit( (string) $sig_input['params']['created'] )
			? (int) $sig_input['params']['created']
			: 0;
		if ( 0 === $created || abs( time() - $created ) > self::MAX_REQUEST_AGE ) {
			return new WP_Error(
				'ap_sig_stale',
				__( 'Request date is too old or too far in the future.', 'agnosis' ),
				[ 'status' => 401 ]
			);
		}
		if ( isset( $sig_input['params']['expires'] ) && ctype_digit( (string) $sig_input['params']['expires'] ) && time() > (int) $sig_input['params']['expires'] ) {
			return new WP_Error(
				'ap_sig_stale',
				__( 'Signature has expired.', 'agnosis' ),
				[ 'status' => 401 ]
			);
		}

		// ── Signed-component strictness (mirrors verify_preamble()'s 1b) —
		// every POST's signature must cover content-digest, since that's the
		// request class carrying a body worth binding to the signature.
		$covered = array_map( 'strtolower', $sig_input['components'] );
		if ( 'POST' === $request->get_method() && ! in_array( 'content-digest', $covered, true ) ) {
			return new WP_Error(
				'ap_sig_no_digest',
				__( 'Signature on a POST request must cover the Content-Digest field.', 'agnosis' ),
				[ 'status' => 401 ]
			);
		}
		if ( in_array( 'content-digest', $covered, true ) ) {
			$digest_err = self::verify_content_digest( $request );
			if ( is_wp_error( $digest_err ) ) {
				return $digest_err;
			}
		}

		$signature_b64 = self::parse_signature_dict( (string) $request->get_header( 'signature' ), $sig_input['label'] );
		if ( null === $signature_b64 ) {
			return new WP_Error(
				'ap_sig_malformed',
				__( 'Signature header does not contain a value for the Signature-Input label.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		$raw_signature = base64_decode( $signature_b64, true );
		if ( false === $raw_signature || '' === $raw_signature ) {
			return new WP_Error(
				'ap_sig_malformed',
				__( 'Signature value is not valid base64.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		// ── Fetch (and cache) the actor's RSA public key — the exact same
		// helper and cache cavage verification already uses; keyid means the
		// same thing (the actor document URL, optionally with a #fragment)
		// in both schemes.
		$public_key_pem = self::fetch_public_key( $key_id );
		if ( is_wp_error( $public_key_pem ) ) {
			return $public_key_pem;
		}

		$signature_base = self::build_rfc9421_signature_base( $request, $sig_input['components'], $sig_input['raw_params'] );
		$result         = openssl_verify( $signature_base, $raw_signature, $public_key_pem, OPENSSL_ALGO_SHA256 );

		if ( 1 !== $result ) {
			return new WP_Error(
				'ap_sig_invalid',
				__( 'HTTP Signature verification failed.', 'agnosis' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Parse a `Signature-Input` header into its label, covered-component
	 * list, and parameters — a light, single-signature parser (first label
	 * found) rather than a general RFC 8941 structured-field parser,
	 * matching parse_signature_header()'s own "just enough for what real
	 * fediverse servers send" scope.
	 *
	 * @param string $header Raw `Signature-Input` header value.
	 * @return array{label: string, components: array<int, string>, params: array<string, string>, raw_params: string}|WP_Error
	 */
	private static function parse_signature_input( string $header ): array|WP_Error {
		if ( ! preg_match( '/^\s*([\w-]+)=(\([^)]*\)(?:;[\w-]+=(?:"[^"]*"|[^;,]+))*)/', trim( $header ), $m ) ) {
			return new WP_Error(
				'ap_sig_malformed',
				__( 'Signature-Input header is malformed.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		$label      = $m[1];
		$raw_params = $m[2];

		preg_match( '/^\(([^)]*)\)/', $raw_params, $list_match );
		preg_match_all( '/"([^"]+)"/', $list_match[1] ?? '', $component_matches );
		$components = $component_matches[1];

		if ( [] === $components ) {
			return new WP_Error(
				'ap_sig_malformed',
				__( 'Signature-Input covers no components.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		$params = [];
		preg_match_all( '/;([\w-]+)=(?:"([^"]*)"|([^;,]+))/', $raw_params, $param_matches, PREG_SET_ORDER );
		foreach ( $param_matches as $p ) {
			$params[ $p[1] ] = '' !== ( $p[2] ?? '' ) ? $p[2] : ( $p[3] ?? '' );
		}

		return [ 'label' => $label, 'components' => $components, 'params' => $params, 'raw_params' => $raw_params ];
	}

	/**
	 * Extract one label's base64 signature value out of a `Signature` header
	 * formatted as an RFC 8941 structured-field dictionary of byte sequences
	 * (`sig1=:base64:`, possibly several comma-separated labels) — the
	 * RFC 9421 counterpart of parse_signature_header()'s cavage parsing.
	 *
	 * @param string $header Raw `Signature` header value.
	 * @param string $label  The label named by this request's Signature-Input.
	 * @return string|null Base64 signature bytes, or null if the label isn't present.
	 */
	private static function parse_signature_dict( string $header, string $label ): ?string {
		if ( ! preg_match( '/(?:^|,)\s*' . preg_quote( $label, '/' ) . '=:([^:]*):/', $header, $m ) ) {
			return null;
		}
		return $m[1];
	}

	/**
	 * Verify a `Content-Digest` header (RFC 9530) against the raw request
	 * body — RFC 9421's own replacement for cavage's `Digest` header,
	 * structured as `sha-256=:base64:` rather than `SHA-256=base64`.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return true|WP_Error
	 */
	private static function verify_content_digest( WP_REST_Request $request ): bool|WP_Error {
		$header = (string) $request->get_header( 'content-digest' );
		if ( ! preg_match( '/sha-256=:([^:]*):/', $header, $m ) ) {
			return new WP_Error(
				'ap_sig_digest',
				__( 'Content-Digest header does not contain a sha-256 value.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		$expected_digest = base64_encode( hash( 'sha256', $request->get_body(), true ) );
		if ( ! hash_equals( $expected_digest, $m[1] ) ) {
			return new WP_Error(
				'ap_sig_digest',
				__( 'Content-Digest header does not match the request body.', 'agnosis' ),
				[ 'status' => 400 ]
			);
		}

		return true;
	}

	/**
	 * Reconstruct the RFC 9421 "signature base" — one `"<component>": <value>`
	 * line per covered component (§2.5), followed by a final
	 * `"@signature-params": <...>` line whose value is the SAME raw
	 * component-list-and-parameters substring that appeared in
	 * `Signature-Input` (byte-exact, per spec — reusing the captured raw
	 * string rather than re-serializing avoids any risk of the two drifting).
	 *
	 * @param WP_REST_Request      $request    Incoming request.
	 * @param array<int, string>   $components Covered component identifiers, in order.
	 * @param string               $raw_params The exact `(...)…` substring from Signature-Input.
	 * @return string
	 */
	private static function build_rfc9421_signature_base( WP_REST_Request $request, array $components, string $raw_params ): string {
		$lines = [];

		foreach ( $components as $component ) {
			$lines[] = '"' . strtolower( $component ) . '": ' . self::rfc9421_component_value( $request, strtolower( $component ) );
		}

		$lines[] = '"@signature-params": ' . $raw_params;

		return implode( "\n", $lines );
	}

	/**
	 * Resolve one RFC 9421 covered-component identifier to its signed value —
	 * the small set of "derived components" (§2.2, `@`-prefixed) real
	 * fediverse signers actually cover, plus ordinary HTTP fields (looked up
	 * the same way build_signing_string() already looks up cavage's signed
	 * headers).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param string           $name    Lowercase component identifier.
	 * @return string
	 */
	private static function rfc9421_component_value( WP_REST_Request $request, string $name ): string {
		switch ( $name ) {
			case '@method':
				return strtoupper( $request->get_method() );
			case '@target-uri':
				// Build as home_url() + <rest-prefix><route> so it works
				// regardless of whether pretty permalinks are enabled — same
				// reasoning as build_signing_string()'s own (request-target).
				return home_url( '/' . rest_get_url_prefix() . $request->get_route() );
			case '@authority':
				return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
			case '@path':
				return '/' . rest_get_url_prefix() . $request->get_route();
			case '@scheme':
				return (string) ( wp_parse_url( home_url(), PHP_URL_SCHEME ) ?: 'https' );
			default:
				return (string) $request->get_header( $name );
		}
	}
}
