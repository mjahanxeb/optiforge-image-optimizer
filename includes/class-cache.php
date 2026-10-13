<?php
/**
 * Cache Invalidation & Third-Party Integration Manager.
 *
 * @package OptiForge_Image_Optimizer
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace Optiforge_Image_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Cache
 */
class Cache {

	/**
	 * Purge entire site cache across popular caching plugins safely.
	 *
	 * @return void
	 */
	public static function purge_all(): void {
		try {
			// FlyingPress Cache
			if ( class_exists( '\FlyingPress\Purge' ) && is_callable( array( '\FlyingPress\Purge', 'purge_everything' ) ) ) {
				\FlyingPress\Purge::purge_everything();
			}

			// LiteSpeed Cache
			if ( has_action( 'litespeed_purge_all' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				do_action( 'litespeed_purge_all' );
			}

			// WP Rocket
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
			}

			// W3 Total Cache
			if ( function_exists( 'w3tc_flush_all' ) ) {
				w3tc_flush_all();
			}

			// WP Super Cache
			if ( function_exists( 'wp_cache_clear_cache' ) ) {
				wp_cache_clear_cache();
			}

			// Autoptimize
			if ( class_exists( 'autoptimizeCache' ) && is_callable( array( 'autoptimizeCache', 'clearall' ) ) ) {
				\autoptimizeCache::clearall();
			}

			// WP Fastest Cache
			if ( isset( $GLOBALS['wp_fastest_cache'] ) && is_callable( array( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) ) {
				$GLOBALS['wp_fastest_cache']->deleteCache( true );
			}
		} catch ( \Throwable $e ) {
			// Fail-safe: Never halt optimization due to cache purge errors.
		}
	}

	/**
	 * Purge cache for a specific media attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public static function purge_attachment( int $attachment_id = 0 ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}

		try {
			clean_post_cache( $attachment_id );

			$parent_id = wp_get_post_parent_id( $attachment_id );
			if ( $parent_id > 0 ) {
				clean_post_cache( $parent_id );
			}

			if ( has_action( 'litespeed_purge_post' ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				do_action( 'litespeed_purge_post', $attachment_id );
				if ( $parent_id > 0 ) {
					// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
					do_action( 'litespeed_purge_post', $parent_id );
				}
			}

			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $attachment_id );
				if ( $parent_id > 0 ) {
					rocket_clean_post( $parent_id );
				}
			}
		} catch ( \Throwable $e ) {
			// Fail-safe: Prevent batch process interruptions.
		}
	}
}

// Backwards-compatible class aliases.
if ( ! class_exists( 'OFIO_Cache', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Cache', 'OFIO_Cache' );
}
if ( ! class_exists( '\OptiForge\ImageOptimizer\Cache', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Cache', '\OptiForge\ImageOptimizer\Cache' );
}
if ( ! class_exists( '\OptiForge_Image_Optimizer\Cache', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Cache', '\OptiForge_Image_Optimizer\Cache' );
}
