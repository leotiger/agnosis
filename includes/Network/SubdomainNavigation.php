<?php
/**
 * Subdomain navigation aids — artist-breadcrumb block, and the theme-facing
 * link fix that lets a visitor get back to the main Agnosis site from an
 * artist subdomain.
 *
 * Both concerns exist because SubdomainRouter rewrites `option_home` to the
 * artist's own subdomain for the whole request: `wp:site-logo` and
 * `wp:site-title` (or anything else built from `home_url()`) end up linking
 * to the artist's own page, not the portal, and nothing else on the page
 * otherwise identifies which artist a visitor is looking at (see
 * SubdomainRouter's `option_blogname` note).
 *
 * This lives in the plugin — not in a specific theme's functions.php — so
 * that any theme built against Agnosis gets both behaviours automatically
 * just by using core's Site Logo / Site Title blocks and, optionally,
 * inserting the `agnosis/artist-breadcrumb` block wherever it wants the
 * artist identified.
 *
 * @package Agnosis\Network
 */

declare(strict_types=1);

namespace Agnosis\Network;

use Agnosis\AI\SubmissionTranslator;
use Agnosis\Artist\ContactForm;
use Agnosis\Compat\LinguaForge;

class SubdomainNavigation {

	// -------------------------------------------------------------------------
	// Block: agnosis/artist-breadcrumb
	// -------------------------------------------------------------------------

	/**
	 * Register the agnosis/artist-breadcrumb dynamic block.
	 *
	 * block.json lives in blocks/artist-breadcrumb/ relative to the plugin root.
	 */
	public function register_block(): void {
		register_block_type(
			\AGNOSIS_DIR . 'blocks/artist-breadcrumb',
			[ 'render_callback' => [ $this, 'render_block' ] ]
		);
	}

