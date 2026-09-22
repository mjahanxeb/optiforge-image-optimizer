<?php
/**
 * Admin UI & Media Library Integration.
 *
 * @package OptiForge_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class OFIO_Admin {

	private OFIO_Stats $stats;
	private OFIO_Optimizer $optimizer;
	private OFIO_Cron $cron;
	private OFIO_Backup $backup;

	public function __construct( OFIO_Stats $stats, OFIO_Optimizer $optimizer, OFIO_Cron $cron, OFIO_Backup $backup ) {
		$this->stats     = $stats;
		$this->optimizer = $optimizer;
		$this->cron      = $cron;
		$this->backup    = $backup;

		$basename = defined( 'OFIO_PLUGIN_BASENAME' ) ? OFIO_PLUGIN_BASENAME : ( defined( 'OFIO_BASENAME' ) ? OFIO_BASENAME : 'optiforge-image-optimizer/optiforge-image-optimizer.php' );

		add_action( 'admin_menu',                 array( $this, 'register_menus' ) );
		add_action( 'admin_init',                 array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts',      array( $this, 'enqueue_assets' ) );
		add_filter( 'manage_media_columns',       array( $this, 'add_media_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_media_column' ), 10, 2 );
		add_filter( 'plugin_action_links_' . $basename, array( $this, 'plugin_action_links' ) );

		add_action( 'restrict_manage_posts',      array( $this, 'add_media_filter_dropdown' ) );
		add_action( 'pre_get_posts',              array( $this, 'filter_media_query' ) );

		add_action( 'wp_ajax_ofio_purge_backups', array( $this, 'ajax_purge_backups' ) );
	}

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

	public function enqueue_assets( string $hook ): void {
		$plugin_screens = array(
			'toplevel_page_optiforge-image-optimizer',
			'optiforge-image-optimizer_page_ofio-settings',
			'optiforge-image-optimizer_page_ofio-server-rules',
			'optiforge-image-optimizer_page_ofio-system-status',
		);

		if ( ! in_array( $hook, $plugin_screens, true ) && 'upload.php' !== $hook ) {
			return;
		}

		$asset_url = defined( 'OFIO_PLUGIN_URL' ) ? OFIO_PLUGIN_URL : ( defined( 'OFIO_URL' ) ? OFIO_URL : plugin_dir_url( dirname( __FILE__ ) ) );
		$version   = defined( 'OFIO_VERSION' ) ? OFIO_VERSION : '1.3.1';

		wp_enqueue_style( 'ofio-admin', $asset_url . 'assets/css/admin.css', array(), $version );
		wp_enqueue_script( 'ofio-admin', $asset_url . 'assets/js/admin.js', array( 'jquery' ), $version, true );

		wp_localize_script(
			'ofio-admin',
			'OFIO',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'ofio_nonce' ),
				'i18n'     => array(
					'starting'         => __( 'Starting optimization…', 'optiforge-image-optimizer' ),
					'paused'           => __( 'Paused.', 'optiforge-image-optimizer' ),
					'resuming'         => __( 'Resuming…', 'optiforge-image-optimizer' ),
					'complete'         => __( 'Process complete!', 'optiforge-image-optimizer' ),
					'stopped'          => __( 'Stopped by user.', 'optiforge-image-optimizer' ),
					'error'            => __( 'Batch error occurred.', 'optiforge-image-optimizer' ),
					'confirm_stop'     => __( 'Stop current process?', 'optiforge-image-optimizer' ),
					'confirm_restore'  => __( 'Restore original image?', 'optiforge-image-optimizer' ),
					'confirm_purge'    => __( 'Are you sure you want to permanently delete all original backups?', 'optiforge-image-optimizer' ),
					'confirm_bulk_rst' => __( 'Restore ALL backed up images back to original? This will revert all optimizations.', 'optiforge-image-optimizer' ),
					'no_images'        => __( 'No images found in queue.', 'optiforge-image-optimizer' ),
					'fetching'         => __( 'Fetching queue…', 'optiforge-image-optimizer' ),
				),
			)
		);
	}

	public function plugin_action_links( array $links ): array {
		$settings = sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=ofio-settings' ) ), esc_html__( 'Settings', 'optiforge-image-optimizer' ) );
		array_unshift( $links, $settings );
		return $links;
	}

	public function add_media_column( array $columns ): array {
		$columns['ofio_status'] = esc_html__( 'Optimization', 'optiforge-image-optimizer' );
		return $columns;
	}

	public function render_media_column( $column, $attachment_id ): void {
		if ( 'ofio_status' !== $column ) {
			return;
		}
		$id = absint( $attachment_id );
		echo '<div class="ofio-media-cell" id="ofio-media-cell-' . (int) $id . '">';
		echo wp_kses_post( $this->get_media_column_html( $id ) );
		echo '</div>';
	}

	public function get_media_column_html( int $attachment_id ): string {
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
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

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	public function add_media_filter_dropdown( $post_type = 'attachment' ): void {
		if ( 'attachment' !== $post_type ) {
			return;
		}
		$selected = isset( $_GET['ofio_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['ofio_filter'] ) ) : '';
		?>
		<select name="ofio_filter">
			<option value=""><?php esc_html_e( 'All Optimization Statuses', 'optiforge-image-optimizer' ); ?></option>
			<option value="optimized" <?php selected( $selected, 'optimized' ); ?>><?php esc_html_e( 'Optimized', 'optiforge-image-optimizer' ); ?></option>
			<option value="unoptimized" <?php selected( $selected, 'unoptimized' ); ?>><?php esc_html_e( 'Unoptimized / Pending', 'optiforge-image-optimizer' ); ?></option>
		</select>
		<?php
	}

	public function filter_media_query( $query ): void {
		if ( ! is_admin() || ! is_a( $query, 'WP_Query' ) || ! $query->is_main_query() ) {
			return;
		}

		$post_type = $query->get( 'post_type' );
		if ( 'attachment' !== $post_type && ! ( is_array( $post_type ) && in_array( 'attachment', $post_type, true ) ) ) {
			return;
		}

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
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	public function render_dashboard(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'optiforge-image-optimizer' ) );
		}
		$stats = $this->stats->get();
		?>
		<div class="wrap ofio-wrap">
			<h1 class="ofio-page-title">
				<span class="dashicons dashicons-images-alt2"></span>
				<?php esc_html_e( 'OptiForge Image Optimizer', 'optiforge-image-optimizer' ); ?>
				<span class="ofio-version">v<?php echo esc_html( defined( 'OFIO_VERSION' ) ? OFIO_VERSION : '1.3.1' ); ?></span>
			</h1>

			<div class="ofio-cards">
				<div class="ofio-card">
					<div class="ofio-card__icon dashicons dashicons-chart-pie"></div>
					<div class="ofio-card__value"><?php echo esc_html( number_format_i18n( (float) ( $stats['saved_mb'] ?? 0 ), 2 ) ); ?> MB</div>
					<div class="ofio-card__label"><?php esc_html_e( 'Total Storage Saved', 'optiforge-image-optimizer' ); ?></div>
				</div>
				<div class="ofio-card">
					<div class="ofio-card__icon dashicons dashicons-performance"></div>
					<div class="ofio-card__value"><?php echo esc_html( number_format_i18n( (float) ( $stats['pct_saved'] ?? 0 ), 1 ) ); ?>%</div>
					<div class="ofio-card__label"><?php esc_html_e( 'Optimization Ratio', 'optiforge-image-optimizer' ); ?></div>
				</div>
				<div class="ofio-card">
					<div class="ofio-card__icon dashicons dashicons-images-alt"></div>
					<div class="ofio-card__value"><?php echo esc_html( number_format_i18n( (int) ( $stats['optimized_images'] ?? 0 ) ) ); ?></div>
					<div class="ofio-card__label"><?php esc_html_e( 'Images Optimized', 'optiforge-image-optimizer' ); ?></div>
				</div>
				<div class="ofio-card">
					<div class="ofio-card__icon dashicons dashicons-media-default"></div>
					<div class="ofio-card__value"><?php echo esc_html( number_format_i18n( (int) ( $stats['total_images'] ?? 0 ) ) ); ?></div>
					<div class="ofio-card__label"><?php esc_html_e( 'Total Media Images', 'optiforge-image-optimizer' ); ?></div>
				</div>
			</div>

			<div class="ofio-bulk-box">
				<h2><?php esc_html_e( 'Bulk Image Optimizer', 'optiforge-image-optimizer' ); ?></h2>
				<p class="ofio-description"><?php esc_html_e( 'Compress your images safely in the background with automatic time-budgeting (no server timeouts).', 'optiforge-image-optimizer' ); ?></p>

				<div class="ofio-warning-banner">
					<?php esc_html_e( '⚠️ Please Note: Do not close or refresh this browser tab during optimization.', 'optiforge-image-optimizer' ); ?>
				</div>

				<div style="margin-bottom:14px;">
					<label style="font-weight:600;display:inline-flex;align-items:center;gap:6px;cursor:pointer;">
						<input type="checkbox" id="ofio-force-reoptimize" value="1">
						<?php esc_html_e( 'Force re-optimize all images (converts previously optimized JPG, PNG & WebP to AVIF)', 'optiforge-image-optimizer' ); ?>
					</label>
				</div>

				<div class="ofio-progress-wrap" id="ofio-progress-wrap" style="display:none;">
					<div class="ofio-progress-bar-container">
						<div class="ofio-progress-bar" id="ofio-progress-bar" style="width:0%"></div>
					</div>
					<div class="ofio-progress-text" id="ofio-progress-text">0%</div>
				</div>

				<div class="ofio-bulk-actions">
					<button class="button button-primary button-hero" id="ofio-btn-start" type="button"><?php esc_html_e( '▶ Start Bulk Optimize', 'optiforge-image-optimizer' ); ?></button>
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
				</div>

				<div class="ofio-log" id="ofio-log" style="display:none;" role="log" aria-live="polite"></div>
			</div>
		</div>
		<?php
	}

	public function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'optiforge-image-optimizer' ) );
		}

		if ( isset( $_POST['ofio_settings_submit'] ) ) {
			check_admin_referer( 'ofio_settings_action', 'ofio_settings_nonce' );

			update_option( 'ofio_auto_optimize',       absint( $_POST['ofio_auto_optimize'] ?? 0 ) );
			update_option( 'ofio_compression_quality', min( 100, max( 1, absint( $_POST['ofio_compression_quality'] ?? 82 ) ) ) );
			update_option( 'ofio_webp_conversion',     absint( $_POST['ofio_webp_conversion'] ?? 0 ) );
			update_option( 'ofio_avif_conversion',     absint( $_POST['ofio_avif_conversion'] ?? 0 ) );
			update_option( 'ofio_backup_enabled',      absint( $_POST['ofio_backup_enabled'] ?? 0 ) );
			update_option( 'ofio_preserve_exif',       absint( $_POST['ofio_preserve_exif'] ?? 0 ) );
			update_option( 'ofio_serve_webp',          absint( $_POST['ofio_serve_webp'] ?? 0 ) );
			update_option( 'ofio_resize_enabled',      absint( $_POST['ofio_resize_enabled'] ?? 0 ) );
			update_option( 'ofio_max_width',           absint( $_POST['ofio_max_width'] ?? 2048 ) );
			update_option( 'ofio_max_height',          absint( $_POST['ofio_max_height'] ?? 2048 ) );

			$mode = sanitize_text_field( wp_unslash( $_POST['ofio_compression_mode'] ?? 'lossy' ) );
			update_option( 'ofio_compression_mode', in_array( $mode, array( 'lossy', 'lossless' ), true ) ? $mode : 'lossy' );

			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'optiforge-image-optimizer' ) . '</p></div>';
		}

		$quality     = (int) get_option( 'ofio_compression_quality', 82 );
		$mode        = (string) get_option( 'ofio_compression_mode', 'lossy' );
		$backup_info = $this->backup->get_backup_directory_size();
		$avif_ready  = $this->optimizer->supports_avif();
		?>
		<div class="wrap ofio-wrap">
			<h1 class="ofio-page-title"><span class="dashicons dashicons-admin-settings"></span><?php esc_html_e( 'OptiForge Optimizer — Settings', 'optiforge-image-optimizer' ); ?></h1>

			<form method="post" action="">
				<?php wp_nonce_field( 'ofio_settings_action', 'ofio_settings_nonce' ); ?>

				<div class="ofio-settings-grid">
					<div class="ofio-settings-card">
						<h2><?php esc_html_e( 'Compression & Sizing', 'optiforge-image-optimizer' ); ?></h2>

						<div class="ofio-field">
							<label class="ofio-toggle">
								<input type="hidden" name="ofio_auto_optimize" value="0">
								<input type="checkbox" name="ofio_auto_optimize" value="1" <?php checked( get_option( 'ofio_auto_optimize', 1 ) ); ?>>
								<span class="ofio-toggle__slider"></span>
							</label>
							<span class="ofio-field__label"><?php esc_html_e( 'Auto-optimize on upload', 'optiforge-image-optimizer' ); ?></span>
						</div>

						<div class="ofio-field">
							<label class="ofio-field__label"><?php esc_html_e( 'Compression Quality', 'optiforge-image-optimizer' ); ?> &mdash; <strong id="ofio-quality-display"><?php echo esc_html( (string) $quality ); ?></strong></label>
							<input type="range" name="ofio_compression_quality" min="1" max="100" value="<?php echo esc_attr( (string) $quality ); ?>" class="ofio-range" oninput="document.getElementById('ofio-quality-display').textContent=this.value">
						</div>

						<div class="ofio-field">
							<span class="ofio-field__label"><?php esc_html_e( 'Compression Mode', 'optiforge-image-optimizer' ); ?></span>
							<label class="ofio-radio"><input type="radio" name="ofio_compression_mode" value="lossy" <?php checked( $mode, 'lossy' ); ?>> Lossy</label>
							<label class="ofio-radio"><input type="radio" name="ofio_compression_mode" value="lossless" <?php checked( $mode, 'lossless' ); ?>> Lossless</label>
						</div>

						<div class="ofio-field">
							<label class="ofio-toggle">
								<input type="hidden" name="ofio_resize_enabled" value="0">
								<input type="checkbox" name="ofio_resize_enabled" value="1" <?php checked( get_option( 'ofio_resize_enabled', 1 ) ); ?>>
								<span class="ofio-toggle__slider"></span>
							</label>
							<span class="ofio-field__label"><?php esc_html_e( 'Auto-Resize Huge Uploads', 'optiforge-image-optimizer' ); ?></span>
						</div>

						<div class="ofio-field">
							<label><?php esc_html_e( 'Max Dimensions (W x H px)', 'optiforge-image-optimizer' ); ?></label>
							<div style="display:flex;gap:8px;">
								<input type="number" name="ofio_max_width" value="<?php echo esc_attr( (string) get_option( 'ofio_max_width', 2048 ) ); ?>" class="small-text">
								<span>&times;</span>
								<input type="number" name="ofio_max_height" value="<?php echo esc_attr( (string) get_option( 'ofio_max_height', 2048 ) ); ?>" class="small-text">
							</div>
						</div>
					</div>

					<div class="ofio-settings-card">
						<h2><?php esc_html_e( 'Next-Gen Formats (WebP & AVIF)', 'optiforge-image-optimizer' ); ?></h2>

						<div class="ofio-field">
							<label class="ofio-toggle">
								<input type="hidden" name="ofio_webp_conversion" value="0">
								<input type="checkbox" name="ofio_webp_conversion" value="1" <?php checked( get_option( 'ofio_webp_conversion', 1 ) ); ?>>
								<span class="ofio-toggle__slider"></span>
							</label>
							<span class="ofio-field__label"><?php esc_html_e( 'Generate WebP sidecars', 'optiforge-image-optimizer' ); ?></span>
						</div>

						<div class="ofio-field">
							<label class="ofio-toggle">
								<input type="hidden" name="ofio_avif_conversion" value="0">
								<input type="checkbox" name="ofio_avif_conversion" value="1" <?php checked( get_option( 'ofio_avif_conversion', 1 ) ); ?> <?php disabled( ! $avif_ready ); ?>>
								<span class="ofio-toggle__slider"></span>
							</label>
							<span class="ofio-field__label">
								<?php esc_html_e( 'Generate AVIF sidecars (Converts JPG, PNG, & WebP)', 'optiforge-image-optimizer' ); ?>
								<?php if ( ! $avif_ready ) : ?>
									<small style="color:#d63638;">(<?php esc_html_e( 'Server lacks AVIF support', 'optiforge-image-optimizer' ); ?>)</small>
								<?php endif; ?>
							</span>
						</div>

						<div class="ofio-field">
							<label class="ofio-toggle">
								<input type="hidden" name="ofio_serve_webp" value="0">
								<input type="checkbox" name="ofio_serve_webp" value="1" <?php checked( get_option( 'ofio_serve_webp', 1 ) ); ?>>
								<span class="ofio-toggle__slider"></span>
							</label>
							<span class="ofio-field__label"><?php esc_html_e( 'Serve Next-Gen on Frontend', 'optiforge-image-optimizer' ); ?></span>
						</div>

						<div class="ofio-field">
							<label class="ofio-toggle">
								<input type="hidden" name="ofio_preserve_exif" value="0">
								<input type="checkbox" name="ofio_preserve_exif" value="1" <?php checked( get_option( 'ofio_preserve_exif', 0 ) ); ?>>
								<span class="ofio-toggle__slider"></span>
							</label>
							<span class="ofio-field__label"><?php esc_html_e( 'Preserve EXIF metadata', 'optiforge-image-optimizer' ); ?></span>
						</div>
					</div>

					<div class="ofio-settings-card">
						<h2><?php esc_html_e( 'Backups & Storage', 'optiforge-image-optimizer' ); ?></h2>

						<div class="ofio-field">
							<label class="ofio-toggle">
								<input type="hidden" name="ofio_backup_enabled" value="0">
								<input type="checkbox" name="ofio_backup_enabled" value="1" <?php checked( get_option( 'ofio_backup_enabled', 1 ) ); ?>>
								<span class="ofio-toggle__slider"></span>
							</label>
							<span class="ofio-field__label"><?php esc_html_e( 'Enable original backups', 'optiforge-image-optimizer' ); ?></span>
						</div>

						<div class="ofio-field" style="margin-top:16px;">
							<p><strong><?php esc_html_e( 'Current Backup Storage:', 'optiforge-image-optimizer' ); ?></strong> <?php echo esc_html( $backup_info['formatted'] ); ?> (<?php echo esc_html( (string) $backup_info['count'] ); ?> files)</p>
							<button type="button" class="button button-secondary" id="ofio-purge-backups"><?php esc_html_e( 'Delete All Backups (Free Up Disk)', 'optiforge-image-optimizer' ); ?></button>
						</div>
					</div>
				</div>

				<p class="submit">
					<input type="submit" name="ofio_settings_submit" class="button button-primary button-large" value="<?php esc_attr_e( 'Save Settings', 'optiforge-image-optimizer' ); ?>">
				</p>
			</form>
		</div>
		<?php
	}

	public function render_server_rules(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'optiforge-image-optimizer' ) );
		}
		?>
		<div class="wrap ofio-wrap">
			<h1 class="ofio-page-title"><span class="dashicons dashicons-networking"></span><?php esc_html_e( 'Server-Level Rewrite Rules', 'optiforge-image-optimizer' ); ?></h1>
			<p><?php esc_html_e( 'Serve WebP and AVIF directly through your web server without modifying HTML tags.', 'optiforge-image-optimizer' ); ?></p>

			<div class="ofio-settings-card">
				<h2><?php esc_html_e( 'Apache / LiteSpeed (.htaccess)', 'optiforge-image-optimizer' ); ?></h2>
				<textarea readonly style="width:100%;height:180px;font-family:monospace;background:#f6f7f7;"><?php echo esc_textarea( OFIO_Delivery::get_apache_rules() ); ?></textarea>
			</div>

			<div class="ofio-settings-card" style="margin-top:20px;">
				<h2><?php esc_html_e( 'Nginx Configuration Block', 'optiforge-image-optimizer' ); ?></h2>
				<textarea readonly style="width:100%;height:140px;font-family:monospace;background:#f6f7f7;"><?php echo esc_textarea( OFIO_Delivery::get_nginx_rules() ); ?></textarea>
			</div>
		</div>
		<?php
	}

	public function render_system_status(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'optiforge-image-optimizer' ) );
		}

		$engine = $this->optimizer->get_engine();
		$checks = array(
			'imagick'          => extension_loaded( 'imagick' ) && class_exists( 'Imagick' ),
			'gd'               => extension_loaded( 'gd' ) && function_exists( 'imagecreatefromjpeg' ),
			'gd_webp'          => function_exists( 'imagewebp' ),
			'avif'             => $this->optimizer->supports_avif(),
			'server_ok'        => method_exists( $this->cron, 'server_healthy' ) ? $this->cron->server_healthy() : true,
			'uploads_writable' => wp_is_writable( (string) wp_upload_dir()['basedir'] ),
		);
		?>
		<div class="wrap ofio-wrap">
			<h1 class="ofio-page-title"><span class="dashicons dashicons-admin-tools"></span><?php esc_html_e( 'System Status', 'optiforge-image-optimizer' ); ?></h1>
			<div class="ofio-status-table">
				<h2><?php esc_html_e( 'Engines & Formats', 'optiforge-image-optimizer' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<tr><td><?php esc_html_e( 'Active Engine', 'optiforge-image-optimizer' ); ?></td><td><span class="ofio-badge ofio-badge--success"><?php echo esc_html( strtoupper( $engine ) ); ?></span></td></tr>
						<tr><td><?php esc_html_e( 'Imagick Library', 'optiforge-image-optimizer' ); ?></td><td><?php echo $checks['imagick'] ? '<span class="ofio-badge ofio-badge--success">✓ Installed</span>' : '<span class="ofio-badge ofio-badge--error">✗ Missing</span>'; ?></td></tr>
						<tr><td><?php esc_html_e( 'GD Library', 'optiforge-image-optimizer' ); ?></td><td><?php echo $checks['gd'] ? '<span class="ofio-badge ofio-badge--success">✓ Installed</span>' : '<span class="ofio-badge ofio-badge--error">✗ Missing</span>'; ?></td></tr>
						<tr><td><?php esc_html_e( 'AVIF Format Support', 'optiforge-image-optimizer' ); ?></td><td><?php echo $checks['avif'] ? '<span class="ofio-badge ofio-badge--success">✓ Supported</span>' : '<span class="ofio-badge ofio-badge--pending">✗ Unsupported</span>'; ?></td></tr>
						<tr><td><?php esc_html_e( 'Uploads Directory', 'optiforge-image-optimizer' ); ?></td><td><?php echo $checks['uploads_writable'] ? '<span class="ofio-badge ofio-badge--success">✓ Writable</span>' : '<span class="ofio-badge ofio-badge--error">✗ Read-Only</span>'; ?></td></tr>
						<tr><td><?php esc_html_e( 'Background Cron Health', 'optiforge-image-optimizer' ); ?></td><td><?php echo $checks['server_ok'] ? '<span class="ofio-badge ofio-badge--success">✓ Healthy</span>' : '<span class="ofio-badge ofio-badge--pending">! Warning</span>'; ?></td></tr>
						<tr><td><?php esc_html_e( 'PHP Version', 'optiforge-image-optimizer' ); ?></td><td><?php echo esc_html( PHP_VERSION ); ?></td></tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	public function ajax_purge_backups(): void {
		check_ajax_referer( 'ofio_nonce', 'security' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden', 'optiforge-image-optimizer' ) ), 403 );
		}

		$res = $this->backup->purge_all_backups();
		wp_send_json_success( $res );
	}
}