<?php
/**
 * Plugin bootstrap: hooks, activation, batch jobs.
 *
 * @package Singular_Markdown
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Singular_Markdown_Plugin
 */
class Singular_Markdown_Plugin {

	/**
	 * Legacy option key (pre–neutral rename).
	 */
	const LEGACY_OPTION_KEY = 'flolive_md_options';

	/**
	 * Legacy cron hook.
	 */
	const LEGACY_CRON_HOOK = 'flolive_md_batch_regenerate';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		Singular_Markdown_Settings::init();
		Singular_Markdown_Post_Options::init();
		add_action( 'init', array( 'Singular_Markdown_Router', 'register_rewrites' ), 5 );
		Singular_Markdown_Router::init();

		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 3 );
		add_action( 'transition_post_status', array( $this, 'on_transition_post_status' ), 10, 3 );
		add_action( 'before_delete_post', array( $this, 'on_before_delete_post' ), 10, 1 );
		add_action( 'wp_trash_post', array( $this, 'on_trash_post' ), 10, 1 );

		add_action( Singular_Markdown_Settings::CRON_HOOK_BATCH, array( __CLASS__, 'run_batch_regeneration' ) );
		add_action( Singular_Markdown_Generator::CRON_HOOK_GENERATE, array( 'Singular_Markdown_Generator', 'run_scheduled_regeneration' ), 10, 1 );
	}

	/**
	 * Plugin activation.
	 */
	public static function activate() {
		self::maybe_migrate_legacy_options();

		Singular_Markdown_Storage::ensure_directory();
		Singular_Markdown_Router::register_rewrites();
		flush_rewrite_rules( false );
		Singular_Markdown_Settings::schedule_full_regeneration();
	}

	/**
	 * Copy options from legacy floLIVE-prefixed plugin if present.
	 */
	private static function maybe_migrate_legacy_options() {
		$legacy = get_option( self::LEGACY_OPTION_KEY, null );
		if ( ! is_array( $legacy ) ) {
			return;
		}
		$current = get_option( Singular_Markdown_Settings::OPTION_KEY, false );
		if ( false !== $current && null !== $current ) {
			delete_option( self::LEGACY_OPTION_KEY );
			wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
			return;
		}
		update_option( Singular_Markdown_Settings::OPTION_KEY, array_merge( Singular_Markdown_Settings::defaults(), $legacy ), false );
		delete_option( self::LEGACY_OPTION_KEY );
		wp_clear_scheduled_hook( self::LEGACY_CRON_HOOK );
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivate() {
		flush_rewrite_rules( false );
		wp_clear_scheduled_hook( Singular_Markdown_Settings::CRON_HOOK_BATCH );
	}

	/**
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an update.
	 */
	public function on_save_post( $post_id, $post, $update ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		Singular_Markdown_Post_Type_Registry::clear_post_eligibility_cache( $post_id );

		if ( ! Singular_Markdown_Post_Type_Registry::is_post_eligible( $post_id ) ) {
			Singular_Markdown_Storage::delete( $post_id );
			return;
		}

		if ( Singular_Markdown_Post_Options::uses_custom_markdown( $post_id ) ) {
			$md = Singular_Markdown_Post_Options::get_filtered_custom_markdown( $post_id );
			if ( false !== $md && '' !== trim( (string) $md ) ) {
				Singular_Markdown_Storage::write( $post_id, $md );
			}
			return;
		}

		Singular_Markdown_Generator::schedule_regeneration( $post_id );
	}

	/**
	 * @param string  $new_status New status.
	 * @param string  $old_status Old status.
	 * @param WP_Post $post       Post.
	 */
	public function on_transition_post_status( $new_status, $old_status, $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}
		Singular_Markdown_Post_Type_Registry::clear_post_eligibility_cache( $post->ID );
		if ( 'publish' === $new_status && Singular_Markdown_Post_Type_Registry::is_post_eligible( $post->ID ) ) {
			// Markdown is refreshed in save_post (priority 20), after Singular_Markdown_Post_Options saves meta (priority 15).
			return;
		}
		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			Singular_Markdown_Storage::delete( $post->ID );
		}
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public function on_before_delete_post( $post_id ) {
		Singular_Markdown_Storage::delete( (int) $post_id );
	}

	/**
	 * @param int $post_id Post ID.
	 */
	public function on_trash_post( $post_id ) {
		Singular_Markdown_Storage::delete( (int) $post_id );
	}

	/**
	 * Background batch regeneration.
	 */
	public static function run_batch_regeneration() {
		$opts   = Singular_Markdown_Settings::get_options();
		$offset = isset( $opts['batch_offset'] ) ? (int) $opts['batch_offset'] : 0;
		$batch  = 15;

		$ids = Singular_Markdown_Post_Type_Registry::query_published_ids( $offset, $batch );
		foreach ( $ids as $id ) {
			if ( Singular_Markdown_Post_Type_Registry::is_post_eligible( $id ) ) {
				Singular_Markdown_Generator::generate_and_cache( $id );
			} else {
				Singular_Markdown_Storage::delete( $id );
			}
		}

		if ( count( $ids ) < $batch ) {
			$opts['batch_offset'] = 0;
			update_option( Singular_Markdown_Settings::OPTION_KEY, $opts, false );
			return;
		}

		$opts['batch_offset'] = $offset + $batch;
		update_option( Singular_Markdown_Settings::OPTION_KEY, $opts, false );
		if ( ! wp_next_scheduled( Singular_Markdown_Settings::CRON_HOOK_BATCH ) ) {
			wp_schedule_single_event( time() + 10, Singular_Markdown_Settings::CRON_HOOK_BATCH );
		}
	}
}
