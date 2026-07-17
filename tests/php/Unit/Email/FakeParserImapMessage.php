<?php
/**
 * Real Webklex\PHPIMAP\Message SUBCLASS test double for
 * Parser::parse_imap_message(), which strictly type-hints the real `Message`
 * class (unlike Inbox::passes_email_auth(), which deliberately accepts a
 * plain `object` — see FakeAliasImapMessage's docblock for why that one is a
 * plain class instead). A plain unrelated fake object cannot satisfy that
 * type hint, so this one genuinely extends Message.
 *
 * Message's own getFrom()/getTo()/getCc()/getSubject()/getUid() are NOT real
 * declared methods — they're dispatched through Message::__call() from
 * `@method` phpdoc annotations only, which is exactly why a PHPUnit
 * createMock(Message::class) can't be used to stub them (PHPUnit's mock
 * builder only overrides genuinely declared methods, and `getStructure()` /
 * `getTextBody()` / `getAttachments()` / `parseBody()` ARE real declared
 * methods with their own strict return types). This subclass instead
 * declares real, concrete override methods for every one of these — a
 * child class's real method takes priority over the parent's `__call()`
 * magic dispatch, so no parent logic (which expects a live IMAP connection)
 * ever runs. The constructor is overridden to a no-op for the same reason.
 *
 * getStructure() always returns null and parseBody() is a no-op returning
 * $this — parse_imap_message() only uses $structure for a diagnostic log
 * line and a needs_fetch decision, never to gate the return value, so this
 * is safe and keeps this double from needing a real Structure object at all.
 *
 * @package Agnosis\Tests\Unit\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Email;

use Agnosis\Vendor\Webklex\PHPIMAP\Message;
use Agnosis\Vendor\Webklex\PHPIMAP\Structure;
use Agnosis\Vendor\Webklex\PHPIMAP\Support\AttachmentCollection;

/**
 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */
class FakeParserImapMessage extends Message {

	/**
	 * @param string[] $to_emails
	 * @param string[] $cc_emails
	 * @param FakeImapAttachment[] $fake_attachments
	 */
	public function __construct(
		private string $from_email = 'artist@example.com',
		private array $to_emails = [],
		private array $cc_emails = [],
		private string $subject_text = 'Test Subject',
		private string $text_body = 'Test body.',
		private int $uid = 1,
		private array $fake_attachments = [],
		private bool $cc_unsupported = false,
		// Audit §6a (AUDIT-1.0.0.md) HTML-only fallback fixture support —
		// defaults to '' so every pre-existing construction (which never
		// passed this param) stays a plain-text-only message, unchanged.
		private string $html_body = ''
	) {}

	/** @return array<int, object{mail: string}> */
	private function address_list( array $emails ): object {
		return new class( $emails ) {
			/** @param array<int, string> $emails */
			public function __construct( private array $emails ) {}

			/** @return array<int, object{mail: string}> */
			public function toArray(): array {
				return array_map( static fn( string $e ) => (object) [ 'mail' => $e ], $this->emails );
			}
		};
	}

	public function getFrom(): object {
		return $this->address_list( '' !== $this->from_email ? [ $this->from_email ] : [] );
	}

	public function getTo(): object {
		return $this->address_list( $this->to_emails );
	}

	public function getCc(): object {
		if ( $this->cc_unsupported ) {
			throw new \RuntimeException( 'This message double does not support getCc() — simulates a test fake without it, exercising the try/catch fallback.' );
		}
		return $this->address_list( $this->cc_emails );
	}

	public function getSubject(): string {
		return $this->subject_text;
	}

	public function getUid(): int {
		return $this->uid;
	}

	public function getTextBody(): string {
		return $this->text_body;
	}

	public function getHTMLBody(): string {
		return $this->html_body;
	}

	public function getStructure(): ?Structure {
		return null;
	}

	public function parseBody(): Message {
		return $this;
	}

	public function getAttachments(): AttachmentCollection {
		return new AttachmentCollection( $this->fake_attachments );
	}
}
// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
