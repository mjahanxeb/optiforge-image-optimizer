<?php
/**
 * WP-CLI Commands for OptiForge Image Optimizer.
 *
 * Registers top-level sub-commands under `wp ofio`:
 *   wp ofio stats
 *   wp ofio queue [status|start|pause|resume|stop]
 *   wp ofio optimize [--all] [--batch-size=N]
 *   wp ofio restore  [--all] [--batch-size=N]
 *   wp ofio purge_backups
 *
 * @package OptiForge_Image_Optimizer
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace Optiforge_Image_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manage image optimization, background queue, restore, and statistics for OptiForge Image Optimizer.
 */
class CLI {

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
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function stats( array $args, array $assoc_args ): void {
		$stats_obj   = new Stats();
		$stats       = $stats_obj->get();
		$backup_info = ( new Backup() )->get_backup_directory_size();

		\WP_CLI::line( '========================================' );
		\WP_CLI::line( '   OptiForge Image Optimizer — Stats    ' );
		\WP_CLI::line( '========================================' );
		\WP_CLI::line( sprintf( 'Total Images:         %d', (int) $stats['total_images'] ) );
		\WP_CLI::line( sprintf( 'Optimized Images:     %d', (int) $stats['optimized_images'] ) );
		\WP_CLI::line( sprintf( 'Unoptimized Images:   %d', $stats_obj->count_unoptimized_images() ) );
		\WP_CLI::line( sprintf( 'Storage Saved:        %.2f MB (%.1f%%)', (float) $stats['saved_mb'], (float) $stats['pct_saved'] ) );
		\WP_CLI::line( sprintf( 'Backup Storage Used:  %s (%d files)', $backup_info['formatted'], $backup_info['count'] ) );
		\WP_CLI::line( '' );

		$engine_obj = new Optimizer();
		\WP_CLI::line( sprintf( 'Image Engine:         %s', strtoupper( $engine_obj->get_engine() ) ) );
		\WP_CLI::line( sprintf( 'AVIF Support:         %s', $engine_obj->supports_avif() ? 'Yes' : 'No' ) );
		\WP_CLI::line( sprintf( 'PHP Version:          %s', PHP_VERSION ) );
		\WP_CLI::line( sprintf( 'Memory Limit:         %s', ini_get( 'memory_limit' ) ) );
		\WP_CLI::line( '========================================' );
	}

