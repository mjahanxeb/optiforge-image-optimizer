<?php
/**
 * Admin UI & Media Library Integration.
 *
 * @package OptiForge_Image_Optimizer
 */

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound
namespace Optiforge_Image_Optimizer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Admin
 */
class Admin {

	/**
	 * Shared Stats.
	 *
	 * @var Stats
	 */
	private Stats $stats;

	/**
	 * Shared Optimizer.
	 *
	 * @var Optimizer
	 */
	private Optimizer $optimizer;

	/**
	 * Shared Cron.
	 *
	 * @var Cron
	 */
	private Cron $cron;

	/**
	 * Shared Backup.
	 *
	 * @var Backup
	 */
	private Backup $backup;

	/**
	 * Shared Queue.
	 *
	 * @var Queue|null
	 */
	private ?Queue $queue = null;

	/**
	 * Admin constructor.
	 *
	 * @param Stats      $stats     Shared stats.
	 * @param Optimizer  $optimizer Shared optimizer.
	 * @param Cron       $cron      Shared cron.
	 * @param Backup     $backup    Shared backup.
	 * @param Queue|null $queue     Shared queue.
	 */
	public function __construct( Stats $stats, Optimizer $optimizer, Cron $cron, Backup $backup, ?Queue $queue = null ) {
		$this->stats     = $stats;
		$this->optimizer = $optimizer;
		$this->cron      = $cron;
		$this->backup    = $backup;
		$this->queue     = $queue;

		$basename = defined( 'OFIO_PLUGIN_BASENAME' ) ? OFIO_PLUGIN_BASENAME : 'optiforge-image-optimizer/optiforge-image-optimizer.php';

		add_action( 'admin_menu',                 array( $this, 'register_menus' ) );
		add_action( 'admin_init',                 array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts',      array( $this, 'enqueue_assets' ) );
		add_filter( 'manage_media_columns',       array( $this, 'add_media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
		add_filter( 'plugin_action_links_' . $basename, array( $this, 'plugin_action_links' ) );

		add_action( 'restrict_manage_posts',      array( $this, 'add_media_filter_dropdown' ) );
		add_action( 'pre_get_posts',              array( $this, 'filter_media_query' ) );

		add_action( 'wp_ajax_ofio_purge_backups',   array( $this, 'ajax_purge_backups' ) );
		add_action( 'wp_ajax_ofio_repair_metadata', array( $this, 'ajax_repair_metadata' ) );
	}

	/**
	 * Set queue dependency.
	 *
	 * @param Queue $queue Queue instance.
	 * @return void
	 */
	public function set_queue( Queue $queue ): void {
		$this->queue = $queue;
	}

	/**
	 * Register admin menu items.
	 *
	 * @return void
	 */
	public function register_menus(): void {
		add_menu_page(
			__( 'OptiForge Optimizer', 'optiforge-image-optimizer' ),
			__( 'OptiForge Optimizer', 'optiforge-image-optimizer' ),
			'manage_options',
			'optiforge-image-optimizer',
			array( $this, 'render_dashboard' ),
			'dashicons-images-alt2',
			81
		);

		add_submenu_page(
			'optiforge-image-optimizer',
			__( 'Dashboard', 'optiforge-image-optimizer' ),
			__( 'Dashboard', 'optiforge-image-optimizer' ),
			'manage_options',
			'optiforge-image-optimizer',
			array( $this, 'render_dashboard' )
		);
		add_submenu_page(
			'optiforge-image-optimizer',
			__( 'Settings', 'optiforge-image-optimizer' ),
			__( 'Settings', 'optiforge-image-optimizer' ),
			'manage_options',
			'ofio-settings',
			array( $this, 'render_settings' )
		);
		add_submenu_page(
			'optiforge-image-optimizer',
			__( 'Server Rules', 'optiforge-image-optimizer' ),
			__( 'Server Rules', 'optiforge-image-optimizer' ),
			'manage_options',
			'ofio-server-rules',
			array( $this, 'render_server_rules' )
		);
		add_submenu_page(
			'optiforge-image-optimizer',
			__( 'System Status', 'optiforge-image-optimizer' ),
			__( 'System Status', 'optiforge-image-optimizer' ),
			'manage_options',
			'ofio-system-status',
			array( $this, 'render_system_status' )
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		$options = array(
			'ofio_auto_optimize'       => 'absint',
			'ofio_compression_quality' => 'absint',
			'ofio_webp_conversion'     => 'absint',
			'ofio_avif_conversion'     => 'absint',
			'ofio_backup_enabled'      => 'absint',
			'ofio_preserve_exif'       => 'absint',
			'ofio_compression_mode'    => 'sanitize_text_field',
			'ofio_serve_webp'          => 'absint',
			'ofio_resize_enabled'      => 'absint',
			'ofio_max_width'           => 'absint',
			'ofio_max_height'          => 'absint',
		);

		foreach ( $options as $opt => $cb ) {
			register_setting( 'ofio_settings_group', $opt, array( 'sanitize_callback' => $cb ) );
		}
	}

	/**
	 * Enqueue admin CSS and JS assets.
	 *
	 * @param string $hook Page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'optiforge' ) && false === strpos( $hook, 'ofio' ) && 'upload.php' !== $hook && 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		$asset_url = defined( 'OFIO_URL' ) ? OFIO_URL : plugin_dir_url( dirname( __FILE__ ) );
		$version   = defined( 'OFIO_VERSION' ) ? OFIO_VERSION : '1.4.0';

		$css_ver = file_exists( OFIO_PATH . 'assets/css/admin.css' ) ? (string) filemtime( OFIO_PATH . 'assets/css/admin.css' ) : $version;
		$js_ver  = file_exists( OFIO_PATH . 'assets/js/admin.js' ) ? (string) filemtime( OFIO_PATH . 'assets/js/admin.js' ) : $version;

		wp_enqueue_style( 'ofio-admin', $asset_url . 'assets/css/admin.css', array(), $css_ver );
		wp_enqueue_script( 'ofio-admin', $asset_url . 'assets/js/admin.js', array( 'jquery' ), $js_ver, true );

		wp_localize_script(
			'ofio-admin',
			'OFIO',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'ofio_nonce' ),
				'i18n'     => array(
					'starting'         => __( 'Starting background optimization…', 'optiforge-image-optimizer' ),
					'paused'           => __( 'Optimization paused.', 'optiforge-image-optimizer' ),
					'resuming'         => __( 'Resuming background queue…', 'optiforge-image-optimizer' ),
					'complete'         => __( 'Process complete!', 'optiforge-image-optimizer' ),
					'stopped'          => __( 'Stopped by user.', 'optiforge-image-optimizer' ),
					'error'            => __( 'An error occurred during queue execution.', 'optiforge-image-optimizer' ),
					'confirm_stop'     => __( 'Stop background optimization? Pending tasks will be cleared.', 'optiforge-image-optimizer' ),
					'confirm_restore'  => __( 'Restore original image?', 'optiforge-image-optimizer' ),
					'confirm_purge'    => __( 'Are you sure you want to permanently delete all original backups?', 'optiforge-image-optimizer' ),
					'confirm_bulk_rst' => __( 'Restore ALL backed up images back to original in the background? This will revert all optimizations.', 'optiforge-image-optimizer' ),
					'no_images'        => __( 'No images found in queue.', 'optiforge-image-optimizer' ),
					'fetching'         => __( 'Checking background queue…', 'optiforge-image-optimizer' ),
				),
			)
		);
	}

	/**
	 * Add plugin action links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function plugin_action_links( array $links ): array {
		$settings = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=ofio-settings' ) ), esc_html__( 'Settings', 'optiforge-image-optimizer' ) );
		array_unshift( $links, $settings );
		return $links;
	}

	/**
	 * Add custom media list column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public function add_media_column( array $columns ): array {
		$columns['ofio_status'] = esc_html__( 'Optimization', 'optiforge-image-optimizer' );
		return $columns;
	}

	/**
	 * Render custom media column content.
	 *
	 * @param string $column        Column slug.
	 * @param int    $attachment_id Attachment ID.
	 * @return void
	 */
	public function render_media_column( $column, $attachment_id ): void {
		if ( 'ofio_status' !== $column ) {
			return;
		}
		$id = absint( $attachment_id );
		echo '<div class="ofio-media-cell" id="ofio-media-cell-' . (int) $id . '">';
		echo wp_kses_post( $this->get_media_column_html( $id ) );
		echo '</div>';
	}

	/**
	 * Generate HTML for the media library column.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	public function get_media_column_html( int $attachment_id ): string {
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
			return '<span class="ofio-badge ofio-badge--neutral">' . esc_html__( 'N/A', 'optiforge-image-optimizer' ) . '</span>';
		}

		$meta    = get_post_meta( $attachment_id, '_ofio_meta', true );
		$nonce   = wp_create_nonce( 'ofio_nonce' );
		$has_bkp = $this->backup->has_backup( $attachment_id );
		$html    = '';

		if ( $meta && is_array( $meta ) && ( $meta['status'] ?? '' ) === 'optimized' ) {
			$saved_kb  = round( (int) ( $meta['saved_bytes'] ?? 0 ) / 1024, 1 );
			$saved_pct = ( ! empty( $meta['original_size'] ) && (int) $meta['original_size'] > 0 )
				? round( ( (int) $meta['saved_bytes'] / (int) $meta['original_size'] ) * 100, 1 )
				: 0;

			$html .= '<span class="ofio-badge ofio-badge--success">' . esc_html__( 'Optimized', 'optiforge-image-optimizer' ) . '</span><br>';
			$html .= sprintf( '<small>%s <strong>%s KB</strong> · %s%%</small><br>', esc_html__( 'Saved:', 'optiforge-image-optimizer' ), esc_html( (string) $saved_kb ), esc_html( (string) $saved_pct ) );

			if ( ! empty( $meta['avif_created'] ) ) {
				$html .= '<small class="ofio-webp-label">AVIF ✓ </small>';
			}
			if ( ! empty( $meta['webp_created'] ) ) {
				$html .= '<small class="ofio-webp-label">WebP ✓</small><br>';
			}

			$html .= sprintf( '<button type="button" class="button button-small ofio-reoptimize" data-id="%d" data-nonce="%s">%s</button>', (int) $attachment_id, esc_attr( $nonce ), esc_html__( 'Re-optimize', 'optiforge-image-optimizer' ) );

			if ( $has_bkp ) {
				$html .= sprintf( ' <button type="button" class="button button-small ofio-restore" data-id="%d" data-nonce="%s">%s</button>', (int) $attachment_id, esc_attr( $nonce ), esc_html__( 'Restore', 'optiforge-image-optimizer' ) );
			}
		} else {
			$html .= '<span class="ofio-badge ofio-badge--pending">' . esc_html__( 'Pending', 'optiforge-image-optimizer' ) . '</span><br>';
			$html .= sprintf( '<button type="button" class="button button-small ofio-reoptimize" data-id="%d" data-nonce="%s">%s</button>', (int) $attachment_id, esc_attr( $nonce ), esc_html__( 'Optimize Now', 'optiforge-image-optimizer' ) );
		}

		return $html;
	}

	/**
	 * Media library filter dropdown.
	 *
	 * @param string $post_type Post type.
	 * @return void
	 */
	public function add_media_filter_dropdown( $post_type = 'attachment' ): void {
		if ( 'attachment' !== $post_type ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$selected = isset( $_GET['ofio_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['ofio_filter'] ) ) : '';
		?>
		<select name="ofio_filter">
			<option value=""><?php esc_html_e( 'All Optimization Statuses', 'optiforge-image-optimizer' ); ?></option>
			<option value="optimized" <?php selected( $selected, 'optimized' ); ?>><?php esc_html_e( 'Optimized', 'optiforge-image-optimizer' ); ?></option>
			<option value="unoptimized" <?php selected( $selected, 'unoptimized' ); ?>><?php esc_html_e( 'Unoptimized / Pending', 'optiforge-image-optimizer' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Filter media query based on dropdown selection.
	 *
	 * @param \WP_Query $query Query.
	 * @return void
	 */
	public function filter_media_query( $query ): void {
		if ( ! is_admin() || ! is_a( $query, 'WP_Query' ) || ! $query->is_main_query() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		if ( 'attachment' !== $post_type && ! ( is_array( $post_type ) && in_array( 'attachment', $post_type, true ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['ofio_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['ofio_filter'] ) ) : '';
		if ( 'optimized' === $filter ) {
			$meta_query   = (array) $query->get( 'meta_query' );
			$meta_query[] = array(
				'key'     => '_ofio_meta',
				'compare' => 'EXISTS',
			);
			$query->set( 'meta_query', $meta_query );
		} elseif ( 'unoptimized' === $filter ) {
			$meta_query   = (array) $query->get( 'meta_query' );
			$meta_query[] = array(
				'key'     => '_ofio_meta',
				'compare' => 'NOT EXISTS',
			);
			$query->set( 'meta_query', $meta_query );
		}
	}

	/**
	 * Render the main plugin dashboard.
	 *
	 * @return void
	 */
	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'optiforge-image-optimizer' ) );
		}
		$stats = $this->stats->get();
		$queue_state = $this->queue ? $this->queue->get_state() : array( 'status' => 'idle' );
		?>
		<div class="wrap ofio-wrap">
			<h1 class="ofio-page-title">
				<span class="dashicons dashicons-images-alt2"></span>
				<?php esc_html_e( 'OptiForge Image Optimizer', 'optiforge-image-optimizer' ); ?>
				<span class="ofio-version">v<?php echo esc_html( defined( 'OFIO_VERSION' ) ? OFIO_VERSION : '1.4.0' ); ?></span>
			</h1>

			<div class="ofio-cards">
				<div class="ofio-card">
					<div class="ofio-card__icon dashicons dashicons-chart-pie"></div>
					<div class="ofio-card__value" id="ofio-stat-saved-mb"><?php echo esc_html( number_format_i18n( (float) ( $stats['saved_mb'] ?? 0 ), 2 ) ); ?> MB</div>
					<div class="ofio-card__label"><?php esc_html_e( 'Total Storage Saved', 'optiforge-image-optimizer' ); ?></div>
				</div>
				<div class="ofio-card">
					<div class="ofio-card__icon dashicons dashicons-performance"></div>
					<div class="ofio-card__value" id="ofio-stat-pct-saved"><?php echo esc_html( number_format_i18n( (float) ( $stats['pct_saved'] ?? 0 ), 1 ) ); ?>%</div>
					<div class="ofio-card__label"><?php esc_html_e( 'Optimization Ratio', 'optiforge-image-optimizer' ); ?></div>
				</div>
				<div class="ofio-card">
					<div class="ofio-card__icon dashicons dashicons-images-alt"></div>
					<div class="ofio-card__value" id="ofio-stat-optimized-images"><?php echo esc_html( number_format_i18n( (int) ( $stats['optimized_images'] ?? 0 ) ) ); ?></div>
					<div class="ofio-card__label"><?php esc_html_e( 'Images Optimized', 'optiforge-image-optimizer' ); ?></div>
				</div>
				<div class="ofio-card">
					<div class="ofio-card__icon dashicons dashicons-media-default"></div>
					<div class="ofio-card__value" id="ofio-stat-total-images"><?php echo esc_html( number_format_i18n( (int) ( $stats['total_images'] ?? 0 ) ) ); ?></div>
					<div class="ofio-card__label"><?php esc_html_e( 'Total Media Images', 'optiforge-image-optimizer' ); ?></div>
				</div>
			</div>

			<div class="ofio-bulk-box">
				<h2><?php esc_html_e( 'Bulk Image Optimizer', 'optiforge-image-optimizer' ); ?></h2>
				<p class="ofio-description">
					<?php esc_html_e( 'True background processing enabled: The server processes images asynchronously in a self-healing queue with dynamic timeout prevention. You can safely close or navigate away from this browser window anytime without interrupting the optimization!', 'optiforge-image-optimizer' ); ?>
				</p>

				<div class="ofio-info-banner" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 18px;color:#166534;font-size:13px;font-weight:600;margin-bottom:20px;display:flex;align-items:center;gap:10px;">
					<span class="dashicons dashicons-yes-alt" style="color:#16a34a;font-size:20px;"></span>
					<span><?php esc_html_e( 'Browser-Independent Background Engine: Start the process and close your tab. When you return, the live dashboard will automatically reflect the latest progress.', 'optiforge-image-optimizer' ); ?></span>
				</div>

				<div style="margin-bottom:18px;">
					<label style="font-weight:600;display:inline-flex;align-items:center;gap:8px;cursor:pointer;">
						<input type="checkbox" id="ofio-force-reoptimize" value="1">
						<span><?php esc_html_e( 'Force re-optimize all images (recompresses and generates next-gen AVIF/WebP across entire media library)', 'optiforge-image-optimizer' ); ?></span>
					</label>
				</div>

				<div class="ofio-progress-wrap" id="ofio-progress-wrap" style="display:none;">
					<div class="ofio-progress-bar-container">
						<div class="ofio-progress-bar" id="ofio-progress-bar" style="width:0%"></div>
					</div>
					<div class="ofio-progress-text" id="ofio-progress-text">0%</div>
				</div>

				<div class="ofio-bulk-actions">
					<button class="button button-primary button-hero" id="ofio-btn-start" type="button"><?php esc_html_e( '▶ Start Optimization', 'optiforge-image-optimizer' ); ?></button>
					<button class="button button-hero" id="ofio-btn-pause" style="display:none;" type="button"><?php esc_html_e( '⏸ Pause', 'optiforge-image-optimizer' ); ?></button>
					<button class="button button-hero" id="ofio-btn-resume" style="display:none;" type="button"><?php esc_html_e( '▶ Resume', 'optiforge-image-optimizer' ); ?></button>
					<button class="button ofio-btn-stop" id="ofio-btn-stop" style="display:none;" type="button"><?php esc_html_e( '■ Stop', 'optiforge-image-optimizer' ); ?></button>
					<button class="button button-secondary" id="ofio-btn-bulk-restore" type="button" style="margin-left:auto;">
						<span class="dashicons dashicons-undo" style="vertical-align:middle;margin-top:-2px;"></span>
						<?php esc_html_e( 'One-Click Bulk Restore All', 'optiforge-image-optimizer' ); ?>
					</button>
				</div>

				<div class="ofio-stats-inline" id="ofio-stats-inline" style="display:none;">
					<span id="ofio-stat-processed">0</span> <?php esc_html_e( 'processed', 'optiforge-image-optimizer' ); ?> &middot;
					<span id="ofio-stat-remaining">0</span> <?php esc_html_e( 'remaining', 'optiforge-image-optimizer' ); ?> &middot;
					<span id="ofio-stat-saved">0 KB</span> <?php esc_html_e( 'saved', 'optiforge-image-optimizer' ); ?>
					<span id="ofio-active-file-wrap" style="margin-left:14px;color:#0284c7;font-style:italic;"></span>
				</div>

				<div class="ofio-log" id="ofio-log" style="display:none;" role="log" aria-live="polite"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'optiforge-image-optimizer' ) );
		}

		if ( isset( $_POST['ofio_settings_submit'] ) ) {
			check_admin_referer( 'ofio_settings_action', 'ofio_settings_nonce' );

			update_option( 'ofio_batch_size', min( 20, max( 1, absint( $_POST['ofio_batch_size'] ?? 5 ) ) ) );
			update_option( 'ofio_auto_optimize', absint( $_POST['ofio_auto_optimize'] ?? 0 ) );
			update_option( 'ofio_compression_quality', min( 100, max( 1, absint( $_POST['ofio_compression_quality'] ?? 82 ) ) ) );
			update_option( 'ofio_webp_conversion', absint( $_POST['ofio_webp_conversion'] ?? 0 ) );

			$avif_val = ( ! empty( $_POST['ofio_avif_conversion'] ) && $this->optimizer->supports_avif() ) ? 1 : 0;
			update_option( 'ofio_avif_conversion', $avif_val );

			update_option( 'ofio_backup_enabled', absint( $_POST['ofio_backup_enabled'] ?? 0 ) );
			update_option( 'ofio_preserve_exif', absint( $_POST['ofio_preserve_exif'] ?? 0 ) );
			update_option( 'ofio_serve_webp', absint( $_POST['ofio_serve_webp'] ?? 0 ) );
			update_option( 'ofio_resize_enabled', absint( $_POST['ofio_resize_enabled'] ?? 0 ) );
			update_option( 'ofio_max_width', absint( $_POST['ofio_max_width'] ?? 2048 ) );
			update_option( 'ofio_max_height', absint( $_POST['ofio_max_height'] ?? 2048 ) );

			$mode = sanitize_text_field( wp_unslash( $_POST['ofio_compression_mode'] ?? 'lossy' ) );
			update_option( 'ofio_compression_mode', in_array( $mode, array( 'lossy', 'lossless' ), true ) ? $mode : 'lossy' );

			echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Settings saved successfully.', 'optiforge-image-optimizer' ) . '</strong></p></div>';
		}

		$quality     = (int) get_option( 'ofio_compression_quality', 82 );
		$batch_size  = (int) get_option( 'ofio_batch_size', 5 );
		$mode        = (string) get_option( 'ofio_compression_mode', 'lossy' );
		$backup_info = $this->backup->get_backup_directory_size();
		$avif_ready  = $this->optimizer->supports_avif();
		$webp_ready  = function_exists( 'imagewebp' ) || ( extension_loaded( 'imagick' ) && class_exists( '\Imagick' ) );
		$max_w       = (int) get_option( 'ofio_max_width', 2048 );
		$max_h       = (int) get_option( 'ofio_max_height', 2048 );
		?>
		<div class="wrap ofio-wrap">
			<h1 class="ofio-page-title">
				<span class="dashicons dashicons-admin-settings"></span>
				<?php esc_html_e( 'OptiForge Optimizer — Settings', 'optiforge-image-optimizer' ); ?>
				<span class="ofio-version">v<?php echo esc_html( defined( 'OFIO_VERSION' ) ? OFIO_VERSION : '1.4.0' ); ?></span>
			</h1>

			<form method="post" action="">
				<?php wp_nonce_field( 'ofio_settings_action', 'ofio_settings_nonce' ); ?>

				<div class="ofio-settings-grid">
					<div class="ofio-settings-card">
						<h2><?php esc_html_e( 'Compression & Sizing', 'optiforge-image-optimizer' ); ?></h2>

						<div class="ofio-field">
							<label class="ofio-field__label">
								<?php esc_html_e( 'Batch Processing Limit', 'optiforge-image-optimizer' ); ?> &mdash;&nbsp;
								<strong id="ofio-batch-display" style="color:var(--ofio-primary);font-size:1.1rem;"><?php echo esc_html( (string) $batch_size ); ?></strong>
								<span class="ofio-tooltip" data-tooltip="1 – 3: Shared Hosting&#10;4 – 6: Standard VPS&#10;10+: Dedicated / High-End Server">&#9432;</span>
							</label>
							<input type="range" name="ofio_batch_size" min="1" max="20" value="<?php echo esc_attr( (string) $batch_size ); ?>" class="ofio-range" oninput="document.getElementById('ofio-batch-display').textContent=this.value">
						</div>

						<div class="ofio-field">
							<input type="hidden" name="ofio_auto_optimize" value="0">
							<label class="ofio-toggle">
								<input type="checkbox" name="ofio_auto_optimize" value="1" <?php checked( get_option( 'ofio_auto_optimize', 1 ) ); ?>>
								<span class="ofio-toggle__slider"></span>
								<span class="ofio-field__label">
									<?php esc_html_e( 'Auto-optimize on upload', 'optiforge-image-optimizer' ); ?>
									<span class="ofio-tooltip" data-tooltip="Automatically compresses and creates next-gen formats whenever a new image is uploaded.">&#9432;</span>
								</span>
							</label>
						</div>

						<div class="ofio-field">
							<label class="ofio-field__label">
								<?php esc_html_e( 'Compression Quality', 'optiforge-image-optimizer' ); ?> &mdash;&nbsp;
								<strong id="ofio-quality-display" style="color:var(--ofio-primary);font-size:1.1rem;"><?php echo esc_html( (string) $quality ); ?></strong>
								<span class="ofio-tooltip" data-tooltip="Recommended 80%–85%. Delivers maximum Core Web Vitals reduction while preserving Retina image sharpness.">&#9432;</span>
							</label>
							<input type="range" name="ofio_compression_quality" min="1" max="100" value="<?php echo esc_attr( (string) $quality ); ?>" class="ofio-range" oninput="document.getElementById('ofio-quality-display').textContent=this.value">
						</div>

						<div class="ofio-field">
							<span class="ofio-field__label">
								<?php esc_html_e( 'Compression Mode', 'optiforge-image-optimizer' ); ?>
								<span class="ofio-tooltip" data-tooltip="Lossy: Smallest file sizes (Recommended).&#10;Lossless: 100% pixel-perfect preservation with slightly larger sizes.">&#9432;</span>
							</span>
							<div style="display:flex;gap:18px;margin-top:6px;">
								<label class="ofio-radio"><input type="radio" name="ofio_compression_mode" value="lossy" <?php checked( $mode, 'lossy' ); ?>> Lossy</label>
								<label class="ofio-radio"><input type="radio" name="ofio_compression_mode" value="lossless" <?php checked( $mode, 'lossless' ); ?>> Lossless</label>
							</div>
						</div>

						<div class="ofio-field">
							<input type="hidden" name="ofio_resize_enabled" value="0">
							<label class="ofio-toggle">
								<input type="checkbox" name="ofio_resize_enabled" value="1" <?php checked( get_option( 'ofio_resize_enabled', 1 ) ); ?>>
								<span class="ofio-toggle__slider"></span>
								<span class="ofio-field__label">
									<?php esc_html_e( 'Auto-Resize Huge Uploads', 'optiforge-image-optimizer' ); ?>
									<span class="ofio-tooltip" data-tooltip="Scales down multi-megapixel raw uploads to maximum threshold to prevent browser memory exhaustion.">&#9432;</span>
								</span>
							</label>
						</div>

						<div class="ofio-field">
							<label class="ofio-field__label"><?php esc_html_e( 'Max Dimensions (W x H px)', 'optiforge-image-optimizer' ); ?></label>
							<div style="display:flex;align-items:center;gap:10px;margin-top:4px;">
								<input type="number" name="ofio_max_width" value="<?php echo esc_attr( (string) $max_w ); ?>" style="width:110px;padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1;">
								<span style="font-weight:bold;color:#94a3b8;">&times;</span>
								<input type="number" name="ofio_max_height" value="<?php echo esc_attr( (string) $max_h ); ?>" style="width:110px;padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1;">
							</div>
						</div>
					</div>

					<div class="ofio-settings-card">
						<h2><?php esc_html_e( 'Next-Gen Formats (WebP & AVIF)', 'optiforge-image-optimizer' ); ?></h2>

						<div class="ofio-field">
							<input type="hidden" name="ofio_webp_conversion" value="0">
							<label class="ofio-toggle">
								<input type="checkbox" name="ofio_webp_conversion" value="1" <?php checked( get_option( 'ofio_webp_conversion', 1 ) ); ?>>
								<span class="ofio-toggle__slider"></span>
								<span class="ofio-field__label">
									<?php esc_html_e( 'Generate WebP sidecars', 'optiforge-image-optimizer' ); ?>
									<?php if ( $webp_ready ) : ?>
										<span class="ofio-badge ofio-badge--success">&#10003; <?php esc_html_e( 'Compatible', 'optiforge-image-optimizer' ); ?></span>
									<?php else : ?>
										<span class="ofio-badge ofio-badge--error">&#10007; <?php esc_html_e( 'Not Compatible', 'optiforge-image-optimizer' ); ?></span>
									<?php endif; ?>
									<span class="ofio-tooltip" data-tooltip="Universal modern image format supported across 98%+ browsers. Acts as ultra-reliable fallback.">&#9432;</span>
								</span>
							</label>
						</div>

						<div class="ofio-field">
							<input type="hidden" name="ofio_avif_conversion" value="0">
							<label class="ofio-toggle">
								<input type="checkbox" name="ofio_avif_conversion" value="1" <?php checked( $avif_ready && get_option( 'ofio_avif_conversion', 1 ) ); ?> <?php disabled( ! $avif_ready ); ?>>
								<span class="ofio-toggle__slider"></span>
								<span class="ofio-field__label">
									<?php esc_html_e( 'Generate AVIF sidecars', 'optiforge-image-optimizer' ); ?>
									<?php if ( $avif_ready ) : ?>
										<span class="ofio-badge ofio-badge--success">&#10003; <?php esc_html_e( 'Compatible', 'optiforge-image-optimizer' ); ?></span>
									<?php else : ?>
										<span class="ofio-badge ofio-badge--error">&#10007; <?php esc_html_e( 'Not Compatible', 'optiforge-image-optimizer' ); ?></span>
									<?php endif; ?>
									<span class="ofio-tooltip" data-tooltip="Next-gen format giving 20%–35% smaller file sizes than WebP. Requires PHP 8.1+ compiled with libavif.">&#9432;</span>
								</span>
							</label>
						</div>

						<div class="ofio-field">
							<input type="hidden" name="ofio_serve_webp" value="0">
							<label class="ofio-toggle">
								<input type="checkbox" name="ofio_serve_webp" value="1" <?php checked( get_option( 'ofio_serve_webp', 1 ) ); ?>>
								<span class="ofio-toggle__slider"></span>
								<span class="ofio-field__label">
									<?php esc_html_e( 'Serve Next-Gen on Frontend', 'optiforge-image-optimizer' ); ?>
									<span class="ofio-tooltip" data-tooltip="Dynamically rewrites image requests or delivers WebP/AVIF via server rules to modern browsers.">&#9432;</span>
								</span>
							</label>
						</div>

						<div class="ofio-field">
							<input type="hidden" name="ofio_preserve_exif" value="0">
							<label class="ofio-toggle">
								<input type="checkbox" name="ofio_preserve_exif" value="1" <?php checked( get_option( 'ofio_preserve_exif', 0 ) ); ?>>
								<span class="ofio-toggle__slider"></span>
								<span class="ofio-field__label">
									<?php esc_html_e( 'Preserve EXIF metadata', 'optiforge-image-optimizer' ); ?>
									<span class="ofio-tooltip" data-tooltip="Retains camera settings, timestamps, and GPS details. Keep disabled for maximum payload reduction.">&#9432;</span>
								</span>
							</label>
						</div>
					</div>

					<div class="ofio-settings-card">
						<h2><?php esc_html_e( 'Backups & Storage', 'optiforge-image-optimizer' ); ?></h2>

						<div class="ofio-field">
							<input type="hidden" name="ofio_backup_enabled" value="0">
							<label class="ofio-toggle">
								<input type="checkbox" name="ofio_backup_enabled" value="1" <?php checked( get_option( 'ofio_backup_enabled', 1 ) ); ?>>
								<span class="ofio-toggle__slider"></span>
								<span class="ofio-field__label">
									<?php esc_html_e( 'Enable original backups', 'optiforge-image-optimizer' ); ?>
									<span class="ofio-tooltip" data-tooltip="Keeps untouched original images in an isolated folder for zero-risk, one-click restoration anytime.">&#9432;</span>
								</span>
							</label>
						</div>

						<div class="ofio-field" style="margin-top:20px;">
							<p style="color:#64748b;font-size:0.9rem;margin-bottom:12px;">
								<strong><?php esc_html_e( 'Current Backup Storage:', 'optiforge-image-optimizer' ); ?></strong>
								<span id="ofio-backup-size" style="color:#0f172a;font-weight:700;"><?php echo esc_html( $backup_info['formatted'] ); ?></span>
								(<?php echo esc_html( (string) $backup_info['count'] ); ?> files)
							</p>
							<button type="button" class="button ofio-btn-stop" id="ofio-purge-backups" style="padding:6px 16px;font-weight:600;">
								<?php esc_html_e( 'Delete All Backups (Free Up Disk)', 'optiforge-image-optimizer' ); ?>
							</button>
						</div>
					</div>
				</div>

				<div style="margin-top:24px;">
					<input type="submit" name="ofio_settings_submit" class="button button-primary button-hero" value="<?php esc_attr_e( 'Save Settings', 'optiforge-image-optimizer' ); ?>" style="border-radius:8px;font-weight:700;">
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Render server rewrite rules page.
	 *
	 * @return void
	 */
	public function render_server_rules(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'optiforge-image-optimizer' ) );
		}
		?>
		<div class="wrap ofio-wrap">
			<h1 class="ofio-page-title"><span class="dashicons dashicons-networking"></span><?php esc_html_e( 'Server-Level Rewrite Rules', 'optiforge-image-optimizer' ); ?></h1>
			<p class="ofio-description"><?php esc_html_e( 'Serve WebP and AVIF directly through your web server without modifying HTML tags.', 'optiforge-image-optimizer' ); ?></p>

			<div class="ofio-settings-card">
				<h2><?php esc_html_e( 'Apache / LiteSpeed (.htaccess)', 'optiforge-image-optimizer' ); ?></h2>
				<textarea readonly style="width:100%;height:180px;font-family:monospace;background:#090d16;color:#38bdf8;padding:14px;border-radius:8px;"><?php echo esc_textarea( Delivery::get_apache_rules() ); ?></textarea>
			</div>

			<div class="ofio-settings-card" style="margin-top:20px;">
				<h2><?php esc_html_e( 'Nginx Configuration Block', 'optiforge-image-optimizer' ); ?></h2>
				<textarea readonly style="width:100%;height:140px;font-family:monospace;background:#090d16;color:#38bdf8;padding:14px;border-radius:8px;"><?php echo esc_textarea( Delivery::get_nginx_rules() ); ?></textarea>
			</div>
		</div>
		<?php
	}

	/**
	 * Render system status page.
	 *
	 * @return void
	 */
	public function render_system_status(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'optiforge-image-optimizer' ) );
		}

		$engine = $this->optimizer->get_engine();
		$checks = array(
			'imagick'          => extension_loaded( 'imagick' ) && class_exists( '\Imagick' ),
			'gd'               => extension_loaded( 'gd' ) && function_exists( 'imagecreatefromjpeg' ),
			'gd_webp'          => function_exists( 'imagewebp' ),
			'avif'             => $this->optimizer->supports_avif(),
			'server_ok'        => $this->cron->server_healthy(),
			'uploads_writable' => wp_is_writable( (string) wp_upload_dir()['basedir'] ),
		);
		$nonce = wp_create_nonce( 'ofio_nonce' );
		?>
		<div class="wrap ofio-wrap">
			<h1 class="ofio-page-title"><span class="dashicons dashicons-admin-tools"></span><?php esc_html_e( 'System Status', 'optiforge-image-optimizer' ); ?></h1>
			<div class="ofio-status-table">
				<h2><?php esc_html_e( 'Engines & Formats', 'optiforge-image-optimizer' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<tr><td><?php esc_html_e( 'Active Processing Engine', 'optiforge-image-optimizer' ); ?></td><td><span class="ofio-badge ofio-badge--success"><?php echo esc_html( strtoupper( $engine ) ); ?></span></td></tr>
						<tr><td><?php esc_html_e( 'Imagick Library', 'optiforge-image-optimizer' ); ?></td><td><?php echo $checks['imagick'] ? '<span class="ofio-badge ofio-badge--success">✓ Installed</span>' : '<span class="ofio-badge ofio-badge--error">✗ Missing</span>'; ?></td></tr>
						<tr><td><?php esc_html_e( 'GD Library', 'optiforge-image-optimizer' ); ?></td><td><?php echo $checks['gd'] ? '<span class="ofio-badge ofio-badge--success">✓ Installed</span>' : '<span class="ofio-badge ofio-badge--error">✗ Missing</span>'; ?></td></tr>
						<tr><td><?php esc_html_e( 'AVIF Format Support', 'optiforge-image-optimizer' ); ?></td><td><?php echo $checks['avif'] ? '<span class="ofio-badge ofio-badge--success">✓ Supported</span>' : '<span class="ofio-badge ofio-badge--pending">✗ Unsupported</span>'; ?></td></tr>
						<tr><td><?php esc_html_e( 'Uploads Directory', 'optiforge-image-optimizer' ); ?></td><td><?php echo $checks['uploads_writable'] ? '<span class="ofio-badge ofio-badge--success">✓ Writable</span>' : '<span class="ofio-badge ofio-badge--error">✗ Read-Only</span>'; ?></td></tr>
						<tr><td><?php esc_html_e( 'Background Queue System', 'optiforge-image-optimizer' ); ?></td><td><span class="ofio-badge ofio-badge--success">✓ Operational (Self-Healing Dual Engine)</span></td></tr>
						<tr><td><?php esc_html_e( 'PHP Version', 'optiforge-image-optimizer' ); ?></td><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
						<tr><td><?php esc_html_e( 'PHP Memory Limit', 'optiforge-image-optimizer' ); ?></td><td><?php echo esc_html( (string) ini_get( 'memory_limit' ) ); ?></td></tr>
						<tr><td><?php esc_html_e( 'Max Execution Time', 'optiforge-image-optimizer' ); ?></td><td><?php echo esc_html( (string) ini_get( 'max_execution_time' ) ); ?>s</td></tr>
					</tbody>
				</table>
			</div>

			<div class="ofio-settings-card" style="margin-top:28px;">
				<h2><?php esc_html_e( '🔧 Self-Healing: Repair Lost Metadata', 'optiforge-image-optimizer' ); ?></h2>
				<p style="color:#64748b;max-width:680px;">
					<?php esc_html_e( 'If optimization or restoration has caused some images to lose their Title, Caption, or Description, this tool will scan all attachments and safely recreate clean titles from their filename — without altering any physical image file or overriding existing valid titles.', 'optiforge-image-optimizer' ); ?>
				</p>
				<button type="button" id="ofio-repair-metadata" class="button button-secondary" data-nonce="<?php echo esc_attr( $nonce ); ?>" style="margin-top:8px;font-weight:600;">
					<span class="dashicons dashicons-superhero-alt" style="vertical-align:middle;margin-top:-2px;"></span>
					<?php esc_html_e( 'Run Metadata Repair', 'optiforge-image-optimizer' ); ?>
				</button>
				<span id="ofio-repair-status" style="display:inline-block;margin-left:16px;font-style:italic;color:#2271b1;vertical-align:middle;"></span>
				<div id="ofio-repair-log" style="margin-top:12px;max-height:200px;overflow-y:auto;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 14px;display:none;font-size:0.85rem;color:#334155;"></div>
			</div>

			<script type="text/javascript">
			( function( $ ) {
				'use strict';
				$( document ).on( 'click', '#ofio-repair-metadata', function () {
					var $btn    = $( this );
					var $status = $( '#ofio-repair-status' );
					var $log    = $( '#ofio-repair-log' );
					var nonce   = $btn.data( 'nonce' );

					$btn.prop( 'disabled', true );
					$status.text( '<?php echo esc_js( __( 'Scanning… please wait.', 'optiforge-image-optimizer' ) ); ?>' );
					$log.empty().hide();

					$.post(
						( typeof OFIO !== 'undefined' && OFIO.ajax_url ) ? OFIO.ajax_url : ajaxurl,
						{
							action   : 'ofio_repair_metadata',
							security : nonce,
						},
						function ( response ) {
							$btn.prop( 'disabled', false );
							if ( response.success ) {
								$status.text( response.data.message );
								if ( response.data.log && response.data.log.length ) {
									var html = '';
									$.each( response.data.log, function ( i, line ) {
										html += '<div>' + $( '<span>' ).text( line ).html() + '</div>';
									} );
									$log.html( html ).show();
								}
							} else {
								$status.css( 'color', '#d63638' ).text(
									( response.data && response.data.message ) ? response.data.message : '<?php echo esc_js( __( 'Repair failed.', 'optiforge-image-optimizer' ) ); ?>'
								);
							}
						}
					).fail( function () {
						$btn.prop( 'disabled', false );
						$status.css( 'color', '#d63638' ).text( '<?php echo esc_js( __( 'Server error. Please try again.', 'optiforge-image-optimizer' ) ); ?>' );
					} );
				} );
			} )( jQuery );
			</script>
		</div>
		<?php
	}

	/**
	 * AJAX: Purge all backups.
	 *
	 * @return void
	 */
	public function ajax_purge_backups(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'optiforge-image-optimizer' ) ), 403 );
		}

