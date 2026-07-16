<?php
/**
 * Deliverability posture check (security audit §5c).
 *
 * The plugin lets an operator configure three From identities (community
 * transactional mail, newsletter digests, and the admin_email fallback both
 * ultimately use) but never checked whether the site can actually send
 * *legitimately* as them. On default hosting (PHP mail(), no SMTP plugin),
 * mail from e.g. hello@agnosis.art sent by a shared host fails SPF alignment
 * and lands in spam — and since Gmail/Yahoo's 2024 bulk-sender rules,
 * unauthenticated bulk mail is increasingly rejected outright rather than
 * just filtered.
 *
 * This class is a read-only diagnostic surface for Settings → Email Inbox's
 * "Deliverability" health card — it never changes sending behavior, headers,
 * or configuration. It reports:
 *   - whether each configured From identity's domain matches the site's own
 *     domain (a third-party domain sending on the site's behalf is a much
 *     more common, and more forgivable, SPF-alignment failure than the
 *     reverse);
 *   - whether the From domain publishes an SPF TXT record and a DMARC record
 *     at all (their *correctness* — do they actually authorize this host —
 *     is out of scope; a plugin has no way to know a shared host's outbound
 *     IP without an actual test send, and even that's inconclusive);
 *   - whether a DKIM record exists under a COMMON selector (dkim_check()) —
 *     best-effort only; unlike SPF (bare domain) and DMARC (fixed `_dmarc.`
 *     subdomain), a DKIM record's location depends on a selector the sending
 *     provider chooses, which this plugin has no way to know for certain.
 *     "Not found" here means "not found under any of the selectors we
 *     guessed", never "DKIM isn't configured" — see dkim_check()'s own
 *     docblock, including the `agnosis_deliverability_dkim_selectors` filter
 *     for a site that knows its own selector;
 *   - whether the domain is listed on Spamhaus's Domain Block List
 *     (dbl_check()) — a real-world confirmed cause of "the email sent fine
 *     but never arrived", especially for a domain still building sending
 *     reputation;
 *   - whether a known SMTP-sending plugin is active, since that's the
 *     single most common fix for PHP mail()'s poor deliverability.
 *
 * DKIM and DBL checks are cached for an hour (see cached()) — DKIM's
 * selector-guessing issues several sequential DNS queries per identity, and
 * Spamhaus's DNSBL is a shared public resource with its own fair-use rate
 * limits; neither should be re-queried on every single admin page load.
 *
 * @package Agnosis\Admin
 */

declare(strict_types=1);

namespace Agnosis\Admin;

use Agnosis\Core\CommunityMailer;
use Agnosis\Newsletter\Mailer as NewsletterMailer;

class Deliverability {

	/**
	 * Known SMTP-plugin footprints, checked via a class/constant that only
	 * exists once that specific plugin has actually loaded — more reliable
	 * than is_plugin_active() (which needs the exact plugin-file path and
	 * says nothing about a must-use or manually-loaded setup) for a purely
	 * diagnostic "is something already handling this" signal.
	 *
	 * @return array<string, callable(): bool>
	 */
	private static function smtp_plugin_detectors(): array {
		$detectors = [
			'WP Mail SMTP'   => static fn(): bool => class_exists( '\WPMailSMTP\Core' ),
			'Post SMTP'      => static fn(): bool => class_exists( '\PostmanOptions' ) || defined( 'POST_SMTP_VERSION' ),
			'FluentSMTP'     => static fn(): bool => defined( 'FLUENTMAIL' ) || class_exists( '\FluentMail\App\Hooks\Handlers\Mailer' ),
			'Easy WP SMTP'   => static fn(): bool => class_exists( '\EasyWPSMTP\Core' ),
			'WP Offload SES' => static fn(): bool => class_exists( '\DeliciousBrains\WPOffloadSES\Core' ),
			'Sendinblue/Brevo' => static fn(): bool => class_exists( '\MailinBlue\WoocommerceMailinblue\WoocommerceMailinBlue' ) || defined( 'SIB_PLUGIN_VERSION' ),
			'Mailgun'        => static fn(): bool => function_exists( 'mailgun' ) || class_exists( '\Mailgun_Config' ),
		];

		/**
		 * Filters the SMTP-plugin detector map — lets a site (or a test)
		 * register additional detectors, or override the built-in ones,
		 * without needing a real class from the target plugin to be loaded.
		 *
		 * @param array<string, callable(): bool> $detectors Name => detector callable.
		 */
		return apply_filters( 'agnosis_deliverability_smtp_plugin_detectors', $detectors );
	}

