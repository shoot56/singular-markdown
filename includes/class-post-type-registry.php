<?php
/**
 * Post types in scope (sitemap-like defaults).
 *
 * @package Singular_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Singular_Markdown_Post_Type_Registry
 */
class Singular_Markdown_Post_Type_Registry {

	/**
	 * Post types excluded from defaults.
	 *
	 * @var string[]
	 */
	private static $default_blocked = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'acf-field-group',
		'acf-field',
	);

	/**
	 * Default included post type names.
	 *
	 * @return string[]
	 */
	public static function get_default_included_types() {
		$public = get_post_types(
			array(
				'public'             => true,
				'publicly_queryable' => true,
			),
			'objects'
		);

		$names = array();
		foreach ( $public as $name => $obj ) {
			if ( in_array( $name, self::$default_blocked, true ) ) {
				continue;
			}
			$names[] = $name;
		}

		$names = self::merge_core_public_types( $names );

		/**
		 * Filter default included post type names.
		 *
		 * @param string[] $names Post type names.
		 */
		return apply_filters( 'singular_markdown_allowed_post_types', $names );
	}

	/**
	 * Ensure built-in public singular types are included even when a site or plugin
	 * sets `publicly_queryable` to false for `post` or `page` (they would otherwise
	 * be missing from get_post_types( ... publicly_queryable => true )).
	 *
	 * @param string[] $names Current type slugs.
	 * @return string[]
	 */
	private static function merge_core_public_types( array $names ) {
		foreach ( array( 'post', 'page' ) as $pt ) {
			if ( in_array( $pt, $names, true ) ) {
				continue;
			}
			$obj = get_post_type_object( $pt );
			if ( ! $obj || empty( $obj->public ) ) {
				continue;
			}
			$names[] = $pt;
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Whether post type is allowed after exclusions.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	public static function is_type_allowed( $post_type ) {
		$post_type = sanitize_key( $post_type );
		if ( ! $post_type ) {
			return false;
		}

		$included = self::get_default_included_types();
		if ( ! in_array( $post_type, $included, true ) ) {
			return false;
		}

		$opts     = Singular_Markdown_Settings::get_options();
		$excluded = isset( $opts['excluded_post_types'] ) ? (array) $opts['excluded_post_types'] : array();
		$excluded = array_map( 'sanitize_key', $excluded );
		return ! in_array( $post_type, $excluded, true );
	}

	/**
	 * Whether a published post should get Markdown (eligibility pipeline).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_post_eligible( $post_id ) {
		$r = self::get_post_eligibility( $post_id );
		return ! empty( $r['eligible'] );
	}

	/**
	 * Full eligibility result with reason code (for diagnostics and filters).
	 *
	 * @param int $post_id Post ID.
	 * @return array{eligible:bool,code:string,message:string}
	 */
	public static function get_post_eligibility( $post_id ) {
		return Singular_Markdown_Eligibility::evaluate( (int) $post_id );
	}

	/**
	 * Yoast SEO noindex detection.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function yoast_noindex( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}

		$val = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		if ( '1' === (string) $val ) {
			return true;
		}

		$adv = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', true );
		if ( is_string( $adv ) && false !== strpos( $adv, 'noindex' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Published post IDs for batch jobs.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 * @return int[]
	 */
	public static function query_published_ids( $offset, $limit ) {
		$types = self::get_default_included_types();
		$opts  = Singular_Markdown_Settings::get_options();
		$excl  = isset( $opts['excluded_post_types'] ) ? array_map( 'sanitize_key', (array) $opts['excluded_post_types'] ) : array();
		$types = array_values( array_diff( $types, $excl ) );
		if ( empty( $types ) ) {
			return array();
		}

		$q = new WP_Query(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => max( 1, (int) $limit ),
				'offset'                 => max( 0, (int) $offset ),
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return array_map( 'intval', $q->posts );
	}

	/**
	 * Rough count of published posts in included types (admin summary).
	 *
	 * @return int
	 */
	public static function count_published_approx() {
		$types = self::get_default_included_types();
		$opts  = Singular_Markdown_Settings::get_options();
		$excl  = isset( $opts['excluded_post_types'] ) ? array_map( 'sanitize_key', (array) $opts['excluded_post_types'] ) : array();
		$types = array_values( array_diff( $types, $excl ) );
		if ( empty( $types ) ) {
			return 0;
		}

		$counts = wp_count_posts( $types[0] );
		$total  = isset( $counts->publish ) ? (int) $counts->publish : 0;
		for ( $i = 1, $c = count( $types ); $i < $c; $i++ ) {
			$c2 = wp_count_posts( $types[ $i ] );
			$total += isset( $c2->publish ) ? (int) $c2->publish : 0;
		}
		return $total;
	}
}
