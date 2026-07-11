<?php
/**
 * PHPUnit bootstrap — unit tests only.
 *
 * Unit tests run without WordPress. We define the minimum stubs needed so that
 * the plugin source can be loaded. Integration tests use the full wp-env stack
 * and have their own bootstrap loaded by phpunit.xml.dist.
 *
 * @package Agnosis\Tests
 */

declare(strict_types=1);

// Namespace-scoped function overrides for unit tests (one file per namespace).
// Must be loaded before any test class that depends on them, so we require
// them here at bootstrap time. Each file only defines functions — no side effects.
require_once dirname( __DIR__ ) . '/tests/php/Unit/Email/Stubs/email_namespace_stubs.php';
require_once dirname( __DIR__ ) . '/tests/php/Unit/AI/Stubs/ai_namespace_stubs.php';

// Ensure vendor/ is available.
$autoload = __DIR__ . '/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
    echo "Run `composer install` inside dev/ first.\n";
    exit( 1 );
}
require_once $autoload;

// ---- Minimum WordPress stubs for unit tests ----

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', sys_get_temp_dir() . '/agnosis-tests/' );
}
if ( ! defined( 'AGNOSIS_VERSION' ) ) {
    define( 'AGNOSIS_VERSION', '0.1.0' );
}
if ( ! defined( 'AGNOSIS_DIR' ) ) {
    define( 'AGNOSIS_DIR', dirname( __DIR__ ) . '/' );
}
if ( ! defined( 'AGNOSIS_URL' ) ) {
    define( 'AGNOSIS_URL', 'http://localhost/' );
}
if ( ! defined( 'AGNOSIS_FILE' ) ) {
    define( 'AGNOSIS_FILE', dirname( __DIR__ ) . '/agnosis.php' );
}
if ( ! defined( 'AGNOSIS_BASENAME' ) ) {
    define( 'AGNOSIS_BASENAME', 'agnosis/agnosis.php' );
}

