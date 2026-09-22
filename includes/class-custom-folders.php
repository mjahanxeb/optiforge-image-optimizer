<?php
/**
 * Custom Folders & Active Theme Image Optimizer.
 *
 * Scans directories outside wp-content/uploads/ (e.g. active theme) for
 * optimizable images and exposes AJAX endpoints for processing them. All
 * directory scanning is protected against path traversal.
 *
 * @package OptiForge_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OFIO_Custom_Folders
 */
class OFIO_Custom_Folders {

	/**
	 * Shared optimizer instance injected by OFIO_Core.
	 *
	 * @var OFIO_Optimizer
	 */
	private OFIO_Optimizer $optimizer;

	/**
	 * Allowed root paths for scanning.
	 *
	 * Directories outside this list are rejected by the traversal guard.
	 *
	 * @var string[]
	 */
	private array $allowed_roots;

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	/**
	 * OFIO_Custom_Folders constructor.
	 *
	 * @param OFIO_Optimizer $optimizer Shared optimizer instance.
	 */
	public function __construct( OFIO_Optimizer $optimizer ) {
		$this->optimizer = $optimizer;

		// Build whitelist of allowed root paths.
		$this->allowed_roots = array(
			realpath( WP_CONTENT_DIR )             ?: WP_CONTENT_DIR,
			realpath( get_stylesheet_directory() ) ?: get_stylesheet_directory(),
			realpath( get_template_directory() )   ?: get_template_directory(),
			realpath( wp_upload_dir()['basedir'] )  ?: wp_upload_dir()['basedir'],
		);
		$this->allowed_roots = array_unique( array_filter( $this->allowed_roots ) );

		add_action( 'wp_ajax_ofio_optimize_custom_folder', array( $this, 'ajax_optimize_custom_folder' ) );
	}

	// -------------------------------------------------------------------------
	// Folder Registry
	// -------------------------------------------------------------------------

	/**
	 * Return the list of scannable folder definitions.
	 *
	 * Plugins or themes can extend this list via the `ofio_scannable_folders`
	 * filter.
	 *
	 * @return array<string, array{label:string, path:string}>
	 */
	public static function get_scannable_folders(): array {
		$folders = array(
			'active_theme' => array(
				'label' => __( 'Active Theme Images', 'optiforge-image-optimizer' ),
				'path'  => get_stylesheet_directory(),
			),
		);

		/**
		 * Filter: allow third-party code to register additional scannable
		 * folders (e.g. a custom uploads sub-directory).
		 *
		 * @param array $folders Associative array of folder definitions.
		 */
		return (array) apply_filters( 'ofio_scannable_folders', $folders );
	}

	// -------------------------------------------------------------------------
	// Directory Scanning
	// -------------------------------------------------------------------------