	/**
	 * PHP render_callback for the agnosis/artist-breadcrumb block.
	 *
	 * Renders nothing at all — not an empty element — off an artist subdomain,
	 * so themes can drop it into a shared template part without an extra
	 * conditional and never show a stray blank line on the main site.
	 *
	 * Text/background color and font size are editable per-instance via the
	 * block's own Color/Typography inspector panels (block.json "supports"):
	 * get_block_wrapper_attributes() turns whatever the editor picked into the
	 * matching class(es)/inline style on the wrapper. Nothing picked in the
	 * editor falls back to the theme's own `.agnosis-artist-breadcrumb` CSS.
	 *
	 * The artist's name links to their own subdomain home
	 * (`SubdomainRouter::url_for_artist()`) — the breadcrumb then doubles as a
	 * "back to the artist's home" link from any other page on that subdomain
	 * (an artwork, biography, or event single), not just an identifying label.
	 *
	 * The name and the "Biography"/"Events" links are two separate groups —
	 * the name is never mixed in with anything else. The theme lays the two
	 * groups out on opposite sides (name on the reading-start side, links on
	 * the reading-end side — i.e. left/right, or the reverse on RTL) via
	 * `.agnosis-artist-breadcrumb`'s flex layout.
	 *
	 * 2026-07-10: Biography/Events used to render as translated text links
	 * ("Biography" / "Events", pipe-separated from each other when both were
	 * present). On mobile, some translations of those two words together
	 * were wide enough to overflow or wrap awkwardly next to the artist's
	 * name. Both are now icon-only links — a fixed-width glyph regardless of
	 * locale — with the translated word moved to `aria-label`/`title`
	 * (screen readers and hover tooltips still get it; sighted mobile
	 * visitors get a compact, self-explanatory icon). Same
	 * stroke="currentColor" 24×24-viewBox convention `PopoverBlock::ICONS`
	 * already established for this plugin's icon buttons. No separator
	 * needed between the two any more — `.agnosis-artist-breadcrumb__links`'s
	 * flex `gap` spaces them evenly whether one or both are present.
	 *
	 * Icon choice (`biographyIcon`/`eventsIcon`), size (`iconSize`), color
	 * (`iconColor`), and vertical alignment (`iconVerticalAlign`) are editable
	 * per-instance from the block's own Inspector panel (block.json
	 * "attributes" — plain per-instance attributes here, not block *supports*,
	 * since there's no built-in support for "recolor/realign just this inner
	 * element" the way there is for the whole block's text color).
	 * `iconColor` defaults to '' (unset), in which case the icons keep
	 * inheriting `currentColor` from the block's own text-color support
	 * exactly as before — setting it only overrides that for the icons
	 * specifically, leaving the artist name's color untouched. Likewise
	 * `iconVerticalAlign` defaults to 'baseline', matching the pre-existing
	 * behaviour of just inheriting `.agnosis-artist-breadcrumb`'s own
	 * `align-items: baseline` — icon-only glyphs (no text baseline of their
	 * own) can sit visibly high/low against the name's text baseline once
	 * `iconSize` is turned up or down from its default, so this lets that be
	 * corrected per-instance instead of only in theme CSS.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public function render_block( array $attributes = [] ): string {
		$artist_id = SubdomainRouter::current_artist_id();

		if ( ! $artist_id ) {
			return '';
		}

		$name = $this->artist_name( $artist_id );

		if ( '' === $name ) {
			return '';
		}

		$wrapper_attributes = get_block_wrapper_attributes( [ 'class' => 'agnosis-artist-breadcrumb' ] );
		$url                = SubdomainRouter::url_for_artist( $artist_id );

		$name_link = sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html( $name ) );
		$markup    = sprintf( '<span class="agnosis-artist-breadcrumb__name">%s</span>', $name_link );

		$icon_size     = max( 12, (int) ( $attributes['iconSize'] ?? 18 ) );
		$icon_color    = sanitize_hex_color( (string) ( $attributes['iconColor'] ?? '' ) ) ?: '';
		$bio_icon      = (string) ( $attributes['biographyIcon'] ?? 'book' );
		$events_icon   = (string) ( $attributes['eventsIcon'] ?? 'calendar' );
		$vertical_align = self::VERTICAL_ALIGN_VALUES[ (string) ( $attributes['iconVerticalAlign'] ?? 'baseline' ) ] ?? 'baseline';

		$secondary_links = [];

		$bio_url = $this->biography_permalink( $artist_id );
		if ( '' !== $bio_url ) {
			$secondary_links[] = $this->icon_link( $bio_url, 'biography', $bio_icon, __( 'Biography', 'agnosis' ), $icon_size );
		}

		if ( $this->has_published_post( 'agnosis_event', $artist_id ) ) {
			$events_url = (string) get_post_type_archive_link( 'agnosis_event' );
			if ( '' !== $events_url ) {
				$secondary_links[] = $this->icon_link( $events_url, 'events', $events_icon, __( 'Events', 'agnosis' ), $icon_size );
			}
		}

		if ( $secondary_links ) {
			$links_style_rules   = [ 'align-self:' . $vertical_align ];
			if ( '' !== $icon_color ) {
				$links_style_rules[] = 'color:' . $icon_color;
			}
			$markup .= sprintf(
				'<span class="agnosis-artist-breadcrumb__links" style="%s">%s</span>',
				esc_attr( implode( ';', $links_style_rules ) ),
				implode( '', $secondary_links )
			);
		}

		return sprintf( '<div %s>%s</div>', $wrapper_attributes, $markup );
	}

	/**
	 * Whitelist mapping the Inspector's `iconVerticalAlign` attribute values
	 * to real `align-self` CSS values — the attribute is user-editable (any
	 * artist/admin with block-editor access), so this is resolved through a
	 * fixed map rather than writing the raw attribute value into `style="..."`
	 * directly.
	 *
	 * @var array<string, string>
	 */
	private const VERTICAL_ALIGN_VALUES = [
		'baseline' => 'baseline',
		'top'      => 'flex-start',
		'middle'   => 'center',
		'bottom'   => 'flex-end',
	];

