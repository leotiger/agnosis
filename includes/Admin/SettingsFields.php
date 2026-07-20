<?php
/**
 * Declarative schema for every Settings → Agnosis field: key, tab, label,
 * description, input type, default, and (where the field isn't a plain
 * string) its sanitize callback.
 *
 * Split out of Admin\Settings (2026-07-17, AUDIT-1.0.0.md §4d — the "god
 * class" finding) purely mechanically: this was, on its own, roughly a
 * third of that file's 3,347 lines, and is pure data with zero `$this->`
 * dependencies on anything else Settings does — a cut-paste-rename with no
 * behavior change at all. `Settings::field_definitions()` keeps its own
 * name and stays a private method (now a one-line delegate to `all()`
 * here), so the two existing tests that reflect into it
 * (`EmailBrandingColorFieldsTest`, `SettingsResettableFieldsTest`) needed
 * no changes.
 *
 * @package Agnosis\Admin
 */

declare(strict_types=1);

namespace Agnosis\Admin;

class SettingsFields {

	/** @return array<string, array<string, mixed>> */
	public static function all(): array {
		return [
			// --- GENERAL ---
			'agnosis_base_domain' => [
				'tab'     => 'general',
				'label'   => __( 'Base domain', 'agnosis' ),
				'desc'    => __( 'Root domain for artist subdomains, e.g. agnosis.art. Each artist is reachable at nicename.{base_domain}. Requires a wildcard DNS record (*.{base_domain}) pointing to this server. Leave blank to disable subdomain routing.', 'agnosis' ),
				'default' => '',
			],

			// --- GENERAL: Debug ---
			'agnosis_debug_enabled' => [
				'tab'      => 'general',
				'label'    => __( 'Debug logging', 'agnosis' ),
				'input'    => 'checkbox',
				'default'  => '0',
				'type'     => 'boolean',
				'sanitize' => fn( $v ) => (bool) $v,
				'desc'     => __( 'Writes raw diagnostic dumps of the email intake pipeline (message structure, attachment MIME detection, media conversion, post creation) to a dedicated debug directory — far more detail than the Logs tab keeps, for tracing exactly where a submission was rejected or lost. Turn off once you have what you need; files can accumulate quickly. Can also be forced from wp-config.php with `define( \'AGNOSIS_DEBUG\', true );`, which overrides this toggle. See the Debug Files panel below for the directory location and a clear-files button.', 'agnosis' ),
			],

			'agnosis_debug_retention_days' => [
				'tab'      => 'general',
				'label'    => __( 'Debug file retention (days)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 14,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Debug dumps older than this are permanently deleted by the daily cleanup — automatically, whether or not debug logging is currently turned on, so files left over from a past debug session don\'t linger indefinitely. A dump is a full raw copy of an artist\'s email, so this defaults shorter than the inbox/queue retention above. Default: 14 days.', 'agnosis' ),
			],

			// --- GENERAL: Biography ---
			'agnosis_biography_preset_title' => [
				'tab'     => 'general',
				'label'   => __( 'Preset biography title', 'agnosis' ),
				'default' => '',
				'desc'    => __( 'When set, every artist\'s biography page uses this exact title instead of their own, regardless of what the artist submitted, edited on their approve form, or later changed on their published page. Leave blank to keep using each artist\'s own title (the default). Shown exactly as typed here on every language version of a biography page — not machine-translated per language. See "Include artist\'s name in preset title" below.', 'agnosis' ),
			],
			'agnosis_biography_preset_title_include_name' => [
				'tab'      => 'general',
				'label'    => __( 'Include artist\'s name in preset title', 'agnosis' ),
				'input'    => 'checkbox',
				'default'  => '0',
				'type'     => 'boolean',
				'sanitize' => fn( $v ) => (bool) $v,
				'desc'     => __( 'When the preset title above is set, append the artist\'s own WordPress display name to it (e.g. "Meet the Artist — Jane Doe"). Has no effect while the preset title is blank.', 'agnosis' ),
			],

			// --- GENERAL: Cloudflare Turnstile (bot/spam protection) ---
			'agnosis_turnstile_site_key' => [
				'tab'   => 'general',
				'label' => __( 'Cloudflare Turnstile site key', 'agnosis' ),
				'desc'  => __( 'From your Cloudflare Turnstile widget (dash.cloudflare.com → Turnstile). Adds a human-verification check to the public Subscribe (newsletter-signup) and Join forms. Leave either key blank to leave both forms exactly as they are today.', 'agnosis' ),
			],
			'agnosis_turnstile_secret_key' => [
				'tab'      => 'general',
				'label'    => __( 'Cloudflare Turnstile secret key', 'agnosis' ),
				'input'    => 'password',
				'sanitize' => fn( $v ) => $v,
				'desc'     => __( 'Kept server-side only — used to verify each form submission directly with Cloudflare before it is accepted.', 'agnosis' ),
			],

			// --- BRANDING ---
			// Every field here is read by Core\EmailTemplate (the shared shell
			// every outgoing HTML email is built around) via a static getter
			// with its own sanitize-fallback, so a stored value that predates
			// validation — or a direct update_option() call bypassing this
			// screen's own sanitize callback — can never break an email.
			// Defaults exactly match the plugin's original hardcoded look, so
			// an existing install sees zero visual change until an operator
			// explicitly opens this tab and changes something.
			'agnosis_email_logo_id' => [
				'tab'      => 'branding',
				'label'    => __( 'Email logo', 'agnosis' ),
				'input'    => 'media',
				'type'     => 'integer',
				'sanitize' => 'absint',
				'default'  => 0,
				'desc'     => __( 'Shown in the header of outgoing HTML emails (submission review, removal confirmation, rejection notice, invitations, and both newsletters) in place of the plain "✦ Site Name" text. Leave empty to keep the text wordmark. Displayed at up to 40px tall — any width or aspect ratio works.', 'agnosis' ),
			],
			'agnosis_email_header_bg' => [
				'tab'      => 'branding',
				'label'    => __( 'Header background', 'agnosis' ),
				'input'    => 'color',
				'type'     => 'string',
				'sanitize' => fn( $v ) => self::sanitize_color( (string) $v, '#0d0d12' ),
				'default'  => '#0d0d12',
				'desc'     => __( 'Background color of the colored header bar on every outgoing HTML email.', 'agnosis' ),
			],
			'agnosis_email_accent' => [
				'tab'      => 'branding',
				'label'    => __( 'Accent color', 'agnosis' ),
				'input'    => 'color',
				'type'     => 'string',
				'sanitize' => fn( $v ) => self::sanitize_color( (string) $v, '#7c6af7' ),
				'default'  => '#7c6af7',
				'desc'     => __( 'Color of primary buttons and links across every outgoing HTML email. Destructive actions (reject, remove, vote to remove) always use a fixed red regardless of this setting, so they stay visually distinct.', 'agnosis' ),
			],
			'agnosis_email_width' => [
				'tab'      => 'branding',
				'label'    => __( 'Email width (px)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 600,
				'min'      => 320,
				'max'      => 800,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 320, min( 800, (int) $v ) ),
				'desc'     => __( 'Width of the card every outgoing HTML email is built around. 600px is the long-standing email-safe default most clients render reliably — narrower reads awkwardly on desktop, wider risks getting clipped or horizontally scrolled by some mobile mail apps. Default: 600.', 'agnosis' ),
			],
			'agnosis_email_body_bg' => [
				'tab'      => 'branding',
				'label'    => __( 'Page background color', 'agnosis' ),
				'input'    => 'color',
				'type'     => 'string',
				'sanitize' => fn( $v ) => self::sanitize_color( (string) $v, '#f5f5f5' ),
				'default'  => '#f5f5f5',
				'desc'     => __( 'Background color that surrounds the email card — the "letterbox" area visible in most desktop mail clients. Distinct from the card background below.', 'agnosis' ),
			],
			'agnosis_email_card_bg' => [
				'tab'      => 'branding',
				'label'    => __( 'Card background color', 'agnosis' ),
				'input'    => 'color',
				'type'     => 'string',
				'sanitize' => fn( $v ) => self::sanitize_color( (string) $v, '#ffffff' ),
				'default'  => '#ffffff',
				'desc'     => __( 'Background color of the card itself — where the body text and footer sit. Keep this light for readable dark text below; for a dark theme, also darken the text colors.', 'agnosis' ),
			],
			'agnosis_email_text_color' => [
				'tab'      => 'branding',
				'label'    => __( 'Primary text color', 'agnosis' ),
				'input'    => 'color',
				'type'     => 'string',
				'sanitize' => fn( $v ) => self::sanitize_color( (string) $v, '#222222' ),
				'default'  => '#222222',
				'desc'     => __( 'Default color for body text — the main reading text of every outgoing HTML email.', 'agnosis' ),
			],
			'agnosis_email_text_size' => [
				'tab'      => 'branding',
				'label'    => __( 'Primary text size (px)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 16,
				'min'      => 10,
				'max'      => 24,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 10, min( 24, (int) $v ) ),
				'desc'     => __( 'Default base font size for body text. Default: 16.', 'agnosis' ),
			],
			'agnosis_email_text_secondary_color' => [
				'tab'      => 'branding',
				'label'    => __( 'Secondary text color', 'agnosis' ),
				'input'    => 'color',
				'type'     => 'string',
				'sanitize' => fn( $v ) => self::sanitize_color( (string) $v, '#bbbbbb' ),
				'default'  => '#bbbbbb',
				'desc'     => __( 'Color for muted/secondary text — the footer tagline, work-address descriptions, and small helper links (unsubscribe, notification preferences, "view online"). Matches the plugin\'s original hardcoded footer color, kept as the default.', 'agnosis' ),
			],
			'agnosis_email_text_secondary_size' => [
				'tab'      => 'branding',
				'label'    => __( 'Secondary text size (px)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 14,
				'min'      => 10,
				'max'      => 20,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 10, min( 20, (int) $v ) ),
				'desc'     => __( 'Font size for muted/secondary text — the footer tagline and smaller helper text below the main body copy. Default: 14.', 'agnosis' ),
			],
			'agnosis_email_footer_bg' => [
				'tab'      => 'branding',
				'label'    => __( 'Footer background color', 'agnosis' ),
				'input'    => 'color',
				'type'     => 'string',
				'sanitize' => fn( $v ) => self::sanitize_color( (string) $v, '#ffffff' ),
				'default'  => '#ffffff',
				'desc'     => __( 'Background color of the footer strip at the bottom of every outgoing HTML email (the tagline, and — on emails that have one — the work-addresses card and unsubscribe/preferences link). Independent of the card background above, so the footer can be set off visually from the body if you like.', 'agnosis' ),
			],
			'agnosis_email_border_color' => [
				'tab'      => 'branding',
				'label'    => __( 'Divider / border color', 'agnosis' ),
				'input'    => 'color',
				'type'     => 'string',
				'sanitize' => fn( $v ) => self::sanitize_color( (string) $v, '#eeeeee' ),
				'default'  => '#eeeeee',
				'desc'     => __( 'Color of thin divider lines across outgoing HTML email — between the body and footer, above the work-addresses card, and around the newsletter\'s "view online" banner.', 'agnosis' ),
			],
			'agnosis_email_button_text_color' => [
				'tab'      => 'branding',
				'label'    => __( 'Button text color', 'agnosis' ),
				'input'    => 'color',
				'type'     => 'string',
				'sanitize' => fn( $v ) => self::sanitize_color( (string) $v, '#ffffff' ),
				'default'  => '#ffffff',
				'desc'     => __( 'Text color on the solid accent-colored call-to-action buttons (e.g. "Confirm subscription", "Apply to join"). Change this alongside the accent color above if you pick a light accent that white text wouldn\'t read well against.', 'agnosis' ),
			],
			'agnosis_email_notice_bg' => [
				'tab'      => 'branding',
				'label'    => __( 'Notice box background color', 'agnosis' ),
				'input'    => 'color',
				'type'     => 'string',
				'sanitize' => fn( $v ) => self::sanitize_color( (string) $v, '#f9f9f9' ),
				'default'  => '#f9f9f9',
				'desc'     => __( 'Background color of the shaded info/quote boxes used throughout outgoing HTML email — quoted bios and statements, "did you mean" suggestions, application summaries, and similar callouts.', 'agnosis' ),
			],
			'agnosis_email_footer_label_color' => [
				'tab'      => 'branding',
				'label'    => __( 'Footer label color', 'agnosis' ),
				'input'    => 'color',
				'type'     => 'string',
				'sanitize' => fn( $v ) => self::sanitize_color( (string) $v, '#555555' ),
				'default'  => '#555555',
				'desc'     => __( 'Color of the bold address labels ("Artwork:", "Biography:", etc.) in the work-addresses reference card that appears in the footer of artist-facing notification emails.', 'agnosis' ),
			],

			// --- EMAIL ---
			'agnosis_email_driver' => [
				'tab'     => 'email',
				'label'   => __( 'Email driver', 'agnosis' ),
				'input'   => 'select',
				'options' => [ 'imap' => 'IMAP (poll)', 'webhook' => 'Webhook (push)' ],
				'default' => 'imap',
			],
			// --- Sender identity (outbound "From:") ---
			// Not to be confused with any address below: those are INBOUND —
			// where an artist sends mail in. These two configure who workflow/
			// transactional mail (admission, vote, welcome, departure,
			// invitation, submission-review) appears to come FROM. See
			// Core\CommunityMailer::sender_header(). Separate from
			// Settings → Newsletter's own Sender name/email, which only
			// governs digest mail.
			'agnosis_mail_from_name' => [
				'tab'   => 'email',
				'label' => __( 'Mail from: name', 'agnosis' ),
				'desc'  => __( 'Name shown as the sender of admission, vote, welcome, departure, invitation, and submission-review emails. Leave blank to use the site name.', 'agnosis' ),
			],
			'agnosis_mail_from_email' => [
				'tab'      => 'email',
				'label'    => __( 'Mail from: email address', 'agnosis' ),
				'sanitize' => 'sanitize_email',
				'desc'     => __( 'Dedicated From: address for admission, vote, welcome, departure, invitation, and submission-review emails — e.g. hello@agnosis.art. This is not one of the endpoint addresses below (submit@, bio@, community@, etc.) — those are where artists send mail in; this is who workflow mail appears to come from. Separate from the Settings → Newsletter sender, which only covers digest mail. Leave blank to use the admin email. Must be a valid, deliverable address on your domain (SPF/DKIM configured) or messages may be marked as spam.', 'agnosis' ),
			],
			// --- Routing addresses ---
			'agnosis_email_submit' => [
				'tab'   => 'email',
				'label' => __( 'Artwork submissions address', 'agnosis' ),
				'desc'  => __( 'Artists send artwork to this address — e.g. submit@agnosis.art', 'agnosis' ),
			],
			'agnosis_email_bio' => [
				'tab'   => 'email',
				'label' => __( 'Biography submissions address', 'agnosis' ),
				'desc'  => __( 'Artists send biography updates to this address — e.g. bio@agnosis.art', 'agnosis' ),
			],
			'agnosis_email_event' => [
				'tab'   => 'email',
				'label' => __( 'Event submissions address', 'agnosis' ),
				'desc'  => __( 'Artists send event announcements to this address — e.g. event@agnosis.art', 'agnosis' ),
			],
			'agnosis_email_replace' => [
				'tab'  => 'email',
				'label' => __( 'Replace artwork address', 'agnosis' ),
				'desc'  => __( 'Artist sends a new version of an existing artwork. Subject must match the original title. Bypasses duplicate detection — e.g. replace@agnosis.art', 'agnosis' ),
			],
			'agnosis_email_remove' => [
				'tab'  => 'email',
				'label' => __( 'Removal request address', 'agnosis' ),
				'desc'  => __( 'Artist requests takedown of an existing artwork. Subject must match the title. Moves the post to draft pending admin review — e.g. remove@agnosis.art', 'agnosis' ),
			],
			'agnosis_email_promote' => [
				'tab'  => 'email',
				'label' => __( 'Promote artwork address', 'agnosis' ),
				'desc'  => __( 'Artist sends an email here (subject = exact artwork title) to choose which artwork represents them in the shared community gallery on the main site — not their own subdomain, which already shows everything they\'ve published. Any previously featured artwork is automatically demoted — e.g. promote@agnosis.art', 'agnosis' ),
			],
			'agnosis_email_photo' => [
				'tab'   => 'email',
				'label' => __( 'Photo-only address', 'agnosis' ),
				'desc'  => __( 'Opt-out lane for photographers and lens-based artists whose photograph is the artwork, not a snapshot of it. Submissions here are published with the original image exactly as sent — AI enhancement is skipped and the quality-rejection gate is bypassed. AI description (title, tags, alt text) still runs. Use the [Photo] subject indicator as a fallback for mail clients that do not support To: aliases — e.g. photo@agnosis.art', 'agnosis' ),
			],
			'agnosis_email_pure' => [
				'tab'   => 'email',
				'label' => __( 'Pure address (no AI at all)', 'agnosis' ),
				'desc'  => __( 'Strictest opt-out lane: nothing sent here is touched by AI — no enhancement, no vision description, no rewriting. The published post title, body, and translated-title subtitle are taken verbatim from the artist\'s own subject and message text; the attached file(s) are published exactly as sent. The quality-rejection gate is bypassed, same as Photo-only. Use the [Pure] subject indicator as a fallback for mail clients that do not support To: aliases — e.g. pure@agnosis.art', 'agnosis' ),
			],
			'agnosis_email_goodbye' => [
				'tab'   => 'email',
				'label' => __( 'Goodbye / self-removal address', 'agnosis' ),
				'desc'  => __( 'Artist emails here (any subject, no attachment needed) to request account deletion. A confirmation link is emailed back before anything is deleted — e.g. goodbye@agnosis.art', 'agnosis' ),
			],
			'agnosis_email_community' => [
				'tab'   => 'email',
				'label' => __( 'Community announcement address', 'agnosis' ),
				'desc'  => __( 'Any active artist emails here (any subject, no attachment needed) to send a message to every other community member — e.g. community@agnosis.art. Never processed as a submission and never becomes a post: each recipient gets the subject and message translated into their own language, with the sender\'s name and email included so they can reply directly. Sent from the address configured under Settings → Community → Rules, which can be different from this one. Only works for a message sent by an active, admitted artist.', 'agnosis' ),
			],

			// --- IMAP connection ---
			'agnosis_imap_host' => [
				'tab'   => 'email',
				'label' => __( 'IMAP host', 'agnosis' ),
				'desc'  => __( 'e.g. imap.yourhost.com', 'agnosis' ),
			],
			'agnosis_imap_port' => [
				'tab'     => 'email',
				'label'   => __( 'IMAP port', 'agnosis' ),
				'input'   => 'number',
				'default' => 993,
				'min'     => 1,
			],
			'agnosis_imap_ssl' => [
				'tab'     => 'email',
				'label'   => __( 'Use SSL', 'agnosis' ),
				'input'   => 'checkbox',
				'default' => 1,
				'type'    => 'boolean',
				'sanitize' => fn( $v ) => (bool) $v,
			],
			'agnosis_imap_novalidate_cert' => [
				'tab'      => 'email',
				'label'    => __( 'Skip SSL certificate validation', 'agnosis' ),
				'input'    => 'checkbox',
				'default'  => 0,
				'type'     => 'boolean',
				'sanitize' => fn( $v ) => (bool) $v,
				'desc'     => __( '⚠ Use only as a temporary workaround when your mail server\'s certificate is not yet trusted (e.g. Plesk self-signed cert before Let\'s Encrypt is applied to the mail daemon). Disable this once the mail server presents a valid certificate.', 'agnosis' ),
			],
			'agnosis_imap_user' => [
				'tab'   => 'email',
				'label' => __( 'IMAP username', 'agnosis' ),
				'desc'  => __( 'Login username for the IMAP account — typically the catch-all mailbox address.', 'agnosis' ),
			],
			'agnosis_imap_pass' => [
				'tab'     => 'email',
				'label'   => __( 'Submission email password', 'agnosis' ),
				'input'   => 'password',
				'sanitize' => fn( $v ) => $v, // Don't sanitize passwords.
			],
			'agnosis_webhook_secret' => [
				'tab'     => 'email',
				'label'   => __( 'Webhook secret', 'agnosis' ),
				'desc'    => __( 'HMAC secret shared with your webhook provider (Mailgun, SendGrid…). Used for both the inbound-mail endpoint (/wp-json/agnosis/v1/email/inbound) and the bounce/complaint events endpoint (/wp-json/agnosis/v1/email/events) — point your provider\'s bounce/spam-complaint webhook at the latter to keep your subscriber and artist addresses clean.', 'agnosis' ),
				'input'   => 'password',
				'sanitize' => fn( $v ) => $v,
			],
			'agnosis_imap_cleanup_days' => [
				'tab'      => 'email',
				'label'    => __( 'Inbox retention (days)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 7,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'IMAP messages and processed/failed queue rows older than this are permanently deleted by the daily cleanup, whether read or unread. Default: 7 days.', 'agnosis' ),
			],
			'agnosis_contact_message_retention_days' => [
				'tab'      => 'email',
				'label'    => __( 'Contact message retention (days)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 90,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Visitor-to-artist contact messages (sent or rejected) older than this are permanently deleted by the daily cleanup. The visitor\'s IP address is cleared independently after 30 days regardless of this setting, once it\'s no longer useful for abuse investigation. Default: 90 days.', 'agnosis' ),
			],

			// --- Email: Security ---
			'agnosis_intake_per_sender_limit' => [
				'tab'      => 'email',
				'label'    => __( 'Per-sender hourly submission limit', 'agnosis' ),
				'input'    => 'number',
				'default'  => 5,
				'min'      => 1,
				'max'      => 100,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Maximum submissions accepted from a single artist per hour, across both IMAP and webhook intake. Exceeding this limit silently drops the message (no retry from ESPs). Default: 5.', 'agnosis' ),
			],

			'agnosis_contact_artist_limit' => [
				'tab'      => 'email',
				'label'    => __( 'Per-artist contact limit', 'agnosis' ),
				'input'    => 'number',
				'default'  => 2,
				'min'      => 1,
				'max'      => 20,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Maximum messages the same visitor (by email address) can send to a single artist within the window below — applies across every language version of that artist\'s page. Default: 2.', 'agnosis' ),
			],
			'agnosis_contact_artist_limit_window_hours' => [
				'tab'      => 'email',
				'label'    => __( 'Per-artist contact limit window (hours)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 1,
				'min'      => 1,
				'max'      => 168,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Time window, in hours, the per-artist contact limit above applies over. Also controls how long a visitor sees the "already contacted" notice instead of the form after messaging an artist. Default: 1 (one hour).', 'agnosis' ),
			],

			'agnosis_require_email_auth' => [
				'tab'     => 'email',
				'label'   => __( 'Require SPF/DKIM authentication', 'agnosis' ),
				'input'   => 'checkbox',
				'default' => '0',
				'desc'    => __( 'When enabled, intake messages must pass SPF or DKIM authentication (via the Authentication-Results header). Prevents spoofed From: addresses. Requires your ESP to include Authentication-Results headers and your domain to have SPF/DKIM records configured. Leave off if you are not sure — rejected legitimate mail is silent. Default: off.', 'agnosis' ),
			],

			// --- AI: Description (text) ---
			'agnosis_description_provider' => [
				'tab'     => 'ai',
				'label'   => __( 'Description provider', 'agnosis' ),
				'input'   => 'select',
				'options' => [
					'openai'    => 'OpenAI (GPT-4o)',
					'anthropic' => 'Anthropic (Claude)',
					'wp_ai'     => 'WordPress AI Services',
				],
				'default' => 'openai',
				'desc'    => __( 'Analyzes the artwork image and writes the title, body, tags and alt text. WordPress AI Services delegates to whichever provider the site has configured via the AI Services plugin.', 'agnosis' ),
			],
			'agnosis_openai_description_model' => [
				'tab'     => 'ai',
				'label'   => __( 'OpenAI vision model', 'agnosis' ),
				'default' => 'gpt-4o',
				'desc'    => __( 'Model used for artwork description when OpenAI is the description provider. Must support vision input.', 'agnosis' ),
			],
			'agnosis_anthropic_model' => [
				'tab'     => 'ai',
				'label'   => __( 'Anthropic model', 'agnosis' ),
				'default' => 'claude-opus-4-8',
				'desc'    => __( 'Model used for artwork description when Anthropic is the description provider. Must support vision input.', 'agnosis' ),
			],
			// Audit §5c: cheap/fast text-only model used for translation and
			// contact-message moderation (Pipeline::chat()/classify_text(),
			// SubmissionTranslator) — previously a hardcoded literal with no
			// operator lever at all, unlike the vision models just above.
			// Deliberately a SEPARATE option from the vision model: this one
			// is picked for speed/cost on plain-text tasks, not vision
			// capability, and the two may reasonably diverge.
			'agnosis_openai_text_model' => [
				'tab'     => 'ai',
				'label'   => __( 'OpenAI text model', 'agnosis' ),
				'default' => 'gpt-4o-mini',
				'desc'    => __( 'Cheap, fast model used for text-only tasks when OpenAI is the description provider — translating a submission into the site\'s primary language, and moderating contact-form messages. Does not need vision support.', 'agnosis' ),
			],
			'agnosis_anthropic_text_model' => [
				'tab'     => 'ai',
				'label'   => __( 'Anthropic text model', 'agnosis' ),
				'default' => 'claude-haiku-4-5-20251001',
				'desc'    => __( 'Cheap, fast model used for text-only tasks when Anthropic is the description provider — translating a submission into the site\'s primary language, and moderating contact-form messages. Does not need vision support.', 'agnosis' ),
			],
			'agnosis_ai_vision_max_width_px' => [
				'tab'      => 'ai',
				'label'    => __( 'Vision image max width (px)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 800,
				'min'      => 0,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 0, (int) $v ),
				'desc'     => __( 'Images are downscaled proportionally to this width before being sent to the AI for description — vision token cost scales with resolution, and a description task needs far fewer pixels than the original photo. Applies to artwork photo uploads, rasterised PDF pages, and video poster frames alike. Set to 0 to always send full-resolution images (uses more tokens, no quality difference for typical description tasks). Requires the Imagick PHP extension; images are sent at their original size regardless of this setting when it is unavailable. Default: 800.', 'agnosis' ),
			],

			// --- AI: Enhancement (image) ---
			'agnosis_enhancement_provider' => [
				'tab'     => 'ai',
				'label'   => __( 'Enhancement provider', 'agnosis' ),
				'input'   => 'select',
				'options' => [
					'auto'   => __( 'Auto (OpenAI if key is set)', 'agnosis' ),
					'openai' => 'OpenAI (gpt-image-1)',
					'none'   => __( 'Disabled — use original image', 'agnosis' ),
				],
				'default' => 'auto',
				'desc'    => __( 'Enhances the artwork image before publishing. Uses OpenAI gpt-image-1. Set to Disabled to skip enhancement and publish the original.', 'agnosis' ),
			],
			'agnosis_openai_image_model' => [
				'tab'     => 'ai',
				'label'   => __( 'OpenAI image model', 'agnosis' ),
				'default' => 'gpt-image-1',
				'desc'    => __( 'Model used for image enhancement when OpenAI is the enhancement provider.', 'agnosis' ),
			],

			// --- AI: Singleton post polish ---
			'agnosis_ai_polish_biography' => [
				'tab'     => 'ai',
				'label'   => __( 'Polish biography with AI', 'agnosis' ),
				'input'   => 'checkbox',
				'default' => '0',
				'desc'    => __( 'When enabled, biography submissions are passed through the AI to fix spelling and make minor text improvements before saving.', 'agnosis' ),
			],
			'agnosis_ai_polish_event' => [
				'tab'     => 'ai',
				'label'   => __( 'Polish events with AI', 'agnosis' ),
				'input'   => 'checkbox',
				'default' => '0',
				'desc'    => __( 'When enabled, event submissions are passed through the AI to fix spelling and make minor text improvements before saving.', 'agnosis' ),
			],

			// --- AI: Biography merge ---
			'agnosis_ai_merge_biography' => [
				'tab'     => 'ai',
				'label'   => __( 'Merge biography updates with AI', 'agnosis' ),
				'input'   => 'checkbox',
				'default' => '1',
				'desc'    => __( 'When enabled, biography updates are merged with the existing biography using AI — new information is integrated rather than replacing everything. Recommended: artists can send incremental updates ("I just won an award") without resubmitting their full bio. Disable to always replace the biography with the latest submission.', 'agnosis' ),
			],

			// --- AI: Quality detection ---
			'agnosis_quality_rejection_threshold' => [
				'tab'      => 'ai',
				'label'    => __( 'Rejection threshold (quality score)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 3,
				'min'      => 0,
				'max'      => 10,
				'step'     => '1',
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 0, min( 10, (int) $v ) ),
				'desc'     => __( 'Photos scoring at or below this agnosis_vendor_value (1–10) are automatically rejected — the artist receives a friendly email explaining the issue and is invited to resubmit. Score 0 disables automatic rejection. Must be lower than the enhancement threshold. Default: 3.', 'agnosis' ),
			],

			'agnosis_quality_threshold' => [
				'tab'      => 'ai',
				'label'    => __( 'Enhancement threshold (quality score)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 7,
				'min'      => 1,
				'max'      => 10,
				'step'     => '1',
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, min( 10, (int) $v ) ),
				'desc'     => __( 'Photos scoring below this agnosis_vendor_value (1–10) are automatically enhanced. Score 10 = perfect photograph, 1 = technically unusable. Default: 7 — only visibly problematic photos are processed. Set to 1 to disable automatic enhancement entirely.', 'agnosis' ),
			],

			// --- AI: API keys ---
			'agnosis_openai_api_key' => [
				'tab'      => 'ai',
				'label'    => __( 'OpenAI API key', 'agnosis' ),
				'input'    => 'password',
				'sanitize' => fn( $v ) => $v,
				'desc'     => __( 'Required when OpenAI is the description or enhancement provider.', 'agnosis' ),
			],
			'agnosis_anthropic_api_key' => [
				'tab'      => 'ai',
				'label'    => __( 'Anthropic API key', 'agnosis' ),
				'input'    => 'password',
				'sanitize' => fn( $v ) => $v,
				'desc'     => __( 'Required when Anthropic is the description provider.', 'agnosis' ),
			],

			// --- BEHAVIOUR: Image sizes ---
			'agnosis_artwork_size_px' => [
				'tab'      => 'behavior',
				'label'    => __( 'Artwork display width (px)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 1920,
				'min'      => 400,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 400, (int) $v ),
				'desc'     => __( 'Width of the agnosis-artwork image size used in post content and lightbox. Height scales proportionally. Default: 1920. Existing images need to be regenerated after changing this value.', 'agnosis' ),
			],
			'agnosis_thumb_size_px' => [
				'tab'      => 'behavior',
				'label'    => __( 'Thumbnail size (px)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 512,
				'min'      => 64,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 64, (int) $v ),
				'desc'     => __( 'Side length of the square agnosis-thumb size used in submission cards and dashboard. Default: 512. Existing images need to be regenerated after changing this value.', 'agnosis' ),
			],
			'agnosis_email_size_px' => [
				'tab'      => 'behavior',
				'label'    => __( 'Email image width (px)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 420,
				'min'      => 200,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 200, (int) $v ),
				'desc'     => __( 'Width of the agnosis-email size used in artist notification emails. Height scales proportionally — no cropping. Default: 420. Existing images need to be regenerated after changing this value.', 'agnosis' ),
			],

			// --- BEHAVIOUR: Gallery overview ---
			'agnosis_gallery_per_page' => [
				'tab'      => 'behavior',
				'label'    => __( 'Gallery overview — items per page', 'agnosis' ),
				'input'    => 'number',
				'default'  => 12,
				'min'      => 3,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 3, (int) $v ),
				'desc'     => __( 'How many artwork cards the gallery overview shows per page. Pool is built proportionally across all artists; featured artworks are preferred. Default: 12.', 'agnosis' ),
			],

			// --- BEHAVIOUR: Submission review ---
			'agnosis_review_token_expiry_days' => [
				'tab'      => 'behavior',
				'label'    => __( 'Review link expiry (days)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 7,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'How long the Approve & Publish / Discard links in a submission review email stay valid before an artist has to log in to manage the item instead. Applies uniformly to artwork, biography, and event drafts. Default: 7.', 'agnosis' ),
			],

			// --- BEHAVIOUR: AI prompts ---
			'agnosis_prompt_system' => [
				'tab'         => 'behavior',
				'label'       => __( 'System prompt', 'agnosis' ),
				'input'       => 'textarea',
				'rows'        => 12,
				'sanitize'    => 'sanitize_textarea_field',
				'default'     => \Agnosis\AI\PromptConfig::default_system_prompt(),
				'resettable'  => true,
				'desc'        => __( 'Sent to the AI as the system instruction. Use {tag_count} and {excerpt_words} as tokens — they are replaced with the values below.', 'agnosis' ),
			],
			'agnosis_prompt_user_template' => [
				'tab'        => 'behavior',
				'label'      => __( 'Artist prompt template', 'agnosis' ),
				'input'      => 'textarea',
				'rows'       => 4,
				'sanitize'   => 'sanitize_textarea_field',
				'default'    => \Agnosis\AI\PromptConfig::default_user_template(),
				'resettable' => true,
				'desc'       => __( 'User message sent alongside the artwork image. Use {artist_prompt} where the artist\'s own description should appear.', 'agnosis' ),
			],
			'agnosis_prompt_enhancement' => [
				'tab'        => 'behavior',
				'label'      => __( 'Enhancement instructions', 'agnosis' ),
				'input'      => 'textarea',
				'rows'       => 4,
				'sanitize'   => 'sanitize_textarea_field',
				'default'    => \Agnosis\AI\PromptConfig::default_enhancement_instructions(),
				'resettable' => true,
				'desc'       => __( 'Instructions passed to the image enhancement provider. The AI-generated artwork description is appended automatically as context.', 'agnosis' ),
			],
			'agnosis_prompt_tag_count' => [
				'tab'      => 'behavior',
				'label'    => __( 'Number of tags', 'agnosis' ),
				'input'    => 'number',
				'default'  => 5,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'How many tags the AI should generate per artwork. Referenced as {tag_count} in the system prompt.', 'agnosis' ),
			],
			'agnosis_prompt_excerpt_words' => [
				'tab'      => 'behavior',
				'label'    => __( 'Excerpt word limit', 'agnosis' ),
				'input'    => 'number',
				'default'  => 30,
				'min'      => 5,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 5, (int) $v ),
				'desc'     => __( 'Maximum words for the one-sentence excerpt. Referenced as {excerpt_words} in the system prompt.', 'agnosis' ),
			],

			// --- BEHAVIOUR: External link embedding ---
			// An artist can point to an external link instead of attaching a file
			// directly (typically a video too large to email) — see
			// Publishing\EmbedPolicy. Trusted-platform hosts below always embed
			// with no AI involved; anything else only embeds if AI review is
			// turned on and the AI approves it against the categories below.
			// Applies uniformly to artwork/biography/event email submissions and
			// to the portfolio link on admission applications.
			'agnosis_embed_trust_community' => [
				'tab'      => 'behavior',
				'label'    => __( 'Trust all admitted artists (skip link review entirely)', 'agnosis' ),
				'input'    => 'checkbox',
				'default'  => 0,
				'type'     => 'boolean',
				'sanitize' => fn( $v ) => (bool) $v,
				'desc'     => __( 'Every artist here was already vouched in by the community — if that trust is enough for you, turn this on to embed any link an artist submits, immediately, with no allowlist check and no AI review at all (the settings below are skipped entirely while this is on). Off by default. Only enable this if you are comfortable that a bad actor who made it through admission could embed a link to anything.', 'agnosis' ),
			],
			'agnosis_embed_trusted_hosts' => [
				'tab'        => 'behavior',
				'label'      => __( 'Trusted embed platforms', 'agnosis' ),
				'input'      => 'textarea',
				'rows'       => 6,
				'sanitize'   => 'sanitize_textarea_field',
				'default'    => implode( "\n", \Agnosis\Publishing\EmbedPolicy::DEFAULT_TRUSTED_HOSTS ),
				'resettable' => true,
				'desc'       => __( 'One hostname per line. A submitted link becomes a rich embed immediately if it matches one of these (or a subdomain of one) — no AI review needed, no network request made to the link itself.', 'agnosis' ),
			],
			'agnosis_embed_ai_vetting_enabled' => [
				'tab'      => 'behavior',
				'label'    => __( 'Let artists submit other links too (AI-reviewed)', 'agnosis' ),
				'input'    => 'checkbox',
				'default'  => 0,
				'type'     => 'boolean',
				'sanitize' => fn( $v ) => (bool) $v,
				'desc'     => __( 'When enabled, a link to a site not on the trusted list above is not simply discarded: the destination page is fetched and reviewed by your configured AI provider (Settings → AI Providers) against the categories below before deciding whether to embed it. If the AI cannot be reached, the request times out, or the page cannot be safely fetched, the link is not embedded — it fails safe. When disabled (the default), only the trusted platforms above are ever embedded.', 'agnosis' ),
			],
			'agnosis_embed_block_adult' => [
				'tab'      => 'behavior',
				'label'    => __( 'Block pornographic / sexually explicit content', 'agnosis' ),
				'input'    => 'checkbox',
				'default'  => 1,
				'type'     => 'boolean',
				'sanitize' => fn( $v ) => (bool) $v,
				'desc'     => __( 'Only takes effect when AI review (above) is enabled.', 'agnosis' ),
			],
			'agnosis_embed_block_commercial' => [
				'tab'      => 'behavior',
				'label'    => __( 'Block primarily commercial / promotional sites', 'agnosis' ),
				'input'    => 'checkbox',
				'default'  => 0,
				'type'     => 'boolean',
				'sanitize' => fn( $v ) => (bool) $v,
				'desc'     => __( 'Online stores, advertising, marketing landing pages. Off by default — an artist linking to a shop selling their own prints is common and not inherently unwanted. Only takes effect when AI review (above) is enabled.', 'agnosis' ),
			],
			'agnosis_embed_block_gambling' => [
				'tab'      => 'behavior',
				'label'    => __( 'Block gambling / betting sites', 'agnosis' ),
				'input'    => 'checkbox',
				'default'  => 0,
				'type'     => 'boolean',
				'sanitize' => fn( $v ) => (bool) $v,
				'desc'     => __( 'Only takes effect when AI review (above) is enabled.', 'agnosis' ),
			],
			'agnosis_embed_block_custom' => [
				'tab'      => 'behavior',
				'label'    => __( 'Additional disallowed categories', 'agnosis' ),
				'input'    => 'textarea',
				'rows'     => 3,
				'sanitize' => 'sanitize_textarea_field',
				'default'  => '',
				'desc'     => __( 'Optional — describe any other kind of content the AI should reject, in plain language (e.g. "hate speech or violent extremist content", "phishing or scam pages"). Only takes effect when AI review (above) is enabled.', 'agnosis' ),
			],

			// --- NETWORK ---
			'agnosis_node_label' => [
				'tab'     => 'network',
				'label'   => __( 'Node label', 'agnosis' ),
				'desc'    => __( 'How this node introduces itself to the network.', 'agnosis' ),
				'default' => get_bloginfo( 'name' ),
			],
			'agnosis_activitypub_enabled' => [
				'tab'     => 'network',
				'label'   => __( 'Enable ActivityPub federation', 'agnosis' ),
				'input'   => 'checkbox',
				'default' => 1,
				'type'    => 'boolean',
				'desc'    => __( 'Broadcast new artworks to Mastodon, Pixelfed and the Fediverse.', 'agnosis' ),
				'sanitize' => fn( $v ) => (bool) $v,
			],
			'agnosis_public_key' => [
				'tab'   => 'network',
				'label' => __( 'Node public key', 'agnosis' ),
				'input' => 'readonly',
				'desc'  => __( 'Auto-generated RSA public key for this node. Share this with peer nodes.', 'agnosis' ),
			],
			// --- COMMUNITY ---
			// Note: the outbound "From:" identity for workflow mail used to live
			// here as agnosis_community_from_name/email. Moved to Settings → Email
			// ("Mail from:") in 0.9.22 — the word "community" was easily confused
			// with the unrelated agnosis_email_community INBOUND endpoint below.
			// Existing values are migrated automatically (Core\Activator::maybe_upgrade()).
			'agnosis_goodbye_request_limit' => [
				'tab'      => 'community',
				'label'    => __( 'Self-removal (goodbye) requests per sender per day', 'agnosis' ),
				'input'    => 'number',
				'default'  => 3,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Maximum number of self-removal ("goodbye") confirmation emails a single sender address can trigger per day. A genuine self-removal only ever needs to be requested once — this caps how many confirmation emails a spoofed or repeated From can be used to spam a real artist with. Default: 3.', 'agnosis' ),
			],
			'agnosis_community_broadcast_limit' => [
				'tab'      => 'community',
				'label'    => __( 'Community broadcasts per artist per day', 'agnosis' ),
				'input'    => 'number',
				'default'  => 3,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Maximum number of messages a single artist can send to the Community announcement address (Settings → Email) per day. Prevents one member from flooding every other member\'s inbox. Default: 3.', 'agnosis' ),
			],
			'agnosis_community_broadcast_max_chars' => [
				'tab'      => 'community',
				'label'    => __( 'Community broadcast max length (characters)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 2000,
				'min'      => 1,
				'max'      => 20000,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, min( 20000, (int) $v ) ),
				'desc'     => __( 'Longer messages to the Community announcement address are bounced back to the sender instead of being broadcast — the sender is told to shorten it and resend. Measured in characters, not words, so it applies fairly across languages that don\'t use spaces between words (Chinese, Japanese, Thai, etc.). Every recipient\'s copy is translated individually, so this also caps how much a single message can cost in AI translation calls. Default: 2000. Hard maximum: 20000, regardless of this setting.', 'agnosis' ),
			],
			// --- COMMUNITY: Voting ---
			// A single switch covering BOTH community voting systems below
			// (admission vouching and removal nomination/voting) — a gallery or
			// other curated context may want a human admin deciding who joins
			// and who is asked to leave, not a community majority. The admin
			// override actions this falls back to already existed before this
			// setting did: Admission::admin_admit()/admin_reject() (Settings →
			// Community → Pending Applications) and Departure::admin_ban()/
			// admin_delete() (Settings → Community → Members) were always
			// available as a bypass alongside voting — this setting just makes
			// the community-vote path unavailable so admin action becomes the
			// only path, rather than an optional shortcut.
			//
			// Admission::record_vote() (REST vouch + the VouchConfirm email-link
			// vote) and Departure::nominate() (starting/advancing a NEW
			// nomination) both refuse while this is on; Admission's expiry cron
			// stops auto-rejecting applications past the voting window (with no
			// possible votes, that would silently reject everything an admin
			// hadn't gotten to yet) — applications simply wait for admin_admit()/
			// admin_reject(). A removal request already 'open' when this is
			// turned on is deliberately left alone: artists can still cast a
			// vote on it (Departure::record_vote_on_request(), reached via
			// cast_vote() or the RemovalVoteConfirm email link) and it resolves
			// normally via the existing check_expired_removal_votes() cron —
			// only *opening new* community votes (fresh nominations reaching
			// threshold, or Departure::admin_open_removal_vote()'s own admin
			// bypass) is blocked going forward.
			'agnosis_voting_disabled' => [
				'tab'      => 'community',
				'label'    => __( 'Admin approval only (disable community voting)', 'agnosis' ),
				'input'    => 'checkbox',
				'default'  => '0',
				'type'     => 'boolean',
				'sanitize' => fn( $v ) => (bool) $v,
				'desc'     => __( 'When enabled, artists can no longer vouch new applicants in or vote to remove a member — every admission and removal decision is made directly by an admin instead (Settings → Community → Pending Applications / Members). Applications wait indefinitely for an admin decision rather than auto-rejecting when no one can vote. A community removal vote already open when this is turned on still runs to its normal conclusion; only starting a new one is blocked. Off by default — most communities want the existing vouching/vote system.', 'agnosis' ),
			],

