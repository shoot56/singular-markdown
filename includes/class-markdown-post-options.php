<?php
/**
 * Per-post Markdown source: automatic HTML conversion vs custom body.
 *
 * @package Singular_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Singular_Markdown_Post_Options
 */
class Singular_Markdown_Post_Options {

	const META_MODE   = '_singular_markdown_mode';
	const META_CUSTOM = '_singular_markdown_custom';

	const MODE_AUTO   = 'auto';
	const MODE_CUSTOM = 'custom';

	/**
	 * Max stored custom Markdown size (bytes).
	 */
	const CUSTOM_MAX_BYTES = 524288;

	/**
	 * Register admin UI.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_post' ), 15, 2 );
	}

	/**
	 * Post types that may show the metabox (same scope as plugin auto-generation types).
	 */
	public static function metabox_post_types() {
		$types = array();
		foreach ( Singular_Markdown_Post_Type_Registry::get_default_included_types() as $t ) {
			if ( Singular_Markdown_Post_Type_Registry::is_type_allowed( $t ) ) {
				$types[] = $t;
			}
		}
		return $types;
	}

	/**
	 * Whether this post uses a non-empty custom Markdown body.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function uses_custom_markdown( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( self::MODE_CUSTOM !== (string) get_post_meta( $post_id, self::META_MODE, true ) ) {
			return false;
		}
		$raw = get_post_meta( $post_id, self::META_CUSTOM, true );
		return is_string( $raw ) && '' !== trim( $raw );
	}

	/**
	 * Filtered custom Markdown for output (same filter as auto-generated).
	 *
	 * @param int $post_id Post ID.
	 * @return string|false
	 */
	public static function get_filtered_custom_markdown( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || ! self::uses_custom_markdown( $post_id ) ) {
			return false;
		}
		$raw = get_post_meta( $post_id, self::META_CUSTOM, true );
		if ( ! is_string( $raw ) ) {
			return false;
		}
		$markdown = rtrim( $raw, "\r\n" ) . "\n";
		/**
		 * Filter final Markdown output (custom or auto).
		 *
		 * @param string $markdown Markdown text.
		 * @param int    $post_id  Post ID.
		 */
		return apply_filters( 'singular_markdown_output', $markdown, $post_id );
	}

	/**
	 * @param string $raw Raw body.
	 * @return string Sanitized string (may be empty).
	 */
	public static function sanitize_custom_body( $raw ) {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$raw = wp_check_invalid_utf8( $raw, true );
		$raw = str_replace( "\0", '', $raw );
		if ( strlen( $raw ) > self::CUSTOM_MAX_BYTES ) {
			$raw = substr( $raw, 0, self::CUSTOM_MAX_BYTES );
		}
		return $raw;
	}

	/**
	 * Register metabox for included post types.
	 */
	public static function register_meta_boxes() {
		foreach ( self::metabox_post_types() as $type ) {
			add_meta_box(
				'singular_markdown_post_options',
				__( 'Singular Markdown', 'singular-markdown' ),
				array( __CLASS__, 'render_metabox' ),
				$type,
				'normal',
				'default'
			);
		}
	}

	/**
	 * @param WP_Post $post Post.
	 */
	public static function render_metabox( $post ) {
		if ( ! ( $post instanceof WP_Post ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		wp_nonce_field( 'singular_md_save_post_options', 'singular_md_post_options_nonce' );

		$mode = get_post_meta( $post->ID, self::META_MODE, true );
		$mode = self::MODE_CUSTOM === (string) $mode ? self::MODE_CUSTOM : self::MODE_AUTO;

		$custom = get_post_meta( $post->ID, self::META_CUSTOM, true );
		$custom = is_string( $custom ) ? $custom : '';
		?>
		<p class="description">
			<?php esc_html_e( 'By default Markdown is built from the public HTML of this URL. You can override it with your own Markdown for the .md alternate.', 'singular-markdown' ); ?>
		</p>
		<p>
			<label>
				<input type="radio" name="singular_md_markdown_mode" value="<?php echo esc_attr( self::MODE_AUTO ); ?>" <?php checked( self::MODE_AUTO, $mode ); ?> />
				<?php esc_html_e( 'Automatic (from HTML)', 'singular-markdown' ); ?>
			</label>
			<br />
			<label>
				<input type="radio" name="singular_md_markdown_mode" value="<?php echo esc_attr( self::MODE_CUSTOM ); ?>" <?php checked( self::MODE_CUSTOM, $mode ); ?> />
				<?php esc_html_e( 'Custom Markdown', 'singular-markdown' ); ?>
			</label>
		</p>
		<p>
			<label for="singular_md_custom_markdown"><strong><?php esc_html_e( 'Custom Markdown body', 'singular-markdown' ); ?></strong></label>
		</p>
		<textarea id="singular_md_custom_markdown" name="singular_md_custom_markdown" class="large-text code" rows="16" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $custom ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'Used only when “Custom Markdown” is selected and this field is not empty. If you select Custom but leave this empty, the automatic HTML conversion is used.', 'singular-markdown' ); ?>
		</p>
		<?php
	}

	/**
	 * Persist meta when the post is saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_post( $post_id, $post ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['singular_md_post_options_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['singular_md_post_options_nonce'] ) ), 'singular_md_save_post_options' ) ) {
			return;
		}
		if ( ! ( $post instanceof WP_Post ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::metabox_post_types(), true ) ) {
			return;
		}

		$mode = isset( $_POST['singular_md_markdown_mode'] ) ? sanitize_key( wp_unslash( $_POST['singular_md_markdown_mode'] ) ) : self::MODE_AUTO;
		if ( self::MODE_CUSTOM !== $mode ) {
			$mode = self::MODE_AUTO;
		}

		$custom_raw = isset( $_POST['singular_md_custom_markdown'] ) ? wp_unslash( $_POST['singular_md_custom_markdown'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$custom     = self::sanitize_custom_body( is_string( $custom_raw ) ? $custom_raw : '' );

		if ( self::MODE_CUSTOM === $mode && '' === trim( $custom ) ) {
			$mode = self::MODE_AUTO;
		}

		update_post_meta( $post_id, self::META_MODE, $mode );
		if ( self::MODE_CUSTOM === $mode ) {
			update_post_meta( $post_id, self::META_CUSTOM, $custom );
		} else {
			delete_post_meta( $post_id, self::META_CUSTOM );
			delete_post_meta( $post_id, self::META_MODE );
		}
	}
}
