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

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace Optiforge_Image_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CustomFolders
 */
class CustomFolders {

	/**
	 * Shared optimizer instance.
	 *
	 * @var Optimizer
	 */
	private Optimizer $optimizer;

	/**
	 * Whitelist of allowed root paths for scanning.
	 *
	 * @var string[]
	 */
	private array $allowed_roots;

	/**
	 * CustomFolders constructor.
	 *
	 * @param Optimizer $optimizer Shared optimizer.
	 */
	public function __construct( Optimizer $optimizer ) {
		$this->optimizer = $optimizer;

		$this->allowed_roots = array(
			realpath( WP_CONTENT_DIR ) ?: WP_CONTENT_DIR,
			realpath( get_stylesheet_directory() ) ?: get_stylesheet_directory(),
			realpath( get_template_directory() ) ?: get_template_directory(),
			realpath( wp_upload_dir()['basedir'] ) ?: wp_upload_dir()['basedir'],
		);
		$this->allowed_roots = array_unique( array_filter( $this->allowed_roots ) );

		add_action( 'wp_ajax_ofio_optimize_custom_folder', array( $this, 'ajax_optimize_custom_folder' ) );
	}

	/**
	 * Get list of scannable folder definitions.
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

		return (array) apply_filters( 'ofio_scannable_folders', $folders );
	}

	/**
	 * Recursively scan directory for optimizable images with traversal guard.
	 *
	 * @param string $dir Path.
	 * @return string[]
	 */
	public static function scan_folder_images( string $dir ): array {
		$images = array();

		if ( ! is_dir( $dir ) ) {
			return $images;
		}

		$real_dir = realpath( $dir );
		if ( false === $real_dir ) {
			return $images;
		}

		$allowed = array(
			realpath( WP_CONTENT_DIR ) ?: WP_CONTENT_DIR,
			realpath( get_stylesheet_directory() ) ?: get_stylesheet_directory(),
			realpath( get_template_directory() ) ?: get_template_directory(),
			realpath( wp_upload_dir()['basedir'] ) ?: wp_upload_dir()['basedir'],
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
			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $real_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				/** @var \SplFileInfo $file */
				if ( ! $file->isFile() || ! $file->isReadable() ) {
					continue;
				}
				$ext = strtolower( $file->getExtension() );
				if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp', 'gif' ), true ) ) {
					$images[] = $file->getPathname();
				}
			}
		} catch ( \Exception $e ) {
			// Fail-safe: Skip unreadable folders.
		}

		return $images;
	}

	/**
	 * Optimize an external file outside uploads directory.
	 *
	 * @param string $path Path.
	 * @return array
	 */
	public function optimize_external_file( string $path ): array {
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
				'error'   => __( 'Path is outside allowed directories.', 'optiforge-image-optimizer' ),
			);
		}

		$quality      = (int) get_option( 'ofio_compression_quality', 82 );
		$mode         = (string) get_option( 'ofio_compression_mode', 'lossy' );
		$webp_enabled = (bool) get_option( 'ofio_webp_conversion', 1 );
		$avif_enabled = (bool) get_option( 'ofio_avif_conversion', 1 ) && $this->optimizer->supports_avif();

		return $this->optimizer->process_file( $real_path, $quality, $mode, $webp_enabled, $avif_enabled );
	}

	/**
	 * AJAX: Optimize images in registered custom folder.
	 *
	 * @return void
	 */
	public function ajax_optimize_custom_folder(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ), 403 );
			return;
		}

		$folder_key = sanitize_text_field( wp_unslash( $_POST['folder_key'] ?? '' ) );
		$folders    = self::get_scannable_folders();

		if ( ! isset( $folders[ $folder_key ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown folder key.', 'optiforge-image-optimizer' ) ) );
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
			if ( ( microtime( true ) - $start_time ) >= $time_budget ) {
				break;
			}

			$result = $this->optimize_external_file( $image_path );

			if ( ! empty( $result['success'] ) ) {
				$processed++;
				$saved_bytes += (int) ( $result['saved_bytes'] ?? 0 );
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
					/* translators: 1: processed, 2: bytes saved */
					__( 'Processed %1$d images. Saved %2$s.', 'optiforge-image-optimizer' ),
					$processed,
					size_format( $saved_bytes )
				),
			)
		);
	}
}

// Backwards-compatible class aliases.
if ( ! class_exists( 'OFIO_Custom_Folders', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\CustomFolders', 'OFIO_Custom_Folders' );
}
if ( ! class_exists( '\OptiForge\ImageOptimizer\CustomFolders', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\CustomFolders', '\OptiForge\ImageOptimizer\CustomFolders' );
}
if ( ! class_exists( '\OptiForge_Image_Optimizer\CustomFolders', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\CustomFolders', '\OptiForge_Image_Optimizer\CustomFolders' );
}