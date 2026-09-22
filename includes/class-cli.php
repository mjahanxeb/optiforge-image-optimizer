<?php
/**
 * WP-CLI Commands for OptiForge Image Optimizer.
 *
 * Registers top-level sub-commands under `wp ofio`:
 *   wp ofio optimize [--all] [--batch-size=N]
 *   wp ofio restore  [--all] [--batch-size=N]
 *   wp ofio stats
 *   wp ofio purge_backups
 *
 * @package OptiForge_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage image optimization, restore, and statistics for OptiForge Image Optimizer.
 *
 * ## EXAMPLES
 *
 *   wp ofio stats
 *   wp ofio optimize --all
 *   wp ofio optimize --batch-size=20
 *   wp ofio restore --all
 *   wp ofio restore --batch-size=10
 *
 * @package OptiForge_Image_Optimizer
 */
class OFIO_CLI {

	// -------------------------------------------------------------------------
	// wp ofio stats
	// -------------------------------------------------------------------------

	/**
	 * Display a summary of optimization statistics and backup disk usage.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ofio stats
	 *
	 * @subcommand stats
	 * @alias status
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments (unused).
	 * @return void
	 */
	public function stats( array $args, array $assoc_args ): void {
		$stats_obj   = new OFIO_Stats();
		$stats       = $stats_obj->get();
		$backup_info = ( new OFIO_Backup() )->get_backup_directory_size();

		WP_CLI::line( '========================================' );
		WP_CLI::line( '   OptiForge Image Optimizer — Stats    ' );
		WP_CLI::line( '========================================' );
		WP_CLI::line( sprintf( 'Total Images:         %d', (int) $stats['total_images'] ) );
		WP_CLI::line( sprintf( 'Optimized Images:     %d', (int) $stats['optimized_images'] ) );
		WP_CLI::line( sprintf( 'Unoptimized Images:   %d', $stats_obj->count_unoptimized_images() ) );
		WP_CLI::line( sprintf( 'Storage Saved:        %.2f MB (%.1f%%)', (float) $stats['saved_mb'], (float) $stats['pct_saved'] ) );
		WP_CLI::line( sprintf( 'Backup Storage Used:  %s (%d files)', $backup_info['formatted'], $backup_info['count'] ) );
		WP_CLI::line( '' );

		$engine_obj = new OFIO_Optimizer();
		WP_CLI::line( sprintf( 'Image Engine:         %s', strtoupper( $engine_obj->get_engine() ) ) );
		WP_CLI::line( sprintf( 'AVIF Support:         %s', $engine_obj->supports_avif() ? 'Yes' : 'No' ) );
		WP_CLI::line( sprintf( 'PHP Version:          %s', PHP_VERSION ) );
		WP_CLI::line( sprintf( 'Memory Limit:         %s', ini_get( 'memory_limit' ) ) );
		WP_CLI::line( '========================================' );
	}

	// -------------------------------------------------------------------------
	// wp ofio optimize
	// -------------------------------------------------------------------------

