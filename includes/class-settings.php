<?php
/**
 * Admin settings and options.
 *
 * @package Singular_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Singular_Markdown_Settings
 */
class Singular_Markdown_Settings {

	const OPTION_KEY = 'singular_markdown_options';

	const CRON_HOOK_BATCH = 'singular_markdown_batch_regenerate';

	/**
	 * Transient key prefix for eligibility diagnostics output.
	 */
	const DIAG_TRANSIENT_PREFIX = 'singular_md_diag_';

	/**
	 * Default option values.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			'excluded_post_types'       => array(),
			'excluded_post_ids'         => array(),
			'excluded_terms'            => array(),
			'force_included_post_ids'   => array(),
			'main_content_selectors'    => '',
			'extra_strip_selectors'     => '',
			'fetch_timeout'             => 30,
			'listing_pages'             => array(),
			'batch_offset'              => 0,
		);
	}

	/**
	 * Get merged options.
	 *
	 * @return array<string,mixed>
	 */
	public static function get_options() {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Register admin UI.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
	}

	/**
	 * Register settings submenu.
	 */
	public static function register_menu() {
		add_options_page(
			__( 'Singular Markdown', 'singular-markdown' ),
			__( 'Singular Markdown', 'singular-markdown' ),
			'manage_options',
			'singular-markdown',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Parse one CSS selector per line (tag, #id, or .class). Lines starting with "# " are comments.
	 *
	 * @param string $raw Textarea contents.
	 * @return string[]
	 */
	public static function parse_selector_lines( $raw ) {
		$raw   = str_replace( "\r\n", "\n", (string) $raw );
		$lines = explode( "\n", $raw );
		$out   = array();
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || preg_match( '/^#\s/', $line ) ) {
				continue;
			}
			if ( ! in_array( $line, $out, true ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Parse comma or whitespace separated post IDs.
	 *
	 * @param string $raw Raw string.
	 * @return int[]
	 */
	public static function parse_post_id_list( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return array();
		}
		return array_values( array_filter( array_map( 'intval', preg_split( '/[\s,]+/', $raw ) ) ) );
	}

	/**
	 * Normalize excluded term lines to taxonomy:term_id pairs.
	 *
	 * @param string $raw Textarea contents.
	 * @return string[] Pairs like category:12.
	 */
	public static function parse_excluded_terms_raw( $raw ) {
		$lines = preg_split( '/\R/', (string) $raw );
		if ( ! is_array( $lines ) ) {
			return array();
		}

		$out = array();
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $tax, $second ) = explode( ':', $line, 2 );
			$tax    = sanitize_key( trim( $tax ) );
			$second = trim( (string) $second );
			if ( ! $tax || '' === $second || ! taxonomy_exists( $tax ) ) {
				continue;
			}

			$term_id = 0;
			if ( ctype_digit( $second ) ) {
				$term_id = (int) $second;
			} else {
				$term = get_term_by( 'slug', sanitize_title( $second ), $tax );
				if ( $term && ! is_wp_error( $term ) ) {
					$term_id = (int) $term->term_id;
				}
			}
			if ( $term_id <= 0 ) {
				continue;
			}
			$t = get_term( $term_id, $tax );
			if ( ! $t || is_wp_error( $t ) ) {
				continue;
			}

			$pair = $tax . ':' . $term_id;
			if ( ! in_array( $pair, $out, true ) ) {
				$out[] = $pair;
			}
		}

		return $out;
	}

	/**
	 * Normalize listing page mappings from admin form rows.
	 *
	 * @param array $page_ids   Page IDs.
	 * @param array $post_types Post type slugs.
	 * @return array<int,array{page_id:int,post_type:string}>
	 */
	public static function parse_listing_pages( array $page_ids, array $post_types ) {
		$out = array();
		$max = max( count( $page_ids ), count( $post_types ) );

		for ( $i = 0; $i < $max; $i++ ) {
			$page_id   = isset( $page_ids[ $i ] ) ? (int) $page_ids[ $i ] : 0;
			$post_type = isset( $post_types[ $i ] ) ? sanitize_key( (string) $post_types[ $i ] ) : '';
			if ( $page_id <= 0 || '' === $post_type ) {
				continue;
			}

			$page = get_post( $page_id );
			if ( ! ( $page instanceof WP_Post ) || 'page' !== $page->post_type ) {
				continue;
			}
			$obj = get_post_type_object( $post_type );
			if ( ! $obj || empty( $obj->public ) ) {
				continue;
			}

			$key = $page_id . ':' . $post_type;
			$out[ $key ] = array(
				'page_id'   => $page_id,
				'post_type' => $post_type,
			);
		}

		return array_values( $out );
	}

	/**
	 * Configured listing page mappings.
	 *
	 * @return array<int,array{page_id:int,post_type:string}>
	 */
	public static function get_listing_pages() {
		$opts = self::get_options();
		if ( empty( $opts['listing_pages'] ) || ! is_array( $opts['listing_pages'] ) ) {
			return array();
		}

		$maps = array();
		foreach ( $opts['listing_pages'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$page_id   = isset( $row['page_id'] ) ? (int) $row['page_id'] : 0;
			$post_type = isset( $row['post_type'] ) ? sanitize_key( (string) $row['post_type'] ) : '';
			if ( $page_id > 0 && '' !== $post_type ) {
				$maps[] = array(
					'page_id'   => $page_id,
					'post_type' => $post_type,
				);
			}
		}

		return $maps;
	}

	/**
	 * Handle form POST.
	 */
	public static function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['singular_md_purge_cache'] ) ) {
			if ( ! isset( $_POST['singular_md_purge_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['singular_md_purge_nonce'] ) ), 'singular_md_purge_cache' ) ) {
				return;
			}
			$n = Singular_Markdown_Storage::purge_ineligible();
			/* translators: %d: number of cache files removed */
			add_settings_error( 'singular_markdown', 'purged', sprintf( _n( 'Removed %d ineligible Markdown cache file.', 'Removed %d ineligible Markdown cache files.', $n, 'singular-markdown' ), $n ), 'success' );
			return;
		}

		if ( isset( $_POST['singular_md_purge_all_cache'] ) ) {
			if ( ! isset( $_POST['singular_md_purge_all_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['singular_md_purge_all_nonce'] ) ), 'singular_md_purge_all_cache' ) ) {
				return;
			}
			$n = Singular_Markdown_Storage::purge_all();
			self::schedule_full_regeneration();
			self::schedule_listing_pages_regeneration();
			/* translators: %d: number of cache files removed */
			add_settings_error( 'singular_markdown', 'purged_all', sprintf( _n( 'Removed %d Markdown cache file and scheduled regeneration.', 'Removed %d Markdown cache files and scheduled regeneration.', $n, 'singular-markdown' ), $n ), 'success' );
			return;
		}

		if ( isset( $_POST['singular_md_diagnose'] ) ) {
			if ( ! isset( $_POST['singular_md_diag_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['singular_md_diag_nonce'] ) ), 'singular_md_diagnose' ) ) {
				return;
			}
			$raw = isset( $_POST['singular_md_diag_input'] ) ? sanitize_text_field( wp_unslash( $_POST['singular_md_diag_input'] ) ) : '';
			$pid = Singular_Markdown_Eligibility::resolve_input_to_post_id( $raw );
			if ( ! $pid ) {
				add_settings_error( 'singular_markdown', 'diag_bad', __( 'Could not resolve a post ID or URL from that input.', 'singular-markdown' ), 'error' );
				return;
			}

			$res   = Singular_Markdown_Post_Type_Registry::get_post_eligibility( $pid );
			$title = get_the_title( $pid );
			$lines = array(
				sprintf(
					/* translators: 1: post ID, 2: post title */
					__( 'Post: #%1$d %2$s', 'singular-markdown' ),
					$pid,
					$title ? $title : '(' . __( 'no title', 'singular-markdown' ) . ')'
				),
				sprintf(
					/* translators: %s: yes or no */
					__( 'Eligible: %s', 'singular-markdown' ),
					$res['eligible'] ? __( 'yes', 'singular-markdown' ) : __( 'no', 'singular-markdown' )
				),
				sprintf(
					/* translators: %s: machine code */
					__( 'Code: %s', 'singular-markdown' ),
					$res['code']
				),
				__( 'Message:', 'singular-markdown' ) . ' ' . $res['message'],
			);
			set_transient( self::DIAG_TRANSIENT_PREFIX . get_current_user_id(), implode( "\n", $lines ), 120 );
			add_settings_error( 'singular_markdown', 'diag_ok', __( 'Diagnostics ready below.', 'singular-markdown' ), 'success' );
			return;
		}

		if ( ! isset( $_POST['singular_md_settings_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['singular_md_settings_nonce'] ) ), 'singular_md_save_settings' ) ) {
			return;
		}

		if ( isset( $_POST['singular_md_save'] ) ) {
			$types = isset( $_POST['singular_md_excluded_types'] ) ? (array) wp_unslash( $_POST['singular_md_excluded_types'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$types = array_map( 'sanitize_key', $types );

			$ids_raw = isset( $_POST['singular_md_excluded_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['singular_md_excluded_ids'] ) ) : '';
			$ids     = self::parse_post_id_list( $ids_raw );

			$force_raw = isset( $_POST['singular_md_force_include_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['singular_md_force_include_ids'] ) ) : '';
			$force_ids = self::parse_post_id_list( $force_raw );

			$terms_raw = isset( $_POST['singular_md_excluded_terms'] ) ? wp_unslash( $_POST['singular_md_excluded_terms'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$terms_raw = is_string( $terms_raw ) ? $terms_raw : '';
			$terms     = self::parse_excluded_terms_raw( $terms_raw );

			$selectors_raw = isset( $_POST['singular_md_main_selectors'] ) ? wp_unslash( $_POST['singular_md_main_selectors'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$selectors_raw = is_string( $selectors_raw ) ? sanitize_textarea_field( $selectors_raw ) : '';

			$strip_raw = isset( $_POST['singular_md_extra_strip_selectors'] ) ? wp_unslash( $_POST['singular_md_extra_strip_selectors'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$strip_raw = is_string( $strip_raw ) ? sanitize_textarea_field( $strip_raw ) : '';

			$fetch_timeout = isset( $_POST['singular_md_fetch_timeout'] ) ? (int) $_POST['singular_md_fetch_timeout'] : 30;
			$fetch_timeout = max( 5, min( 120, $fetch_timeout ) );

			$listing_page_ids   = isset( $_POST['singular_md_listing_page_ids'] ) ? (array) wp_unslash( $_POST['singular_md_listing_page_ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$listing_post_types = isset( $_POST['singular_md_listing_post_types'] ) ? (array) wp_unslash( $_POST['singular_md_listing_post_types'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$listing_pages      = self::parse_listing_pages( $listing_page_ids, $listing_post_types );

			$opts                              = self::get_options();
			$opts['excluded_post_types']       = $types;
			$opts['excluded_post_ids']         = $ids;
			$opts['force_included_post_ids']    = $force_ids;
			$opts['excluded_terms']            = $terms;
			$opts['main_content_selectors']    = $selectors_raw;
			$opts['extra_strip_selectors']     = $strip_raw;
			$opts['fetch_timeout']             = $fetch_timeout;
			$opts['listing_pages']             = $listing_pages;
			update_option( self::OPTION_KEY, $opts, false );

			Singular_Markdown_Storage::purge_ineligible();
			self::schedule_full_regeneration();
			self::schedule_listing_pages_regeneration();

			add_settings_error( 'singular_markdown', 'saved', __( 'Settings saved. Ineligible cache files were removed and a full regeneration was scheduled.', 'singular-markdown' ), 'success' );
		}

		if ( isset( $_POST['singular_md_regenerate'] ) ) {
			Singular_Markdown_Storage::purge_ineligible();
			self::schedule_full_regeneration();
			self::schedule_listing_pages_regeneration();
			add_settings_error( 'singular_markdown', 'regen', __( 'Ineligible cache files were removed and full regeneration was scheduled in the background.', 'singular-markdown' ), 'success' );
		}
	}

	/**
	 * Reset batch offset and schedule cron batches.
	 */
	public static function schedule_full_regeneration() {
		wp_clear_scheduled_hook( self::CRON_HOOK_BATCH );
		$opts                 = self::get_options();
		$opts['batch_offset'] = 0;
		update_option( self::OPTION_KEY, $opts, false );
		wp_schedule_single_event( time() + 5, self::CRON_HOOK_BATCH );
	}

	/**
	 * Schedule regeneration for all configured listing pages.
	 */
	public static function schedule_listing_pages_regeneration() {
		foreach ( self::get_listing_pages() as $mapping ) {
			$page_id = isset( $mapping['page_id'] ) ? (int) $mapping['page_id'] : 0;
			if ( $page_id <= 0 ) {
				continue;
			}
			$path = wp_parse_url( get_permalink( $page_id ), PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $path ) {
				Singular_Markdown_Generator::schedule_archive_regeneration( $path );
			}
		}
	}

	/**
	 * Render settings page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		settings_errors( 'singular_markdown' );
		$opts     = self::get_options();
		$types    = Singular_Markdown_Post_Type_Registry::get_default_included_types();
		$excluded = isset( $opts['excluded_post_types'] ) ? (array) $opts['excluded_post_types'] : array();
		$ids_str  = isset( $opts['excluded_post_ids'] ) ? implode( ', ', array_map( 'intval', (array) $opts['excluded_post_ids'] ) ) : '';

		$force_str = isset( $opts['force_included_post_ids'] ) ? implode( ', ', array_map( 'intval', (array) $opts['force_included_post_ids'] ) ) : '';

		$terms_lines = '';
		if ( ! empty( $opts['excluded_terms'] ) && is_array( $opts['excluded_terms'] ) ) {
			$terms_lines = implode( "\n", $opts['excluded_terms'] );
		}

		$selectors_val = isset( $opts['main_content_selectors'] ) ? (string) $opts['main_content_selectors'] : '';
		$strip_val     = isset( $opts['extra_strip_selectors'] ) ? (string) $opts['extra_strip_selectors'] : '';
		$fetch_timeout = isset( $opts['fetch_timeout'] ) ? (int) $opts['fetch_timeout'] : 30;
		$fetch_timeout = max( 5, min( 120, $fetch_timeout ) );
		$listing_pages = self::get_listing_pages();
		$pages         = get_pages(
			array(
				'post_status' => array( 'publish', 'private', 'draft' ),
				'sort_column' => 'post_title',
			)
		);
		$post_type_choices = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);
		unset( $post_type_choices['attachment'] );

		$counts_note = Singular_Markdown_Post_Type_Registry::count_published_approx();

		$diag_out = get_transient( self::DIAG_TRANSIENT_PREFIX . get_current_user_id() );
		if ( is_string( $diag_out ) && '' !== $diag_out ) {
			delete_transient( self::DIAG_TRANSIENT_PREFIX . get_current_user_id() );
		} else {
			$diag_out = '';
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Singular Markdown', 'singular-markdown' ); ?></h1>
			<p><?php esc_html_e( 'Markdown alternates are served at the same path as HTML with a .md extension. Cache files are stored under uploads (not publicly listed).', 'singular-markdown' ); ?></p>
			<p><strong><?php esc_html_e( 'Approximate published items in included types:', 'singular-markdown' ); ?></strong> <?php echo esc_html( (string) $counts_note ); ?></p>

			<h2><?php esc_html_e( 'Included post types (default)', 'singular-markdown' ); ?></h2>
			<table class="widefat striped" style="max-width:640px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Post type', 'singular-markdown' ); ?></th>
						<th><?php esc_html_e( 'Published (approx.)', 'singular-markdown' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $types as $t ) : ?>
						<?php
						$cobj = wp_count_posts( $t );
						$pc   = isset( $cobj->publish ) ? (int) $cobj->publish : 0;
						?>
						<tr>
							<td><code><?php echo esc_html( $t ); ?></code></td>
							<td><?php echo esc_html( (string) $pc ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post">
				<?php wp_nonce_field( 'singular_md_save_settings', 'singular_md_settings_nonce' ); ?>
				<h2><?php esc_html_e( 'Exclude post types', 'singular-markdown' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Unchecked types are included (default: all public, publicly_queryable types except built-in noise like attachments).', 'singular-markdown' ); ?></p>
				<fieldset style="columns: 2;">
					<?php foreach ( $types as $t ) : ?>
						<label style="display:block;margin:4px 0;">
							<input type="checkbox" name="singular_md_excluded_types[]" value="<?php echo esc_attr( $t ); ?>" <?php checked( in_array( $t, $excluded, true ) ); ?> />
							<?php echo esc_html( $t ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<h2><?php esc_html_e( 'Exclude post IDs', 'singular-markdown' ); ?></h2>
				<p><input type="text" class="large-text" name="singular_md_excluded_ids" value="<?php echo esc_attr( $ids_str ); ?>" placeholder="123, 456" /></p>

				<h2><?php esc_html_e( 'Force-include post IDs', 'singular-markdown' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Optional. These published posts get Markdown even if excluded by post type, term rules, SEO noindex, or canonical mismatch. Password-protected posts are never included.', 'singular-markdown' ); ?></p>
				<p><input type="text" class="large-text" name="singular_md_force_include_ids" value="<?php echo esc_attr( $force_str ); ?>" placeholder="789" /></p>

				<h2><?php esc_html_e( 'Exclude by taxonomy term', 'singular-markdown' ); ?></h2>
				<p class="description"><?php esc_html_e( 'One per line: taxonomy:term_id or taxonomy:term-slug (e.g. category:12 or post_tag:news). Lines starting with # are ignored.', 'singular-markdown' ); ?></p>
				<p><textarea class="large-text code" name="singular_md_excluded_terms" rows="6" cols="60"><?php echo esc_textarea( $terms_lines ); ?></textarea></p>

				<h2><?php esc_html_e( 'Listing pages', 'singular-markdown' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Use this for normal WordPress pages whose template displays posts. The selected page URL will generate archive-style Markdown from the selected post type instead of only the page body.', 'singular-markdown' ); ?></p>
				<table class="widefat striped" style="max-width:900px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Page', 'singular-markdown' ); ?></th>
							<th><?php esc_html_e( 'Post type to list', 'singular-markdown' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$rows = $listing_pages;
						for ( $i = count( $rows ); $i < 8; $i++ ) {
							$rows[] = array(
								'page_id'   => 0,
								'post_type' => '',
							);
						}
						?>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$row_page_id = isset( $row['page_id'] ) ? (int) $row['page_id'] : 0;
							$row_type    = isset( $row['post_type'] ) ? sanitize_key( (string) $row['post_type'] ) : '';
							?>
							<tr>
								<td>
									<select name="singular_md_listing_page_ids[]">
										<option value="0"><?php esc_html_e( '— Select page —', 'singular-markdown' ); ?></option>
										<?php foreach ( $pages as $page ) : ?>
											<option value="<?php echo esc_attr( (string) $page->ID ); ?>" <?php selected( $row_page_id, (int) $page->ID ); ?>>
												<?php echo esc_html( get_the_title( $page ) . ' (#' . $page->ID . ')' ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
								<td>
									<select name="singular_md_listing_post_types[]">
										<option value=""><?php esc_html_e( '— Select post type —', 'singular-markdown' ); ?></option>
										<?php foreach ( $post_type_choices as $type_name => $type_obj ) : ?>
											<option value="<?php echo esc_attr( $type_name ); ?>" <?php selected( $row_type, $type_name ); ?>>
												<?php echo esc_html( $type_obj->labels->singular_name . ' (' . $type_name . ')' ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Markdown generation', 'singular-markdown' ); ?></h2>
				<p class="description"><?php esc_html_e( 'These options affect how public HTML is turned into Markdown when a post is saved or when a .md URL is requested.', 'singular-markdown' ); ?></p>

				<h3><?php esc_html_e( 'Main content selectors', 'singular-markdown' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Optional. One CSS selector per line (tag name, #id, or .class only). Tried in order, then built-in fallbacks (.main-wrap, main, article, .entry-content, .wp-block-post-content). Leave empty to use defaults only.', 'singular-markdown' ); ?></p>
				<p><textarea class="large-text code" name="singular_md_main_selectors" rows="5" cols="60"><?php echo esc_textarea( $selectors_val ); ?></textarea></p>

				<h3><?php esc_html_e( 'Extra strip selectors', 'singular-markdown' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Removed from the main content fragment before Markdown conversion (e.g. cookie banners, sidebars). One selector per line (tag, #id, or .class). Appended after the built-in list (header, footer, nav, …). Lines starting with "# " are comments.', 'singular-markdown' ); ?></p>
				<p><textarea class="large-text code" name="singular_md_extra_strip_selectors" rows="6" cols="60"><?php echo esc_textarea( $strip_val ); ?></textarea></p>

				<h3><?php esc_html_e( 'HTML fetch timeout (seconds)', 'singular-markdown' ); ?></h3>
				<p class="description"><?php esc_html_e( 'How long to wait when requesting the public HTML permalink for conversion. Range 5–120.', 'singular-markdown' ); ?></p>
				<p><input type="number" name="singular_md_fetch_timeout" value="<?php echo esc_attr( (string) $fetch_timeout ); ?>" min="5" max="120" step="1" class="small-text" /></p>

				<p>
					<button type="submit" name="singular_md_save" class="button button-primary" value="1"><?php esc_html_e( 'Save settings', 'singular-markdown' ); ?></button>
					<button type="submit" name="singular_md_regenerate" class="button" value="1" onclick="return confirm('<?php echo esc_js( __( 'Purge ineligible cache and schedule regeneration for all published posts?', 'singular-markdown' ) ); ?>');"><?php esc_html_e( 'Regenerate Markdown files', 'singular-markdown' ); ?></button>
				</p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Eligibility diagnostics', 'singular-markdown' ); ?></h2>
			<?php if ( '' !== $diag_out ) : ?>
				<pre style="background:#f6f7f7;padding:12px;max-width:720px;white-space:pre-wrap;"><?php echo esc_html( $diag_out ); ?></pre>
			<?php endif; ?>
			<form method="post" style="max-width:720px;">
				<?php wp_nonce_field( 'singular_md_diagnose', 'singular_md_diag_nonce' ); ?>
				<p class="description"><?php esc_html_e( 'Enter a numeric post ID or a front-end URL for a singular post.', 'singular-markdown' ); ?></p>
				<p><input type="text" class="large-text" name="singular_md_diag_input" value="" placeholder="<?php esc_attr_e( 'e.g. 42 or https://example.com/my-post/', 'singular-markdown' ); ?>" /></p>
				<p><button type="submit" name="singular_md_diagnose" class="button" value="1"><?php esc_html_e( 'Check eligibility', 'singular-markdown' ); ?></button></p>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Cache maintenance', 'singular-markdown' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'singular_md_purge_cache', 'singular_md_purge_nonce' ); ?>
				<p class="description"><?php esc_html_e( 'Remove Markdown cache files for posts that are no longer eligible (without scheduling a full regeneration).', 'singular-markdown' ); ?></p>
				<p><button type="submit" name="singular_md_purge_cache" class="button" value="1"><?php esc_html_e( 'Purge ineligible cache files', 'singular-markdown' ); ?></button></p>
			</form>

			<form method="post" style="margin-top:12px;">
				<?php wp_nonce_field( 'singular_md_purge_all_cache', 'singular_md_purge_all_nonce' ); ?>
				<p class="description"><strong><?php esc_html_e( 'Use after changing generation logic or strip selectors.', 'singular-markdown' ); ?></strong> <?php esc_html_e( 'This removes every cached Markdown file, including archive/listing cache, then schedules background regeneration. First uncached .md requests may temporarily return 503 Retry-After while cron rebuilds them.', 'singular-markdown' ); ?></p>
				<p><button type="submit" name="singular_md_purge_all_cache" class="button button-secondary" value="1" onclick="return confirm('<?php echo esc_js( __( 'Delete all Markdown cache files and schedule background regeneration?', 'singular-markdown' ) ); ?>');"><?php esc_html_e( 'Purge all Markdown cache and regenerate', 'singular-markdown' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
