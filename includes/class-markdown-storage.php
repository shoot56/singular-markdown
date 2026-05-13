<?php
/**
 * Markdown file cache under uploads.
 *
 * @package Singular_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Singular_Markdown_Storage
 */
class Singular_Markdown_Storage {

	const CACHE_SUBDIR = 'singular-markdown-cache';

	const ARCHIVE_PREFIX = 'archive-';

	/**
	 * Cache directory path (trailing slash).
	 *
	 * @return string
	 */
	public static function get_cache_dir() {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return '';
		}
		return trailingslashit( $upload['basedir'] ) . self::CACHE_SUBDIR . '/';
	}

	/**
	 * Ensure cache directory exists.
	 *
	 * @return bool
	 */
	public static function ensure_directory() {
		$dir = self::get_cache_dir();
		if ( '' === $dir ) {
			return false;
		}
		if ( file_exists( $dir ) && is_dir( $dir ) ) {
			self::write_index_html( $dir );
			self::write_server_protection_files( $dir );
			return true;
		}
		wp_mkdir_p( $dir );
		self::write_index_html( $dir );
		self::write_server_protection_files( $dir );
		return file_exists( $dir ) && is_dir( $dir );
	}

	/**
	 * @param string $dir Directory.
	 */
	private static function write_index_html( $dir ) {
		$index = trailingslashit( $dir ) . 'index.html';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}
	}

	/**
	 * Add best-effort server config files so numeric cache files are not directly
	 * served from uploads on Apache/IIS. WordPress still reads them from disk.
	 *
	 * @param string $dir Directory.
	 */
	private static function write_server_protection_files( $dir ) {
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}

		$web_config = trailingslashit( $dir ) . 'web.config';
		if ( ! file_exists( $web_config ) ) {
			$config = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n";
			file_put_contents( $web_config, $config ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		}
	}

	/**
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_file_path( $post_id ) {
		return self::get_cache_dir() . (int) $post_id . '.md';
	}

	/**
	 * Cache key for archive paths.
	 *
	 * @param string $slug_path Archive path without .md.
	 * @return string
	 */
	public static function get_archive_key( $slug_path ) {
		$slug_path = trim( (string) $slug_path, '/' );
		return self::ARCHIVE_PREFIX . md5( $slug_path );
	}

	/**
	 * @param string $slug_path Archive path without .md.
	 * @return string
	 */
	public static function get_archive_file_path( $slug_path ) {
		return self::get_cache_dir() . self::get_archive_key( $slug_path ) . '.md';
	}

	/**
	 * @param int $post_id Post ID.
	 * @return string|false
	 */
	public static function read( $post_id ) {
		$file = self::get_file_path( $post_id );
		if ( ! is_readable( $file ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return file_get_contents( $file );
	}

	/**
	 * @param string $slug_path Archive path without .md.
	 * @return string|false
	 */
	public static function read_archive( $slug_path ) {
		$file = self::get_archive_file_path( $slug_path );
		if ( ! is_readable( $file ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return file_get_contents( $file );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $markdown Content.
	 * @return bool
	 */
	public static function write( $post_id, $markdown ) {
		if ( ! self::ensure_directory() ) {
			return false;
		}
		$file = self::get_file_path( $post_id );
		return self::write_file_atomic( $file, $markdown );
	}

	/**
	 * @param string $slug_path Archive path without .md.
	 * @param string $markdown  Content.
	 * @return bool
	 */
	public static function write_archive( $slug_path, $markdown ) {
		if ( ! self::ensure_directory() ) {
			return false;
		}
		$file = self::get_archive_file_path( $slug_path );
		return self::write_file_atomic( $file, $markdown );
	}

	/**
	 * @param string $file     Destination file.
	 * @param string $markdown Content.
	 * @return bool
	 */
	private static function write_file_atomic( $file, $markdown ) {
		$tmp  = $file . '.' . str_replace( '.', '', uniqid( 'tmp-', true ) ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents
		if ( false === file_put_contents( $tmp, $markdown ) ) {
			return false;
		}
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}
		return true;
	}

	/**
	 * Modification time of the cache file.
	 *
	 * @param int $post_id Post ID.
	 * @return int|false Unix timestamp or false.
	 */
	public static function get_modified_time( $post_id ) {
		$file = self::get_file_path( $post_id );
		if ( ! is_readable( $file ) ) {
			return false;
		}
		$mtime = filemtime( $file );
		return false === $mtime ? false : (int) $mtime;
	}

	/**
	 * @param string $slug_path Archive path without .md.
	 * @return int|false Unix timestamp or false.
	 */
	public static function get_archive_modified_time( $slug_path ) {
		$file = self::get_archive_file_path( $slug_path );
		if ( ! is_readable( $file ) ) {
			return false;
		}
		$mtime = filemtime( $file );
		return false === $mtime ? false : (int) $mtime;
	}

	/**
	 * Whether archive cache is older than the allowed TTL.
	 *
	 * @param string $slug_path Archive path without .md.
	 * @return bool
	 */
	public static function is_archive_stale( $slug_path ) {
		$mtime = self::get_archive_modified_time( $slug_path );
		if ( false === $mtime ) {
			return true;
		}
		$ttl = (int) apply_filters( 'singular_markdown_archive_cache_ttl', 5 * MINUTE_IN_SECONDS, $slug_path );
		return $ttl > 0 && $mtime < ( time() - $ttl );
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public static function delete( $post_id ) {
		$file = self::get_file_path( $post_id );
		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Remove cached Markdown files for posts that are no longer eligible.
	 *
	 * @return int Number of files removed.
	 */
	public static function purge_ineligible() {
		$dir = self::get_cache_dir();
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return 0;
		}

		$pattern = trailingslashit( $dir ) . '*.md';
		$files   = glob( $pattern );
		if ( ! is_array( $files ) ) {
			return 0;
		}

		$removed = 0;
		foreach ( $files as $file ) {
			if ( ! is_string( $file ) || ! is_file( $file ) ) {
				continue;
			}
			$base = basename( $file, '.md' );
			if ( ! ctype_digit( (string) $base ) ) {
				continue;
			}
			$post_id = (int) $base;
			if ( $post_id <= 0 ) {
				continue;
			}
			if ( ! Singular_Markdown_Post_Type_Registry::is_post_eligible( $post_id ) ) {
				self::delete( $post_id );
				++$removed;
			}
		}

		return $removed;
	}

	/**
	 * Whether the cache file is older than the post's last modified time.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_stale( $post_id ) {
		$file = self::get_file_path( $post_id );
		if ( ! is_readable( $file ) ) {
			return true;
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			return true;
		}

		$mtime = filemtime( $file );
		if ( false === $mtime ) {
			return true;
		}

		$mtime = (int) $mtime;
		$pmod  = (int) get_post_modified_time( 'U', true, $post );

		return $mtime < $pmod;
	}
}
