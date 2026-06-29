<?php
/**
 * Email authentication helper.
 *
 * Parses the `Authentication-Results` header produced by receiving MTAs and
 * inbound ESPs (Mailgun, Postmark, SES) to extract SPF, DKIM, and DMARC
 * verdicts. Used to gate email intake when `agnosis_require_email_auth` is on.
 *
 * Format (RFC 8601):
 *   Authentication-Results: mx.example.com;
 *     spf=pass smtp.mailfrom=artist@example.com;
 *     dkim=pass header.d=example.com;
 *     dmarc=pass policy.dmarc=reject
 *
 * This class only reads and parses — it never modifies state.
 *
 * @package Agnosis\Email
 */

declare(strict_types=1);

namespace Agnosis\Email;

class EmailAuth {

	/**
	 * Parse an `Authentication-Results` header string into verdicts.
	 *
	 * Returns an array with keys 'spf', 'dkim', 'dmarc'. Each value is the
	 * verdict string ('pass', 'fail', 'softfail', 'neutral', 'none', 'permerror',
	 * 'temperror') or '' when the mechanism was not present in the header.
	 *
	 * @param string $header Raw `Authentication-Results` header value (one or more lines).
	 * @return array{spf: string, dkim: string, dmarc: string}
	 */
	public static function check_header( string $header ): array {
		return [
			'spf'  => self::extract_verdict( $header, 'spf' ),
			'dkim' => self::extract_verdict( $header, 'dkim' ),
			'dmarc' => self::extract_verdict( $header, 'dmarc' ),
		];
	}

	/**
	 * Extract the `Authentication-Results` header value from a Mailgun webhook
	 * payload.
	 *
	 * Mailgun encodes all original headers as a JSON array under the key
	 * `message-headers`, where each element is a two-element array [name, value].
	 * This method finds the first `Authentication-Results` entry and returns its
	 * value, or '' when absent.
	 *
	 * @param array<string, mixed> $payload Decoded Mailgun webhook POST body.
	 * @return string Raw header value, or '' when not present.
	 */
	public static function extract_from_mailgun_payload( array $payload ): string {
		$raw = $payload['message-headers'] ?? '';

		if ( is_string( $raw ) ) {
			$headers = json_decode( $raw, true );
		} elseif ( is_array( $raw ) ) {
			$headers = $raw;
		} else {
			return '';
		}

		if ( ! is_array( $headers ) ) {
			return '';
		}

		foreach ( $headers as $pair ) {
			if ( is_array( $pair ) && count( $pair ) >= 2
				&& strtolower( (string) $pair[0] ) === 'authentication-results'
			) {
				return (string) $pair[1];
			}
		}

		return '';
	}

	/**
	 * Return true when at least SPF or DKIM passes.
	 *
	 * DMARC is the strongest signal (it aggregates SPF + DKIM alignment), but
	 * many legitimate senders do not yet publish a DMARC record. Requiring
	 * DMARC-pass alone would block too much real mail. Requiring at least one of
	 * SPF or DKIM gives a meaningful filter while remaining broadly compatible.
	 *
	 * @param array{spf: string, dkim: string, dmarc: string} $verdicts
	 */
	public static function passes( array $verdicts ): bool {
		return $verdicts['spf'] === 'pass' || $verdicts['dkim'] === 'pass';
	}

	// -------------------------------------------------------------------------

	/**
	 * Extract a single mechanism verdict from a raw Authentication-Results string.
	 *
	 * Matches `mechanism=verdict` where mechanism is a whole word boundary match
	 * so 'spf' does not accidentally match inside 'dspf'.
	 *
	 * @param string $header    Full header value.
	 * @param string $mechanism One of 'spf', 'dkim', 'dmarc'.
	 * @return string Verdict ('pass', 'fail', etc.) or '' when not found.
	 */
	private static function extract_verdict( string $header, string $mechanism ): string {
		if ( preg_match( '/\b' . preg_quote( $mechanism, '/' ) . '=(\w+)/i', $header, $m ) ) {
			return strtolower( $m[1] );
		}
		return '';
	}
}