	/**
	 * Icon-only stroke SVGs for the breadcrumb's Biography/Events links — a
	 * couple of variants per link, selectable from the block's Inspector
	 * panel (`biographyIcon`/`eventsIcon` attributes). Same 24×24-viewBox,
	 * stroke="currentColor" Feather-style convention as
	 * `Newsletter\PopoverBlock::ICONS`. Raw, hand-authored markup — never
	 * user input. Kept in sync by hand with editor.js's `ICON_MARKUP` (same
	 * "vanilla JS, no build step" tradeoff `PopoverBlock`'s own icon picker
	 * already made).
	 *
	 * @var array<string, array<string, string>>
	 */
	private const LINK_ICON_SETS = [
		'biography' => [
			'book' => '<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>',
			'user' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle>',
		],
		'events' => [
			'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>',
			'pin'      => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle>',
		],
		// 2026-07-12: added alongside the new contact-form feature — this
		// entry's 'type' is handled entirely differently to the two above
		// (a popover trigger + panel, not a plain <a href>; see
		// render_contact_icon_link()) but shares this same const array so its
		// icon glyphs stay next to biography/events' own, and so editor.js's
		// icon picker (kept in sync by hand — see that file's own comment)
		// has one single JS-side mirror of this whole array, not two.
		'contact' => [
			'mail'    => '<rect x="2" y="4" width="20" height="16" rx="2"></rect><path d="m2 6 10 7 10-7"></path>',
			'message' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>',
		],
	];

	/**
	 * Build one icon-only breadcrumb link. The translated word never appears
	 * as visible text — it's the link's `aria-label` (for screen readers)
	 * and `title` (for a mouse-hover tooltip on desktop) — see render_block()'s
	 * docblock for why.
	 */
	private function icon_link( string $url, string $link_type, string $icon_key, string $label, int $size ): string {
		$set  = self::LINK_ICON_SETS[ $link_type ] ?? [];
		$path = $set[ $icon_key ] ?? reset( $set ) ?: '';

		return sprintf(
			'<a href="%1$s" aria-label="%2$s" title="%2$s"><svg width="%3$d" height="%3$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">%4$s</svg></a>',
			esc_url( $url ),
			esc_attr( $label ),
			$size,
			$path
		);
	}

	// -------------------------------------------------------------------------
	// Blocks: agnosis/artist-name-link, agnosis/breadcrumb-icon-link
	// -------------------------------------------------------------------------

	/**
	 * 2026-07-10: split out of the single `agnosis/artist-breadcrumb` block
	 * above (which is untouched and keeps working exactly as before — any
	 * site whose header template part is already customized in the database
	 * still has it, and WordPress has no mechanism to retroactively rewrite a
	 * site's saved template content when a theme/plugin update changes what
	 * the *default* template looks like). These two new blocks are the
	 * recommended way to build a breadcrumb going forward: a real Group block
	 * in the template does the layout (justify content, vertical alignment —
	 * all native Group-block toolbar controls, no custom attributes needed
	 * for that any more), `agnosis/artist-name-link` is the name, and
	 * `agnosis/breadcrumb-icon-link` (one instance per link, `type` attribute
	 * picks Biography vs Events — mirrors `core/social-link`'s single-block-
	 * type-plus-attribute shape) is each icon — each independently selectable
	 * and stylable through ordinary Gutenberg Color/Typography panels, instead
	 * of `artist-breadcrumb`'s bespoke iconSize/iconColor/iconVerticalAlign
	 * attributes. `agnosis-theme` 0.5.10's bundled header.html/header-pages.html
	 * use this new structure; the old block registration/render_callback
	 * above is kept solely for backward compatibility and receives no further
	 * feature work.
	 *
	 * 2026-07-10 (later same day): both blocks below correctly render '' off
	 * an artist subdomain, exactly like render_block() above — but unlike
	 * that single block, they now sit inside a plain `core/group` in the
	 * template (agnosis-theme 0.5.10's header.html), and a Group block has no
	 * "collapse to nothing when every child renders empty" behaviour of its
	 * own — it's static content, so its wrapper `<div>` (padding/gap/whatever
	 * the Inspector's Style panel set on it) still rendered on the main site,
	 * empty but visible, which is exactly the bug fixed by
	 * hide_empty_breadcrumb_group() below.
	 */
	public function register_artist_name_link_block(): void {
		register_block_type(
			\AGNOSIS_DIR . 'blocks/artist-name-link',
			[ 'render_callback' => [ $this, 'render_artist_name_link_block' ] ]
		);
	}

