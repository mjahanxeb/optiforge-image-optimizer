<?php
/**
 * Core Orchestrator — Singleton Bootstrap.
 *
 * Instantiates all plugin sub-systems, injects shared dependencies, and wires
 * WordPress hooks. Only one instance is created per request via get_instance().
 *
 * @package OptiForge_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OFIO_Core
 *
 * Central service locator / composition root for OptiForge Image Optimizer.
 */
class OFIO_Core {

	/**
	 * Singleton instance.
	 *
	 * @var OFIO_Core|null
	 */
	private static ?OFIO_Core $instance = null;

	/** @var OFIO_Optimizer */
	public OFIO_Optimizer $optimizer;

	/** @var OFIO_Bulk */
	public OFIO_Bulk $bulk;

	/** @var OFIO_Backup */
	public OFIO_Backup $backup;

	/** @var OFIO_Stats */
	public OFIO_Stats $stats;

	/** @var OFIO_Admin */
	public OFIO_Admin $admin;

	/** @var OFIO_Cron */
	public OFIO_Cron $cron;

	/** @var OFIO_Delivery */
	public OFIO_Delivery $delivery;

	/** @var OFIO_Custom_Folders */
	public OFIO_Custom_Folders $custom_folders;

	// -------------------------------------------------------------------------
	// Singleton Access
	// -------------------------------------------------------------------------

	/**
	 * Return (or create) the singleton instance.
	 *
	 * @return OFIO_Core
	 */
	public static function get_instance(): OFIO_Core {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// -------------------------------------------------------------------------
	// Constructor — Dependency Wiring
	// -------------------------------------------------------------------------

	/**
	 * Private constructor. Instantiate sub-systems and register hooks.
	 */
	private function __construct() {
		// Instantiate shared dependencies first.
		$this->backup    = new OFIO_Backup();
		$this->stats     = new OFIO_Stats();
		$this->optimizer = new OFIO_Optimizer();

		// Inject shared backup instance into optimizer.
		$this->optimizer->set_backup( $this->backup );

		// Instantiate orchestration services with injected dependencies.
		$this->bulk           = new OFIO_Bulk( $this->optimizer, $this->backup, $this->stats );
		$this->cron           = new OFIO_Cron( $this->optimizer, $this->stats );
		$this->delivery       = new OFIO_Delivery();
		$this->custom_folders = new OFIO_Custom_Folders( $this->optimizer );
		$this->admin          = new OFIO_Admin( $this->stats, $this->optimizer, $this->cron, $this->backup );

		// Hook automatic backup + sidecar cleanup on attachment deletion.
		add_action( 'delete_attachment', array( $this->backup, 'on_attachment_deleted' ) );

		// Hook upload processing.
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'handle_upload' ), 10, 2 );
	}

	// -------------------------------------------------------------------------
	// Upload Hook
	// -------------------------------------------------------------------------

	/**
	 * Auto-optimize freshly uploaded images.
	 *
	 * Fires on the `wp_generate_attachment_metadata` filter immediately after
	 * WordPress generates thumbnails. Returns fresh metadata so that any
	 * dimension changes made by scale_image_dimensions() are not overwritten.
	 *
	 * @param mixed $metadata      Attachment metadata as produced by WordPress.
	 * @param int   $attachment_id Attachment post ID.
	 * @return mixed Fresh attachment metadata.
	 */
	public function handle_upload( $metadata, int $attachment_id ) {
		// Guard: skip during bulk restore operations.
		if ( ! empty( OFIO_Backup::$is_restoring ) ) {
			return $metadata;
		}

		// Metadata corruption guard: return non-array metadata intact.
		if ( ! is_array( $metadata ) ) {
			return $metadata;
		}

		// Guard: check auto-optimize option.
		if ( ! get_option( 'ofio_auto_optimize', 1 ) ) {
			return $metadata;
		}

		// Guard: only process supported MIME types.
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return $metadata;
		}

		// Run optimization on original and thumbnails.
		$result = $this->optimizer->optimize_attachment( $attachment_id, $metadata );
		$this->stats->record( $attachment_id, $result );

		// Return fresh metadata if dimensions were scaled on disk.
		$fresh_metadata = wp_get_attachment_metadata( $attachment_id );

		return is_array( $fresh_metadata ) ? $fresh_metadata : $metadata;
	}
}