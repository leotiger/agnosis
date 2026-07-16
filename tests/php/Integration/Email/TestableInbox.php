<?php
/**
 * Testable subclass of Inbox for UID cursor integration tests.
 *
 * Overrides query_messages() to inject a controlled MessageCollection so tests
 * exercise cursor-advancement logic without requiring a live IMAP connection.
 *
 * @package Agnosis\Tests\Integration\Email
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Email;

use Agnosis\Email\Inbox;
use Agnosis\Vendor\Webklex\PHPIMAP\Folder;
use Agnosis\Vendor\Webklex\PHPIMAP\Support\MessageCollection;

class TestableInbox extends Inbox {

	/** Messages the next poll should return. */
	private array $pending_messages = [];

	/** The $last_uid value passed to the most recent query_messages() call. */
	public ?int $last_query_uid = null;

	public function set_pending_messages( array $messages ): void {
		$this->pending_messages = $messages;
	}

	/**
	 * Override: record the $last_uid argument and return the injected collection.
	 *
	 * @param Folder $folder   Ignored — no IMAP connection in tests.
	 * @param int    $last_uid The UID cursor value passed by process_messages().
	 * @return MessageCollection
	 */
	protected function query_messages( Folder $folder, int $last_uid ): MessageCollection {
		$this->last_query_uid = $last_uid;
		$collection           = new MessageCollection();
		foreach ( $this->pending_messages as $msg ) {
			$collection->push( $msg );
		}
		return $collection;
	}

	/**
	 * Expose process_messages() for direct invocation in tests.
	 *
	 * process_messages() is private on Inbox; reflection is the least-invasive
	 * way to reach it without changing production visibility.
	 *
	 * @param Folder $folder Passed through to process_messages().
	 */
	public function run_process_messages( Folder $folder ): void {
		$ref = new \ReflectionMethod( Inbox::class, 'process_messages' );
		$ref->setAccessible( true );
		$ref->invoke( $this, $folder );
	}
}