	/**
	 * PHP render_callback for the agnosis/artist-name-link block. Renders
	 * nothing at all off an artist subdomain, same reasoning as
	 * render_block()'s docblock above.
	 *
	 * Color/Typography are ordinary block *supports* here (block.json), so
	 * `get_block_wrapper_attributes()` alone carries whatever the Inspector's
	 * standard panels picked — no per-instance attributes to read.
	 *
	 * @param array<string, mixed> $attributes Block attributes (unused — no
	 *                                          custom attributes on this block).
	 * @return string
	 */
	public function render_artist_name_link_block( array $attributes = [] ): string {
		$artist_id = SubdomainRouter::current_artist_id();

		if ( ! $artist_id ) {
			return '';
		}

		$name = $this->artist_name( $artist_id );

		if ( '' === $name ) {
			return '';
		}

		$wrapper_attributes = get_block_wrapper_attributes();
		$url                = SubdomainRouter::url_for_artist( $artist_id );

		return sprintf( '<a %s href="%s">%s</a>', $wrapper_attributes, esc_url( $url ), esc_html( $name ) );
	}

	/**
	 * Register the agnosis/breadcrumb-icon-link dynamic block.
	 *
	 * block.json lives in blocks/breadcrumb-icon-link/ relative to the plugin
	 * root.
	 */
	public function register_breadcrumb_icon_link_block(): void {
		register_block_type(
			\AGNOSIS_DIR . 'blocks/breadcrumb-icon-link',
			[ 'render_callback' => [ $this, 'render_breadcrumb_icon_link_block' ] ]
		);
	}

	/**
	 * PHP render_callback for the agnosis/breadcrumb-icon-link block. Renders
	 * nothing at all off an artist subdomain, or when the artist has no
	 * biography/events yet — same "nothing, not an empty element" reasoning
	 * as render_block()'s docblock above.
	 *
	 * The icon is sized in `1em` (rather than a fixed pixel `width`/`height`
	 * the way `artist-breadcrumb`'s icons are) so the block's own Typography
	 * → Font Size support directly controls how big it renders, and
	 * `stroke="currentColor"` so the block's own Color → Text support directly
	 * recolors it — both ordinary block *supports* (block.json), read here
	 * only via `get_block_wrapper_attributes()`, same as
	 * `render_artist_name_link_block()` above. `type`/`icon` are this block's
	 * only real custom attributes, since there's no core supports equivalent
	 * for "which link" or "which glyph."
	 *
	 * `type=contact` is handled entirely separately (render_contact_icon_link())
	 * — a popover trigger + panel rather than a plain link — see that method's
	 * own docblock. `type=language` is likewise handled separately
	 * (render_language_badge()) — a plain-text badge with no href at all,
	 * rather than a link or a popover trigger.
	 *
	 * @param array<string, mixed> $attributes Block attributes ('type': 'biography'|'events'|'contact'|'language',
	 *                                          'icon': one of self::LINK_ICON_SETS[type]'s keys — unused for 'language').
	 * @return string
	 */
	public function render_breadcrumb_icon_link_block( array $attributes = [] ): string {
		$artist_id = SubdomainRouter::current_artist_id();

		if ( ! $artist_id ) {
			return '';
		}

		$type = (string) ( $attributes['type'] ?? 'biography' );
		if ( ! in_array( $type, [ 'biography', 'events', 'contact', 'language' ], true ) ) {
			$type = 'biography';
		}

		if ( 'contact' === $type ) {
			return $this->render_contact_icon_link( (int) $artist_id, $attributes );
		}

		if ( 'language' === $type ) {
			return $this->render_language_badge( (int) $artist_id );
		}

		if ( 'biography' === $type ) {
			$url          = $this->biography_permalink( $artist_id );
			$label        = __( 'Biography', 'agnosis' );
			$default_icon = 'book';
		} else {
			$url          = $this->has_published_post( 'agnosis_event', $artist_id )
				? (string) get_post_type_archive_link( 'agnosis_event' )
				: '';
			$label        = __( 'Events', 'agnosis' );
			$default_icon = 'calendar';
		}

		if ( '' === $url ) {
			return '';
		}

		$this->enqueue_breadcrumb_icon_link_assets();

		// self::LINK_ICON_SETS has exactly these two keys ('biography'/'events',
		// matching $type's only two possible values) and $default_icon is
		// always one of that set's own keys — both guaranteed by the const
		// array's own definition just below, not by anything at runtime, so
		// no '??' fallback is needed for either lookup. $icon_key is the only
		// genuinely unverified value here (an arbitrary block attribute), so
		// it's the only one that still needs one.
		$set      = self::LINK_ICON_SETS[ $type ];
		$icon_key = (string) ( $attributes['icon'] ?? '' );
		$path     = $set[ $icon_key ] ?? $set[ $default_icon ];

		$wrapper_attributes = get_block_wrapper_attributes();

		return sprintf(
			'<a %1$s href="%2$s" aria-label="%3$s" title="%3$s"><svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">%4$s</svg></a>',
			$wrapper_attributes,
			esc_url( $url ),
			esc_attr( $label ),
			$path
		);
	}

