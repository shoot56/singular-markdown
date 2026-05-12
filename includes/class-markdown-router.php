<?php
/**
 * Rewrite rules, .md response, and alternate Link header.
 *
 * @package Singular_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Singular_Markdown_Router
 */
class Singular_Markdown_Router {

	const QUERY_FLAG  = 'sing_md';
	const QUERY_SLUGS = 'sing_md_path';

	/**
	 * Bootstrap hooks.
	 */
	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_markdown' ), 0 );
		add_action( 'send_headers', array( __CLASS__, 'maybe_send_alternate_link' ) );
	}

	/**
	 * Register rewrite rule (also call from activation before flush).
	 */
	public static function register_rewrites() {
		add_rewrite_rule( '^(.+)\.md$', 'index.php?' . self::QUERY_FLAG . '=1&' . self::QUERY_SLUGS . '=$matches[1]', 'top' );
	}

	/**
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public static function register_query_vars( $vars ) {
		$vars[] = self::QUERY_FLAG;
		$vars[] = self::QUERY_SLUGS;
		return $vars;
	}

	/**
	 * Relative path for Link header, e.g. /blog/post.md
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_alternate_md_path( $post_id ) {
		$post_id = (int) $post_id;
		$front   = (int) get_option( 'page_on_front' );
		if ( $front > 0 && $post_id === $front ) {
			$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			$home_path = is_string( $home_path ) ? trim( $home_path, '/' ) : '';
			if ( '' === $home_path ) {
				return '/index.md';
			}
			return '/' . $home_path . '/index.md';
		}

		$url = get_permalink( $post_id );
		if ( ! $url ) {
			return '';
		}
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		$path = untrailingslashit( $path ) . '.md';
		return $path;
	}

	/**
	 * Strip WordPress subdirectory prefix from rewrite capture (if present).
	 *
	 * @param string $slug_path Raw captured path.
	 * @return string Normalized path relative to site home.
	 */
	private static function normalize_rewrite_path( $slug_path ) {
		$slug_path = trim( (string) $slug_path, '/' );
		$base      = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( ! is_string( $base ) ) {
			return $slug_path;
		}
		$base = trim( $base, '/' );
		if ( '' === $base ) {
			return $slug_path;
		}
		if ( $slug_path === $base ) {
			return '';
		}
		$prefix = $base . '/';
		if ( 0 === strpos( $slug_path, $prefix ) ) {
			$rest = substr( $slug_path, strlen( $prefix ) );
			return $rest ? $rest : '';
		}
		return $slug_path;
	}

	/**
	 * Map rewrite slug path to post ID.
	 *
	 * @param string $slug_path Path without .md (may contain slashes).
	 * @return int Post ID or 0.
	 */
	public static function resolve_path_to_post_id( $slug_path ) {
		$slug_path = self::normalize_rewrite_path( $slug_path );

		if ( 'index' === $slug_path ) {
			$fid = (int) get_option( 'page_on_front' );
			return $fid > 0 ? $fid : 0;
		}

		if ( '' === $slug_path ) {
			return 0;
		}

		$candidates = array(
			home_url( '/' . $slug_path . '/' ),
			home_url( '/' . $slug_path ),
			untrailingslashit( home_url( '/' . $slug_path ) ),
		);

		foreach ( $candidates as $c ) {
			$id = url_to_postid( $c );
			if ( $id ) {
				return (int) $id;
			}
		}

		return 0;
	}

	/**
	 * Serve Markdown response when rewrite matched.
	 */
	public static function maybe_serve_markdown() {
		if ( 1 !== (int) get_query_var( self::QUERY_FLAG ) ) {
			return;
		}

		$slug_path = (string) get_query_var( self::QUERY_SLUGS );
		$post_id   = self::resolve_path_to_post_id( $slug_path );

		if ( ! $post_id || ! Singular_Markdown_Post_Type_Registry::is_post_eligible( $post_id ) ) {
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo esc_html__( 'Markdown version not found.', 'singular-markdown' );
			exit;
		}

		if ( Singular_Markdown_Post_Options::uses_custom_markdown( $post_id ) ) {
			$md = Singular_Markdown_Post_Options::get_filtered_custom_markdown( $post_id );
		} else {
			$md = Singular_Markdown_Storage::read( $post_id );
			if ( false === $md || Singular_Markdown_Storage::is_stale( $post_id ) ) {
				$md = Singular_Markdown_Generator::generate_and_cache( $post_id );
			}
		}

		if ( false === $md || '' === $md ) {
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo esc_html__( 'Markdown could not be generated.', 'singular-markdown' );
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=UTF-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $md;
		exit;
	}

	/**
	 * Add Link header on singular HTML pages.
	 */
	public static function maybe_send_alternate_link() {
		if ( is_admin() ) {
			return;
		}
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		if ( ! Singular_Markdown_Post_Type_Registry::is_post_eligible( $post->ID ) ) {
			return;
		}

		$path = self::get_alternate_md_path( $post->ID );
		if ( '' === $path ) {
			return;
		}

		header( sprintf( 'Link: <%s>; rel="alternate"; type="text/markdown"', $path ), false );
	}
}
