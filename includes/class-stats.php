<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
/**
 * High-Speed Statistics Engine with Aggressive Transient Caching.
 *
 * @package OptiForge_Image_Optimizer
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace Optiforge_Image_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Stats
 */
class Stats {

	const OPTION_KEY              = 'ofio_stats';
	const CACHE_KEY               = 'ofio_stats_cache';
	const TOTAL_COUNT_CACHE_KEY   = 'ofio_total_images_count';
	const UNOPT_COUNT_CACHE_KEY   = 'ofio_unoptimized_count';

	/**
	 * Record optimization results and increment aggregate telemetry.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $result        Optimization result array.
	 * @return void
	 */
	public function record( int $attachment_id, array $result ): void {
		$is_success = ! empty( $result['success'] );

		$new_meta = array(
			'status'         => $is_success ? 'optimized' : 'failed',
			'original_size'  => (int) ( $result['original_size'] ?? 0 ),
			'optimized_size' => (int) ( $result['optimized_size'] ?? 0 ),
			'saved_bytes'    => (int) ( $result['saved_bytes'] ?? 0 ),
			'webp_created'   => (bool) ( $result['webp_created'] ?? false ),
			'avif_created'   => (bool) ( $result['avif_created'] ?? false ),
			'error'          => (string) ( $result['error'] ?? '' ),
			'optimized_at'   => time(),
		);

		$existing_meta = get_post_meta( $attachment_id, '_ofio_meta', true );
		update_post_meta( $attachment_id, '_ofio_meta', $new_meta );

		$this->clear_all_caches();

		if ( ! $is_success ) {
			return;
		}

		$raw = $this->get_raw();

		if ( ! empty( $existing_meta ) && is_array( $existing_meta ) && ( $existing_meta['status'] ?? '' ) === 'optimized' ) {
			$raw['saved_bytes']   += ( $new_meta['saved_bytes'] - (int) ( $existing_meta['saved_bytes'] ?? 0 ) );
			$raw['original_size'] += ( $new_meta['original_size'] - (int) ( $existing_meta['original_size'] ?? 0 ) );
			$raw['new_size']      += ( $new_meta['optimized_size'] - (int) ( $existing_meta['optimized_size'] ?? 0 ) );
		} else {
			$raw['optimized_images'] = (int) $raw['optimized_images'] + 1;
			$raw['saved_bytes']      = (int) $raw['saved_bytes'] + $new_meta['saved_bytes'];
			$raw['original_size']    = (int) $raw['original_size'] + $new_meta['original_size'];
			$raw['new_size']         = (int) $raw['new_size'] + $new_meta['optimized_size'];
		}

		$raw['saved_bytes']   = max( 0, (int) $raw['saved_bytes'] );
		$raw['original_size'] = max( 0, (int) $raw['original_size'] );
		$raw['new_size']      = max( 0, (int) $raw['new_size'] );

		$this->save_raw( $raw );
	}

	/**
	 * Record single-image restoration.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return void
	 */
	public function record_restore( int $attachment_id ): void {
		$existing_meta = get_post_meta( $attachment_id, '_ofio_meta', true );
		$raw           = $this->get_raw();

		if ( ! empty( $existing_meta ) && is_array( $existing_meta ) && ( $existing_meta['status'] ?? '' ) === 'optimized' ) {
			$saved_bytes   = (int) ( $existing_meta['saved_bytes'] ?? 0 );
			$original_size = (int) ( $existing_meta['original_size'] ?? 0 );
			$new_size      = (int) ( $existing_meta['optimized_size'] ?? 0 );

			$raw['saved_bytes']   = max( 0, (int) $raw['saved_bytes'] - $saved_bytes );
			$raw['original_size'] = max( 0, (int) $raw['original_size'] - $original_size );
			$raw['new_size']      = max( 0, (int) $raw['new_size'] - $new_size );
		}

		$raw['optimized_images'] = max( 0, (int) $raw['optimized_images'] - 1 );

		if ( 0 === $raw['optimized_images'] ) {
			$raw['saved_bytes']   = 0;
			$raw['original_size'] = 0;
			$raw['new_size']      = 0;
		}

		$this->save_raw( $raw );
		delete_post_meta( $attachment_id, '_ofio_meta' );
		$this->clear_all_caches();
	}