	/**
	 * Recursively scan a directory for optimizable image files.
	 *
	 * Path traversal guard: rejects any directory whose realpath does not start
	 * with one of the allowed root paths.
	 *
	 * @param string $dir Absolute path to the directory to scan.
	 * @return string[] Absolute paths of discovered image files.
	 */
	public static function scan_folder_images( string $dir ): array {
		$images = array();

		if ( ! is_dir( $dir ) ) {
			return $images;
		}

		// Path traversal guard.
		$real_dir = realpath( $dir );
		if ( false === $real_dir ) {
			return $images;
		}

		// Build allowed roots from static context — use WP_CONTENT_DIR as baseline.
		$allowed = array(
			realpath( WP_CONTENT_DIR )             ?: WP_CONTENT_DIR,
			realpath( get_stylesheet_directory() ) ?: get_stylesheet_directory(),
			realpath( get_template_directory() )   ?: get_template_directory(),
			realpath( wp_upload_dir()['basedir'] )  ?: wp_upload_dir()['basedir'],
		);
		$allowed = array_unique( array_filter( $allowed ) );

		$is_allowed = false;
		foreach ( $allowed as $root ) {
			if ( 0 === strpos( $real_dir, $root ) ) {
				$is_allowed = true;
				break;
			}
		}

		if ( ! $is_allowed ) {
			return $images;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $real_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				/** @var SplFileInfo $file */
				if ( ! $file->isFile() || ! $file->isReadable() ) {
					continue;
				}
				$ext = strtolower( $file->getExtension() );
				if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
					$images[] = $file->getPathname();
				}
			}
		} catch ( \Exception $e ) {
			error_log( '[OFIO] scan_folder_images error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $images;
	}

	// -------------------------------------------------------------------------
	// External File Optimization
	// -------------------------------------------------------------------------

	/**
	 * Optimize a single image file that is NOT a WordPress attachment.
	 *
	 * Applies path traversal guard before processing.
	 *
	 * @param string $path Absolute path to the image file.
	 * @return array Optimization result array (same shape as process_file()).
	 */
	public function optimize_external_file( string $path ): array {
		// Traversal guard.
		$real_path = realpath( $path );
		if ( false === $real_path ) {
			return array(
				'success' => false,
				'error'   => __( 'File not found or unreadable.', 'optiforge-image-optimizer' ),
			);
		}

		$is_allowed = false;
		foreach ( $this->allowed_roots as $root ) {
			if ( 0 === strpos( $real_path, $root ) ) {
				$is_allowed = true;
				break;
			}
		}

		if ( ! $is_allowed ) {
			return array(
				'success' => false,
				'error'   => __( 'Path is outside the allowed directory list.', 'optiforge-image-optimizer' ),
			);
		}

		$quality      = (int) get_option( 'ofio_compression_quality', 82 );
		$mode         = (string) get_option( 'ofio_compression_mode', 'lossy' );
		$webp_enabled = (bool) get_option( 'ofio_webp_conversion', 1 );
		$avif_enabled = (bool) get_option( 'ofio_avif_conversion', 1 ) && $this->optimizer->supports_avif();

		return $this->optimizer->process_file( $real_path, $quality, $mode, $webp_enabled, $avif_enabled );
	}

	// -------------------------------------------------------------------------
	// AJAX Handler
	// -------------------------------------------------------------------------

	/**
	 * AJAX handler: optimize all images in a registered custom folder.
	 *
	 * Expects POST fields:
	 *   security  (nonce)
	 *   folder_key (string — key from get_scannable_folders())
	 *
	 * @return void
	 */
	public function ajax_optimize_custom_folder(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ),
				403
			);
			return;
		}

		$folder_key = sanitize_text_field( wp_unslash( $_POST['folder_key'] ?? '' ) );
		$folders    = self::get_scannable_folders();

		if ( ! isset( $folders[ $folder_key ] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Unknown folder key.', 'optiforge-image-optimizer' ) )
			);
			return;
		}

		$folder_path = (string) $folders[ $folder_key ]['path'];
		$images      = self::scan_folder_images( $folder_path );

		if ( empty( $images ) ) {
			wp_send_json_success(
				array(
					'processed'   => 0,
					'saved_bytes' => 0,
					'message'     => __( 'No optimizable images found in folder.', 'optiforge-image-optimizer' ),
				)
			);
			return;
		}

		$start_time  = microtime( true );
		$time_budget = 14.0;
		$processed   = 0;
		$saved_bytes = 0;
		$errors      = array();

		foreach ( $images as $image_path ) {
			if ( ! empty( $errors ) && ( microtime( true ) - $start_time ) >= $time_budget ) {
				break;
			}

			$result = $this->optimize_external_file( $image_path );

			if ( ! empty( $result['success'] ) ) {
				$processed++;
				$saved_bytes += (int) ( $result['saved_bytes'] ?? max( 0, (int) ( $result['original_size'] ?? 0 ) - (int) ( $result['new_size'] ?? 0 ) ) );
			} else {
				$errors[] = basename( $image_path ) . ': ' . ( $result['error'] ?? 'unknown error' );
			}

			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		wp_send_json_success(
			array(
				'processed'   => $processed,
				'saved_bytes' => $saved_bytes,
				'saved_kb'    => round( $saved_bytes / 1024, 1 ),
				'errors'      => $errors,
				'message'     => sprintf(
					/* translators: 1: number of images processed, 2: bytes saved */
					__( 'Processed %1$d images. Saved %2$s.', 'optiforge-image-optimizer' ),
					$processed,
					size_format( $saved_bytes )
				),
			)
		);
	}
}