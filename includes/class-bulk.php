<?php
/**
 * Bulk Optimization & Restore Controller.
 *
 * Time-budgeted AJAX batch processor that handles bulk image optimization and
 * bulk restore. Each AJAX handler is fully secured with nonce verification,
 * capability checks, and input sanitization. Error-flagged images are marked
 * in postmeta so failed images are skipped on subsequent batch calls.
 *
 * @package OptiForge_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OFIO_Bulk
 */
class OFIO_Bulk {

	/**
	 * @var OFIO_Optimizer
	 */
	private OFIO_Optimizer $optimizer;

	/**
	 * @var OFIO_Backup
	 */
	private OFIO_Backup $backup;

	/**
	 * @var OFIO_Stats
	 */
	private OFIO_Stats $stats;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * OFIO_Bulk constructor.
	 *
	 * @param OFIO_Optimizer $optimizer Shared optimizer instance.
	 * @param OFIO_Backup    $backup    Shared backup instance.
	 * @param OFIO_Stats     $stats     Shared stats instance.
	 */
	public function __construct( OFIO_Optimizer $optimizer, OFIO_Backup $backup, OFIO_Stats $stats ) {
		$this->optimizer = $optimizer;
		$this->backup    = $backup;
		$this->stats     = $stats;

		add_action( 'wp_ajax_ofio_get_unoptimized_images', array( $this, 'ajax_get_unoptimized' ) );
		add_action( 'wp_ajax_ofio_process_image_batch',    array( $this, 'ajax_process_batch' ) );
		add_action( 'wp_ajax_ofio_restore_image',          array( $this, 'ajax_restore_image' ) );
		add_action( 'wp_ajax_ofio_reoptimize_image',       array( $this, 'ajax_reoptimize_image' ) );
		add_action( 'wp_ajax_ofio_get_restore_queue',      array( $this, 'ajax_get_restore_queue' ) );
		add_action( 'wp_ajax_ofio_process_restore_batch',  array( $this, 'ajax_process_restore_batch' ) );
	}

	// -------------------------------------------------------------------------
	// AJAX — Queue Info
	// -------------------------------------------------------------------------

