<?php
/**
 * Minimal webklex Attachment double for FakeParserImapMessage's
 * getAttachments() — duck-typed only (Parser::parse_imap_message() never
 * type-hints the individual attachment, only iterates whatever
 * getAttachments() returns), so this does not need to extend the real
 * Webklex\PHPIMAP\Attachment class at all.
 *
 * @package Agnosis\Tests\Unit\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Unit\Email;

/**
 * phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */
class FakeImapAttachment {

	public function __construct(
		private string $mime,
		private string $name,
		private string $content,
		private string $declared_content_type = '',
		private string $disposition = 'attachment'
	) {}

	public function getMimeType(): string {
		return $this->mime;
	}

	public function getContentType(): string {
		return '' !== $this->declared_content_type ? $this->declared_content_type : $this->mime;
	}

	public function getName(): string {
		return $this->name;
	}

	public function getDisposition(): string {
		return $this->disposition;
	}

	public function getContent(): string {
		return $this->content;
	}
}
// phpcs:enable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
