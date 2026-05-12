<?php
/**
 * Post eligibility for Markdown generation (SEO-aware, with reason codes).
 *
 * @package Singular_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Singular_Markdown_Eligibility
 */
class Singular_Markdown_Eligibility {

	const CODE_ELIGIBLE              = 'eligible';
	const CODE_INVALID_POST          = 'invalid_post';
	const CODE_NOT_PUBLISH           = 'not_publish';
	const CODE_PASSWORD_PROTECTED    = 'password_protected';
	const CODE_NO_PERMALINK          = 'no_permalink';
	const CODE_POST_TYPE_NOT_ALLOWED = 'post_type_not_allowed';
	const CODE_POST_TYPE_EXCLUDED    = 'post_type_excluded';
	const CODE_POST_ID_EXCLUDED      = 'post_id_excluded';
	const CODE_TERM_EXCLUDED         = 'term_excluded';
	const CODE_SEO_NOINDEX           = 'seo_noindex';
	const CODE_CANONICAL_MISMATCH    = 'canonical_mismatch';
	const CODE_FILTERED_FALSE        = 'filtered_false';
	const CODE_FORCE_INCLUDED        = 'force_included';

	/**
	 * Evaluate whether a post should receive Markdown alternates.
	 *
	 * @param int $post_id Post ID.
	 * @return array{eligible:bool,code:string,message:string}
	 */
	public static function evaluate( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return self::result( false, self::CODE_INVALID_POST, __( 'Invalid post ID.', 'singular-markdown' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return self::result( false, self::CODE_INVALID_POST, __( 'Post not found.', 'singular-markdown' ) );
		}

		if ( 'publish' !== $post->post_status ) {
			return self::result( false, self::CODE_NOT_PUBLISH, __( 'Post is not published.', 'singular-markdown' ) );
		}

		if ( '' !== (string) $post->post_password ) {
			return self::result( false, self::CODE_PASSWORD_PROTECTED, __( 'Password-protected posts are excluded.', 'singular-markdown' ) );
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink || ! is_string( $permalink ) ) {
			return self::result( false, self::CODE_NO_PERMALINK, __( 'No permalink available.', 'singular-markdown' ) );
		}

		$opts = Singular_Markdown_Settings::get_options();

		$force_ids = isset( $opts['force_included_post_ids'] ) ? array_map( 'intval', (array) $opts['force_included_post_ids'] ) : array();
		$forced    = in_array( $post_id, $force_ids, true );

		if ( $forced ) {
			if ( ! self::is_public_queryable_post_type( $post->post_type ) ) {
				return self::result( false, self::CODE_POST_TYPE_NOT_ALLOWED, __( 'Post type is not public or publicly queryable.', 'singular-markdown' ) );
			}
			$res = self::result( true, self::CODE_FORCE_INCLUDED, __( 'Included by force-include list (overrides exclusions and common SEO noindex).', 'singular-markdown' ) );
			return self::apply_final_filter( $post_id, $res );
		}

		if ( ! Singular_Markdown_Post_Type_Registry::is_type_allowed( $post->post_type ) ) {
			$included = Singular_Markdown_Post_Type_Registry::get_default_included_types();
			if ( ! in_array( $post->post_type, $included, true ) ) {
				return self::result( false, self::CODE_POST_TYPE_NOT_ALLOWED, __( 'Post type is not in the default included set.', 'singular-markdown' ) );
			}
			return self::result( false, self::CODE_POST_TYPE_EXCLUDED, __( 'Post type is excluded in settings.', 'singular-markdown' ) );
		}

		$skip_ids = isset( $opts['excluded_post_ids'] ) ? array_map( 'intval', (array) $opts['excluded_post_ids'] ) : array();
		if ( in_array( $post_id, $skip_ids, true ) ) {
			return self::result( false, self::CODE_POST_ID_EXCLUDED, __( 'Post ID is in the exclude list.', 'singular-markdown' ) );
		}

		$term_reason = self::matches_excluded_term( $post_id, $opts );
		if ( null !== $term_reason ) {
			return self::result( false, self::CODE_TERM_EXCLUDED, $term_reason );
		}

		if ( self::seo_noindex( $post_id ) ) {
			return self::result( false, self::CODE_SEO_NOINDEX, __( 'SEO meta indicates noindex.', 'singular-markdown' ) );
		}

		if ( self::canonical_points_elsewhere( $post_id, $permalink ) ) {
			return self::result( false, self::CODE_CANONICAL_MISMATCH, __( 'Canonical URL points to another URL.', 'singular-markdown' ) );
		}

		$res = self::result( true, self::CODE_ELIGIBLE, __( 'Eligible for Markdown generation.', 'singular-markdown' ) );
		return self::apply_final_filter( $post_id, $res );
	}

	/**
	 * Resolve a post ID from admin input (numeric ID or front-end URL).
	 *
	 * @param string $input Raw input.
	 * @return int Post ID or 0.
	 */
	public static function resolve_input_to_post_id( $input ) {
		$input = trim( (string) $input );
		if ( '' === $input ) {
			return 0;
		}
		if ( preg_match( '/^\d+$/', $input ) ) {
			return (int) $input;
		}
		$url = esc_url_raw( $input );
		if ( '' === $url ) {
			return 0;
		}
		$id = url_to_postid( $url );
		return $id ? (int) $id : 0;
	}

	/**
	 * Whether a post type can have a public singular URL (force-include gate).
	 * Core `post` and `page` are accepted when `public` is true even if a plugin
	 * sets `publicly_queryable` to false.
	 *
	 * @param string $post_type Post type slug.
	 * @return bool
	 */
	private static function is_public_queryable_post_type( $post_type ) {
		$post_type = sanitize_key( $post_type );
		if ( ! $post_type ) {
			return false;
		}
		$obj = get_post_type_object( $post_type );
		if ( ! $obj || empty( $obj->public ) ) {
			return false;
		}
		if ( in_array( $post_type, array( 'post', 'page' ), true ) ) {
			return true;
		}
		return ! empty( $obj->publicly_queryable );
	}

	/**
	 * @param bool   $eligible Eligible.
	 * @param string $code     Machine-readable code.
	 * @param string $message  Human-readable message.
	 * @return array{eligible:bool,code:string,message:string}
	 */
	private static function result( $eligible, $code, $message ) {
		return array(
			'eligible' => (bool) $eligible,
			'code'     => (string) $code,
			'message'  => (string) $message,
		);
	}

	/**
	 * @param int   $post_id Post ID.
	 * @param array $res     Result array.
	 * @return array{eligible:bool,code:string,message:string}
	 */
	private static function apply_final_filter( $post_id, array $res ) {
		/**
		 * Filter final eligibility boolean (after built-in rules).
		 *
		 * @param bool $eligible Current eligibility.
		 * @param int  $post_id  Post ID.
		 */
		$eligible = (bool) apply_filters( 'singular_markdown_is_post_eligible', $res['eligible'], $post_id );
		if ( $eligible !== $res['eligible'] ) {
			$res['eligible'] = $eligible;
			$res['code']     = $eligible ? self::CODE_ELIGIBLE : self::CODE_FILTERED_FALSE;
			$res['message']  = $eligible
				? __( 'Eligible after custom filter.', 'singular-markdown' )
				: __( 'Excluded by singular_markdown_is_post_eligible filter.', 'singular-markdown' );
		}

		/**
		 * Filter full eligibility result (code and message).
		 *
		 * @param array $res     Keys: eligible, code, message.
		 * @param int   $post_id Post ID.
		 */
		$filtered = apply_filters( 'singular_markdown_eligibility', $res, $post_id );
		if ( is_array( $filtered ) && isset( $filtered['eligible'], $filtered['code'], $filtered['message'] ) ) {
			return array(
				'eligible' => (bool) $filtered['eligible'],
				'code'     => (string) $filtered['code'],
				'message'  => (string) $filtered['message'],
			);
		}
		return $res;
	}

	/**
	 * @param int   $post_id Post ID.
	 * @param array $opts    Options array.
	 * @return string|null Message if excluded, null if not.
	 */
	private static function matches_excluded_term( $post_id, array $opts ) {
		$pairs = isset( $opts['excluded_terms'] ) ? (array) $opts['excluded_terms'] : array();
		foreach ( $pairs as $pair ) {
			$pair = (string) $pair;
			if ( false === strpos( $pair, ':' ) ) {
				continue;
			}
			list( $tax, $tid ) = explode( ':', $pair, 2 );
			$tax = sanitize_key( $tax );
			$tid = (int) $tid;
			if ( ! $tax || $tid <= 0 ) {
				continue;
			}
			if ( has_term( $tid, $tax, $post_id ) ) {
				/* translators: 1: taxonomy slug, 2: term ID */
				return sprintf( __( 'Post has excluded term: %1$s:%2$d.', 'singular-markdown' ), $tax, $tid );
			}
		}
		return null;
	}

	/**
	 * Aggregate noindex detection for common SEO plugins.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function seo_noindex( $post_id ) {
		if ( Singular_Markdown_Post_Type_Registry::yoast_noindex( $post_id ) ) {
			return true;
		}
		if ( self::rank_math_noindex( $post_id ) ) {
			return true;
		}
		if ( self::seopress_noindex( $post_id ) ) {
			return true;
		}
		if ( self::aioseo_noindex( $post_id ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function rank_math_noindex( $post_id ) {
		$robots = get_post_meta( $post_id, 'rank_math_robots', true );
		if ( is_array( $robots ) ) {
			foreach ( $robots as $r ) {
				if ( is_string( $r ) && false !== stripos( $r, 'noindex' ) ) {
					return true;
				}
			}
		} elseif ( is_string( $robots ) && false !== stripos( $robots, 'noindex' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function seopress_noindex( $post_id ) {
		$val = get_post_meta( $post_id, '_seopress_robots_index', true );
		return 'no' === (string) $val || 'noindex' === strtolower( (string) $val );
	}

	/**
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function aioseo_noindex( $post_id ) {
		$direct = get_post_meta( $post_id, '_aioseo_noindex', true );
		if ( '1' === (string) $direct || true === $direct ) {
			return true;
		}
		$json = get_post_meta( $post_id, '_aioseo_meta_data', true );
		if ( is_string( $json ) && '' !== $json ) {
			$data = json_decode( $json, true );
			if ( is_array( $data ) ) {
				if ( ! empty( $data['robots_noindex'] ) || ! empty( $data['noindex'] ) ) {
					return true;
				}
				if ( isset( $data['robots_default'] ) && is_string( $data['robots_default'] ) && false !== stripos( $data['robots_default'], 'noindex' ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * True if canonical URL is set and does not match this post's permalink.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $permalink Expected permalink.
	 * @return bool
	 */
	private static function canonical_points_elsewhere( $post_id, $permalink ) {
		$canonical = self::get_canonical_url( $post_id );
		if ( '' === $canonical ) {
			return false;
		}
		$a = self::normalize_url_for_compare( $permalink );
		$b = self::normalize_url_for_compare( $canonical );
		if ( '' === $a || '' === $b ) {
			return false;
		}
		if ( $a === $b ) {
			return false;
		}
		// Same post resolved via canonical URL is OK.
		$other_id = url_to_postid( $canonical );
		if ( $other_id && (int) $other_id === (int) $post_id ) {
			return false;
		}
		return true;
	}

	/**
	 * @param int $post_id Post ID.
	 * @return string Canonical URL or empty.
	 */
	private static function get_canonical_url( $post_id ) {
		$yoast = get_post_meta( $post_id, '_yoast_wpseo_canonical', true );
		if ( is_string( $yoast ) && '' !== trim( $yoast ) ) {
			return esc_url_raw( trim( $yoast ) );
		}
		$rm = get_post_meta( $post_id, 'rank_math_canonical_url', true );
		if ( is_string( $rm ) && '' !== trim( $rm ) ) {
			return esc_url_raw( trim( $rm ) );
		}
		$sp = get_post_meta( $post_id, '_seopress_robots_canonical', true );
		if ( is_string( $sp ) && '' !== trim( $sp ) ) {
			return esc_url_raw( trim( $sp ) );
		}
		$ai = get_post_meta( $post_id, '_aioseo_canonical_url', true );
		if ( is_string( $ai ) && '' !== trim( $ai ) ) {
			return esc_url_raw( trim( $ai ) );
		}
		return '';
	}

	/**
	 * @param string $url URL.
	 * @return string Normalized full URL for comparison.
	 */
	private static function normalize_url_for_compare( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'https';
		$host   = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$path   = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( '' === $host ) {
			return '';
		}
		$path = '/' . trim( $path, '/' );
		$path = untrailingslashit( $path );
		return $scheme . '://' . $host . $path;
	}
}