	/**
	 * The first known SMTP plugin detected active, or null when none of the
	 * known footprints match (the site may still have server-level SMTP
	 * configured — e.g. a custom mu-plugin or php.ini sendmail_path pointing
	 * at a real relay — this can only ever detect the well-known plugin lane).
	 */
	public static function detected_smtp_plugin(): ?string {
		foreach ( self::smtp_plugin_detectors() as $name => $detect ) {
			if ( $detect() ) {
				return $name;
			}
		}
		return null;
	}

	/** Lowercased domain portion of an email address, or '' if unparseable. */
	private static function domain_of( string $email ): string {
		$at = strrpos( $email, '@' );
		return false !== $at ? strtolower( substr( $email, $at + 1 ) ) : '';
	}

	/**
	 * The site's own domain, from home_url() — what a From address is
	 * compared against.
	 *
	 * Filterable for the same reason the DNS lookup and SMTP-detector map
	 * are: some environments (notably a bare WP_TESTS_DOMAIN of "localhost",
	 * a single-label host with no TLD) resolve home_url() to something
	 * is_email() itself would reject as a domain, which has nothing to do
	 * with the comparison logic under test.
	 */
	public static function site_domain(): string {
		$domain = strtolower( (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: '' ) );

		/**
		 * Filters the site domain Deliverability::identity_report() compares
		 * each From identity's domain against.
		 *
		 * @param string $domain The domain derived from home_url().
		 */
		return (string) apply_filters( 'agnosis_deliverability_site_domain', $domain );
	}