	/**
	 * Manage the background optimization queue.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : The queue action to perform. Valid options: status, start, pause, resume, stop.
	 *
	 * [--force-all]
	 * : Used with 'start' to re-optimize already optimized images.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ofio queue status
	 *   wp ofio queue start
	 *   wp ofio queue start --force-all
	 *   wp ofio queue pause
	 *   wp ofio queue resume
	 *   wp ofio queue stop
	 *
	 * @subcommand queue
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function queue( array $args, array $assoc_args ): void {
		$action    = isset( $args[0] ) ? strtolower( $args[0] ) : 'status';
		$optimizer = new Optimizer();
		$backup    = new Backup();
		$stats     = new Stats();
		$queue     = new Queue( $optimizer, $backup, $stats );

		switch ( $action ) {
			case 'status':
				$state = $queue->get_state();
				\WP_CLI::line( '========================================' );
				\WP_CLI::line( '     OptiForge Background Queue         ' );
				\WP_CLI::line( '========================================' );
				\WP_CLI::line( sprintf( 'Queue Status:      %s', strtoupper( $state['status'] ) ) );
				\WP_CLI::line( sprintf( 'Execution Mode:    %s', $state['mode'] ) );
				\WP_CLI::line( sprintf( 'Current Step:      %s', $state['step'] ) );
				\WP_CLI::line( sprintf( 'Total Queued:      %d', (int) $state['total'] ) );
				\WP_CLI::line( sprintf( 'Processed:         %d', (int) $state['processed'] ) );
				\WP_CLI::line( sprintf( 'Remaining:         %d', (int) $state['remaining'] ) );
				\WP_CLI::line( sprintf( 'Failed:            %d', (int) $state['failed'] ) );
				\WP_CLI::line( sprintf( 'Storage Saved:     %s', size_format( (int) $state['saved_bytes'] ) ) );
				\WP_CLI::line( sprintf( 'Active Task:       %s', $state['current_file'] ) );
				\WP_CLI::line( '========================================' );
				break;

			case 'start':
				$force_all = isset( $assoc_args['force-all'] );
				$res       = $queue->start_optimization( $force_all );
				\WP_CLI::success( $res['message'] );
				break;

			case 'pause':
				$res = $queue->pause();
				\WP_CLI::success( $res['message'] );
				break;

			case 'resume':
				$res = $queue->resume();
				\WP_CLI::success( $res['message'] );
				break;

			case 'stop':
				$res = $queue->stop();
				\WP_CLI::success( $res['message'] );
				break;

			default:
				\WP_CLI::error( sprintf( 'Unknown queue action: %s. Use status, start, pause, resume, or stop.', $action ) );
		}
	}

	/**
	 * Directly optimize images synchronously via CLI.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Re-optimize every image, including previously optimized ones.
	 *
	 * [--batch-size=<number>]
	 * : Number of images to process. Defaults to all unoptimized images.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ofio optimize
	 *   wp ofio optimize --all
	 *   wp ofio optimize --batch-size=50
	 *
	 * @subcommand optimize
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function optimize( array $args, array $assoc_args ): void {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$stats_obj = new Stats();
		$optimizer = new Optimizer();
		$backup    = new Backup();
		$optimizer->set_backup( $backup );

		$force_all  = isset( $assoc_args['all'] );
		$batch_size = isset( $assoc_args['batch-size'] )
			? max( 1, absint( $assoc_args['batch-size'] ) )
			: 0;

		$ids = $stats_obj->get_unoptimized_ids( $batch_size, $force_all );

		if ( empty( $ids ) ) {
			\WP_CLI::success( 'No images found to optimize.' );
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
				\WP_CLI::warning(
					sprintf( 'Failed [ID %d]: %s', $id, $result['error'] )
				);
			}

			$progress->tick();

			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		$progress->finish();

		\WP_CLI::success(
			sprintf(
				'Done. %d optimized, %d failed. Saved: %s.',
				$success_count,
				$fail_count,
				size_format( $saved_total )
			)
		);
	}

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
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function restore( array $args, array $assoc_args ): void {
		$backup = new Backup();
		$total  = $backup->count_restorable_images();

		if ( 0 === $total ) {
			\WP_CLI::success( 'No images with backup records found.' );
			return;
		}

		$force_all  = isset( $assoc_args['all'] );
		$batch_size = isset( $assoc_args['batch-size'] )
			? max( 1, absint( $assoc_args['batch-size'] ) )
			: 0;

		if ( $force_all ) {
			\WP_CLI::confirm(
				sprintf(
					'About to restore %d image(s) to their originals. All optimizations will be reverted. Continue?',
					$total
				)
			);
		}

		$ids = $backup->get_restorable_ids( $batch_size );

		if ( empty( $ids ) ) {
			\WP_CLI::success( 'No restorable images found.' );
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
				\WP_CLI::warning(
					sprintf( 'Failed to restore [ID %d]: %s', $id, $result['message'] )
				);
			}

			$progress->tick();

			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		$progress->finish();

		\WP_CLI::success(
			sprintf(
				'Done. %d restored, %d failed.',
				$success_count,
				$fail_count
			)
		);
	}

	/**
	 * Delete all backup files from disk and remove all backup postmeta records.
	 *
	 * ## EXAMPLES
	 *
	 *   wp ofio purge_backups
	 *
	 * @subcommand purge_backups
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function purge_backups( array $args, array $assoc_args ): void {
		\WP_CLI::confirm( 'Are you sure you want to permanently delete ALL original image backups? This cannot be undone.' );
		$result = ( new Backup() )->purge_all_backups();
		\WP_CLI::success( $result['message'] );
	}
}

// Backwards-compatible class aliases.
if ( ! class_exists( 'OFIO_CLI', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\CLI', 'OFIO_CLI' );
}
if ( ! class_exists( '\OptiForge\ImageOptimizer\CLI', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\CLI', '\OptiForge\ImageOptimizer\CLI' );
}
if ( ! class_exists( '\OptiForge_Image_Optimizer\CLI', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\CLI', '\OptiForge_Image_Optimizer\CLI' );
}