// Stub WP functions used at class-load time so unit tests don't need WP.
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data, int $options = 0, int $depth = 512 ): string|false {
        return json_encode( $data, $options, $depth );
    }
}
if ( ! function_exists( 'get_transient' ) ) {
    /** @var array<string, mixed> $agnosis_transients */
    $GLOBALS['agnosis_transients'] = [];
    function get_transient( string $transient ): mixed {
        return $GLOBALS['agnosis_transients'][ $transient ] ?? false;
    }
}
if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( string $transient, mixed $value, int $expiration = 0 ): bool {
        $GLOBALS['agnosis_transients'][ $transient ] = $value;
        return true;
    }
}
if ( ! function_exists( 'delete_transient' ) ) {
    function delete_transient( string $transient ): bool {
        unset( $GLOBALS['agnosis_transients'][ $transient ] );
        return true;
    }
}
if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( mixed $value ): mixed {
        return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $str ): string { return trim( $str ); }
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
    function sanitize_textarea_field( string $str ): string { return trim( $str ); }
}
if ( ! function_exists( 'sanitize_email' ) ) {
    function sanitize_email( string $email ): string { return filter_var( $email, FILTER_SANITIZE_EMAIL ) ?: ''; }
}
if ( ! function_exists( 'sanitize_file_name' ) ) {
    function sanitize_file_name( string $name ): string { return preg_replace( '/[^a-zA-Z0-9._-]/', '-', $name ); }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( string $url ): string { return filter_var( $url, FILTER_SANITIZE_URL ) ?: ''; }
}
if ( ! function_exists( 'wp_kses_post' ) ) {
    function wp_kses_post( string $data ): string { return strip_tags( $data, '<p><a><strong><em><ul><ol><li><br>' ); }
}
if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $key, $default = false ) { return $default; }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ): bool { return $thing instanceof \WP_Error; }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
    function is_user_logged_in(): bool { return false; }
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time( string $type ): string { return date( 'Y-m-d H:i:s' ); }
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( ...$args ): bool { return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( ...$args ): bool { return true; }
}
if ( ! function_exists( 'do_action' ) ) {
    function do_action( ...$args ): void {}
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( string $tag, $value, ...$args ) { return $value; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id(): int { return 0; }
}
if ( ! function_exists( 'get_userdata' ) ) {
    function get_userdata( int $user_id ) { return false; }
}
if ( ! function_exists( 'get_user_by' ) ) {
    function get_user_by( string $field, $value ) { return false; }
}
if ( ! function_exists( 'get_user_meta' ) ) {
    function get_user_meta( int $user_id, string $key = '', bool $single = false ) { return $single ? '' : []; }
}
if ( ! function_exists( 'update_user_meta' ) ) {
    function update_user_meta( int $user_id, string $meta_key, $meta_value ): bool { return true; }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
    // Unit tests never make real HTTP calls — always return a WP_Error.
    function wp_remote_post( string $url, array $args = [] ): \WP_Error {
        return new \WP_Error( 'http_disabled', 'HTTP requests are disabled in unit tests.' );
    }
}
if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( string $url, array $args = [] ): \WP_Error {
        return new \WP_Error( 'http_disabled', 'HTTP requests are disabled in unit tests.' );
    }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ): string { return ''; }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ) { return 0; }
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
    function wp_remote_retrieve_header( $response, string $header ): string { return ''; }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( int $length = 12, bool $special_chars = true ): string {
        return bin2hex( random_bytes( (int) ceil( $length / 2 ) ) );
    }
}
if ( ! function_exists( 'get_temp_dir' ) ) {
    // Reached by MediaAdapter::adapt_video()'s ffmpeg round-trip. 2026-07-06:
    // this now runs deterministically in every unit test run regardless of
    // whether ffmpeg is actually installed — MediaAdapterTest forces both the
    // "ffmpeg present" probe and the extraction command's own outcome via the
    // namespace-scoped shell_exec()/exec() overrides in
    // tests/php/Unit/AI/Stubs/ai_namespace_stubs.php.
    function get_temp_dir(): string { return rtrim( sys_get_temp_dir(), '/\\' ) . '/'; }
}
if ( ! function_exists( 'wp_delete_file' ) ) {
    function wp_delete_file( string $file ): void {
        if ( is_file( $file ) ) {
            unlink( $file );
        }
    }
}
if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = 'default' ): string { return $text; }
}
if ( ! function_exists( '_n' ) ) {
    function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
        return $number === 1 ? $single : $plural;
    }
}
if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( string $text, string $domain = 'default' ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( string $path = '' ): string { return 'http://localhost' . $path; }
}
if ( ! function_exists( 'is_ssl' ) ) {
    function is_ssl(): bool { return false; }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( string $text ): string { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'absint' ) ) {
    function absint( mixed $val ): int { return abs( (int) $val ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( string $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ); }
}
if ( ! function_exists( 'get_locale' ) ) {
    function get_locale(): string { return 'en_US'; }
}
if ( ! class_exists( 'WP_REST_Request' ) ) {
    /**
     * Minimal WP_REST_Request stub for unit tests.
     * Override get_header(), get_body(), get_param() per test via subclassing or mocking.
     */
    class WP_REST_Request {
        /** @var array<string, mixed> */
        private array $params  = [];
        /** @var array<string, string> */
        private array $headers = [];
        private string $body   = '';

        /** @param array<string, mixed> $params */
        public function __construct( array $params = [], array $headers = [], string $body = '' ) {
            $this->params  = $params;
            $this->headers = $headers;
            $this->body    = $body;
        }
        public function get_param( string $key ): mixed { return $this->params[ $key ] ?? null; }
        /** @param array<string, mixed> $params */
        public function set_params( array $params ): void { $this->params = $params; }
        public function get_header( string $key ): ?string { return $this->headers[ strtolower( $key ) ] ?? null; }
        public function get_body(): string { return $this->body; }
    }
}
if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public int $status;
        /** @var array<string, mixed> */
        public array $data;
        /** @param array<string, mixed> $data */
        public function __construct( array $data = [], int $status = 200 ) {
            $this->data   = $data;
            $this->status = $status;
        }
        /** @return array<string, mixed> */
        public function get_data(): array { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public function __construct(
            private string $code = '',
            private string $message = '',
            private mixed $data = ''
        ) {}
        public function get_error_message(): string { return $this->message; }
        public function get_error_code(): string { return $this->code; }
        // Real WP_Error::get_error_data() takes an optional $code and looks
        // up that specific error's data; this stub only ever holds one error
        // per instance (the only pattern used anywhere in this codebase), so
        // $code is accepted for signature compatibility but unused.
        public function get_error_data( string $code = '' ): mixed { return $this->data; }
    }
}

