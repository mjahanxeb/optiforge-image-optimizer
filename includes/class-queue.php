<?php
/**
 * True Background Processing Queue Engine.
 *
 * Implements a browser-independent, non-blocking asynchronous queue runner with:
 * 1. Dedicated indexed database queue table for sub-millisecond lookups.
 * 2. Instant startup with zero UI blocking (indexing offloaded to background).
 * 3. Dynamic resource & timeout monitoring (memory_get_usage, microtime, max_execution_time).
 * 4. Dual-engine dispatcher: Non-blocking HTTP loopback + WP-Cron watchdog fallback.
 * 5. Real-time transient state management for live dashboard telemetry.
 * 6. Concurrency lock protection and automatic zombie process unlocking.
 *
 * @package OptiForge_Image_Optimizer
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace Optiforge_Image_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Queue
 */
class Queue {

	/**
	 * Shared optimizer instance.
	 *
	 * @var Optimizer
	 */
	private Optimizer $optimizer;

	/**
	 * Shared backup instance.
	 *
	 * @var Backup
	 */
	private Backup $backup;

	/**
	 * Shared stats instance.
	 *
	 * @var Stats
	 */
	private Stats $stats;

	/**
	 * State option name.
	 */
	const STATE_OPTION = 'ofio_queue_state';

	/**
	 * Lock transient name.
	 */
	const LOCK_KEY = 'ofio_queue_lock';

	/**
	 * Recent log transient name.
	 */
	const RECENT_LOG_KEY = 'ofio_queue_recent_log';

	/**
	 * Background execution token transient name.
	 */
	const TOKEN_KEY = 'ofio_queue_token';

	/**
	 * Queue constructor.
	 *
	 * @param Optimizer $optimizer Shared optimizer.
	 * @param Backup    $backup    Shared backup.
	 * @param Stats     $stats     Shared stats.
	 */
	public function __construct( Optimizer $optimizer, Backup $backup, Stats $stats ) {
		$this->optimizer = $optimizer;
		$this->backup    = $backup;
		$this->stats     = $stats;

		// Ensure queue database table exists.
		$this->maybe_create_table();

		// AJAX handlers for background runner.
		add_action( 'wp_ajax_ofio_process_queue_background',        array( $this, 'ajax_process_background' ) );
		add_action( 'wp_ajax_nopriv_ofio_process_queue_background', array( $this, 'ajax_process_background' ) );

		// WP-Cron watchdog event.
		add_action( 'ofio_queue_watchdog', array( $this, 'cron_watchdog' ) );
	}

