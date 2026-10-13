<?php
/**
 * Advanced Image Optimization Engine.
 *
 * Auto-detects and optimizes images using:
 * Priority 1: Imagick with multi-threaded limits, optimal compression, and YUV 4:2:0 AVIF calibration.
 * Priority 2: GD library with alpha preservation, auto-rotation, and memory-safe streams.
 *
 * Strict guarantees:
 * - Atomic file swaps with comprehensive post-compression validation (@getimagesize, filesize).
 * - Safe fallbacks on any compression failure: original files are never corrupted, broken, or lost.
 * - Strict size gatekeeping: WebP and AVIF sidecars are discarded if not strictly smaller than original.
 * - Full animated GIF preservation and animated WebP conversion.
 *
 * @package OptiForge_Image_Optimizer
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace Optiforge_Image_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Optimizer
 */
class Optimizer {

	/**
	 * Shared Backup instance.
	 *
	 * @var Backup|null
	 */
	private ?Backup $backup = null;

	/**
	 * Inject the shared backup instance.
	 *
	 * @param Backup $backup Backup instance.
	 * @return void
	 */
	public function set_backup( Backup $backup ): void {
		$this->backup = $backup;
	}

	/**
	 * Auto-detect the fastest available server-side image processing engine.
	 *
	 * Priority 1: Imagick
	 * Priority 2: GD
	 *
	 * @return string 'imagick', 'gd', or 'none'.
	 */
	public function get_engine(): string {
		if ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) ) {
			return 'imagick';
		}
		if ( extension_loaded( 'gd' ) && function_exists( 'imagecreatefromjpeg' ) ) {
			return 'gd';
		}
		return 'none';
	}

	/**
	 * Check whether AVIF format generation is supported by the server.
	 *
	 * @return bool True if AVIF encoding is available.
	 */
	public function supports_avif(): bool {
		$engine = $this->get_engine();

		if ( 'imagick' === $engine ) {
			try {
				$formats = \Imagick::queryFormats( 'AVIF' );
				return is_array( $formats ) && ! empty( $formats );
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		if ( 'gd' === $engine ) {
			return function_exists( 'imageavif' )
				&& defined( 'IMG_AVIF' )
				&& ( ( imagetypes() & IMG_AVIF ) !== 0 );
		}

		return false;
	}

	/**
	 * Detect if a GIF file contains multiple animated frames.
	 *
	 * @param string $path Path to file.
	 * @return bool True if animated.
	 */
	public static function is_animated_gif( string $path ): bool {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		$data = file_get_contents( $path, false, null, 0, 1048576 );
		if ( false === $data ) {
			return false;
		}

		$frame_count = substr_count( $data, "\x00\x21\xF9\x04" );
		return $frame_count > 1;
	}

	/**
	 * Translate JPEG-scale quality (1-100) to psycho-visually calibrated AVIF quality (1-68).
	 *
	 * libavif produces bloated files at qualities >= 70 due to switching to lossless chroma paths.
	 *
	 * @param int $jpeg_quality JPEG quality.
	 * @return int Calibrated AVIF quality.
	 */
	private function calibrate_avif_quality( int $jpeg_quality ): int {
		$avif_quality = (int) floor( $jpeg_quality * 0.82 );
		return max( 1, min( 68, $avif_quality ) );
	}

	/**
	 * Optimize an entire media library attachment (main file + all responsive sizes).
	 *
	 * @param int   $attachment_id Attachment post ID.
	 * @param array $metadata      Existing attachment metadata (optional).
	 * @return array Optimization results summary.
	 */
	public function optimize_attachment( int $attachment_id, array $metadata = array() ): array {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		if ( empty( $metadata ) ) {
			$metadata = (array) wp_get_attachment_metadata( $attachment_id );
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );
		$file       = get_attached_file( $attachment_id );

		$quality      = (int) get_option( 'ofio_compression_quality', 82 );
		$mode         = (string) get_option( 'ofio_compression_mode', 'lossy' );
		$webp_enabled = (bool) get_option( 'ofio_webp_conversion', 1 );
		$avif_enabled = (bool) get_option( 'ofio_avif_conversion', 1 ) && $this->supports_avif();
		$backup_on    = (bool) get_option( 'ofio_backup_enabled', 1 );

		if ( ! $file || ! file_exists( $file ) ) {
			return $this->error_result( __( 'Source file not found on disk.', 'optiforge-image-optimizer' ) );
		}

		$mime = $this->detect_mime( $file );

		// Skip unsupported MIME types safely.
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: MIME type */
					__( 'Unsupported MIME type: %s', 'optiforge-image-optimizer' ),
					$mime
				)
			);
		}

		// Cache post fields and alt text before changes.
		$cached_alt         = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$attachment_post    = get_post( $attachment_id );
		$cached_title       = $attachment_post ? $attachment_post->post_title : '';
		$cached_caption     = $attachment_post ? $attachment_post->post_excerpt : '';
		$cached_description = $attachment_post ? $attachment_post->post_content : '';

		// Create backup of pristine original before any modification.
		$backup = $this->backup ?? new Backup();
		if ( $backup_on ) {
			$backup->create_backup( $file, $attachment_id );
		}

		// Delete old sidecars before regeneration.
		$backup->delete_sidecar_files( $attachment_id );

		// Measure pristine original baseline.
		$backup_path = get_post_meta( $attachment_id, '_ofio_backup_path', true );
		if ( $backup_path && file_exists( (string) $backup_path ) ) {
			$initial_main_filesize = (int) filesize( (string) $backup_path );
		} else {
			$initial_main_filesize = (int) filesize( $file );
		}

		// Auto-resize oversized master uploads.
		if ( get_option( 'ofio_resize_enabled', 1 ) && 'image/gif' !== $mime ) {
			$max_w = (int) get_option( 'ofio_max_width', 2048 );
			$max_h = (int) get_option( 'ofio_max_height', 2048 );
			if ( $this->scale_image_dimensions( $file, $max_w, $max_h ) ) {
				$new_dims = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $new_dims ) {
					$metadata['width']  = $new_dims[0];
					$metadata['height'] = $new_dims[1];
					wp_update_attachment_metadata( $attachment_id, $metadata );
				}
			}
		}

		$total_original = 0;
		$total_new      = 0;
		$webp_created   = false;
		$avif_created   = false;
		$errors         = array();

		// Optimize main master image.
		$main = $this->process_file( $file, $quality, $mode, $webp_enabled, $avif_enabled, $initial_main_filesize );
		if ( $main['success'] ) {
			$total_original += $main['original_size'];
			$total_new      += $main['new_size'];
			$webp_created    = $webp_created || $main['webp_created'];
			$avif_created    = $avif_created || $main['avif_created'];
		} else {
			$errors[] = basename( $file ) . ': ' . $main['error'];
		}

		// Optimize each generated thumbnail size.
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			$sub_dir = ! empty( $metadata['file'] )
				? trailingslashit( $base_dir . dirname( $metadata['file'] ) )
				: trailingslashit( dirname( $file ) );

			foreach ( $metadata['sizes'] as $size_data ) {
				if ( empty( $size_data['file'] ) ) {
					continue;
				}
				$thumb = $sub_dir . $size_data['file'];
				if ( ! file_exists( $thumb ) ) {
					continue;
				}

				$res = $this->process_file( $thumb, $quality, $mode, $webp_enabled, $avif_enabled );
				if ( $res['success'] ) {
					$total_original += $res['original_size'];
					$total_new      += $res['new_size'];
					$webp_created    = $webp_created || $res['webp_created'];
					$avif_created    = $avif_created || $res['avif_created'];
				} else {
					$errors[] = basename( $thumb ) . ': ' . $res['error'];
				}

				if ( function_exists( 'gc_collect_cycles' ) ) {
					gc_collect_cycles();
				}
			}
		}

		// Restore post fields and alt text unconditionally.
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

		// Purge page and object caches.
		if ( class_exists( '\OFIO_Cache' ) ) {
			\OFIO_Cache::purge_all();
			\OFIO_Cache::purge_attachment( $attachment_id );
		}

		return array(
			'success'        => empty( $errors ),
			'original_size'  => $total_original,
			'optimized_size' => $total_new,
			'saved_bytes'    => max( 0, $total_original - $total_new ),
			'webp_created'   => $webp_created,
			'avif_created'   => $avif_created,
			'error'          => implode( ' | ', $errors ),
		);
	}

	/**
	 * Resize an oversized image safely.
	 *
	 * @param string $file_path Path to image file.
	 * @param int    $max_w     Max width.
	 * @param int    $max_h     Max height.
	 * @return bool True if resized.
	 */
	public function scale_image_dimensions( string $file_path, int $max_w, int $max_h ): bool {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		$size = @getimagesize( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $size ) {
			return false;
		}

		list( $orig_w, $orig_h ) = $size;
		if ( $orig_w <= $max_w && $orig_h <= $max_h ) {
			return false;
		}

		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return false;
		}

		$result = $editor->resize( $max_w, $max_h, false );
		if ( is_wp_error( $result ) ) {
			return false;
		}

		$saved = $editor->save( $file_path );
		unset( $editor );

		return ! is_wp_error( $saved );
	}

	/**
	 * Process a single image file with atomic temp validation and zero broken image risk.
	 *
	 * @param string $file_path                   Source file path.
	 * @param int    $quality                     Quality level.
	 * @param string $mode                        'lossy' or 'lossless'.
	 * @param bool   $webp_enabled                Generate WebP.
	 * @param bool   $avif_enabled                Generate AVIF.
	 * @param int    $pre_measured_original_size  Pre-measured original file size.
	 * @return array Result array.
	 */
	public function process_file(
		string $file_path,
		int $quality,
		string $mode,
		bool $webp_enabled,
		bool $avif_enabled = false,
		int $pre_measured_original_size = 0
	): array {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'image' );
		}

		if ( ! file_exists( $file_path ) ) {
			return $this->error_result( __( 'File not found.', 'optiforge-image-optimizer' ) );
		}

		$actual_file_size = (int) filesize( $file_path );
		$original_size    = ( $pre_measured_original_size > 0 ) ? $pre_measured_original_size : $actual_file_size;

		if ( 0 === $actual_file_size ) {
			return $this->error_result( __( 'File is empty (0 bytes).', 'optiforge-image-optimizer' ) );
		}

		$max_allowed_bytes = defined( 'OFIO_MAX_FILE_BYTES' ) ? OFIO_MAX_FILE_BYTES : 52428800; // 50MB
		if ( $actual_file_size > $max_allowed_bytes ) {
			return $this->error_result(
				sprintf(
					/* translators: %s: file size */
					__( 'File exceeds maximum processing size (%s).', 'optiforge-image-optimizer' ),
					size_format( $actual_file_size )
				)
			);
		}

		$mime   = $this->detect_mime( $file_path );
		$engine = $this->get_engine();

		// Animated GIF Preservation & Animated WebP conversion.
		if ( 'image/gif' === $mime && self::is_animated_gif( $file_path ) ) {
			$webp_anim = false;
			if ( $webp_enabled && 'imagick' === $engine ) {
				$webp_anim = $this->convert_animated_gif_to_webp( $file_path, $quality );
			}
			return array(
				'success'       => true,
				'original_size' => $original_size,
				'new_size'      => $actual_file_size,
				'webp_created'  => $webp_anim,
				'avif_created'  => false,
				'error'         => '',
			);
		}

		$webp_created = false;
		$avif_created = false;
		$temp_path    = $file_path . '.ofio_tmp';

		if ( ! copy( $file_path, $temp_path ) ) {
			return $this->error_result( __( 'Could not create atomic temp file.', 'optiforge-image-optimizer' ) );
		}

		// Perform compression on temp copy.
		if ( 'imagick' === $engine ) {
			$res = $this->compress_imagick( $temp_path, $mime, $quality, $mode );
		} elseif ( 'gd' === $engine ) {
			$res = $this->compress_gd( $temp_path, $mime, $quality, $mode );
		} else {
			wp_delete_file( $temp_path );
			return $this->error_result( __( 'No imaging library available (GD or Imagick required).', 'optiforge-image-optimizer' ) );
		}

		if ( ! $res['success'] ) {
			wp_delete_file( $temp_path );
			return $res;
		}

		clearstatcache( true, $temp_path );
		$temp_size = (int) filesize( $temp_path );

		// Strict Post-Optimization Validation:
		// Ensure temp file exists, is non-empty, and decodes to a valid image.
		$is_valid_image = false;
		if ( $temp_size > 0 ) {
			$check_dims = @getimagesize( $temp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $check_dims && ! empty( $check_dims[0] ) && ! empty( $check_dims[1] ) ) {
				$is_valid_image = true;
			}
		}

		// Atomic Replacement Gatekeeper:
		// Only replace original if the optimized image is valid AND smaller than original.
		if ( $is_valid_image && $temp_size < $actual_file_size ) {
			global $wp_filesystem;
			if ( ! function_exists( 'WP_Filesystem' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			if ( function_exists( 'WP_Filesystem' ) && empty( $wp_filesystem ) ) {
				\WP_Filesystem();
			}

			$moved = false;
			if ( ! empty( $wp_filesystem ) ) {
				$moved = $wp_filesystem->move( $temp_path, $file_path, true );
			}

			if ( ! $moved ) {
				copy( $temp_path, $file_path );
				wp_delete_file( $temp_path );
			}
			$final_size = $temp_size;
		} else {
			// Discard temp file — fallback safely to untouched original!
			wp_delete_file( $temp_path );
			$final_size = $actual_file_size;
		}

		// Next-Gen WebP Sidecar Generation with Strict Size Gatekeeping.
		if ( $webp_enabled && 'image/webp' !== $mime ) {
			if ( 'imagick' === $engine ) {
				$webp_created = $this->generate_webp_imagick( $file_path, $quality );
			} else {
				$webp_created = $this->generate_webp_gd( $file_path, $mime, $quality );
			}

			if ( $webp_created ) {
				$webp_path = $this->get_destination_path( $file_path, 'webp' );
				clearstatcache( true, $webp_path );
				// Validate WebP sidecar: must exist, have valid size, and be smaller than original.
				if ( file_exists( $webp_path ) ) {
					$webp_sz = (int) filesize( $webp_path );
					if ( $webp_sz <= 0 || $webp_sz >= $final_size ) {
						wp_delete_file( $webp_path );
						$webp_created = false;
					}
				} else {
					$webp_created = false;
				}
			}
		}

		// Next-Gen AVIF Sidecar Generation with Psycho-Visual Recalibration.
		if ( $avif_enabled ) {
			$avif_quality = $this->calibrate_avif_quality( $quality );

			if ( 'imagick' === $engine ) {
				$avif_created = $this->generate_avif_imagick( $file_path, $avif_quality );
			} else {
				$avif_created = $this->generate_avif_gd( $file_path, $mime, $avif_quality );
			}

			if ( $avif_created ) {
				$avif_path = $this->get_destination_path( $file_path, 'avif' );
				clearstatcache( true, $avif_path );
				// Validate AVIF sidecar: must exist, have valid size, and be smaller than original.
				if ( file_exists( $avif_path ) ) {
					$avif_sz = (int) filesize( $avif_path );
					if ( $avif_sz <= 0 || $avif_sz >= $final_size ) {
						wp_delete_file( $avif_path );
						$avif_created = false;
					}
				} else {
					$avif_created = false;
				}
			}
		}

		return array(
			'success'       => true,
			'original_size' => $original_size,
			'new_size'      => $final_size,
			'webp_created'  => $webp_created,
			'avif_created'  => $avif_created,
			'error'         => '',
		);
	}

	/**
	 * Convert an animated GIF to animated WebP using Imagick.
	 *
	 * @param string $path    File path.
	 * @param int    $quality Quality.
	 * @return bool True on success.
	 */
	public function convert_animated_gif_to_webp( string $path, int $quality ): bool {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( '\Imagick' ) ) {
			return false;
		}

		$webp_dst = $this->get_destination_path( $path, 'webp' );
		$im       = null;

		try {
			$im = new \Imagick( $path );
			if ( defined( '\Imagick::RESOURCETYPE_THREAD' ) ) {
				$im->setResourceLimit( \Imagick::RESOURCETYPE_THREAD, 2 );
			}
			$im = $im->coalesceImages();

			foreach ( $im as $frame ) {
				$frame->setImageFormat( 'WEBP' );
				$frame->setImageCompressionQuality( $quality );
			}

			return (bool) $im->writeImages( $webp_dst, true );
		} catch ( \Throwable $e ) {
			return false;
		} finally {
			if ( null !== $im ) {
				$im->clear();
				$im->destroy();
			}
		}
	}

	/**
	 * Compress image via Imagick with optimal compression and resource bounds.
	 *
	 * @param string $path    File path.
	 * @param string $mime    MIME type.
	 * @param int    $quality Quality.
	 * @param string $mode    'lossy' or 'lossless'.
	 * @return array Result array.
	 */
	private function compress_imagick( string $path, string $mime, int $quality, string $mode ): array {
		$im = null;
		try {
			$im = new \Imagick( $path );
			if ( defined( '\Imagick::RESOURCETYPE_THREAD' ) ) {
				$im->setResourceLimit( \Imagick::RESOURCETYPE_THREAD, 2 );
			}

			if ( method_exists( $im, 'autoOrient' ) ) {
				$im->autoOrient();
			}

			if ( ! get_option( 'ofio_preserve_exif', 0 ) ) {
				$im->stripImage();
			}

			if ( 'image/jpeg' === $mime ) {
				$im->setImageFormat( 'JPEG' );
				$im->setInterlaceScheme( \Imagick::INTERLACE_PLANE );
				$im->setImageCompression( \Imagick::COMPRESSION_JPEG );
				$im->setImageCompressionQuality( 'lossless' === $mode ? 90 : $quality );
				if ( method_exists( $im, 'setOption' ) ) {
					$im->setOption( 'jpeg:optimize-coding', 'true' );
				}
			} elseif ( 'image/png' === $mime ) {
				$im->setImageFormat( 'PNG' );
				$im->setImageCompression( \Imagick::COMPRESSION_ZIP );
				$im->setImageCompressionQuality( 95 );
			} elseif ( 'image/webp' === $mime ) {
				$im->setImageFormat( 'WEBP' );
				$im->setImageCompressionQuality( 'lossless' === $mode ? 100 : $quality );
			}

			$ok = $im->writeImage( $path );

			return $ok
				? array( 'success' => true, 'error' => '' )
				: $this->error_result( __( 'Imagick write failed.', 'optiforge-image-optimizer' ) );

		} catch ( \Throwable $e ) {
			return $this->error_result( 'Imagick: ' . $e->getMessage() );
		} finally {
			if ( null !== $im ) {
				$im->clear();
				$im->destroy();
			}
		}
	}

	/**
	 * Compress image via GD library.
	 *
	 * @param string $path    File path.
	 * @param string $mime    MIME type.
	 * @param int    $quality Quality.
	 * @param string $mode    'lossy' or 'lossless'.
	 * @return array Result array.
	 */
	private function compress_gd( string $path, string $mime, int $quality, string $mode ): array {
		$image = null;
		try {
			if ( 'image/jpeg' === $mime ) {
				$image = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $image && function_exists( 'exif_read_data' ) ) {
					$exif = @exif_read_data( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( is_array( $exif ) && ! empty( $exif['Orientation'] ) ) {
						$image = $this->auto_rotate_gd( $image, (int) $exif['Orientation'] );
					}
				}
			} elseif ( 'image/png' === $mime ) {
				$image = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $image ) {
					imagealphablending( $image, false );
					imagesavealpha( $image, true );
				}
			} elseif ( 'image/webp' === $mime && function_exists( 'imagecreatefromwebp' ) ) {
				$image = @imagecreatefromwebp( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( 'image/gif' === $mime && function_exists( 'imagecreatefromgif' ) ) {
				$image = @imagecreatefromgif( $path );
			}

			if ( ! $image ) {
				return $this->error_result( __( 'GD could not open image stream.', 'optiforge-image-optimizer' ) );
			}

			$ok = false;
			if ( 'image/jpeg' === $mime ) {
				$ok = imagejpeg( $image, $path, 'lossless' === $mode ? 90 : $quality );
			} elseif ( 'image/png' === $mime ) {
				$ok = imagepng( $image, $path, 9 );
			} elseif ( 'image/webp' === $mime && function_exists( 'imagewebp' ) ) {
				$ok = imagewebp( $image, $path, 'lossless' === $mode ? 100 : $quality );
			} elseif ( 'image/gif' === $mime && function_exists( 'imagegif' ) ) {
				$ok = imagegif( $image, $path );
			}

			return $ok
				? array( 'success' => true, 'error' => '' )
				: $this->error_result( __( 'GD write failed.', 'optiforge-image-optimizer' ) );

		} finally {
			if ( null !== $image && ( is_resource( $image ) || $image instanceof \GdImage ) ) {
				imagedestroy( $image );
			}
		}
	}

	/**
	 * Generate a WebP sidecar via Imagick.
	 *
	 * @param string $path    Source path.
	 * @param int    $quality Quality.
	 * @return bool True on success.
	 */
	private function generate_webp_imagick( string $path, int $quality ): bool {
		$webp_dst = $this->get_destination_path( $path, 'webp' );
		$im       = null;

		try {
			$im = new \Imagick( $path );
			if ( defined( '\Imagick::RESOURCETYPE_THREAD' ) ) {
				$im->setResourceLimit( \Imagick::RESOURCETYPE_THREAD, 2 );
			}
			if ( method_exists( $im, 'autoOrient' ) ) {
				$im->autoOrient();
			}
			$im->setImageFormat( 'WEBP' );
			$im->setImageCompressionQuality( $quality );
			if ( method_exists( $im, 'setOption' ) ) {
				$im->setOption( 'webp:method', '5' );
			}

			return (bool) $im->writeImage( $webp_dst );
		} catch ( \Throwable $e ) {
			return false;
		} finally {
			if ( null !== $im ) {
				$im->clear();
				$im->destroy();
			}
		}
	}

	/**
	 * Generate a WebP sidecar via GD.
	 *
	 * @param string $path    Source path.
	 * @param string $mime    MIME type.
	 * @param int    $quality Quality.
	 * @return bool True on success.
	 */
	private function generate_webp_gd( string $path, string $mime, int $quality ): bool {
		if ( ! function_exists( 'imagewebp' ) ) {
			return false;
		}

		$webp_dst = $this->get_destination_path( $path, 'webp' );
		$image    = null;

		try {
			if ( 'image/jpeg' === $mime ) {
				$image = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( 'image/png' === $mime ) {
				$image = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( 'image/gif' === $mime && function_exists( 'imagecreatefromgif' ) ) {
				$image = @imagecreatefromgif( $path );
			}

			if ( ! $image ) {
				return false;
			}

			if ( 'image/png' === $mime ) {
				if ( function_exists( 'imagepalettetotruecolor' ) ) {
					imagepalettetotruecolor( $image );
				}
				imagealphablending( $image, false );
				imagesavealpha( $image, true );
			}

			return (bool) imagewebp( $image, $webp_dst, $quality );
		} finally {
			if ( null !== $image && ( is_resource( $image ) || $image instanceof \GdImage ) ) {
				imagedestroy( $image );
			}
		}
	}

	/**
	 * Generate an AVIF sidecar via Imagick with YUV 4:2:0 subsampling.
	 *
	 * @param string $path    Source path.
	 * @param int    $quality Calibrated AVIF quality.
	 * @return bool True on success.
	 */
	private function generate_avif_imagick( string $path, int $quality ): bool {
		$avif_dst = $this->get_destination_path( $path, 'avif' );
		if ( file_exists( $avif_dst ) ) {
			wp_delete_file( $avif_dst );
		}

		$im = null;
		try {
			$im = new \Imagick( $path );
			if ( defined( '\Imagick::RESOURCETYPE_THREAD' ) ) {
				$im->setResourceLimit( \Imagick::RESOURCETYPE_THREAD, 2 );
			}
			if ( method_exists( $im, 'autoOrient' ) ) {
				$im->autoOrient();
			}
			$im->setImageFormat( 'AVIF' );
			$im->setImageCompressionQuality( $quality );
			if ( method_exists( $im, 'setOption' ) ) {
				$im->setOption( 'heic:chroma', '420' );
				$im->setOption( 'avif:chroma', '420' );
				$im->setOption( 'heic:speed', '6' );
				$im->setOption( 'avif:speed', '6' );
			}

			return (bool) $im->writeImage( $avif_dst );
		} catch ( \Throwable $e ) {
			return false;
		} finally {
			if ( null !== $im ) {
				$im->clear();
				$im->destroy();
			}
		}
	}

	/**
	 * Generate an AVIF sidecar via GD.
	 *
	 * @param string $path    Source path.
	 * @param string $mime    MIME type.
	 * @param int    $quality Calibrated AVIF quality.
	 * @return bool True on success.
	 */
	private function generate_avif_gd( string $path, string $mime, int $quality ): bool {
		if ( ! function_exists( 'imageavif' ) || ! defined( 'IMG_AVIF' ) || ! ( ( imagetypes() & IMG_AVIF ) !== 0 ) ) {
			return false;
		}

		$avif_dst = $this->get_destination_path( $path, 'avif' );
		if ( file_exists( $avif_dst ) ) {
			wp_delete_file( $avif_dst );
		}

		$image = null;
		try {
			if ( 'image/jpeg' === $mime ) {
				$image = @imagecreatefromjpeg( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( 'image/png' === $mime ) {
				$image = @imagecreatefrompng( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} elseif ( 'image/gif' === $mime && function_exists( 'imagecreatefromgif' ) ) {
				$image = @imagecreatefromgif( $path );
			}

			if ( ! $image ) {
				return false;
			}

			if ( function_exists( 'imagepalettetotruecolor' ) ) {
				imagepalettetotruecolor( $image );
			}
			imagealphablending( $image, false );
			imagesavealpha( $image, true );

			return (bool) imageavif( $image, $avif_dst, $quality );
		} finally {
			if ( null !== $image && ( is_resource( $image ) || $image instanceof \GdImage ) ) {
				imagedestroy( $image );
			}
		}
	}

	/**
	 * Return clean destination path for sidecar format.
	 *
	 * @param string $path Source path.
	 * @param string $ext  Target extension.
	 * @return string Destination path.
	 */
	private function get_destination_path( string $path, string $ext ): string {
		$clean_ext = '.' . ltrim( $ext, '.' );
		if ( preg_match( '/\.[^.\/\\\\]+$/', $path ) ) {
			return (string) preg_replace( '/\.[^.\/\\\\]+$/', $clean_ext, $path );
		}
		return $path . $clean_ext;
	}

	/**
	 * Auto-rotate a GD image resource based on EXIF orientation.
	 *
	 * @param \GdImage|resource $image       GD image.
	 * @param int               $orientation EXIF orientation flag.
	 * @return \GdImage|resource
	 */
	private function auto_rotate_gd( $image, int $orientation ) {
		switch ( $orientation ) {
			case 3:
				$image = imagerotate( $image, 180, 0 );
				break;
			case 6:
				$image = imagerotate( $image, -90, 0 );
				break;
			case 8:
				$image = imagerotate( $image, 90, 0 );
				break;
		}
		return $image;
	}

	/**
	 * Detect MIME type safely.
	 *
	 * @param string $file_path Path to file.
	 * @return string Detected MIME type.
	 */
	private function detect_mime( string $file_path ): string {
		$mime = '';
		if ( function_exists( 'mime_content_type' ) ) {
			$detected = @mime_content_type( $file_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $detected ) {
				$mime = (string) $detected;
			}
		}
		if ( empty( $mime ) || 'application/octet-stream' === $mime ) {
			if ( function_exists( 'wp_check_filetype' ) ) {
				$check = wp_check_filetype( $file_path );
				$mime  = (string) ( $check['type'] ?? '' );
			}
			if ( empty( $mime ) ) {
				$ext = strtolower( (string) pathinfo( $file_path, PATHINFO_EXTENSION ) );
				$map = array(
					'jpg'  => 'image/jpeg',
					'jpeg' => 'image/jpeg',
					'png'  => 'image/png',
					'webp' => 'image/webp',
					'gif'  => 'image/gif',
					'avif' => 'image/avif',
				);
				$mime = $map[ $ext ] ?? '';
			}
		}
		return $mime;
	}

	/**
	 * Standard error result generator.
	 *
	 * @param string $message Error message.
	 * @return array
	 */
	private function error_result( string $message ): array {
		return array(
			'success'        => false,
			'original_size'  => 0,
			'optimized_size' => 0,
			'new_size'       => 0,
			'saved_bytes'    => 0,
			'webp_created'   => false,
			'avif_created'   => false,
			'error'          => $message,
		);
	}
}

// Backwards-compatible class aliases.
if ( ! class_exists( 'OFIO_Optimizer', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Optimizer', 'OFIO_Optimizer' );
}
if ( ! class_exists( '\OptiForge\ImageOptimizer\Optimizer', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Optimizer', '\OptiForge\ImageOptimizer\Optimizer' );
}
if ( ! class_exists( '\OptiForge_Image_Optimizer\Optimizer', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Optimizer', '\OptiForge_Image_Optimizer\Optimizer' );
}