// ---- Fake Imagick, for machines where the real PHP extension isn't installed ----
//
// Only defined when the real classes aren't already loaded, so a machine that
// genuinely has Imagick installed still exercises the real thing end-to-end —
// this is purely a stand-in for machines/CI runners that don't. Combined with
// the extension_loaded() override in tests/php/Unit/AI/Stubs/ai_namespace_stubs.php
// (which lets MediaAdapterTest force MediaAdapter's "is Imagick available?"
// check in either direction on demand), this means every Imagick-dependent
// unit test runs deterministically regardless of what's actually installed —
// no test in MediaAdapterTest ever skips for environment reasons. 2026-07-06:
// added specifically because the "Imagick unavailable" fallback tests could
// previously only run for real on a machine that genuinely lacked Imagick,
// and self-skipped everywhere else — the same class of problem already
// solved for ffmpeg via the shell_exec()/exec() overrides above.
//
// Tracks just enough state — current width/height, and a flat list of "page"
// dimensions for multi-page PDF rasterisation — to let MediaAdapter's own
// control flow and resize/rasterise math (proportional scaling, never
// upscaling, per-page iteration) be verified without any real image codec.
// Blobs are plain encoded strings, never real image bytes:
//   'FAKEJPEG:{w}x{h}'                  — a single flat image.
//   'FAKEHEIC:{w}x{h}'                  — a single flat image, same shape as
//                                         FAKEJPEG; a distinct prefix only for
//                                         test readability (MediaAdapter::
//                                         adapt_heic() doesn't care what the
//                                         source format was).
//   'FAKEPDF:{w1}x{h1}|{w2}x{h2}|...'   — a multi-page source.
// Anything else fed to readImageBlob() throws ImagickException, mirroring
// real Imagick's behaviour on unreadable/corrupt data — including a
// "libheif delegate not compiled in" failure, which looks identical to any
// other undecodable blob from the caller's point of view.
if ( ! class_exists( 'ImagickException' ) ) {
    // MediaAdapter::adapt_pdf() catches \ImagickException specifically (not
    // \Throwable), so a plain \Exception wouldn't satisfy that catch block —
    // this fake needs the exact same class name to be caught correctly.
    class ImagickException extends \Exception {}
}
if ( ! class_exists( 'ImagickPixel' ) ) {
    // MediaAdapter never calls a method on this — it's only ever constructed
    // and handed to Imagick::newImage() as a background colour, which the
    // fake below ignores entirely (colour is irrelevant to the width/height
    // math these tests actually verify).
    class ImagickPixel {
        public function __construct( string $color = '' ) {}
    }
}
if ( ! class_exists( 'Imagick' ) ) {
    class Imagick {
        const FILTER_LANCZOS = 1;

        private int $width      = 0;
        private int $height     = 0;
        /** @var array<int, array{0:int,1:int}> Parsed page dimensions — PDF sources only. */
        private array $pages    = [];
        private int $page_index = 0;

        public function newImage( int $width, int $height, ImagickPixel $background ): bool {
            $this->width  = $width;
            $this->height = $height;
            return true;
        }

        public function readImageBlob( string $data ): bool {
            if ( str_starts_with( $data, 'FAKEJPEG:' ) && preg_match( '/^FAKEJPEG:(\d+)x(\d+)$/', $data, $m ) ) {
                $this->width  = (int) $m[1];
                $this->height = (int) $m[2];
                $this->pages  = [ [ $this->width, $this->height ] ];
                return true;
            }
            if ( str_starts_with( $data, 'FAKEHEIC:' ) && preg_match( '/^FAKEHEIC:(\d+)x(\d+)$/', $data, $m ) ) {
                $this->width  = (int) $m[1];
                $this->height = (int) $m[2];
                $this->pages  = [ [ $this->width, $this->height ] ];
                return true;
            }
            if ( str_starts_with( $data, 'FAKEPDF:' ) ) {
                $pages = [];
                foreach ( explode( '|', substr( $data, strlen( 'FAKEPDF:' ) ) ) as $page ) {
                    if ( preg_match( '/^(\d+)x(\d+)$/', $page, $m ) ) {
                        $pages[] = [ (int) $m[1], (int) $m[2] ];
                    }
                }
                if ( empty( $pages ) ) {
                    throw new ImagickException( 'no decodable pages in fake PDF blob' );
                }
                $this->pages                     = $pages;
                $this->page_index                = 0;
                [ $this->width, $this->height ]  = $pages[0];
                return true;
            }
            throw new ImagickException( 'unable to decode fake image blob: ' . substr( $data, 0, 20 ) );
        }

        public function setResolution( int $x, int $y ): bool {
            return true; // No-op — resolution doesn't affect fake page dimensions.
        }

        public function setImageFormat( string $format ): bool {
            return true; // No-op — format is implicit in the fake blob's own prefix.
        }

        public function setImageCompressionQuality( int $quality ): bool {
            return true; // No-op.
        }

        public function getNumberImages(): int {
            return max( 1, count( $this->pages ) );
        }

        public function setIteratorIndex( int $index ): bool {
            $this->page_index = $index;
            if ( isset( $this->pages[ $index ] ) ) {
                [ $this->width, $this->height ] = $this->pages[ $index ];
            }
            return true;
        }

        public function getImageWidth(): int {
            return $this->width;
        }

        public function getImageHeight(): int {
            return $this->height;
        }

        public function resizeImage( int $width, int $height, int $filter, float $blur ): bool {
            $this->width  = $width;
            $this->height = $height;
            return true;
        }

        public function getImageBlob(): string {
            return sprintf( 'FAKEJPEG:%dx%d', $this->width, $this->height );
        }

        public function destroy(): bool {
            return true;
        }
    }
}
