<?php
/**
 * Bulk Optimization, Queue Controller & AJAX Endpoints.
 *
 * Implements Instant Startup (zero UI blocking), fast polling telemetry,
 * and robust background queue interaction.
 *
 * @package OptiForge_Image_Optimizer
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace Optiforge_Image_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bulk
 */
class Bulk {

	/**
	 * Shared Optimizer.
	 *
	 * @var Optimizer
	 */
	private Optimizer $optimizer;

	/**
	 * Shared Backup.
	 *
	 * @var Backup
	 */
	private Backup $backup;

	/**
	 * Shared Stats.
	 *
	 * @var Stats
	 */
	private Stats $stats;

	/**
	 * Shared Queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * Bulk constructor.
	 *
	 * @param Optimizer $optimizer Shared optimizer.
	 * @param Backup    $backup    Shared backup.
	 * @param Stats     $stats     Shared stats.
	 * @param Queue     $queue     Shared queue.
	 */
	public function __construct( Optimizer $optimizer, Backup $backup, Stats $stats, Queue $queue ) {
		$this->optimizer = $optimizer;
		$this->backup    = $backup;
		$this->stats     = $stats;
		$this->queue     = $queue;

		// Modern Queue AJAX endpoints (Instant Startup & Telemetry).
		add_action( 'wp_ajax_ofio_start_bulk_queue',   array( $this, 'ajax_start_bulk_queue' ) );
		add_action( 'wp_ajax_ofio_get_bulk_status',    array( $this, 'ajax_get_bulk_status' ) );
		add_action( 'wp_ajax_ofio_pause_bulk_queue',   array( $this, 'ajax_pause_bulk_queue' ) );
		add_action( 'wp_ajax_ofio_resume_bulk_queue',  array( $this, 'ajax_resume_bulk_queue' ) );
		add_action( 'wp_ajax_ofio_stop_bulk_queue',    array( $this, 'ajax_stop_bulk_queue' ) );
		add_action( 'wp_ajax_ofio_start_restore_queue', array( $this, 'ajax_start_restore_queue' ) );

		// Single image actions.
		add_action( 'wp_ajax_ofio_reoptimize_image',   array( $this, 'ajax_reoptimize_image' ) );
		add_action( 'wp_ajax_ofio_restore_image',      array( $this, 'ajax_restore_image' ) );

		// Legacy AJAX endpoints maintained for backwards compatibility.
		add_action( 'wp_ajax_ofio_get_unoptimized_images', array( $this, 'ajax_legacy_get_unoptimized' ) );
		add_action( 'wp_ajax_ofio_process_image_batch',    array( $this, 'ajax_legacy_process_batch' ) );
		add_action( 'wp_ajax_ofio_get_restore_queue',      array( $this, 'ajax_legacy_get_restore_queue' ) );
		add_action( 'wp_ajax_ofio_process_restore_batch',  array( $this, 'ajax_legacy_process_restore_batch' ) );
	}