	/**
	 * Build the contact breadcrumb icon: a popover trigger button plus the
	 * popover panel itself (containing the agnosis/contact-form block) —
	 * unlike the biography/events branches above, this never renders a plain
	 * `<a href>`. Mirrors Newsletter\PopoverBlock's exact trigger-button +
	 * `popover="auto"` panel shape (native Popover API, no custom JS needed
	 * for the show/hide toggle itself — only the form submission inside the
	 * panel needs one, see blocks/contact-form/frontend.js).
	 *
	 * Renders nothing at all when the current artist has turned the form off
	 * (Artist\ContactForm::artist_accepts_contact()) — checked twice,
	 * deliberately: once here (so the trigger icon doesn't appear pointing at
	 * a popover with nothing useful inside it) and again inside
	 * ContactFormBlock::render_block() itself (so the form still renders
	 * correctly if that block is ever placed directly, outside this popover).
	 *
	 * @param array<string, mixed> $attributes Block attributes ('icon': one of
	 *                                          self::LINK_ICON_SETS['contact']'s keys).
	 */
	private function render_contact_icon_link( int $artist_id, array $attributes ): string {
		if ( ! ContactForm::artist_accepts_contact( $artist_id ) ) {
			return '';
		}

		// Standard WP block rendering rather than instantiating
		// ContactFormBlock directly — same reasoning as
		// Newsletter\PopoverBlock::render_block()'s identical call for
		// agnosis/newsletter-signup.
		$contact_form = render_block( [
			'blockName'    => 'agnosis/contact-form',
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => '',
			'innerContent' => [],
		] );

		if ( '' === $contact_form ) {
			return '';
		}

		$this->enqueue_breadcrumb_icon_link_assets();

		$set         = self::LINK_ICON_SETS['contact'];
		$icon_key    = (string) ( $attributes['icon'] ?? '' );
		$path        = $set[ $icon_key ] ?? $set['mail'];
		$artist_name = $this->artist_name( $artist_id );

		// Personalize both the trigger button's label and the popover title
		// with the artist's name when it resolves — falls back to the
		// generic wording ('artist_name()' already handles a user lookup
		// failure elsewhere, but this method's own caller only ever reaches
		// here after artist_accepts_contact() confirmed a real artist, so
		// the fallback is a defensive belt-and-suspenders, not an expected
		// path).
		$label = '' !== $artist_name
			/* translators: %s: artist display name */
			? sprintf( __( 'Contact %s', 'agnosis' ), $artist_name )
			: __( 'Contact this artist', 'agnosis' );

		$popover_title = '' !== $artist_name
			/* translators: %s: artist display name */
			? sprintf( __( 'Get in touch with %s', 'agnosis' ), $artist_name )
			: __( 'Get in touch', 'agnosis' );

		$wrapper_attributes = get_block_wrapper_attributes();

		ob_start();
		?>
		<button
			type="button"
			<?php echo $wrapper_attributes; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- get_block_wrapper_attributes() escapes its own output. ?>
			popovertarget="agnosis-contact-popover"
			popovertargetaction="show"
			aria-label="<?php echo esc_attr( $label ); ?>"
			title="<?php echo esc_attr( $label ); ?>"
		>
			<svg width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">
				<?php echo $path; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed, hand-authored markup from self::LINK_ICON_SETS, never user input. ?>
			</svg>
		</button>

		<div id="agnosis-contact-popover" class="agnosis-contact-popover" popover="auto">
			<button
				type="button"
				class="agnosis-contact-popover__close"
				popovertarget="agnosis-contact-popover"
				popovertargetaction="hide"
				aria-label="<?php esc_attr_e( 'Close', 'agnosis' ); ?>"
			>
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false">
					<path d="M4 4l16 16M20 4 4 20"></path>
				</svg>
			</button>

			<div class="agnosis-contact-popover__inner">
				<h2 class="agnosis-contact-popover__title"><?php echo esc_html( $popover_title ); ?></h2>
				<?php echo $contact_form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_block() output is already escaped by the contact-form block itself. ?>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build the language breadcrumb badge: the artist's native language as a
	 * plain two-letter ISO 639-1 code (e.g. "ES"), with the language's own
	 * native name — its endonym, e.g. "Español", "Deutsch", "日本語" — as the
	 * hover tooltip and screen-reader label. Unlike the biography/events/
	 * contact variants, this renders no `<a href>`/`<button>` at all — there's
	 * nowhere for it to link to, it's purely informational.
	 *
	 * The native name comes from `SubmissionTranslator::language_names()`,
	 * which is sourced from Lingua Forge's `linguaforge_language_label()` —
	 * that function deliberately renders a language's name IN ITS OWN
	 * language (via `locale_get_display_language( $lang, $lang )`, same
	 * locale on both sides), not translated into the current visitor's own
	 * page language. That's exactly the behavior wanted here: "ES" always
	 * tooltips as "Español", regardless of what language the visitor is
	 * currently browsing the site in.
	 *
	 * Language source: the WP 'locale' user meta already set on the artist's
	 * own account at admission time, from the language they picked on the
	 * join form (`Admission::iso_to_wp_locale()` maps the submitted ISO code
	 * to a WP locale string like 'es_ES' before storing it). Reusing this
	 * existing meta — rather than adding a dedicated field — means an artist
	 * admitted before this meta was introduced (pre-0.9.22), or any account
	 * created outside the join flow, has no locale set and renders nothing;
	 * it also means an artist who later changes their own wp-admin interface
	 * language (Users → Profile → Language writes this same meta key) changes
	 * what this badge shows too — an accepted tradeoff for not needing a
	 * second, separately-maintained "native language" field.
	 *
	 * The first two characters of the locale reconstruct the original ISO
	 * code correctly for every entry in `iso_to_wp_locale()`'s map (every
	 * value there either IS the bare ISO code, e.g. 'fi', 'ar', or starts
	 * with it followed by an underscore, e.g. 'es_ES') — except 'zh-tw',
	 * which maps to 'zh_TW' and reduces back to just 'zh', same as plain
	 * Chinese. That's an accepted loss of precision for a two-letter badge —
	 * exactly what was asked for — not a bug.
	 */
	private function render_language_badge( int $artist_id ): string {
		$locale = (string) get_user_meta( $artist_id, 'locale', true );
		$code   = strtolower( substr( $locale, 0, 2 ) );

		if ( '' === $code ) {
			return '';
		}

		$native_name = SubmissionTranslator::language_names()[ $code ] ?? '';

		$this->enqueue_breadcrumb_icon_link_assets();

		$extra_attributes = [ 'class' => 'agnosis-breadcrumb-icon-link--language' ];
		if ( '' !== $native_name ) {
			$extra_attributes['title']      = $native_name;
			$extra_attributes['aria-label'] = $native_name;
		}

		$wrapper_attributes = get_block_wrapper_attributes( $extra_attributes );

		return sprintf( '<span %1$s>%2$s</span>', $wrapper_attributes, esc_html( strtoupper( $code ) ) );
	}