	/**
	 * Get the queue database table name.
	 *
	 * @return string Full table name with WP prefix.
	 */
	public function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'ofio_queue';
	}

	/**
	 * Create or update the queue database table schema.
	 *
	 * @return void
	 */
	public function maybe_create_table(): void {
		global $wpdb;

		// Check if table already exists in cache/schema.
		$installed_ver = get_option( 'ofio_queue_db_version', '0' );
		$current_ver   = '1.4.0';

		if ( $installed_ver === $current_ver ) {
			return;
		}

		$table_name      = $wpdb->prefix . 'ofio_queue';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			item_type VARCHAR(20) NOT NULL DEFAULT 'attachment',
			item_path TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			saved_bytes BIGINT(20) NOT NULL DEFAULT 0,
			error_message TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY attachment_id (attachment_id),
			KEY status (status),
			KEY item_type (item_type)
		) {$charset_collate};";

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		dbDelta( $sql );

		update_option( 'ofio_queue_db_version', $current_ver, false );
	}

	// -------------------------------------------------------------------------
	// State Management
	// -------------------------------------------------------------------------

	/**
	 * Get the current queue state array.
	 *
	 * @return array{
	 *     status: string,
	 *     mode: string,
	 *     step: string,
	 *     force_all: bool,
	 *     total: int,
	 *     processed: int,
	 *     remaining: int,
	 *     failed: int,
	 *     saved_bytes: int,
	 *     current_file: string,
	 *     started_at: int,
	 *     updated_at: int,
	 *     last_active: int
	 * }
	 */
	public function get_state(): array {
		$defaults = array(
			'status'       => 'idle',      // 'idle', 'running', 'paused', 'stopped', 'completed'
			'mode'         => 'optimize',  // 'optimize' or 'restore'
			'step'         => 'idle',      // 'idle', 'indexing', 'processing', 'completed'
			'force_all'    => false,
			'total'        => 0,
			'processed'    => 0,
			'remaining'    => 0,
			'failed'       => 0,
			'saved_bytes'  => 0,
			'current_file' => '',
			'started_at'   => 0,
			'updated_at'   => 0,
			'last_active'  => 0,
		);

		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) {
			return $defaults;
		}

		return wp_parse_args( $state, $defaults );
	}

	/**
	 * Update the queue state array.
	 *
	 * @param array $updates Partial or full array of state values to update.
	 * @return array Updated state array.
	 */
	public function update_state( array $updates ): array {
		$current = $this->get_state();
		$updates['updated_at'] = time();

		$new_state = array_merge( $current, $updates );
		update_option( self::STATE_OPTION, $new_state, false );

		return $new_state;
	}

	/**
	 * Reset the queue state to idle.
	 *
	 * @return void
	 */
	public function reset_state(): void {
		$this->update_state(
			array(
				'status'       => 'idle',
				'mode'         => 'optimize',
				'step'         => 'idle',
				'force_all'    => false,
				'total'        => 0,
				'processed'    => 0,
				'remaining'    => 0,
				'failed'       => 0,
				'saved_bytes'  => 0,
				'current_file' => '',
				'started_at'   => 0,
				'updated_at'   => time(),
				'last_active'  => 0,
			)
		);
		delete_transient( self::LOCK_KEY );
		delete_transient( self::RECENT_LOG_KEY );
	}

	/**
	 * Push a log record into the recent telemetry buffer.
	 *
	 * @param array $entry Entry data.
	 * @return void
	 */
	public function log_recent_entry( array $entry ): void {
		$log = get_transient( self::RECENT_LOG_KEY );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$entry['time'] = time();
		array_unshift( $log, $entry );

		// Keep only the most recent 25 items for fast polling payload.
		if ( count( $log ) > 25 ) {
			$log = array_slice( $log, 0, 25 );
		}

		set_transient( self::RECENT_LOG_KEY, $log, 3600 );
	}

	/**
	 * Get recent activity log entries.
	 *
	 * @return array
	 */
	public function get_recent_log(): array {
		$log = get_transient( self::RECENT_LOG_KEY );
		return is_array( $log ) ? $log : array();
	}

	// -------------------------------------------------------------------------
	// Queue Controls (Instant UI Response)
	// -------------------------------------------------------------------------

	/**
	 * Start optimization queue instantly with zero UI blocking.
	 *
	 * Responds in < 30ms by setting initial state to 'indexing' and dispatching
	 * the background worker asynchronously.
	 *
	 * @param bool $force_all Whether to re-optimize already optimized images.
	 * @return array Response payload.
	 */
	public function start_optimization( bool $force_all = false ): array {
		$current = $this->get_state();

		// If already running, return existing state immediately.
		if ( 'running' === $current['status'] ) {
			return array(
				'status'  => 'running',
				'step'    => $current['step'],
				'message' => __( 'Optimization is already running in background.', 'optiforge-image-optimizer' ),
				'state'   => $current,
			);
		}

		// Clean previous uncompleted queue items for a fresh run.
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}ofio_queue WHERE status IN ( %s, %s )",
				'pending',
				'processing'
			)
		);

		// Reset recent log.
		delete_transient( self::RECENT_LOG_KEY );

		// Initialize state instantly.
		$state = $this->update_state(
			array(
				'status'       => 'running',
				'mode'         => 'optimize',
				'step'         => 'indexing',
				'force_all'    => $force_all,
				'total'        => 0,
				'processed'    => 0,
				'remaining'    => 0,
				'failed'       => 0,
				'saved_bytes'  => 0,
				'current_file' => __( 'Indexing media library…', 'optiforge-image-optimizer' ),
				'started_at'   => time(),
				'last_active'  => time(),
			)
		);

		// Dispatch background worker non-blockingly.
		$this->dispatch_background_runner();

		return array(
			'status'  => 'running',
			'step'    => 'indexing',
			'message' => __( 'Background optimization started.', 'optiforge-image-optimizer' ),
			'state'   => $state,
		);
	}

	/**
	 * Start bulk restore queue instantly.
	 *
	 * @return array Response payload.
	 */
	public function start_restore(): array {
		$current = $this->get_state();

		if ( 'running' === $current['status'] ) {
			return array(
				'status'  => 'running',
				'step'    => $current['step'],
				'message' => __( 'A task is already running in background.', 'optiforge-image-optimizer' ),
				'state'   => $current,
			);
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}ofio_queue WHERE status IN ( %s, %s )",
				'pending',
				'processing'
			)
		);

		delete_transient( self::RECENT_LOG_KEY );

		$state = $this->update_state(
			array(
				'status'       => 'running',
				'mode'         => 'restore',
				'step'         => 'indexing',
				'force_all'    => false,
				'total'        => 0,
				'processed'    => 0,
				'remaining'    => 0,
				'failed'       => 0,
				'saved_bytes'  => 0,
				'current_file' => __( 'Indexing restorable backups…', 'optiforge-image-optimizer' ),
				'started_at'   => time(),
				'last_active'  => time(),
			)
		);

		$this->dispatch_background_runner();

		return array(
			'status'  => 'running',
			'step'    => 'indexing',
			'message' => __( 'Bulk restore started in background.', 'optiforge-image-optimizer' ),
			'state'   => $state,
		);
	}

	/**
	 * Pause the background queue cleanly.
	 *
	 * @return array
	 */
	public function pause(): array {
		$state = $this->update_state(
			array(
				'status'      => 'paused',
				'last_active' => time(),
			)
		);
		delete_transient( self::LOCK_KEY );

		return array(
			'status'  => 'paused',
			'message' => __( 'Optimization paused.', 'optiforge-image-optimizer' ),
			'state'   => $state,
		);
	}

	/**
	 * Resume the background queue seamlessly.
	 *
	 * @return array
	 */
	public function resume(): array {
		$state = $this->update_state(
			array(
				'status'      => 'running',
				'last_active' => time(),
			)
		);

		$this->dispatch_background_runner();

		return array(
			'status'  => 'running',
			'message' => __( 'Optimization resumed in background.', 'optiforge-image-optimizer' ),
			'state'   => $state,
		);
	}

	/**
	 * Stop the background queue and cancel remaining tasks.
	 *
	 * @return array
	 */
	public function stop(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}ofio_queue WHERE status IN ( %s, %s )",
				'pending',
				'processing'
			)
		);

		delete_transient( self::LOCK_KEY );

		$state = $this->update_state(
			array(
				'status'       => 'stopped',
				'step'         => 'idle',
				'remaining'    => 0,
				'current_file' => '',
				'last_active'  => time(),
			)
		);

		return array(
			'status'  => 'stopped',
			'message' => __( 'Optimization stopped by user.', 'optiforge-image-optimizer' ),
			'state'   => $state,
		);
	}

	// -------------------------------------------------------------------------
	// Background Dispatching (Non-Blocking HTTP Loopback + Cron Watchdog)
	// -------------------------------------------------------------------------

	/**
	 * Dispatch an asynchronous non-blocking background request to admin-ajax.php.
	 *
	 * Returns immediately without waiting for execution.
	 *
	 * @return void
	 */
	public function dispatch_background_runner(): void {
		$token = wp_generate_password( 32, false );
		set_transient( self::TOKEN_KEY, $token, 300 );

		$nonce    = wp_create_nonce( 'ofio_process_queue' );
		$ajax_url = admin_url( 'admin-ajax.php' );
		$args     = array(
			'timeout'   => 0.01,
			'blocking'  => false,
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			'sslverify' => apply_filters( 'optiforge_image_optimizer_ssl_verify', false ),
			'body'      => array(
				'action'     => 'ofio_process_queue_background',
				'ofio_token' => $token,
				'ofio_nonce' => $nonce,
			),
		);

		wp_remote_post( $ajax_url, $args );

		// Register watchdog cron event 45s from now as insurance.
		if ( ! wp_next_scheduled( 'ofio_queue_watchdog' ) ) {
			wp_schedule_single_event( time() + 45, 'ofio_queue_watchdog' );
		}
	}

	/**
	 * AJAX endpoint for the background worker.
	 *
	 * @return void
	 */
	public function ajax_process_background(): void {
		// Ignore user abort so script continues running if connection closes.
		ignore_user_abort( true );

		$nonce          = isset( $_POST['ofio_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['ofio_nonce'] ) ) : '';
		$is_nonce_valid = (bool) wp_verify_nonce( $nonce, 'ofio_process_queue' );

		$provided_token = isset( $_POST['ofio_token'] ) ? sanitize_text_field( wp_unslash( $_POST['ofio_token'] ) ) : '';
		$saved_token    = (string) get_transient( self::TOKEN_KEY );
		$is_token_valid = ( ! empty( $provided_token ) && hash_equals( $saved_token, $provided_token ) );

		if ( $is_token_valid ) {
			delete_transient( self::TOKEN_KEY );
		}

		if ( ! $is_nonce_valid && ! $is_token_valid && ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Forbidden', 403 );
		}

		// Run a full execution cycle.
		$this->run_worker_cycle();

		wp_die();
	}

	/**
	 * Watchdog cron handler.
	 *
	 * Rescues stalled or blocked queues if loopback requests were prevented by host.
	 *
	 * @return void
	 */
	public function cron_watchdog(): void {
		$state = $this->get_state();

		if ( 'running' !== $state['status'] ) {
			return;
		}

		// If worker has been inactive for > 30 seconds, pick up the queue.
		if ( ( time() - (int) $state['last_active'] ) >= 30 ) {
			$this->run_worker_cycle();
		}
	}

	// -------------------------------------------------------------------------
	// Worker Execution Engine (Timeout Prevention & Smart Batching)
	// -------------------------------------------------------------------------

	/**
	 * Run a batch execution cycle with dynamic resource & timeout monitoring.
	 *
	 * @return void
	 */
	public function run_worker_cycle(): void {
		// Check concurrency lock.
		$lock = get_transient( self::LOCK_KEY );
		if ( false !== $lock && ( time() - (int) $lock ) < 25 ) {
			// Another worker is actively executing — avoid dual processing collision.
			return;
		}

		// Acquire lock.
		set_transient( self::LOCK_KEY, time(), 60 );

		$state = $this->get_state();
		if ( 'running' !== $state['status'] ) {
			delete_transient( self::LOCK_KEY );
			return;
		}

		// Raise memory limit if permitted.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		// Step 1: Indexing if needed.
		if ( 'indexing' === $state['step'] ) {
			$this->execute_indexing( $state );
			$state = $this->get_state();
			if ( 'running' !== $state['status'] ) {
				delete_transient( self::LOCK_KEY );
				return;
			}
		}

		// Step 2: Processing batch loop.
		$start_time            = microtime( true );
		$time_budget           = $this->calculate_time_budget();
		$safe_memory_threshold = $this->calculate_memory_threshold();
		$batch_limit           = (int) get_option( 'ofio_batch_size', 5 );
		$batch_limit           = max( 1, min( 20, $batch_limit ) );

		global $wpdb;
		$processed_in_cycle = 0;

		while ( true ) {
			// Re-check state: if paused or stopped by user in UI, exit immediately.
			$live_state = $this->get_state();
			if ( 'running' !== $live_state['status'] ) {
				break;
			}

			// Check time budget.
			if ( ( microtime( true ) - $start_time ) >= $time_budget ) {
				$this->yield_and_respawn();
				return;
			}

			// Check memory threshold.
			if ( memory_get_usage( true ) >= $safe_memory_threshold ) {
				$this->yield_and_respawn();
				return;
			}

			// Refresh lock timestamp to indicate worker is alive.
			set_transient( self::LOCK_KEY, time(), 60 );

			// Fetch next pending item.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$item = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, attachment_id, item_type, attempts 
					 FROM {$wpdb->prefix}ofio_queue 
					 WHERE status = %s 
					 ORDER BY id ASC 
					 LIMIT %d",
					'pending',
					1
				)
			);

			if ( ! $item ) {
				// No more pending items! Check if queue is fully complete.
				$this->finalize_queue( $live_state );
				return;
			}

			// Mark item as processing atomically.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status'     => 'processing',
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => (int) $item->id )
			);

			// Process the image safely.
			$this->process_queue_item( $item, $live_state );
			$processed_in_cycle++;

			// Free memory buffers immediately.
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}

			// Yield if batch slice reached to let server breathe and update state.
			if ( $processed_in_cycle >= $batch_limit ) {
				$this->yield_and_respawn();
				return;
			}
		}

		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Offload heavy image indexing to background runner with optimized queries.
	 *
	 * @param array $state Current queue state.
	 * @return void
	 */
	private function execute_indexing( array $state ): void {
		global $wpdb;

		$mode      = $state['mode'] ?? 'optimize';
		$force_all = ! empty( $state['force_all'] );

		if ( 'optimize' === $mode ) {
			if ( $force_all ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$wpdb->prefix}ofio_queue (attachment_id, item_type, status, created_at, updated_at)
						 SELECT p.ID, %s, %s, NOW(), NOW()
						 FROM {$wpdb->posts} p
						 WHERE p.post_type = %s
						   AND p.post_status IN (%s, %s, %s)
						   AND p.post_mime_type IN (%s, %s, %s, %s)
						 ORDER BY p.ID DESC",
						'attachment',
						'pending',
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
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"INSERT INTO {$wpdb->prefix}ofio_queue (attachment_id, item_type, status, created_at, updated_at)
						 SELECT p.ID, %s, %s, NOW(), NOW()
						 FROM {$wpdb->posts} p
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
						'pending',
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
		} elseif ( 'restore' === $mode ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->prefix}ofio_queue (attachment_id, item_type, status, created_at, updated_at)
					 SELECT DISTINCT pm.post_id, %s, %s, NOW(), NOW()
					 FROM {$wpdb->postmeta} pm
					 WHERE pm.meta_key = %s
					 ORDER BY pm.post_id DESC",
					'restore',
					'pending',
					'_ofio_backup_path'
				)
			);
		}

		// Calculate total pending items in table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(id) FROM {$wpdb->prefix}ofio_queue WHERE status = %s",
				'pending'
			)
		);

		if ( 0 === $total ) {
			$this->update_state(
				array(
					'step'        => 'completed',
					'status'      => 'completed',
					'total'       => 0,
					'remaining'   => 0,
					'processed'   => 0,
					'last_active' => time(),
				)
			);
			return;
		}

		$this->update_state(
			array(
				'step'         => 'processing',
				'total'        => $total,
				'remaining'    => $total,
				'processed'    => 0,
				'current_file' => sprintf(
					/* translators: %d: count of queued images */
					__( 'Queued %d images for processing.', 'optiforge-image-optimizer' ),
					$total
				),
				'last_active'  => time(),
			)
		);
	}

	/**
	 * Process a single queue item with safe fallbacks and exception guarding.
	 *
	 * @param object $item       Queue record object.
	 * @param array  $live_state Current queue state.
	 * @return void
	 */
	private function process_queue_item( object $item, array $live_state ): void {
		global $wpdb;
		$table         = $this->get_table_name();
		$attachment_id = (int) $item->attachment_id;
		$item_type     = (string) $item->item_type;

		$filename = esc_html( basename( (string) get_attached_file( $attachment_id ) ) );
		if ( empty( $filename ) ) {
			$filename = 'ID #' . $attachment_id;
		}

		$this->update_state(
			array(
				'current_file' => $filename,
				'last_active'  => time(),
			)
		);

		if ( 'restore' === $item_type ) {
			// Restore logic.
			$this->process_restore_item( $item, $filename, $attachment_id );
			return;
		}

		// Validation check: ensure attachment is an image.
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status'        => 'skipped',
					'error_message' => 'Not a valid image attachment',
					'updated_at'    => current_time( 'mysql' ),
				),
				array( 'id' => (int) $item->id )
			);

			$this->increment_processed( 0, false );
			return;
		}

		// Cache post fields and alt text before optimization to prevent any metadata wiping.
		$cached_alt         = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$attachment_post    = get_post( $attachment_id );
		$cached_title       = $attachment_post ? $attachment_post->post_title : '';
		$cached_caption     = $attachment_post ? $attachment_post->post_excerpt : '';
		$cached_description = $attachment_post ? $attachment_post->post_content : '';

		try {
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

			if ( ! empty( $result['success'] ) ) {
				$saved = (int) ( $result['saved_bytes'] ?? 0 );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array(
						'status'      => 'completed',
						'saved_bytes' => $saved,
						'updated_at'  => current_time( 'mysql' ),
					),
					array( 'id' => (int) $item->id )
				);

				$this->log_recent_entry(
					array(
						'id'          => $attachment_id,
						'filename'    => $filename,
						'success'     => true,
						'saved_bytes' => $saved,
						'saved_kb'    => round( $saved / 1024, 1 ),
						'webp'        => (bool) ( $result['webp_created'] ?? false ),
						'avif'        => (bool) ( $result['avif_created'] ?? false ),
						'message'     => sprintf(
							/* translators: %s: formatted file size saved */
							__( 'Saved %s', 'optiforge-image-optimizer' ),
							size_format( $saved )
						),
					)
				);

				$this->increment_processed( $saved, true );
			} else {
				$error_msg = (string) ( $result['error'] ?? 'Unknown error' );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array(
						'status'        => 'failed',
						'error_message' => $error_msg,
						'updated_at'    => current_time( 'mysql' ),
					),
					array( 'id' => (int) $item->id )
				);

				$this->log_recent_entry(
					array(
						'id'          => $attachment_id,
						'filename'    => $filename,
						'success'     => false,
						'saved_bytes' => 0,
						'message'     => $error_msg,
					)
				);

				$this->increment_processed( 0, false );
			}
		} catch ( \Throwable $e ) {
			// Catch any fatal or unhandled exception so the queue never halts.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status'        => 'failed',
					'error_message' => $e->getMessage(),
					'updated_at'    => current_time( 'mysql' ),
				),
				array( 'id' => (int) $item->id )
			);

			$this->log_recent_entry(
				array(
					'id'          => $attachment_id,
					'filename'    => $filename,
					'success'     => false,
					'saved_bytes' => 0,
					'message'     => $e->getMessage(),
				)
			);

			$this->increment_processed( 0, false );
		}
	}

	/**
	 * Process a single backup restoration queue item.
	 *
	 * @param object $item          Queue item.
	 * @param string $filename      File basename.
	 * @param int    $attachment_id Attachment ID.
	 * @return void
	 */
	private function process_restore_item( object $item, string $filename, int $attachment_id ): void {
		global $wpdb;
		$table = $this->get_table_name();

		// Cache post fields before restore.
		$attachment_post    = get_post( $attachment_id );
		$cached_title       = $attachment_post ? $attachment_post->post_title : '';
		$cached_caption     = $attachment_post ? $attachment_post->post_excerpt : '';
		$cached_description = $attachment_post ? $attachment_post->post_content : '';

		try {
			$res = $this->backup->restore_image( $attachment_id );

			// Restore post fields.
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

			if ( ! empty( $res['success'] ) ) {
				$this->stats->record_restore( $attachment_id );
				delete_post_meta( $attachment_id, '_ofio_meta' );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array(
						'status'     => 'completed',
						'updated_at' => current_time( 'mysql' ),
					),
					array( 'id' => (int) $item->id )
				);

				$this->log_recent_entry(
					array(
						'id'          => $attachment_id,
						'filename'    => $filename,
						'success'     => true,
						'saved_bytes' => 0,
						'message'     => __( 'Restored to original', 'optiforge-image-optimizer' ),
					)
				);

				$this->increment_processed( 0, true );
			} else {
				// Clean up record if backup missing.
				delete_post_meta( $attachment_id, '_ofio_backup_path' );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$table,
					array(
						'status'        => 'failed',
						'error_message' => $res['message'] ?? 'Failed',
						'updated_at'    => current_time( 'mysql' ),
					),
					array( 'id' => (int) $item->id )
				);

				$this->log_recent_entry(
					array(
						'id'          => $attachment_id,
						'filename'    => $filename,
						'success'     => false,
						'saved_bytes' => 0,
						'message'     => $res['message'] ?? 'Failed',
					)
				);

				$this->increment_processed( 0, false );
			}
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array(
					'status'        => 'failed',
					'error_message' => $e->getMessage(),
					'updated_at'    => current_time( 'mysql' ),
				),
				array( 'id' => (int) $item->id )
			);

			$this->increment_processed( 0, false );
		}
	}

	/**
	 * Increment processed counter and decrement remaining counter in live state.
	 *
	 * @param int  $saved_bytes Saved bytes to add.
	 * @param bool $success     Whether item was successfully processed.
	 * @return void
	 */
	private function increment_processed( int $saved_bytes, bool $success ): void {
		$state = $this->get_state();

		$processed   = (int) $state['processed'] + 1;
		$remaining   = max( 0, (int) $state['remaining'] - 1 );
		$failed      = (int) $state['failed'] + ( $success ? 0 : 1 );
		$total_saved = (int) $state['saved_bytes'] + $saved_bytes;

		$this->update_state(
			array(
				'processed'   => $processed,
				'remaining'   => $remaining,
				'failed'      => $failed,
				'saved_bytes' => $total_saved,
				'last_active' => time(),
			)
		);
	}

	/**
	 * Finalize completed queue.
	 *
	 * @param array $state Current queue state.
	 * @return void
	 */
	private function finalize_queue( array $state ): void {
		global $wpdb;
		$table = $this->get_table_name();

		if ( 'restore' === ( $state['mode'] ?? '' ) ) {
			// Purge all backups and reset stats on complete bulk restore.
			$this->backup->purge_all_backups();
			$this->stats->reset();
			delete_option( 'ofio_stats' );
			delete_option( 'ofio_dashboard_cache' );
		}

		// Delete completed queue rows to keep database compact.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}ofio_queue WHERE status IN ( %s, %s )",
				'completed',
				'skipped'
			)
		);

		$this->update_state(
			array(
				'status'       => 'completed',
				'step'         => 'completed',
				'remaining'    => 0,
				'current_file' => __( 'All tasks completed successfully!', 'optiforge-image-optimizer' ),
				'last_active'  => time(),
			)
		);

		delete_transient( self::LOCK_KEY );

		// Flush object and page caches.
		if ( class_exists( 'OFIO_Cache' ) ) {
			\OFIO_Cache::purge_all();
		}
	}

	/**
	 * Yield execution right before limits and schedule the next async cycle.
	 *
	 * @return void
	 */
	private function yield_and_respawn(): void {
		delete_transient( self::LOCK_KEY );
		$this->dispatch_background_runner();
	}

	// -------------------------------------------------------------------------
	// Timeout & Resource Calculation Helpers
	// -------------------------------------------------------------------------

	/**
	 * Calculate dynamic safe execution time budget.
	 *
	 * @return float Safe time limit in seconds.
	 */
	public function calculate_time_budget(): float {
		$max_exec = (int) ini_get( 'max_execution_time' );

		// If CLI (0) or excessive, cap safe work cycle at 22 seconds.
		if ( $max_exec <= 0 || $max_exec > 120 ) {
			return 22.0;
		}

		// Allocate 75% of max execution time or max_exec - 5 seconds.
		return max( 4.0, (float) min( $max_exec * 0.75, $max_exec - 5 ) );
	}

	/**
	 * Calculate safe memory limit threshold in bytes.
	 *
	 * @return int Threshold in bytes (80% of limit or 32MB safety margin).
	 */
	public function calculate_memory_threshold(): int {
		$memory_limit = $this->parse_memory_bytes( (string) ini_get( 'memory_limit' ) );

		if ( $memory_limit <= 0 ) {
			return PHP_INT_MAX;
		}

		// Leave at least 32MB or 20% buffer for garbage collection and image buffers.
		return (int) min( $memory_limit * 0.80, $memory_limit - 33554432 );
	}

	/**
	 * Convert human-readable php ini memory limit to bytes.
	 *
	 * @param string $val Value like '256M', '1G', '-1'.
	 * @return int Bytes, or -1 for unlimited.
	 */
	public function parse_memory_bytes( string $val ): int {
		$val = trim( $val );
		if ( '' === $val || '-1' === $val ) {
			return -1;
		}

		$unit  = strtolower( substr( $val, -1 ) );
		$bytes = (int) $val;

		switch ( $unit ) {
			case 'g':
				$bytes *= 1073741824;
				break;
			case 'm':
				$bytes *= 1048576;
				break;
			case 'k':
				$bytes *= 1024;
				break;
		}

		return $bytes;
	}
}

// Backwards-compatible class aliases.
if ( ! class_exists( 'OFIO_Queue', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Queue', 'OFIO_Queue' );
}
if ( ! class_exists( '\OptiForge\ImageOptimizer\Queue', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Queue', '\OptiForge\ImageOptimizer\Queue' );
}
if ( ! class_exists( '\OptiForge_Image_Optimizer\Queue', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Queue', '\OptiForge_Image_Optimizer\Queue' );
}