	// -------------------------------------------------------------------------
	// Modern Background Queue Handlers (Zero UI Blocking)
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Start bulk optimization queue instantly.
	 *
	 * Zero UI blocking: does not count or query images synchronously.
	 * Sets queue status to 'running' / 'indexing' and dispatches background worker in < 30ms.
	 *
	 * @return void
	 */
	public function ajax_start_bulk_queue(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ), 403 );
			return;
		}

		$force_all = ! empty( $_POST['force_all'] );
		$result    = $this->queue->start_optimization( $force_all );

		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Fast polling endpoint to retrieve live telemetry and progress.
	 *
	 * Sub-millisecond execution: purely reads transient and option states without table scans.
	 *
	 * @return void
	 */
	public function ajax_get_bulk_status(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ), 403 );
			return;
		}

		$state      = $this->queue->get_state();
		$recent_log = $this->queue->get_recent_log();
		$stats      = $this->stats->get();

		wp_send_json_success(
			array(
				'state'      => $state,
				'recent_log' => $recent_log,
				'stats'      => $stats,
			)
		);
	}

	/**
	 * AJAX: Pause the running background queue.
	 *
	 * @return void
	 */
	public function ajax_pause_bulk_queue(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ), 403 );
			return;
		}

		$res = $this->queue->pause();
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Resume the paused background queue.
	 *
	 * @return void
	 */
	public function ajax_resume_bulk_queue(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ), 403 );
			return;
		}

		$res = $this->queue->resume();
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Stop and cancel the background queue.
	 *
	 * @return void
	 */
	public function ajax_stop_bulk_queue(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ), 403 );
			return;
		}

		$res = $this->queue->stop();
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Start bulk restore queue in the background.
	 *
	 * @return void
	 */
	public function ajax_start_restore_queue(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ), 403 );
			return;
		}

		$res = $this->queue->start_restore();
		wp_send_json_success( $res );
	}

	// -------------------------------------------------------------------------
	// Single Image Actions (Media Library List View & Modal)
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Re-optimize a single image from the media library list table.
	 *
	 * @return void
	 */
	public function ajax_reoptimize_image(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ), 403 );
			return;
		}

		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid image attachment ID.', 'optiforge-image-optimizer' ) ) );
			return;
		}

		// Cache post fields before single-image optimization.
		$cached_alt         = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$attachment_post    = get_post( $attachment_id );
		$cached_title       = $attachment_post ? $attachment_post->post_title : '';
		$cached_caption     = $attachment_post ? $attachment_post->post_excerpt : '';
		$cached_description = $attachment_post ? $attachment_post->post_content : '';

		$result = $this->optimizer->optimize_attachment( $attachment_id );
		$this->stats->record( $attachment_id, $result );

		// Restore post fields.
		if ( '' !== $cached_alt ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $cached_alt );
		}
		$post_updates = array( 'ID' => $attachment_id );
		if ( '' !== $cached_title ) {
			$post_updates['post_title'] = $cached_title;
		}
		if ( '' !== $cached_caption ) {
			$post_updates['post_excerpt'] = $cached_caption;
		}
		if ( '' !== $cached_description ) {
			$post_updates['post_content'] = $cached_description;
		}
		if ( count( $post_updates ) > 1 ) {
			wp_update_post( $post_updates );
		}

		if ( $result['success'] ) {
			$html = Core::get_instance()->admin->get_media_column_html( $attachment_id );
			wp_send_json_success(
				array(
					/* translators: %s: file size saved */
					'message'     => sprintf( __( 'Saved %s.', 'optiforge-image-optimizer' ), size_format( (int) $result['saved_bytes'] ) ),
					'saved_bytes' => (int) $result['saved_bytes'],
					'html'        => $html,
					'stats'       => $this->stats->get(),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => esc_html( $result['error'] ) ) );
		}
	}

	/**
	 * AJAX: Restore a single image from backup in the media library list table.
	 *
	 * @return void
	 */
	public function ajax_restore_image(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ), 403 );
			return;
		}

		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment ID.', 'optiforge-image-optimizer' ) ) );
			return;
		}

		$attachment_post    = get_post( $attachment_id );
		$cached_title       = $attachment_post ? $attachment_post->post_title : '';
		$cached_caption     = $attachment_post ? $attachment_post->post_excerpt : '';
		$cached_description = $attachment_post ? $attachment_post->post_content : '';

		$result = $this->backup->restore_image( $attachment_id );

		$post_updates = array( 'ID' => $attachment_id );
		if ( '' !== $cached_title ) {
			$post_updates['post_title'] = $cached_title;
		}
		if ( '' !== $cached_caption ) {
			$post_updates['post_excerpt'] = $cached_caption;
		}
		if ( '' !== $cached_description ) {
			$post_updates['post_content'] = $cached_description;
		}
		if ( count( $post_updates ) > 1 ) {
			wp_update_post( $post_updates );
		}

		if ( $result['success'] ) {
			$this->stats->record_restore( $attachment_id );
			delete_post_meta( $attachment_id, '_ofio_meta' );

			$html = Core::get_instance()->admin->get_media_column_html( $attachment_id );
			wp_send_json_success(
				array(
					'message' => $result['message'],
					'html'    => $html,
					'stats'   => $this->stats->get(),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => esc_html( $result['message'] ) ) );
		}
	}

	// -------------------------------------------------------------------------
	// Backwards Compatibility Endpoints
	// -------------------------------------------------------------------------

	/**
	 * Legacy endpoint: Get unoptimized images count.
	 *
	 * @return void
	 */
	public function ajax_legacy_get_unoptimized(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ), 403 );
			return;
		}

		$force_all   = (bool) absint( $_POST['force_all'] ?? 0 );
		$total_count = $this->stats->count_total_images();
		$queue_count = $force_all ? $total_count : $this->stats->count_unoptimized_images();

		wp_send_json_success(
			array(
				'total_images' => (int) $total_count,
				'unoptimized'  => (int) $queue_count,
				'stats'        => $this->stats->get(),
			)
		);
	}

	/**
	 * Legacy endpoint: Process single AJAX batch.
	 *
	 * @return void
	 */
	public function ajax_legacy_process_batch(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ), 403 );
			return;
		}

		$force_all = (bool) absint( $_POST['force_all'] ?? 0 );
		$offset    = absint( $_POST['offset'] ?? 0 );
		$batch_opt = (int) get_option( 'ofio_batch_size', 5 );
		$max_fetch = min( 20, max( 1, $batch_opt ) );

		$ids = $this->stats->get_unoptimized_ids( $max_fetch, $force_all, $offset );

		if ( empty( $ids ) ) {
			wp_send_json_success(
				array(
					'processed'   => array(),
					'count_done'  => 0,
					'remaining'   => 0,
					'saved_bytes' => 0,
					'saved_kb'    => 0.0,
					'stats'       => $this->stats->get(),
					'message'     => __( 'Queue completed.', 'optiforge-image-optimizer' ),
				)
			);
			return;
		}

		$processed   = array();
		$saved_bytes = 0;

		foreach ( $ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				continue;
			}

			$result = $this->optimizer->optimize_attachment( $attachment_id );
			$this->stats->record( $attachment_id, $result );

			if ( $result['success'] ) {
				$bytes        = (int) $result['saved_bytes'];
				$saved_bytes += $bytes;
				$processed[]  = array(
					'id'          => $attachment_id,
					'success'     => true,
					'filename'    => esc_html( basename( (string) get_attached_file( $attachment_id ) ) ),
					'saved_bytes' => $bytes,
					'saved_kb'    => round( $bytes / 1024, 1 ),
					'webp'        => (bool) ( $result['webp_created'] ?? false ),
					'avif'        => (bool) ( $result['avif_created'] ?? false ),
					/* translators: %s: formatted file size saved */
					'message'     => sprintf( __( 'Saved %s', 'optiforge-image-optimizer' ), size_format( $bytes ) ),
				);
			} else {
				$processed[] = array(
					'id'       => $attachment_id,
					'success'  => false,
					'filename' => esc_html( basename( (string) get_attached_file( $attachment_id ) ) ),
					'message'  => esc_html( $result['error'] ),
				);
			}

			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		$remaining = $force_all
			? max( 0, $this->stats->count_total_images() - ( $offset + count( $processed ) ) )
			: $this->stats->count_unoptimized_images();

		wp_send_json_success(
			array(
				'processed'   => $processed,
				'count_done'  => count( $processed ),
				'remaining'   => $remaining,
				'saved_bytes' => $saved_bytes,
				'saved_kb'    => round( $saved_bytes / 1024, 1 ),
				'stats'       => $this->stats->get(),
			)
		);
	}

	/**
	 * Legacy endpoint: Get restore queue count.
	 *
	 * @return void
	 */
	public function ajax_legacy_get_restore_queue(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'optiforge-image-optimizer' ) ), 403 );
			return;
		}

		$total = $this->backup->count_restorable_images();
		wp_send_json_success( array( 'total_restorable' => (int) $total ) );
	}

	/**
	 * Legacy endpoint: Process restore batch.
	 *
	 * @return void
	 */
	public function ajax_legacy_process_restore_batch(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'optiforge-image-optimizer' ) ), 403 );
			return;
		}

		$batch_size  = absint( $_POST['batch_size'] ?? 5 );
		$batch_size  = max( 1, min( 20, $batch_size ) );
		$exclude_ids = array_filter( array_map( 'absint', (array) ( $_POST['exclude_ids'] ?? array() ) ) );

		$fetch_limit = $batch_size + count( $exclude_ids ) + 10;
		$all_ids     = $this->backup->get_restorable_ids( $fetch_limit );
		$ids         = array_values( array_diff( $all_ids, $exclude_ids ) );
		$ids         = array_slice( $ids, 0, $batch_size );

		if ( empty( $ids ) ) {
			wp_send_json_success(
				array(
					'remaining' => 0,
					'processed' => array(),
					'stats'     => $this->stats->get(),
				)
			);
			return;
		}

		$processed = array();
		foreach ( $ids as $id ) {
			$id  = absint( $id );
			$res = $this->backup->restore_image( $id );

			if ( $res['success'] ) {
				$this->stats->record_restore( $id );
				delete_post_meta( $id, '_ofio_meta' );
			}

			$processed[] = array(
				'id'       => $id,
				'success'  => (bool) $res['success'],
				'message'  => esc_html( $res['message'] ),
				'filename' => esc_html( basename( (string) get_attached_file( $id ) ) ),
			);
		}

		$remaining = $this->backup->count_restorable_images();
		if ( 0 === $remaining ) {
			$this->backup->purge_all_backups();
			$this->stats->reset();
			delete_option( 'ofio_stats' );
			delete_option( 'ofio_dashboard_cache' );
		}

		wp_send_json_success(
			array(
				'remaining' => $remaining,
				'processed' => $processed,
				'stats'     => $this->stats->get(),
			)
		);
	}
}

// Backwards-compatible class aliases.
if ( ! class_exists( 'OFIO_Bulk', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Bulk', 'OFIO_Bulk' );
}
if ( ! class_exists( '\OptiForge\ImageOptimizer\Bulk', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Bulk', '\OptiForge\ImageOptimizer\Bulk' );
}
if ( ! class_exists( '\OptiForge_Image_Optimizer\Bulk', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Bulk', '\OptiForge_Image_Optimizer\Bulk' );
}