	/**
	 * Retrieve dashboard statistics instantly from transient cache.
	 *
	 * @return array
	 */
	public function get(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$raw           = $this->get_raw();
		$total_images  = $this->count_total_images();
		$saved_bytes   = (int) $raw['saved_bytes'];
		$original_size = (int) $raw['original_size'];

		$pct_saved = ( $original_size > 0 && $saved_bytes > 0 )
			? round( ( $saved_bytes / $original_size ) * 100, 1 )
			: 0.0;

		$stats = array(
			'total_images'     => $total_images,
			'optimized_images' => min( (int) $raw['optimized_images'], $total_images ),
			'original_size'    => $original_size,
			'new_size'         => (int) $raw['new_size'],
			'saved_bytes'      => $saved_bytes,
			'saved_mb'         => round( $saved_bytes / 1048576, 2 ),
			'saved_gb'         => round( $saved_bytes / 1073741824, 4 ),
			'pct_saved'        => $pct_saved,
		);

		set_transient( self::CACHE_KEY, $stats, 30 );
		return $stats;
	}

	/**
	 * Count total images in the media library with transient caching.
	 *
	 * @return int
	 */
	public function count_total_images(): int {
		$cached = get_transient( self::TOTAL_COUNT_CACHE_KEY );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(ID) FROM {$wpdb->posts} 
				 WHERE post_type = %s
				 AND post_status IN (%s, %s, %s)
				 AND post_mime_type IN (%s, %s, %s, %s)",
				'attachment',
				'inherit',
				'publish',
				'private',
				'image/jpeg',
				'image/png',
				'image/webp',
				'image/gif'
			)
		);

		set_transient( self::TOTAL_COUNT_CACHE_KEY, $count, 300 );
		return $count;
	}

	/**
	 * Count unoptimized images in the media library with transient caching.
	 *
	 * @return int
	 */
	public function count_unoptimized_images(): int {
		$cached = get_transient( self::UNOPT_COUNT_CACHE_KEY );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(p.ID) FROM {$wpdb->posts} p 
				 WHERE p.post_type = %s
				 AND p.post_status IN (%s, %s, %s)
				 AND p.post_mime_type IN (%s, %s, %s, %s)
				 AND NOT EXISTS (
					 SELECT 1 FROM {$wpdb->postmeta} pm 
					 WHERE pm.post_id = p.ID 
					 AND pm.meta_key = %s
				 )",
				'attachment',
				'inherit',
				'publish',
				'private',
				'image/jpeg',
				'image/png',
				'image/webp',
				'image/gif',
				'_ofio_meta'
			)
		);

		set_transient( self::UNOPT_COUNT_CACHE_KEY, $count, 300 );
		return $count;
	}

	/**
	 * Retrieve unoptimized attachment IDs.
	 *
	 * @param int  $limit     Limit of IDs to return (0 = all).
	 * @param bool $force_all Whether to ignore optimization status.
	 * @param int  $offset    Offset.
	 * @return int[]
	 */
	public function get_unoptimized_ids( int $limit = 500, bool $force_all = false, int $offset = 0 ): array {
		global $wpdb;

		if ( $force_all ) {
			if ( $limit > 0 ) {
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} 
						 WHERE post_type = %s
						 AND post_status IN (%s, %s, %s)
						 AND post_mime_type IN (%s, %s, %s, %s)
						 ORDER BY ID ASC
						 LIMIT %d OFFSET %d",
						'attachment',
						'inherit',
						'publish',
						'private',
						'image/jpeg',
						'image/png',
						'image/webp',
						'image/gif',
						$limit,
						max( 0, $offset )
					)
				);
			} else {
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} 
						 WHERE post_type = %s
						 AND post_status IN (%s, %s, %s)
						 AND post_mime_type IN (%s, %s, %s, %s)
						 ORDER BY ID ASC",
						'attachment',
						'inherit',
						'publish',
						'private',
						'image/jpeg',
						'image/png',
						'image/webp',
						'image/gif'
					)
				);
			}
		} else {
			if ( $limit > 0 ) {
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT p.ID FROM {$wpdb->posts} p 
						 WHERE p.post_type = %s
						 AND p.post_status IN (%s, %s, %s)
						 AND p.post_mime_type IN (%s, %s, %s, %s)
						 AND NOT EXISTS (
							 SELECT 1 FROM {$wpdb->postmeta} pm 
							 WHERE pm.post_id = p.ID 
							 AND pm.meta_key = %s
						 )
						 ORDER BY p.ID DESC
						 LIMIT %d",
						'attachment',
						'inherit',
						'publish',
						'private',
						'image/jpeg',
						'image/png',
						'image/webp',
						'image/gif',
						'_ofio_meta',
						$limit
					)
				);
			} else {
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT p.ID FROM {$wpdb->posts} p 
						 WHERE p.post_type = %s
						 AND p.post_status IN (%s, %s, %s)
						 AND p.post_mime_type IN (%s, %s, %s, %s)
						 AND NOT EXISTS (
							 SELECT 1 FROM {$wpdb->postmeta} pm 
							 WHERE pm.post_id = p.ID 
							 AND pm.meta_key = %s
						 )
						 ORDER BY p.ID DESC",
						'attachment',
						'inherit',
						'publish',
						'private',
						'image/jpeg',
						'image/png',
						'image/webp',
						'image/gif',
						'_ofio_meta'
					)
				);
			}
		}

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Reset all statistics cleanly to zero.
	 *
	 * @return void
	 */
	public function reset(): void {
		$this->save_raw(
			array(
				'total_images'     => $this->count_total_images(),
				'optimized_images' => 0,
				'original_size'    => 0,
				'new_size'         => 0,
				'saved_bytes'      => 0,
			)
		);
		$this->clear_all_caches();

		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_ofio_meta'
			)
		);
	}

	/**
	 * Flush all statistics transients and object caches.
	 *
	 * @return void
	 */
	public function clear_all_caches(): void {
		delete_transient( self::CACHE_KEY );
		delete_transient( self::TOTAL_COUNT_CACHE_KEY );
		delete_transient( self::UNOPT_COUNT_CACHE_KEY );
		delete_transient( 'ofio_unoptimized_ids' );
		wp_cache_delete( 'ofio_total_images_count', 'ofio' );
		wp_cache_delete( 'ofio_unoptimized_count', 'ofio' );
	}

	/**
	 * Retrieve raw stats array from options.
	 *
	 * @return array
	 */
	private function get_raw(): array {
		return wp_parse_args(
			(array) get_option( self::OPTION_KEY, array() ),
			array(
				'total_images'     => 0,
				'optimized_images' => 0,
				'original_size'    => 0,
				'new_size'         => 0,
				'saved_bytes'      => 0,
			)
		);
	}

	/**
	 * Persist raw stats array to options.
	 *
	 * @param array $stats Stats data.
	 * @return void
	 */
	private function save_raw( array $stats ): void {
		update_option( self::OPTION_KEY, $stats, false );
	}
}

// Backwards-compatible class aliases.
if ( ! class_exists( 'OFIO_Stats', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Stats', 'OFIO_Stats' );
}
if ( ! class_exists( '\OptiForge\ImageOptimizer\Stats', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Stats', '\OptiForge\ImageOptimizer\Stats' );
}
if ( ! class_exists( '\OptiForge_Image_Optimizer\Stats', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Stats', '\OptiForge_Image_Optimizer\Stats' );
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