	/**
	 * Raw TXT record lookup for a host.
	 *
	 * @return array{status: string, records: string[]} status is one of
	 *         'found', 'not_found', 'lookup_failed' (DNS query itself
	 *         errored), or 'unavailable' (dns_get_record() doesn't exist —
	 *         some hosts disable it).
	 */
	private static function txt_lookup( string $host ): array {
		if ( '' === $host ) {
			return [ 'status' => 'unavailable', 'records' => [] ];
		}

		// Test/integration seam: dns_get_record() has no WordPress core
		// filter of its own (unlike wp_remote_get()'s pre_http_request), so
		// this short-circuits the real DNS call entirely when filtered to a
		// non-null value — lets tests supply canned results instead of
		// depending on live, possibly network-isolated, DNS resolution for a
		// purely diagnostic admin-page feature.
		$filtered = apply_filters( 'agnosis_deliverability_dns_txt', null, $host );
		if ( null !== $filtered ) {
			return $filtered;
		}

		if ( ! function_exists( 'dns_get_record' ) ) {
			return [ 'status' => 'unavailable', 'records' => [] ];
		}

		// On some resolvers an NXDOMAIN response surfaces as a PHP E_WARNING
		// from dns_get_record() rather than a clean false/empty return — this
		// is a best-effort, read-only diagnostic on an admin settings page,
		// not somewhere a PHP warning should ever be visible. Rather than
		// silencing with @ (flagged by WordPress.PHP.NoSilencedErrors — it
		// hides genuine errors along with the expected NXDOMAIN one), swap in
		// a scoped error handler that only swallows E_WARNING for the
		// duration of this one call and always restores the previous handler
		// afterward, success or not.
		set_error_handler( static fn(): bool => true, E_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		try {
			$records = dns_get_record( $host, DNS_TXT );
		} finally {
			restore_error_handler();
		}

		if ( false === $records ) {
			return [ 'status' => 'lookup_failed', 'records' => [] ];
		}

		$txt = array_values( array_filter( array_column( $records, 'txt' ) ) );

		return [ 'status' => empty( $txt ) ? 'not_found' : 'found', 'records' => $txt ];
	}

	/**
	 * SPF check for a domain — an SPF record is a TXT record starting with
	 * "v=spf1", so this filters the raw TXT lookup down to that prefix
	 * rather than treating any TXT record as SPF.
	 *
	 * @return array{status: string, records: string[]}
	 */
	public static function spf_check( string $domain ): array {
		$result = self::txt_lookup( $domain );
		if ( 'found' !== $result['status'] ) {
			return $result;
		}

		$spf_records = array_values( array_filter(
			$result['records'],
			static fn( string $t ): bool => str_starts_with( strtolower( $t ), 'v=spf1' )
		) );

		return [
			'status'  => empty( $spf_records ) ? 'not_found' : 'found',
			'records' => $spf_records,
		];
	}

	/**
	 * DMARC check — DMARC records live at the fixed `_dmarc.` subdomain, not
	 * the bare domain.
	 *
	 * @return array{status: string, records: string[]}
	 */
	public static function dmarc_check( string $domain ): array {
		return self::txt_lookup( '' !== $domain ? '_dmarc.' . $domain : '' );
	}

	/**
	 * Raw A-record lookup for a host — the same pattern as txt_lookup(), used
	 * by dbl_check() below (DNSBL responses are conventionally A records in
	 * the 127.0.0.0/8 range, not TXT).
	 *
	 * @return array{status: string, records: string[]} status is one of
	 *         'found', 'not_found', 'lookup_failed', or 'unavailable' — same
	 *         meanings as txt_lookup().
	 */
	private static function a_lookup( string $host ): array {
		if ( '' === $host ) {
			return [ 'status' => 'unavailable', 'records' => [] ];
		}

		// Same test/integration seam as txt_lookup()'s own filter, kept as a
		// separate hook since an A-record lookup returns a different shape
		// (IPs, not TXT strings) than what agnosis_deliverability_dns_txt supplies.
		$filtered = apply_filters( 'agnosis_deliverability_dns_a', null, $host );
		if ( null !== $filtered ) {
			return $filtered;
		}

		if ( ! function_exists( 'dns_get_record' ) ) {
			return [ 'status' => 'unavailable', 'records' => [] ];
		}

		// Same NXDOMAIN-as-E_WARNING handling as txt_lookup() — see that
		// method's own comment for why this isn't silenced with @ instead.
		set_error_handler( static fn(): bool => true, E_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		try {
			$records = dns_get_record( $host, DNS_A );
		} finally {
			restore_error_handler();
		}

		if ( false === $records ) {
			return [ 'status' => 'lookup_failed', 'records' => [] ];
		}

		$ips = array_values( array_filter( array_column( $records, 'ip' ) ) );

		return [ 'status' => empty( $ips ) ? 'not_found' : 'found', 'records' => $ips ];
	}

	/**
	 * Small transient cache wrapper for dkim_check()/dbl_check() — see this
	 * class's own docblock for why those two specifically need it (unlike
	 * spf_check()/dmarc_check(), which stay uncached, exactly as before this
	 * was added).
	 *
	 * @param string   $key     Cache key fragment — namespaced internally.
	 * @param callable $compute Callback producing the value on a cache miss.
	 * @return mixed Whatever $compute() returns (and whatever was cached).
	 */
	private static function cached( string $key, callable $compute ) {
		$transient_key = 'agnosis_dlv_' . md5( $key );

		$cached = get_transient( $transient_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$value = $compute();
		set_transient( $transient_key, $value, HOUR_IN_SECONDS );

		return $value;
	}

	/**
	 * DKIM selectors to try when guessing where a domain's DKIM record lives.
	 *
	 * There is no fixed, discoverable location for a DKIM record the way
	 * there is for SPF (bare domain) or DMARC (`_dmarc.` subdomain) — the
	 * selector is chosen by whichever service actually signs outbound mail
	 * (the SMTP-relay provider FluentSMTP/WP Mail SMTP/etc. is configured
	 * against, not the WordPress plugin itself), and is only ever visible in
	 * that provider's own setup instructions. This list covers selectors
	 * common enough to be worth a guess (several transactional-mail
	 * providers' defaults, plus a handful of generic conventions), but a
	 * "not found" result here is NOT proof DKIM isn't configured — only that
	 * it isn't configured under one of these specific names.
	 *
	 * Filterable so a site that knows its own selector (the provider's setup
	 * page always states it) can add it without a plugin update — e.g. from
	 * a small mu-plugin: `add_filter( 'agnosis_deliverability_dkim_selectors',
	 * fn( $s ) => array_merge( $s, [ 'my-actual-selector' ] ) );`.
	 *
	 * @return string[]
	 */
	private static function dkim_selectors(): array {
		$selectors = [
			'default', 'selector1', 'selector2', 'google', 'k1', 'k2', 's1', 's2',
			'dkim', 'mail', 'smtp', 'em', 'mandrill', 'mailgun', 'mg',
			'fm1', 'fm2', 'fm3', 'zoho', 'sendgrid', 'pm', 'pm-bounces',
		];

		/**
		 * Filters the list of DKIM selectors dkim_check() tries.
		 *
		 * @param string[] $selectors Selector names, tried in order.
		 */
		return (array) apply_filters( 'agnosis_deliverability_dkim_selectors', $selectors );
	}

	/**
	 * Best-effort DKIM check — tries each selector from dkim_selectors() at
	 * `{selector}._domainkey.{domain}` and reports the first one with a
	 * record that actually looks like DKIM (contains `v=DKIM1` or a `p=`
	 * public-key field, rather than treating any TXT record found at that
	 * name as a match).
	 *
	 * Cached (see cached()) since a full miss costs one DNS query per
	 * selector in the list.
	 *
	 * @return array{status: string, selector: string, records: string[]}
	 *         status is 'found', 'not_found' (see this method's own caveat
	 *         above — and dkim_selectors()'s), or 'unavailable'
	 *         (dns_get_record() doesn't exist on this host).
	 */
	public static function dkim_check( string $domain ): array {
		if ( '' === $domain ) {
			return [ 'status' => 'unavailable', 'selector' => '', 'records' => [] ];
		}

		if ( ! function_exists( 'dns_get_record' ) ) {
			return [ 'status' => 'unavailable', 'selector' => '', 'records' => [] ];
		}

		return self::cached( 'dkim:' . $domain, static function () use ( $domain ): array {
			foreach ( self::dkim_selectors() as $selector ) {
				$result = self::txt_lookup( $selector . '._domainkey.' . $domain );
				if ( 'found' !== $result['status'] ) {
					continue;
				}

				$dkim_records = array_values( array_filter(
					$result['records'],
					static fn( string $t ): bool => str_contains( strtolower( $t ), 'v=dkim1' ) || str_contains( strtolower( $t ), 'p=' )
				) );

				if ( ! empty( $dkim_records ) ) {
					return [ 'status' => 'found', 'selector' => $selector, 'records' => $dkim_records ];
				}
			}

			return [ 'status' => 'not_found', 'selector' => '', 'records' => [] ];
		} );
	}

	/**
	 * Spamhaus Domain Block List (DBL) check — queries
	 * `{domain}.dbl.spamhaus.org` for an A record, the standard DNSBL lookup
	 * convention: an NXDOMAIN response (a_lookup() returns 'not_found') means
	 * the domain is clean; any `127.0.1.x`-range response means it's listed,
	 * with the specific category (spam source, phishing, malware, a newly
	 * observed domain with no sending history yet, etc.) available as a TXT
	 * explanation record at that same query name.
	 *
	 * `127.255.255.x` responses are Spamhaus's own documented error range
	 * (rate-limited or malformed query) — treated as 'unavailable' rather
	 * than a real listing, since reporting an error response as "listed"
	 * would be actively misleading.
	 *
	 * Cached (see cached()) — Spamhaus's public DNSBL mirror has its own
	 * fair-use query-rate policy; re-querying it on every admin page load
	 * would be both wasteful and a bad way to treat a shared resource other
	 * mail infrastructure depends on too.
	 *
	 * @return array{status: string, reason: string} status is 'not_listed',
	 *         'listed', 'lookup_failed', or 'unavailable'.
	 */
	public static function dbl_check( string $domain ): array {
		if ( '' === $domain ) {
			return [ 'status' => 'unavailable', 'reason' => '' ];
		}

		return self::cached( 'dbl:' . $domain, static function () use ( $domain ): array {
			$query_host = $domain . '.dbl.spamhaus.org';
			$a          = self::a_lookup( $query_host );

			if ( 'unavailable' === $a['status'] ) {
				return [ 'status' => 'unavailable', 'reason' => '' ];
			}
			if ( 'lookup_failed' === $a['status'] ) {
				return [ 'status' => 'lookup_failed', 'reason' => '' ];
			}
			if ( 'not_found' === $a['status'] ) {
				return [ 'status' => 'not_listed', 'reason' => '' ];
			}

			// See this method's own docblock — Spamhaus's documented
			// error-response range, not a genuine listing.
			$is_error_response = ! empty( array_filter(
				$a['records'],
				static fn( string $ip ): bool => str_starts_with( $ip, '127.255.255.' )
			) );
			if ( $is_error_response ) {
				return [ 'status' => 'unavailable', 'reason' => '' ];
			}

			$txt = self::txt_lookup( $query_host );

			return [ 'status' => 'listed', 'reason' => $txt['records'][0] ?? '' ];
		} );
	}

	/**
	 * One row per distinct configured From identity — community
	 * (transactional) and newsletter, de-duplicated when both resolve to the
	 * same address (the common case: neither configured, both fall back to
	 * admin_email), since there's nothing extra to learn from checking the
	 * same domain's DNS twice.
	 *
	 * @return array<int, array{
	 *     label: string,
	 *     email: string,
	 *     domain: string,
	 *     domain_matches_site: bool,
	 *     spf: array{status: string, records: string[]},
	 *     dmarc: array{status: string, records: string[]},
	 *     dkim: array{status: string, selector: string, records: string[]},
	 *     dbl: array{status: string, reason: string}
	 * }>
	 */
	public static function identity_report(): array {
		$site_domain = self::site_domain();

		$identities = [
			__( 'Mail from (workflow)', 'agnosis' )       => CommunityMailer::sender_header(),
			__( 'Newsletter', 'agnosis' )                 => NewsletterMailer::sender_header(),
		];

		$rows       = [];
		$row_by_key = [];

		foreach ( $identities as $label => $header ) {
			// sender_header() returns "Name <email>" — pull out just the address.
			$email = $header;
			if ( preg_match( '/<([^>]+)>/', $header, $m ) ) {
				$email = $m[1];
			}
			$domain = self::domain_of( $email );
			$key    = $domain . '|' . strtolower( $email );

			if ( isset( $row_by_key[ $key ] ) ) {
				// Same address already reported under an earlier label — fold
				// this identity's label into that row instead of duplicating
				// an identical DNS lookup for a domain already checked.
				$rows[ $row_by_key[ $key ] ]['label'] .= ' / ' . $label;
				continue;
			}
			$row_by_key[ $key ] = count( $rows );

			$rows[] = [
				'label'               => $label,
				'email'               => $email,
				'domain'              => $domain,
				'domain_matches_site' => '' !== $domain && $domain === $site_domain,
				'spf'                 => self::spf_check( $domain ),
				'dmarc'               => self::dmarc_check( $domain ),
				'dkim'                => self::dkim_check( $domain ),
				'dbl'                 => self::dbl_check( $domain ),
			];
		}

		return $rows;
	}
}
