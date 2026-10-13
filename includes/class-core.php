<?php
/**
 * Core Orchestrator — Singleton Bootstrap & Dependency Injector.
 *
 * Instantiates all plugin sub-systems, wires WordPress hooks, and coordinates
 * background queue processing, upload hooks, and media modal integration.
 *
 * @package OptiForge_Image_Optimizer
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace Optiforge_Image_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Core
 */
class Core {

	/**
	 * Singleton instance.
	 *
	 * @var Core|null
	 */
	private static ?Core $instance = null;

	/** @var Optimizer */
	public Optimizer $optimizer;

	/** @var Backup */
	public Backup $backup;

	/** @var Stats */
	public Stats $stats;

	/** @var Queue */
	public Queue $queue;

	/** @var Bulk */
	public Bulk $bulk;

	/** @var Admin */
	public Admin $admin;

	/** @var Cron */
	public Cron $cron;

	/** @var Delivery */
	public Delivery $delivery;

	/** @var CustomFolders */
	public CustomFolders $custom_folders;

	/**
	 * Return (or create) the singleton instance.
	 *
	 * @return Core
	 */
	public static function get_instance(): Core {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor. Instantiate sub-systems and register hooks.
	 */
	private function __construct() {
		// 1. Shared core services.
		$this->backup    = new Backup();
		$this->stats     = new Stats();
		$this->optimizer = new Optimizer();

		$this->optimizer->set_backup( $this->backup );

		// 2. Queue service.
		$this->queue = new Queue( $this->optimizer, $this->backup, $this->stats );

		// 3. Orchestration & UI services.
		$this->bulk           = new Bulk( $this->optimizer, $this->backup, $this->stats, $this->queue );
		$this->cron           = new Cron( $this->optimizer, $this->stats );
		$this->delivery       = new Delivery();
		$this->custom_folders = new CustomFolders( $this->optimizer );
		$this->admin          = new Admin( $this->stats, $this->optimizer, $this->cron, $this->backup, $this->queue );

		// 4. WordPress Action & Filter Hooks.
		add_action( 'delete_attachment', array( $this->backup, 'on_attachment_deleted' ) );
		add_filter( 'wp_generate_attachment_metadata', array( $this, 'handle_upload' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_media_modal_fields' ), 10, 2 );
		add_action( 'wp_ajax_ofio_modal_optimize', array( $this, 'ajax_modal_optimize' ) );
	}

	/**
	 * Auto-optimize freshly uploaded images on wp_generate_attachment_metadata.
	 *
	 * @param mixed $metadata      Metadata array.
	 * @param int   $attachment_id Attachment post ID.
	 * @return mixed
	 */
	public function handle_upload( $metadata, int $attachment_id ) {
		// Skip during bulk restore operations.
		if ( ! empty( Backup::$is_restoring ) ) {
			return $metadata;
		}

		if ( ! is_array( $metadata ) ) {
			return $metadata;
		}

		// Skip if auto-optimize is disabled.
		if ( ! get_option( 'ofio_auto_optimize', 1 ) ) {
			return $metadata;
		}

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
			return $metadata;
		}

		// Cache post fields and alt text before changes.
		$cached_alt         = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$attachment_post    = get_post( $attachment_id );
		$cached_title       = $attachment_post ? $attachment_post->post_title : '';
		$cached_caption     = $attachment_post ? $attachment_post->post_excerpt : '';
		$cached_description = $attachment_post ? $attachment_post->post_content : '';

		// Run optimization.
		$result = $this->optimizer->optimize_attachment( $attachment_id, $metadata );
		$this->stats->record( $attachment_id, $result );

		// Forcefully restore post fields.
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

		$fresh_metadata = wp_get_attachment_metadata( $attachment_id );

		return is_array( $fresh_metadata ) ? $fresh_metadata : $metadata;
	}

	/**
	 * Inject OptiForge optimization box into Media Modal attachment details.
	 *
	 * @param array $form_fields Form fields.
	 * @param mixed $post        Post object.
	 * @return array Modified form fields.
	 */
	public function add_media_modal_fields( array $form_fields, $post ): array {
		if ( ! is_object( $post ) || ! isset( $post->ID ) ) {
			return $form_fields;
		}

		$attachment_id = (int) $post->ID;

		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
			return $form_fields;
		}

		$meta    = get_post_meta( $attachment_id, '_ofio_meta', true );
		$nonce   = wp_create_nonce( 'ofio_nonce' );
		$has_bkp = $this->backup->has_backup( $attachment_id );

		$status_html = '';

		if ( $meta && is_array( $meta ) && ( $meta['status'] ?? '' ) === 'optimized' ) {
			$saved_kb  = round( (int) ( $meta['saved_bytes'] ?? 0 ) / 1024, 1 );
			$saved_pct = ( ! empty( $meta['original_size'] ) && (int) $meta['original_size'] > 0 )
				? round( ( (int) $meta['saved_bytes'] / (int) $meta['original_size'] ) * 100, 1 )
				: 0;

			$status_html .= '<span class="ofio-badge ofio-badge--success">' . esc_html__( 'Optimized', 'optiforge-image-optimizer' ) . '</span> ';
			$status_html .= '<small><strong>' . esc_html( (string) $saved_kb ) . ' KB</strong> saved (' . esc_html( (string) $saved_pct ) . '%)</small><br>';

			if ( ! empty( $meta['avif_created'] ) ) {
				$status_html .= '<small class="ofio-webp-label">AVIF ✓ </small>';
			}
			if ( ! empty( $meta['webp_created'] ) ) {
				$status_html .= '<small class="ofio-webp-label">WebP ✓</small>';
			}
			$status_html .= '<br>';
		} else {
			$status_html .= '<span class="ofio-badge ofio-badge--pending">' . esc_html__( 'Not Optimized', 'optiforge-image-optimizer' ) . '</span><br>';
		}

		$btn_label = ( $meta && is_array( $meta ) && ( $meta['status'] ?? '' ) === 'optimized' )
			? esc_html__( 'Re-Optimize', 'optiforge-image-optimizer' )
			: esc_html__( 'Optimize Now', 'optiforge-image-optimizer' );

		$status_html .= sprintf(
			'<button type="button" class="button button-small ofio-modal-optimize" data-id="%d" data-nonce="%s" style="margin-top:4px;">%s</button>',
			$attachment_id,
			esc_attr( $nonce ),
			$btn_label
		);

		if ( $has_bkp ) {
			$status_html .= sprintf(
				' <button type="button" class="button button-small ofio-restore" data-id="%d" data-nonce="%s" style="margin-top:4px;">%s</button>',
				$attachment_id,
				esc_attr( $nonce ),
				esc_html__( 'Restore Original', 'optiforge-image-optimizer' )
			);
		}

		$status_html .= '<span id="ofio-modal-status-' . (int) $attachment_id . '" style="display:inline-block;margin-left:8px;font-style:italic;color:#2271b1;"></span>';

		$form_fields['ofio_optimizer'] = array(
			'label' => esc_html__( 'OptiForge', 'optiforge-image-optimizer' ),
			'input' => 'html',
			'html'  => $status_html,
			'helps' => '',
		);

		return $form_fields;
	}

	/**
	 * AJAX: Manual single image optimization from media modal.
	 *
	 * @return void
	 */
	public function ajax_modal_optimize(): void {
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

		$cached_alt         = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		$attachment_post    = get_post( $attachment_id );
		$cached_title       = $attachment_post ? $attachment_post->post_title : '';
		$cached_caption     = $attachment_post ? $attachment_post->post_excerpt : '';
		$cached_description = $attachment_post ? $attachment_post->post_content : '';

		// Capture true original baseline from backup file if exists.
		$backup_path   = get_post_meta( $attachment_id, '_ofio_backup_path', true );
		$true_original = 0;
		if ( $backup_path && file_exists( (string) $backup_path ) ) {
			$true_original = (int) filesize( (string) $backup_path );
		}

		$result = $this->optimizer->optimize_attachment( $attachment_id );
		$this->stats->record( $attachment_id, $result );

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
			$display_saved = (int) $result['saved_bytes'];
			if ( $true_original > 0 ) {
				$current_file = get_attached_file( $attachment_id );
				if ( $current_file && file_exists( $current_file ) ) {
					$display_saved = max( 0, $true_original - (int) filesize( $current_file ) );
				}
			}

			wp_send_json_success(
				array(
					'message'     => sprintf(
						/* translators: %s: formatted file size saved */
						__( 'Optimized! Saved %s.', 'optiforge-image-optimizer' ),
						size_format( $display_saved )
					),
					'saved_bytes' => $display_saved,
					'stats'       => $this->stats->get(),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => esc_html( $result['error'] ) ) );
		}
	}
}

// Backwards-compatible class aliases.
if ( ! class_exists( 'OFIO_Core', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Core', 'OFIO_Core' );
}
if ( ! class_exists( '\OptiForge\ImageOptimizer\Core', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Core', '\OptiForge\ImageOptimizer\Core' );
}
if ( ! class_exists( '\OptiForge_Image_Optimizer\Core', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Core', '\OptiForge_Image_Optimizer\Core' );
}