	/**
	 * Minimal structural CSS for agnosis/breadcrumb-icon-link — just enough
	 * that the `<a>` behaves as an inline icon button (no stray line-height
	 * gap under the SVG) regardless of theme. Same "small, block-local
	 * frontend.css, enqueued only when the block actually renders something"
	 * pattern `Newsletter\PopoverBlock::enqueue_assets()` already established.
	 */
	private function enqueue_breadcrumb_icon_link_assets(): void {
		wp_enqueue_style(
			'agnosis-breadcrumb-icon-link',
			\AGNOSIS_URL . 'blocks/breadcrumb-icon-link/frontend.css',
			[],
			\AGNOSIS_VERSION
		);
	}

	/**
	 * CSS class marker `agnosis-theme` puts on the `core/group` block that
	 * wraps `agnosis/artist-name-link` + the icon-links Group in
	 * header.html/header-pages.html (see register_artist_name_link_block()'s
	 * later docblock for why this is needed at all).
	 */
	private const BREADCRUMB_GROUP_CLASS = 'agnosis-artist-breadcrumb-group';

	/**
	 * Strip the breadcrumb's wrapping `core/group` entirely off an artist
	 * subdomain, so it renders nothing at all — not an empty box — matching
	 * render_block()'s "renders nothing, not an empty element" behaviour for
	 * the older single-block version of this same breadcrumb.
	 *
	 * Scoped to ONLY the one Group block carrying `self::BREADCRUMB_GROUP_CLASS`
	 * in its `className` attribute (set directly in the theme template) —
	 * every other `core/group` on the site, including ones nested anywhere
	 * else, passes straight through untouched.
	 *
	 * Hooked on `render_block_core/group`, which WordPress calls with the
	 * block's fully-rendered inner content already in `$block_content` —
	 * including both `agnosis/artist-name-link` and the icon-links Group
	 * nested inside it, which is exactly why checking "is the artist ID
	 * present" here (rather than, say, checking whether $block_content
	 * happens to be blank/whitespace-only) is the correct gate: those inner
	 * blocks already correctly render '' off-subdomain, but the Group's own
	 * wrapper `<div>` — and any padding/gap/background its Style panel set —
	 * still renders regardless of how empty its content is, since a static
	 * Group block has no concept of "collapse when empty."
	 *
	 * @param string               $block_content Rendered block HTML.
	 * @param array<string, mixed> $block         Parsed block, incl. 'attrs'.
	 * @return string
	 */
	public function hide_empty_breadcrumb_group( string $block_content, array $block ): string {
		$class_name = (string) ( $block['attrs']['className'] ?? '' );

		if ( ! str_contains( ' ' . $class_name . ' ', ' ' . self::BREADCRUMB_GROUP_CLASS . ' ' ) ) {
			return $block_content;
		}

		return SubdomainRouter::current_artist_id() ? $block_content : '';
	}