			'agnosis_admission_percent' => [
				'tab'     => 'community',
				'label'   => __( 'Admission vote threshold (%)', 'agnosis' ),
				'input'   => 'number',
				'default' => 10,
				'min'     => 0,
				'max'     => 100,
				'desc'    => __( 'Percentage of active artists that must vote yes for admission. Combined with the minimum floor below.', 'agnosis' ),
			],
			'agnosis_admission_minimum' => [
				'tab'     => 'community',
				'label'   => __( 'Admission minimum votes', 'agnosis' ),
				'input'   => 'number',
				'default' => 3,
				'min'     => 1,
				'desc'    => __( 'Absolute minimum positive votes required regardless of the percentage above.', 'agnosis' ),
			],
			'agnosis_admission_window_days' => [
				'tab'     => 'community',
				'label'   => __( 'Voting window (days)', 'agnosis' ),
				'input'   => 'number',
				'default' => 7,
				'min'     => 1,
				'desc'    => __( 'Days an application stays open. If the threshold is not reached within this window the application is rejected.', 'agnosis' ),
			],
			'agnosis_application_retention_days' => [
				'tab'      => 'community',
				'label'    => __( 'Resolved application retention (days)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 180,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'A rejected, withdrawn, or left application\'s email, name, biography, statement, and portfolio link are anonymized by the daily cleanup after this many days — the application record itself (status and dates) is kept for admission history. A banned artist\'s biography/statement/portfolio are anonymized on the same schedule, but their email is deliberately kept so the ban stays enforceable. Default: 180 days.', 'agnosis' ),
			],
			'agnosis_join_success_url' => [
				'tab'      => 'community',
				'label'    => __( 'After applying, send artists to', 'agnosis' ),
				'input'    => 'page',
				'default'  => 0,
				'sanitize' => 'absint',
				'desc'     => __( 'Optional — pick a page explaining the vouching process and what happens next. When the application is submitted successfully, the artist is redirected there instead of just seeing an inline confirmation message. Leave as "None" to keep the inline message only.', 'agnosis' ),
			],
			'agnosis_community_max_artists' => [
				'tab'     => 'community',
				'label'   => __( 'Community size cap', 'agnosis' ),
				'input'   => 'number',
				'default' => 50,
				'min'     => 0,
				'desc'    => __( 'Maximum number of admitted artists. When full, new applications join a waitlist instead of being rejected, and a freed slot admits the next in line. Set to 0 for no cap. The community can also vote to change this.', 'agnosis' ),
			],
			'agnosis_cap_proposal_threshold' => [
				'tab'     => 'community',
				'label'   => __( 'Cap-change co-signers required', 'agnosis' ),
				'input'   => 'number',
				'default' => 3,
				'min'     => 1,
				'desc'    => __( 'How many artists must co-sign a proposal to change the community size cap before it opens as a full community vote.', 'agnosis' ),
			],
			'agnosis_cap_vote_window_days' => [
				'tab'     => 'community',
				'label'   => __( 'Cap-change vote window (days)', 'agnosis' ),
				'input'   => 'number',
				'default' => 7,
				'min'     => 1,
				'desc'    => __( 'Days a community cap-change vote stays open. A strict majority of active artists voting yes adopts the new cap.', 'agnosis' ),
			],
			'agnosis_removal_nomination_threshold' => [
				'tab'      => 'community',
				'label'    => __( 'Removal nominations required', 'agnosis' ),
				'input'    => 'number',
				'default'  => 3,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Number of artist nominations needed before a community removal vote opens. Admins can bypass this threshold.', 'agnosis' ),
			],
			'agnosis_removal_window_days' => [
				'tab'      => 'community',
				'label'   => __( 'Removal vote window (days)', 'agnosis' ),
				'input'   => 'number',
				'default'  => 7,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Days a community removal vote stays open. A majority (>50%) of active artists must vote yes for removal to proceed.', 'agnosis' ),
			],
			'agnosis_invitation_intro' => [
				'tab'        => 'community',
				'label'      => __( 'Invitation intro', 'agnosis' ),
				'input'      => 'textarea',
				'rows'       => 6,
				'sanitize'   => 'sanitize_textarea_field',
				'default'    => __(
					"Agnosis is a small, self-hosted home for artists who'd rather make work than manage a platform. There's no algorithm deciding who sees your art, no portfolio site to maintain, and no account to configure — just your work, published and translated automatically for a global audience.\n\nBeing part of the community is simple: Fellow artists vouch for new applicants, and everyone keeps full ownership of what they publish; you can leave at any time and take your work with you.\n\nOnce admitted, you submit new work by sending it as an email — no dashboard, no forms and you can update, promote or remove your artwork as well sending an email. You can find more information visiting the agnosis.art website.",
					'agnosis'
				),
				'resettable' => true,
				'desc'       => __( 'Shown near the top of the "Send Invitation" email below (an "Apply to join" link and site name follow automatically — no need to add either here). Two or three short paragraphs is plenty. Standing copy, not cleared after use like the newsletter intros above — translated automatically when an invitation is sent in a language other than the site\'s own.', 'agnosis' ),
			],

