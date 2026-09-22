<?php
/**
 * Statistics Engine with JPEG, PNG, and WebP Support.
 *
 * @package OptiForge_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OFIO_Stats {

	const OPTION_KEY = 'ofio_stats';
	const CACHE_KEY  = 'ofio_stats_cache';

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

	public function record_restore( int $attachment_id ): void {
		$existing_meta = get_post_meta( $attachment_id, '_ofio_meta', true );
		if ( empty( $existing_meta ) || ! is_array( $existing_meta ) || ( $existing_meta['status'] ?? '' ) !== 'optimized' ) {
			return;
		}

		$raw = $this->get_raw();
		$raw['optimized_images'] = max( 0, (int) $raw['optimized_images'] - 1 );
		$raw['saved_bytes']      = max( 0, (int) $raw['saved_bytes'] - (int) ( $existing_meta['saved_bytes'] ?? 0 ) );
		$raw['original_size']    = max( 0, (int) $raw['original_size'] - (int) ( $existing_meta['original_size'] ?? 0 ) );
		$raw['new_size']         = max( 0, (int) $raw['new_size'] - (int) ( $existing_meta['optimized_size'] ?? 0 ) );

		$this->save_raw( $raw );
		$this->clear_all_caches();
	}

	public function get(): array {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$raw           = $this->get_raw();
		$total_images  = $this->count_total_images();
		$saved_bytes   = (int) $raw['saved_bytes'];
		$original_size = (int) $raw['original_size'];

		$pct_saved = ( $original_size > 0 )
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

		set_transient( self::CACHE_KEY, $stats, 15 );
		return $stats;
	}

	public function count_total_images(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			"SELECT COUNT(ID) FROM {$wpdb->posts}
			 WHERE post_type = 'attachment'
			 AND post_status = 'inherit'
			 AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp')"
		);

		return (int) $count;
	}

	public function count_unoptimized_images(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			"SELECT COUNT(p.ID) FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_ofio_meta')
			 WHERE p.post_type = 'attachment'
			 AND p.post_status = 'inherit'
			 AND p.post_mime_type IN ('image/jpeg', 'image/png', 'image/webp')
			 AND pm.meta_id IS NULL"
		);

		return (int) $count;
	}

	public function get_unoptimized_ids( int $limit = 500, bool $force_all = false, int $offset = 0 ): array {
		global $wpdb;

		if ( $force_all ) {
			if ( $limit > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts}
						WHERE post_type = 'attachment'
						AND post_status = 'inherit'
						AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp')
						ORDER BY ID ASC
						LIMIT %d OFFSET %d",
						$limit,
						max( 0, $offset )
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ids = $wpdb->get_col(
					"SELECT ID FROM {$wpdb->posts}
					WHERE post_type = 'attachment'
					AND post_status = 'inherit'
					AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp')
					ORDER BY ID ASC"
				);
			}
		} else {
			if ( $limit > 0 ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ids = $wpdb->get_col(
					$wpdb->prepare(
						"SELECT p.ID FROM {$wpdb->posts} p
						LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_ofio_meta')
						WHERE p.post_type = 'attachment'
						AND p.post_status = 'inherit'
						AND p.post_mime_type IN ('image/jpeg', 'image/png', 'image/webp')
						AND pm.meta_id IS NULL
						ORDER BY p.ID DESC
						LIMIT %d",
						$limit
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$ids = $wpdb->get_col(
					"SELECT p.ID FROM {$wpdb->posts} p
					LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_ofio_meta')
					WHERE p.post_type = 'attachment'
					AND p.post_status = 'inherit'
					AND p.post_mime_type IN ('image/jpeg', 'image/png', 'image/webp')
					AND pm.meta_id IS NULL
					ORDER BY p.ID DESC"
				);
			}
		}

		return array_map( 'absint', (array) $ids );
	}

	public function reset(): void {
		$this->save_raw(
			array(
				'total_images'     => 0,
				'optimized_images' => 0,
				'original_size'    => 0,
				'new_size'         => 0,
				'saved_bytes'      => 0,
			)
		);
		$this->clear_all_caches();

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_ofio_meta'" );
	}

	public function clear_all_caches(): void {
		delete_transient( self::CACHE_KEY );
		delete_transient( 'ofio_unoptimized_ids' );
		wp_cache_delete( 'ofio_total_images_count', 'ofio' );
		wp_cache_delete( 'ofio_unoptimized_count', 'ofio' );
	}

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

	private function save_raw( array $stats ): void {
		update_option( self::OPTION_KEY, $stats, false );
	}
}