	/**
	 * Return total image count and unoptimized count for UI initialisation.
	 *
	 * @return void
	 */
	public function ajax_get_unoptimized(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ),
				403
			);
			return;
		}

		$force_all   = (bool) absint( $_POST['force_all'] ?? 0 );
		$total_count = $this->stats->count_total_images();
		$queue_count = $force_all ? $total_count : $this->stats->count_unoptimized_images();

		wp_send_json_success(
			array(
				'total_images' => $total_count,
				'unoptimized'  => (int) $queue_count,
			)
		);
	}

	// -------------------------------------------------------------------------
	// AJAX — Batch Optimization
	// -------------------------------------------------------------------------

	/**
	 * Process one time-budgeted batch of images.
	 *
	 * Runs for at most 14 seconds per call to avoid HTTP 504 gateway timeouts.
	 * Failed images are flagged via stats->record() writing `_ofio_meta`
	 * postmeta, so get_unoptimized_ids() will skip them on future calls.
	 *
	 * @return void
	 */
	public function ajax_process_batch(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ),
				403
			);
			return;
		}

		// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged
			@set_time_limit( 300 );
		}
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		// Sanitize inputs.
		$force_all = (bool) absint( $_POST['force_all'] ?? 0 );
		$offset    = absint( $_POST['offset'] ?? 0 );
		$max_fetch = 10;

		$ids = $this->stats->get_unoptimized_ids( $max_fetch, $force_all, $offset );

		if ( empty( $ids ) ) {
			wp_send_json_success(
				array(
					'processed'   => array(),
					'count_done'  => 0,
					'remaining'   => 0,
					'saved_bytes' => 0,
					'saved_kb'    => 0.0,
					'message'     => __( 'Queue completed.', 'optiforge-image-optimizer' ),
				)
			);
			return;
		}

		$start_time  = microtime( true );
		$time_budget = 14.0;
		$processed   = array();
		$saved_bytes = 0;

		foreach ( $ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );

			// Stop if we've burned the time budget (never stop on very first item).
			if ( ! empty( $processed ) && ( microtime( true ) - $start_time ) >= $time_budget ) {
				break;
			}

			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				$this->stats->record(
					$attachment_id,
					array(
						'success' => false,
						'error'   => __( 'Not a valid image attachment.', 'optiforge-image-optimizer' ),
					)
				);
				$processed[] = array(
					'id'       => $attachment_id,
					'success'  => false,
					'filename' => esc_html( basename( (string) get_attached_file( $attachment_id ) ) ),
					'message'  => __( 'Skipped: not a valid image.', 'optiforge-image-optimizer' ),
				);
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
					'message'     => sprintf(
						/* translators: %s: human-readable file size saved */
						__( 'Saved %s', 'optiforge-image-optimizer' ),
						size_format( $bytes )
					),
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
			)
		);
	}

	// -------------------------------------------------------------------------
	// AJAX — Restore Queue Info
	// -------------------------------------------------------------------------

	/**
	 * Return how many images have valid backup records.
	 *
	 * @return void
	 */
	public function ajax_get_restore_queue(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Forbidden.', 'optiforge-image-optimizer' ) ),
				403
			);
			return;
		}

		$total = $this->backup->count_restorable_images();
		wp_send_json_success( array( 'total_restorable' => $total ) );
	}

	// -------------------------------------------------------------------------
	// AJAX — Batch Restore
	// -------------------------------------------------------------------------

	/**
	 * Process a batch of restore operations.
	 *
	 * @return void
	 */
	public function ajax_process_restore_batch(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Forbidden.', 'optiforge-image-optimizer' ) ),
				403
			);
			return;
		}

		$batch_size = absint( $_POST['batch_size'] ?? 5 );
		$batch_size = max( 1, min( 20, $batch_size ) ); // Clamp 1–20.

		$ids = $this->backup->get_restorable_ids( $batch_size );

		if ( empty( $ids ) ) {
			wp_send_json_success( array( 'remaining' => 0, 'processed' => array() ) );
			return;
		}

		$processed = array();
		foreach ( $ids as $id ) {
			$id  = absint( $id );
			$res = $this->backup->restore_image( $id );

			$processed[] = array(
				'id'       => $id,
				'success'  => $res['success'],
				'message'  => $res['message'],
				'filename' => esc_html( basename( (string) get_attached_file( $id ) ) ),
			);

			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		// Count AFTER restore so the value reflects the updated state.
		$remaining = $this->backup->count_restorable_images();

		wp_send_json_success(
			array(
				'remaining' => $remaining,
				'processed' => $processed,
			)
		);
	}

	// -------------------------------------------------------------------------
	// AJAX — Single Image Restore
	// -------------------------------------------------------------------------

	/**
	 * Restore a single image from its backup (Media Library inline action).
	 *
	 * @return void
	 */
	public function ajax_restore_image(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ),
				403
			);
			return;
		}

		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		if ( ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid attachment ID.', 'optiforge-image-optimizer' ) ) );
			return;
		}

		$result = $this->backup->restore_image( $attachment_id );

		if ( $result['success'] ) {
			$html = OFIO_Core::get_instance()->admin->get_media_column_html( $attachment_id );
			wp_send_json_success(
				array(
					'message' => $result['message'],
					'html'    => $html,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => esc_html( $result['message'] ) ) );
		}
	}

	// -------------------------------------------------------------------------
	// AJAX — Single Image Re-optimize
	// -------------------------------------------------------------------------

	/**
	 * Re-optimize a single image (Media Library inline action).
	 *
	 * @return void
	 */
	public function ajax_reoptimize_image(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ),
				403
			);
			return;
		}

		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid image attachment ID.', 'optiforge-image-optimizer' ) ) );
			return;
		}

		$result = $this->optimizer->optimize_attachment( $attachment_id );
		$this->stats->record( $attachment_id, $result );

		if ( $result['success'] ) {
			$html = OFIO_Core::get_instance()->admin->get_media_column_html( $attachment_id );
			wp_send_json_success(
				array(
					'message'     => sprintf(
						/* translators: %s: human-readable bytes saved */
						__( 'Saved %s.', 'optiforge-image-optimizer' ),
						size_format( (int) $result['saved_bytes'] )
					),
					'saved_bytes' => (int) $result['saved_bytes'],
					'html'        => $html,
				)
			);
		} else {
			wp_send_json_error( array( 'message' => esc_html( $result['error'] ) ) );
		}
	}
}