			// --- DONATIONS ---
			// No fields here (yet). This tab used to hold agnosis_tx_fee_percent,
			// a 7%-platform-fee setting for a donation/art-sale split-payment
			// model — that model was decided against for 1.0.0 (C-1,
			// agnosis-audit/AUDIT-0.9.38.md §9: real per-artist payout splitting
			// needs a genuine Stripe Connect integration, out of scope) and its
			// backing agnosis_transactions table was dropped in 0.9.40 (see
			// Core\Activator::maybe_upgrade()). The plan going forward is a
			// simpler, no-fee visitor-donation feature instead; Agnosis leaves
			// marketplace/checkout functionality to dedicated commerce plugins.
			// Settings::render_tab_tools()'s 'donations' branch renders a status
			// card here in place of a settings field until that feature exists.

			// --- NEWSLETTER ---
			'agnosis_newsletter_from_name' => [
				'tab'   => 'newsletter',
				'label' => __( 'Sender name', 'agnosis' ),
				'desc'  => __( 'Name shown as the sender of digest emails. Leave blank to use the site name.', 'agnosis' ),
			],
			'agnosis_newsletter_from_email' => [
				'tab'      => 'newsletter',
				'label'    => __( 'Sender email address', 'agnosis' ),
				'sanitize' => 'sanitize_email',
				'desc'     => __( 'Dedicated From: address for both newsletters, e.g. newsletter@agnosis.art — keeps digest mail separate from the site\'s admin email for deliverability and so artists can filter it. Leave blank to use the admin email. Must be a valid, deliverable address on your domain (SPF/DKIM configured) or messages may be marked as spam.', 'agnosis' ),
			],
			'agnosis_newsletter_intro_proposal_enabled' => [
				'tab'      => 'newsletter',
				'label'    => __( 'Auto-draft newsletter intros', 'agnosis' ),
				'input'    => 'checkbox',
				'default'  => 1,
				'type'     => 'boolean',
				'sanitize' => fn( $v ) => (bool) $v,
				'desc'     => __( 'When enabled, Agnosis drafts an intro via AI ahead of each issue (see the lead time below), saves it to that newsletter\'s intro field, and emails you to review it — unless you\'ve already written one for that cycle. Disable to leave both intro fields exactly as you left them, always.', 'agnosis' ),
			],
			'agnosis_newsletter_intro_proposal_lead_hours' => [
				'tab'      => 'newsletter',
				'label'    => __( 'Draft intro this many hours ahead', 'agnosis' ),
				'input'    => 'number',
				'default'  => 24,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'How long before an issue is due Agnosis proposes an AI-drafted intro. Only takes effect when auto-drafting (above) is enabled. Default: 24.', 'agnosis' ),
			],
			'agnosis_newsletter_artist_enabled' => [
				'tab'     => 'newsletter',
				'label'   => __( 'Artist newsletter', 'agnosis' ),
				'input'   => 'checkbox',
				'default' => '1',
				'desc'    => __( 'Admitted artists are auto-enrolled (they can unsubscribe from any issue with one click). Community digest: recent activity, new members, open votes.', 'agnosis' ),
			],
			'agnosis_newsletter_artist_frequency_days' => [
				'tab'      => 'newsletter',
				'label'    => __( 'Artist newsletter frequency (days)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 30,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Default 30 ≈ monthly. A new issue is prepared once this many days have passed since the last one was sent.', 'agnosis' ),
			],
			'agnosis_newsletter_artist_intro' => [
				'tab'      => 'newsletter',
				'label'    => __( 'Artist newsletter intro', 'agnosis' ),
				'input'    => 'textarea',
				'rows'     => 4,
				'sanitize' => 'sanitize_textarea_field',
				'desc'     => __( 'Optional note prepended to the next artist issue only — cleared automatically once that issue is queued. Leave blank to send the auto-digest with no intro. If left blank, Agnosis drafts one automatically from what\'s new about a day before the issue sends, and emails you to review it first — write your own here any time to skip that.', 'agnosis' ),
			],
			'agnosis_newsletter_public_enabled' => [
				'tab'     => 'newsletter',
				'label'   => __( 'Public newsletter', 'agnosis' ),
				'input'   => 'checkbox',
				'default' => '1',
				'desc'    => __( 'Visitors subscribe via the agnosis/newsletter-signup block (double opt-in). Digest: new artwork and events published since the last issue.', 'agnosis' ),
			],
			'agnosis_newsletter_public_frequency_days' => [
				'tab'      => 'newsletter',
				'label'    => __( 'Public newsletter frequency (days)', 'agnosis' ),
				'input'    => 'number',
				'default'  => 30,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Default 30 ≈ monthly.', 'agnosis' ),
			],
			'agnosis_newsletter_public_intro' => [
				'tab'      => 'newsletter',
				'label'    => __( 'Public newsletter intro', 'agnosis' ),
				'input'    => 'textarea',
				'rows'     => 4,
				'sanitize' => 'sanitize_textarea_field',
				'desc'     => __( 'Optional note prepended to the next public issue only — cleared automatically once that issue is queued. If left blank, Agnosis drafts one automatically from what\'s new about a day before the issue sends, and emails you to review it first — write your own here any time to skip that.', 'agnosis' ),
			],
			'agnosis_newsletter_batch_size' => [
				'tab'      => 'newsletter',
				'label'    => __( 'Send batch size', 'agnosis' ),
				'input'    => 'number',
				'default'  => 20,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Recipients emailed per 5-minute cron tick. Lower this if your host throttles outbound mail; raise it for faster delivery on hosts with generous limits.', 'agnosis' ),
			],
			'agnosis_newsletter_subscriber_warn_threshold' => [
				'tab'      => 'newsletter',
				'label'    => __( 'Self-hosting comfort threshold', 'agnosis' ),
				'input'    => 'number',
				'default'  => 250,
				'min'      => 1,
				'type'     => 'integer',
				'sanitize' => fn( $v ) => max( 1, (int) $v ),
				'desc'     => __( 'Advisory only — self-hosted sending keeps working above this count. Once confirmed public subscribers pass it, this page shows a reminder to consider an email service provider (e.g. Brevo\'s free tier) instead.', 'agnosis' ),
			],
		];
	}

	/**
	 * Sanitize a color-field submission, falling back to $default when the
	 * submitted value isn't a real `#rgb`/`#rrggbb` hex color — e.g. an
	 * emptied field (browsers never actually submit an empty `<input
	 * type="color">`, but a direct POST/REST write could) or a stray value
	 * from an old install. Mirrors EmailTemplate::header_bg()/accent()'s own
	 * sanitize-fallback shape, kept here too since register_setting()'s
	 * `sanitize_callback` needs a pure function of the submitted value with
	 * no access to which field it's sanitizing.
	 */
	private static function sanitize_color( string $value, string $fallback ): string {
		$sanitized = sanitize_hex_color( $value );
		return null !== $sanitized && '' !== $sanitized ? $sanitized : $fallback;
	}
}
