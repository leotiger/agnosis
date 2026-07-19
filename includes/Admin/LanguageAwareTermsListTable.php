<?php
/**
 * Custom WP_Terms_List_Table subclass — adds a per-language filter dropdown
 * above the Tags/Mediums admin screens (edit-tags.php).
 *
 * WP_Posts_List_Table fires `restrict_manage_posts` specifically so plugins
 * can inject a filter dropdown above a post list; WP_Terms_List_Table has no
 * equivalent hook for term lists at all — `extra_tablenav( $which )` is
 * declared but never overridden by core, so nothing fires there. This class
 * exists purely to override that one protected method; it's swapped in for
 * WP_Terms_List_Table via the `wp_list_table_class_name` filter (added in
 * WP 6.1 specifically for this kind of substitution — see
 * Admin\TaxonomyLanguageFilter::maybe_swap_list_table_class()), never
 * instantiated directly.
 *
 * @package Agnosis\Admin
 */

declare(strict_types=1);

namespace Agnosis\Admin;

use Agnosis\AI\SubmissionTranslator;

class LanguageAwareTermsListTable extends \WP_Terms_List_Table {

	/**
	 * Renders the language filter dropdown above the table.
	 *
	 * The list table's own wrapping `<form>` on edit-tags.php is
	 * method="post" (for the bulk-delete/quick-edit actions), so a normal
	 * submit here wouldn't produce the `?agnosis_admin_lang=` GET param this
	 * screen actually reads — navigating on 'change' instead sidesteps that
	 * nested-form/method mismatch entirely rather than fighting it with a
	 * second form. The actual navigation JS is enqueued separately (see
	 * Admin\TaxonomyLanguageFilter::admin_lang_filter_js()) rather than an
	 * inline onchange="" attribute here, which didn't reliably fire.
	 *
	 * Also renders a one-click "Sync all translations" button alongside the
	 * dropdown, when the current user can use it — see
	 * Admin\TaxonomyLanguageFilter::sync_all_url()/handle_sync_all_terms()
	 * for what it actually does (runs the per-term sync across every
	 * primary-language term on this taxonomy in one pass).
	 *
	 * @param string $which 'top' or 'bottom' — only rendered once, above the table.
	 * @return void
	 */
	// phpcs:ignore Squiz.Commenting.FunctionComment.ScalarTypeHintMissing -- WP_List_Table::extra_tablenav() declares $which with no native type hint at all (PHPStan infers mixed); matching the parent's signature exactly, instead of narrowing to string, is what keeps this override compatible. $which is documented as a string in the @param tag above and is always one in practice.
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		$primary   = sanitize_key( (string) get_option( 'linguaforge_primary_language', '' ) );
		$languages = SubmissionTranslator::language_names();
		unset( $languages[ $primary ] );

		if ( empty( $languages ) ) {
			return; // Nothing to filter to (or sync to) besides the primary language shown by default.
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display state (which option is pre-selected), no state change.
		$current = isset( $_GET['agnosis_admin_lang'] )
			? sanitize_key( wp_unslash( $_GET['agnosis_admin_lang'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$taxonomy = (string) $this->screen->taxonomy;
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="agnosis-admin-lang"><?php esc_html_e( 'Filter by language', 'agnosis' ); ?></label>
			<select
				name="agnosis_admin_lang"
				id="agnosis-admin-lang"
			>
				<option value="" <?php selected( '', $current ); ?>><?php esc_html_e( 'Primary language', 'agnosis' ); ?></option>
				<?php foreach ( $languages as $code => $label ) : ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $current ); ?>><?php echo esc_html( $label ); ?> (<?php echo esc_html( $code ); ?>)</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php if ( current_user_can( 'manage_categories' ) ) : ?>
		<div class="alignleft actions">
			<a
				href="<?php echo esc_url( TaxonomyLanguageFilter::sync_all_url( $taxonomy ) ); ?>"
				class="button"
				onclick="return confirm( '<?php echo esc_js( __( 'Create missing translations for every term shown here, in every configured language? This can take a moment on a large vocabulary.', 'agnosis' ) ); ?>' );"
			><?php esc_html_e( 'Sync all translations', 'agnosis' ); ?></a>
		</div>
		<?php endif; ?>
		<?php
	}
}