	/**
	 * Optimize images in the media library.
	 *
	 * By default, optimizes only unoptimized images. Use --all to re-optimize
	 * every image regardless of existing optimization status.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Re-optimize every image, including previously optimized ones.
	 *
	 * [--batch-size=<number>]
	 * : Number of images to process. Defaults to all available when --all is
	 * set, or all unoptimized images otherwise. Set explicitly to limit scope.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ofio optimize
	 *   wp ofio optimize --all
	 *   wp ofio optimize --batch-size=50
	 *   wp ofio optimize --all --batch-size=100
	 *
	 * @subcommand optimize
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function optimize( array $args, array $assoc_args ): void {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$stats_obj = new OFIO_Stats();
		$optimizer = new OFIO_Optimizer();
		$backup    = new OFIO_Backup();
		$optimizer->set_backup( $backup );

		$force_all  = isset( $assoc_args['all'] );
		$batch_size = isset( $assoc_args['batch-size'] )
			? max( 1, absint( $assoc_args['batch-size'] ) )
			: 0; // 0 = no limit.

		$ids = $stats_obj->get_unoptimized_ids( $batch_size, $force_all );

		if ( empty( $ids ) ) {
			WP_CLI::success( 'No images found to optimize.' );
			return;
		}

		$total    = count( $ids );
		$progress = \WP_CLI\Utils\make_progress_bar(
			sprintf( 'Optimizing %d image(s)', $total ),
			$total
		);

		$success_count = 0;
		$fail_count    = 0;
		$saved_total   = 0;

		foreach ( $ids as $id ) {
			$id     = absint( $id );
			$result = $optimizer->optimize_attachment( $id );
			$stats_obj->record( $id, $result );

			if ( $result['success'] ) {
				$success_count++;
				$saved_total += (int) $result['saved_bytes'];
			} else {
				$fail_count++;
				WP_CLI::warning(
					sprintf(
						'Failed [ID %d]: %s',
						$id,
						$result['error']
					)
				);
			}

			$progress->tick();

			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		$progress->finish();

		WP_CLI::success(
			sprintf(
				'Done. %d optimized, %d failed. Saved: %s.',
				$success_count,
				$fail_count,
				size_format( $saved_total )
			)
		);
	}

	// -------------------------------------------------------------------------
	// wp ofio restore
	// -------------------------------------------------------------------------

	/**
	 * Restore images from backup.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Restore ALL backed-up images. Requires confirmation.
	 *
	 * [--batch-size=<number>]
	 * : Maximum number of images to restore in this run. Default: all.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ofio restore --all
	 *   wp ofio restore --batch-size=10
	 *
	 * @subcommand restore
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function restore( array $args, array $assoc_args ): void {
		$backup = new OFIO_Backup();
		$total  = $backup->count_restorable_images();

		if ( 0 === $total ) {
			WP_CLI::success( 'No images with backup records found.' );
			return;
		}

		$force_all  = isset( $assoc_args['all'] );
		$batch_size = isset( $assoc_args['batch-size'] )
			? max( 1, absint( $assoc_args['batch-size'] ) )
			: 0; // 0 = all.

		if ( $force_all ) {
			WP_CLI::confirm(
				sprintf(
					'About to restore %d image(s) to their originals. All current optimizations will be lost. Continue?',
					$total
				)
			);
		}

		$ids = $backup->get_restorable_ids( $batch_size );

		if ( empty( $ids ) ) {
			WP_CLI::success( 'No restorable images found.' );
			return;
		}

		$count    = count( $ids );
		$progress = \WP_CLI\Utils\make_progress_bar(
			sprintf( 'Restoring %d image(s)', $count ),
			$count
		);

		$success_count = 0;
		$fail_count    = 0;

		foreach ( $ids as $id ) {
			$id     = absint( $id );
			$result = $backup->restore_image( $id );

			if ( $result['success'] ) {
				$success_count++;
			} else {
				$fail_count++;
				WP_CLI::warning(
					sprintf(
						'Failed to restore [ID %d]: %s',
						$id,
						$result['message']
					)
				);
			}

			$progress->tick();

			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		$progress->finish();

		WP_CLI::success(
			sprintf(
				'Done. %d restored, %d failed.',
				$success_count,
				$fail_count
			)
		);
	}

	// -------------------------------------------------------------------------
	// wp ofio purge_backups
	// -------------------------------------------------------------------------

	/**
	 * Delete all backup files from disk and remove all backup postmeta records.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ofio purge_backups
	 *
	 * @subcommand purge_backups
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments (unused).
	 * @return void
	 */
	public function purge_backups( array $args, array $assoc_args ): void {
		WP_CLI::confirm( 'Are you sure you want to permanently delete ALL original image backups? This cannot be undone.' );
		$result = ( new OFIO_Backup() )->purge_all_backups();
		WP_CLI::success( $result['message'] );
	}
}