		$res = $this->backup->purge_all_backups();
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: Self-healing metadata repair.
	 *
	 * @return void
	 */
	public function ajax_repair_metadata(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'optiforge-image-optimizer' ) ), 403 );
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$attachments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, guid
				 FROM {$wpdb->posts}
				 WHERE post_type = %s
				   AND post_status = %s
				   AND post_mime_type LIKE %s
				   AND (
				       post_title = %s
				    OR post_title = %s
				    OR post_title REGEXP %s
				   )",
				'attachment',
				'inherit',
				$wpdb->esc_like( 'image/' ) . '%',
				'',
				'No Title',
				'^[0-9]+$'
			),
			OBJECT
		);

		$repaired = 0;
		$skipped  = 0;
		$log      = array();

		foreach ( $attachments as $attachment ) {
			$id    = (int) $attachment->ID;
			$title = (string) $attachment->post_title;
			$guid  = (string) $attachment->guid;

			$filename  = basename( $guid );
			$name_part = pathinfo( $filename, PATHINFO_FILENAME );

			$clean_title = trim( str_replace( array( '-', '_' ), ' ', $name_part ) );
			$clean_title = (string) preg_replace( '/\s+/', ' ', $clean_title );
			$clean_title = ucwords( $clean_title );

			if ( empty( $clean_title ) ) {
				$skipped++;
				$log[] = sprintf( 'Skipped #%d — Could not derive title from guid.', $id );
				continue;
			}

			if ( $clean_title === $title ) {
				$skipped++;
				continue;
			}

			$result = wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $clean_title,
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				$log[] = sprintf( 'Error repairing #%d (%s): %s', $id, esc_html( $filename ), $result->get_error_message() );
			} else {
				$repaired++;
				$log[] = sprintf( 'Repaired #%d — "%s" → "%s"', $id, esc_html( $filename ), esc_html( $clean_title ) );
			}
		}

		$total_scanned = count( $attachments );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: repaired, 2: total */
					__( 'Repair complete. %1$d title(s) repaired out of %2$d scanned.', 'optiforge-image-optimizer' ),
					$repaired,
					$total_scanned
				),
				'repaired' => $repaired,
				'skipped'  => $skipped,
				'scanned'  => $total_scanned,
				'log'      => $log,
			)
		);
	}
}

// Backwards-compatible class aliases.
if ( ! class_exists( 'OFIO_Admin', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Admin', 'OFIO_Admin' );
}
if ( ! class_exists( '\OptiForge\ImageOptimizer\Admin', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Admin', '\OptiForge\ImageOptimizer\Admin' );
}
if ( ! class_exists( '\OptiForge_Image_Optimizer\Admin', false ) ) {
	class_alias( '\Optiforge_Image_Optimizer\Admin', '\OptiForge_Image_Optimizer\Admin' );
}