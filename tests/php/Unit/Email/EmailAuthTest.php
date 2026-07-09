<?php
/**
 * Unit tests for EmailAuth.
 *
 * Covers header parsing, Mailgun payload extraction, and the passes() gate.
 * All tests are pure-PHP (no WordPress functions required).
 *
 * @package Agnosis\Tests\Unit\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Email;

use Agnosis\Email\EmailAuth;
use PHPUnit\Framework\TestCase;

class EmailAuthTest extends TestCase {

	// -------------------------------------------------------------------------
	// check_header()
	// -------------------------------------------------------------------------

	public function test_extracts_spf_pass(): void {
		$header = 'mx.example.com; spf=pass smtp.mailfrom=artist@example.com';
		$result = EmailAuth::check_header( $header );
		$this->assertSame( 'pass', $result['spf'] );
	}

	public function test_extracts_dkim_pass(): void {
		$header = 'mx.example.com; dkim=pass header.d=example.com';
		$result = EmailAuth::check_header( $header );
		$this->assertSame( 'pass', $result['dkim'] );
	}

	public function test_extracts_dmarc_pass(): void {
		$header = 'mx.example.com; dmarc=pass policy.dmarc=reject';
		$result = EmailAuth::check_header( $header );
		$this->assertSame( 'pass', $result['dmarc'] );
	}

	public function test_extracts_all_three_mechanisms(): void {
		$header = 'mx.example.com; spf=pass smtp.mailfrom=a@b.com; dkim=pass header.d=b.com; dmarc=pass';
		$result = EmailAuth::check_header( $header );
		$this->assertSame( 'pass', $result['spf'] );
		$this->assertSame( 'pass', $result['dkim'] );
		$this->assertSame( 'pass', $result['dmarc'] );
	}

	public function test_returns_empty_string_for_absent_mechanism(): void {
		$header = 'mx.example.com; spf=pass';
		$result = EmailAuth::check_header( $header );
		$this->assertSame( '', $result['dkim'] );
		$this->assertSame( '', $result['dmarc'] );
	}

	public function test_extracts_spf_fail(): void {
		$header = 'mx.example.com; spf=fail smtp.mailfrom=attacker@evil.com; dkim=fail';
		$result = EmailAuth::check_header( $header );
		$this->assertSame( 'fail', $result['spf'] );
		$this->assertSame( 'fail', $result['dkim'] );
	}

	public function test_extracts_spf_softfail(): void {
		$header = 'mx.example.com; spf=softfail';
		$result = EmailAuth::check_header( $header );
		$this->assertSame( 'softfail', $result['spf'] );
	}

	public function test_is_case_insensitive(): void {
		$header = 'mx.example.com; SPF=Pass DKIM=PASS';
		$result = EmailAuth::check_header( $header );
		$this->assertSame( 'pass', $result['spf'] );
		$this->assertSame( 'pass', $result['dkim'] );
	}

	public function test_empty_header_returns_all_empty(): void {
		$result = EmailAuth::check_header( '' );
		$this->assertSame( '', $result['spf'] );
		$this->assertSame( '', $result['dkim'] );
		$this->assertSame( '', $result['dmarc'] );
	}

	// -------------------------------------------------------------------------
	// passes()
	// -------------------------------------------------------------------------

	public function test_passes_when_spf_pass(): void {
		$this->assertTrue( EmailAuth::passes( [ 'spf' => 'pass', 'dkim' => 'fail', 'dmarc' => '' ] ) );
	}

	public function test_passes_when_dkim_pass(): void {
		$this->assertTrue( EmailAuth::passes( [ 'spf' => 'fail', 'dkim' => 'pass', 'dmarc' => '' ] ) );
	}

	public function test_passes_when_both_pass(): void {
		$this->assertTrue( EmailAuth::passes( [ 'spf' => 'pass', 'dkim' => 'pass', 'dmarc' => 'pass' ] ) );
	}

	public function test_fails_when_neither_spf_nor_dkim_passes(): void {
		$this->assertFalse( EmailAuth::passes( [ 'spf' => 'fail', 'dkim' => 'fail', 'dmarc' => 'pass' ] ) );
	}

	public function test_fails_when_all_empty(): void {
		$this->assertFalse( EmailAuth::passes( [ 'spf' => '', 'dkim' => '', 'dmarc' => '' ] ) );
	}

	public function test_fails_when_softfail(): void {
		// softfail is not pass — should be rejected.
		$this->assertFalse( EmailAuth::passes( [ 'spf' => 'softfail', 'dkim' => 'neutral', 'dmarc' => '' ] ) );
	}

	// -------------------------------------------------------------------------
	// extract_from_mailgun_payload()
	// -------------------------------------------------------------------------

	public function test_extracts_auth_header_from_mailgun_json_string(): void {
		$value   = 'mx.mailgun.org; spf=pass; dkim=pass header.d=example.com';
		$payload = [
			'message-headers' => json_encode( [
				[ 'Received', 'from mail.example.com' ],
				[ 'Authentication-Results', $value ],
				[ 'Subject', 'Test' ],
			] ),
		];

		$result = EmailAuth::extract_from_mailgun_payload( $payload );
		$this->assertSame( $value, $result );
	}

	public function test_extracts_auth_header_from_mailgun_array(): void {
		$value   = 'mx.mailgun.org; spf=pass; dkim=pass';
		$payload = [
			'message-headers' => [
				[ 'From', 'artist@example.com' ],
				[ 'Authentication-Results', $value ],
			],
		];

		$result = EmailAuth::extract_from_mailgun_payload( $payload );
		$this->assertSame( $value, $result );
	}

	public function test_returns_empty_when_no_auth_header_in_payload(): void {
		$payload = [
			'message-headers' => json_encode( [
				[ 'Subject', 'No auth here' ],
			] ),
		];

		$this->assertSame( '', EmailAuth::extract_from_mailgun_payload( $payload ) );
	}

	public function test_returns_empty_when_message_headers_absent(): void {
		$this->assertSame( '', EmailAuth::extract_from_mailgun_payload( [] ) );
	}

	public function test_returns_empty_when_message_headers_invalid_json(): void {
		$payload = [ 'message-headers' => 'not-json{{{' ];
		$this->assertSame( '', EmailAuth::extract_from_mailgun_payload( $payload ) );
	}

	public function test_header_name_match_is_case_insensitive(): void {
		$value   = 'mx.example.com; spf=pass';
		$payload = [
			'message-headers' => [
				[ 'authentication-results', $value ],
			],
		];

		$result = EmailAuth::extract_from_mailgun_payload( $payload );
		$this->assertSame( $value, $result );
	}

	// -------------------------------------------------------------------------
	// extract_mailgun_header() — the generalised form (fourth audit §3c), added
	// so Webhook's Auto-Submitted mail-loop guard could reuse this parsing
	// instead of duplicating it. extract_from_mailgun_payload() above is now a
	// thin wrapper over this — every test above still passes unchanged.
	// -------------------------------------------------------------------------

	public function test_extract_mailgun_header_finds_an_arbitrary_header(): void {
		$payload = [
			'message-headers' => [
				[ 'From', 'artist@example.com' ],
				[ 'Auto-Submitted', 'auto-replied' ],
			],
		];

		$this->assertSame( 'auto-replied', EmailAuth::extract_mailgun_header( $payload, 'auto-submitted' ) );
	}

	public function test_extract_mailgun_header_is_case_insensitive_on_header_name(): void {
		$payload = [
			'message-headers' => [
				[ 'AUTO-SUBMITTED', 'auto-replied' ],
			],
		];

		$this->assertSame( 'auto-replied', EmailAuth::extract_mailgun_header( $payload, 'auto-submitted' ) );
	}

	public function test_extract_mailgun_header_returns_empty_when_not_present(): void {
		$payload = [
			'message-headers' => [
				[ 'Subject', 'No Auto-Submitted here' ],
			],
		];

		$this->assertSame( '', EmailAuth::extract_mailgun_header( $payload, 'auto-submitted' ) );
	}
}
