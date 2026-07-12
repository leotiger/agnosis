<?php
/**
 * Integration tests for PostCreator::build_post_content()'s single-image
 * skip logic, added 2026-07-13.
 *
 * Both single-agnosis_biography.html and single-agnosis_event.html
 * (agnosis-theme) render wp:post-featured-image above wp:post-content.
 * write_post_meta() already sets a gallery's lone image (or a video's poster
 * frame) as the post's featured image whenever the gallery is non-empty —
 * build_post_content() used to ALSO prepend that same image as a leading
 * wp:image block, so it appeared twice: once as the featured/hero image,
 * once again at the top of the body. Artwork's template has no
 * featured-image block at all (the content IS the gallery there), so it was
 * never affected.
 *
 * The method is private and exercised via ReflectionMethod (same convention
 * PostCreatorGalleryAudioSkipTest already uses for merge_gallery()).
 *
 * @package Agnosis\Tests\Integration\Publishing
 */

declare(strict_types=1);

namespace Agnosis\Tests\Integration\Publishing;

use Agnosis\AI\Pipeline;
use Agnosis\Publishing\PostCreator;

class PostCreatorDuplicateHeroImageTest extends \WP_UnitTestCase {

	private PostCreator $creator;

	protected function setUp(): void {
		parent::setUp();

		$pipeline = new class() extends Pipeline {
			public function __construct() {}
		};

		$this->creator = new PostCreator( $pipeline );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $primary
	 * @param int[]                $gallery
	 */
	private function call_build_post_content( array $primary, array $gallery, string $post_type, string $artist_text = '' ): string {
		$ref = new \ReflectionMethod( PostCreator::class, 'build_post_content' );
		$ref->setAccessible( true );
		return $ref->invoke( $this->creator, $primary, $gallery, $post_type, $artist_text );
	}

	/** A real Media Library attachment with an image/* mime type. */
	private function create_image_attachment(): int {
		return (int) self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );
	}

	/**
	 * A "video" attachment carrying a linked poster-frame image —
	 * pick_thumbnail_id() prefers this poster over any genuine image
	 * attachment elsewhere in the same gallery (see PostCreator's own
	 * docblock on that method).
	 *
	 * @return array{0: int, 1: int} [video_attachment_id, poster_attachment_id]
	 */
	private function create_video_with_poster(): array {
		$video_id  = (int) self::factory()->attachment->create( [ 'post_mime_type' => 'video/mp4' ] );
		$poster_id = $this->create_image_attachment();
		update_post_meta( $video_id, '_agnosis_video_poster_id', $poster_id );
		return [ $video_id, $poster_id ];
	}

	// -------------------------------------------------------------------------
	// Biography / event: lone image duplicated no more
	// -------------------------------------------------------------------------

	public function test_biography_solo_image_is_not_duplicated_in_content(): void {
		$image_id = $this->create_image_attachment();

		$content = $this->call_build_post_content( [], [ $image_id ], 'agnosis_biography', 'My biography text.' );

		$this->assertStringNotContainsString( 'wp:image', $content, 'The lone image already becomes the featured image — it must not also appear as a leading content block.' );
		$this->assertStringContainsString( 'My biography text.', $content );
	}

	public function test_event_solo_image_is_not_duplicated_in_content(): void {
		$image_id = $this->create_image_attachment();

		$content = $this->call_build_post_content( [], [ $image_id ], 'agnosis_event', 'Join us for the opening.' );

		$this->assertStringNotContainsString( 'wp:image', $content );
		$this->assertStringContainsString( 'Join us for the opening.', $content );
	}

	// -------------------------------------------------------------------------
	// Artwork: unaffected — its template has no featured-image block, the
	// gallery block IS the page's visual content.
	// -------------------------------------------------------------------------

	public function test_artwork_solo_image_still_included_in_content(): void {
		$image_id = $this->create_image_attachment();

		$content = $this->call_build_post_content( [ 'body' => 'AI-written description.' ], [ $image_id ], 'agnosis_artwork' );

		$this->assertStringContainsString( 'wp:image', $content, 'Artwork has no featured-image block in its template — the content block is the only place the image appears.' );
	}

	// -------------------------------------------------------------------------
	// Multi-image galleries are untouched — the featured image only ever
	// covers ONE of them, so the rest must still render somewhere.
	// -------------------------------------------------------------------------

	public function test_event_multi_image_gallery_still_renders_the_gallery_block(): void {
		$first  = $this->create_image_attachment();
		$second = $this->create_image_attachment();

		$content = $this->call_build_post_content( [], [ $first, $second ], 'agnosis_event', 'Multiple photos from the venue.' );

		$this->assertStringContainsString( 'wp:gallery', $content, 'A genuine multi-image gallery must still render — only a single-image, featured-image-duplicating case is skipped.' );
	}

	// -------------------------------------------------------------------------
	// The precise edge case the fix has to get right: a lone photo submitted
	// ALONGSIDE a video (whose poster frame wins the featured-image slot
	// instead of the photo — see pick_thumbnail_id()) must NOT be suppressed,
	// or it would vanish from the post entirely.
	// -------------------------------------------------------------------------

	public function test_event_solo_photo_alongside_a_video_is_not_suppressed(): void {
		[ $video_id, $poster_id ] = $this->create_video_with_poster();
		$photo_id                 = $this->create_image_attachment();

		// Order matters for MediaAdapter's real pipeline (video first is typical),
		// but build_post_content() itself just iterates whatever order it's given.
		$content = $this->call_build_post_content( [], [ $video_id, $photo_id ], 'agnosis_event', 'Event recap.' );

		$this->assertStringContainsString(
			'wp:image',
			$content,
			'pick_thumbnail_id() picks the VIDEO\'s poster as the featured image here, not this photo — so the photo is not the duplicated attachment and must still get its own content block.'
		);
	}
}