	// -------------------------------------------------------------------------
	// Site Logo / Site Title → back to the main site
	// -------------------------------------------------------------------------

	/**
	 * On an artist subdomain, point the Site Logo and Site Title links at the
	 * main Agnosis site instead of the artist's own home.
	 *
	 * Both blocks link to home_url() by default, which
	 * SubdomainRouter::rewrite_home() rewrites to the current artist subdomain
	 * for every request — so without this, there is no way back to the portal
	 * from an artist's page. Each block renders exactly one link, so replacing
	 * the first `href` found is enough.
	 *
	 * @param string $block_content Rendered block HTML.
	 * @return string
	 */
	public function link_to_portal( string $block_content ): string {
		if ( ! SubdomainRouter::is_artist_subdomain() ) {
			return $block_content;
		}

		return (string) preg_replace(
			'/href="[^"]*"/',
			'href="' . esc_url( $this->portal_home_url() ) . '"',
			$block_content,
			1
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/** Display name for a given artist user ID, or '' if the user can't be found. */
	private function artist_name( int $artist_id ): string {
		$user = get_user_by( 'id', $artist_id );

		return $user ? (string) ( $user->display_name ?: $user->user_nicename ) : '';
	}

	/**
	 * Permalink of the artist's biography post, localized to the CURRENT
	 * request's language, or '' if they don't have one (yet).
	 *
	 * Biography is "singleton" only in the sense that intake never creates a
	 * second SOURCE-language post — but Lingua Forge translation fan-out
	 * still creates one additional `agnosis_biography` post per translated
	 * language once publish happens, so an artist can have several published
	 * biography posts. Without scoping to the source language first,
	 * `get_posts()` could return ANY of those siblings (ordered by date, not
	 * language) — which is exactly why this link used to always point at
	 * whichever translation happened to be created last, regardless of which
	 * language the visitor was actually reading. `localized_post()` then
	 * swaps in the visitor's own language's sibling when one exists,
	 * mirroring `Newsletter\Digest::localized_post()`'s exact fallback chain.
	 */
	private function biography_permalink( int $artist_id ): string {
		$query_args = [
			'post_type'      => 'agnosis_biography',
			'author'         => $artist_id,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		];

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Lingua Forge's public API; prefix belongs to that plugin.
		if ( function_exists( 'linguaforge_source_language' ) ) {
			$query_args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- single, cheap lookup, once per artist-breadcrumb render.
				'relation' => 'OR',
				[ 'key' => '_lf_lang', 'value' => linguaforge_source_language() ],
				[ 'key' => '_lf_lang', 'compare' => 'NOT EXISTS' ],
			];
		}

		$ids = get_posts( $query_args );
		if ( ! $ids ) {
			return '';
		}

		$post = get_post( $ids[0] );

		return $post instanceof \WP_Post ? (string) get_permalink( $this->localized_post( $post ) ) : '';
	}

	/**
	 * Resolve a post to its published translated counterpart in the CURRENT
	 * request's language (`LF_LANG`), or the post itself when Lingua Forge
	 * isn't active, the current language IS the source language, or no
	 * published translation exists yet.
	 */
	private function localized_post( \WP_Post $post ): \WP_Post {
		if ( ! defined( 'LF_LANG' ) || ! LF_LANG || ! function_exists( 'linguaforge_get_translations' ) ) {
			return $post;
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Lingua Forge's public API; prefix belongs to that plugin.
		$source = function_exists( 'linguaforge_source_language' ) ? linguaforge_source_language() : '';
		if ( LF_LANG === $source ) {
			return $post; // Already the source-language post — nothing to look up.
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- Lingua Forge's public API; prefix belongs to that plugin.
		$translations  = linguaforge_get_translations( $post->ID );
		$translated_id = (int) ( $translations[ LF_LANG ] ?? 0 );
		if ( $translated_id <= 0 ) {
			return $post; // No translation into the visitor's language yet.
		}

		$translated = get_post( $translated_id );

		return ( $translated instanceof \WP_Post && 'publish' === $translated->post_status ) ? $translated : $post;
	}

	/** True when the artist has at least one published post of the given type. */
	private function has_published_post( string $post_type, int $artist_id ): bool {
		$ids = get_posts( [
			'post_type'      => $post_type,
			'author'         => $artist_id,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		] );

		return ! empty( $ids );
	}

	/**
	 * The main Agnosis site's URL, on the visitor's current language.
	 *
	 * Same reasoning as `SubdomainRouter::url_for_artist()`: a visitor reading
	 * `ourartist.agnosis.art/fr/...` who clicks back to the portal should land
	 * on the portal's `/fr/` home, not always its source-language root.
	 */
	private function portal_home_url(): string {
		$base = (string) get_option( 'agnosis_base_domain', '' );

		if ( ! $base ) {
			return home_url();
		}

		$scheme = is_ssl() ? 'https' : 'http';
		$prefix = LinguaForge::current_lang_path_prefix();

		// Trailing slash only when a language prefix is actually appended — see
		// the matching comment in SubdomainRouter::url_for_artist().
		return $scheme . '://' . $base . $prefix . ( '' !== $prefix ? '/' : '' );
	}
}
