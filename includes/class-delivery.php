<?php
/**
 * Frontend Image Delivery & Server Rewrite Rule Generator.
 *
 * Injects <picture> elements with WebP/AVIF <source> alternatives while
 * preserving original responsive srcset/sizes attributes. Generates correct
 * server rewrite rules for Apache/LiteSpeed (mod_rewrite) and Nginx (map-based).
 *
 * @package OptiForge_Image_Optimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OFIO_Delivery
 */
class OFIO_Delivery {

	/**
	 * OFIO_Delivery constructor.
	 *
	 * Registers frontend filters only when the "serve next-gen" option is on.
	 */
	public function __construct() {
		if ( ! get_option( 'ofio_serve_webp', 1 ) ) {
			return;
		}

		add_filter( 'the_content',            array( $this, 'replace_images_in_content' ), 20 );
		add_filter( 'post_thumbnail_html',    array( $this, 'replace_thumbnail_html' ), 20, 5 );
		add_filter( 'wp_get_attachment_image', array( $this, 'filter_attachment_image' ), 20, 5 );
	}

	// -------------------------------------------------------------------------
	// Content Filters
	// -------------------------------------------------------------------------

	/**
	 * Replace <img> tags in post content with <picture> elements.
	 *
	 * FIX: Guards against RSS feeds, REST API payloads, preview modes, and
	 * admin context — all of which cannot consume <picture> syntax.
	 *
	 * @param string $content Post content HTML.
	 * @return string Modified content (or original on early bail).
	 */
	public function replace_images_in_content( string $content ): string {
		// FIX: RSS/Atom feeds break completely with <picture> tags.
		if ( is_feed() ) {
			return $content;
		}

		// FIX: Preview mode may load in restricted contexts (email preview, etc.).
		if ( is_preview() ) {
			return $content;
		}

		// FIX: REST API consumers (Mailchimp, headless front-ends) don't parse <picture>.
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return $content;
		}

		// FIX: Admin pages should never get modified output.
		if ( is_admin() ) {
			return $content;
		}

		if ( empty( $content ) ) {
			return $content;
		}

		$filtered = preg_replace_callback(
			'/(<picture[^>]*>.*?<\/picture>)|(<img\s[^>]*src=["\']([^"\']+\.(jpe?g|png|webp))["\'][^>]*>)/is',
			function ( array $matches ): string {
				// Already wrapped in <picture> — leave as-is.
				if ( ! empty( $matches[1] ) ) {
					return $matches[1];
				}
				return $this->maybe_wrap_in_picture( $matches[2] );
			},
			$content
		);

		return ( null !== $filtered ) ? $filtered : $content;
	}

	/**
	 * Wrap post thumbnail HTML in a <picture> element.
	 *
	 * @param string $html         The thumbnail HTML string.
	 * @param int    $post_id      Post ID.
	 * @param int    $thumbnail_id Thumbnail attachment ID.
	 * @param mixed  $size         Registered image size name or dimensions array.
	 * @param mixed  $attr         Attributes passed to get_the_post_thumbnail().
	 * @return string
	 */
	public function replace_thumbnail_html( string $html, int $post_id, int $thumbnail_id, $size, $attr ): string {
		if ( is_feed() || is_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_admin() ) {
			return $html;
		}
		return $this->maybe_wrap_in_picture( $html );
	}

	/**
	 * Wrap wp_get_attachment_image() output in a <picture> element.
	 *
	 * @param string $html          Generated <img> HTML.
	 * @param int    $attachment_id Attachment post ID.
	 * @param mixed  $size          Image size.
	 * @param bool   $icon          Whether the image is an icon.
	 * @param array  $attr          Array of HTML attributes.
	 * @return string
	 */
	public function filter_attachment_image( string $html, int $attachment_id, $size, bool $icon, array $attr ): string {
		if ( is_feed() || is_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_admin() ) {
			return $html;
		}
		return $this->maybe_wrap_in_picture( $html );
	}

	// -------------------------------------------------------------------------
	// Core Picture-Wrapping Logic
	// -------------------------------------------------------------------------

	/**
	 * Wrap an <img> tag in a <picture> element with WebP/AVIF <source> alternatives.
	 *
	 * FIX (LCP / CORE WEB VITALS): Preserves the original srcset and sizes
	 * attributes by parsing them from the <img> and generating matching srcset
	 * strings on the <source> elements. Legacy browsers and bots fall back to
	 * the original <img> which is always present as the last child.
	 *
	 * @param string $img_html The raw <img> HTML string.
	 * @return string Original HTML, or wrapped in <picture> if sidecars exist.
	 */
	private function maybe_wrap_in_picture( string $img_html ): string {
		if ( empty( $img_html ) || false !== stripos( $img_html, '<picture' ) ) {
			return $img_html;
		}

		// Extract src.
		if ( ! preg_match( '/src=["\']([^"\']+\.(jpe?g|png|webp))["\']/i', $img_html, $src_matches ) ) {
			return $img_html;
		}
		$src = $src_matches[1];

		$src_info = pathinfo( $src );
		$src_base = $src_info['dirname'] . '/' . $src_info['filename'];

		// Support Nginx appended sidecar ($path . '.webp') first, fallback to replaced
		$webp_url  = $src . '.webp';
		$webp_path = $this->url_to_path( $webp_url );
		if ( ! $webp_path || ! file_exists( $webp_path ) ) {
			$webp_url  = $src_base . '.webp';
			$webp_path = $this->url_to_path( $webp_url );
		}

		$avif_url  = $src . '.avif';
		$avif_path = $this->url_to_path( $avif_url );
		if ( ! $avif_path || ! file_exists( $avif_path ) ) {
			$avif_url  = $src_base . '.avif';
			$avif_path = $this->url_to_path( $avif_url );
		}

		// Extract existing srcset and sizes from the <img> tag.
		$srcset = '';
		$sizes  = '';
		if ( preg_match( '/\ssrcset=["\']([^"\']+)["\']/i', $img_html, $srcset_matches ) ) {
			$srcset = $srcset_matches[1];
		}
		if ( preg_match( '/\ssizes=["\']([^"\']+)["\']/i', $img_html, $sizes_matches ) ) {
			$sizes = $sizes_matches[1];
		}

		$sources = '';

		// Build AVIF <source> if sidecar exists.
		if ( $avif_path && file_exists( $avif_path ) ) {
			$avif_srcset = $this->build_next_gen_srcset( $srcset, 'avif' );
			$source_attr = 'srcset="' . esc_attr( $avif_url ) . '"';
			if ( ! empty( $avif_srcset ) ) {
				$source_attr = 'srcset="' . esc_attr( $avif_srcset ) . '"';
			}
			if ( ! empty( $sizes ) ) {
				$source_attr .= ' sizes="' . esc_attr( $sizes ) . '"';
			}
			$sources .= '<source ' . $source_attr . ' type="image/avif">';
		}

		// Build WebP <source> if sidecar exists (skip if source is already WebP).
		if ( $webp_url !== $src && $webp_path && file_exists( $webp_path ) ) {
			$webp_srcset = $this->build_next_gen_srcset( $srcset, 'webp' );
			$source_attr = 'srcset="' . esc_attr( $webp_url ) . '"';
			if ( ! empty( $webp_srcset ) ) {
				$source_attr = 'srcset="' . esc_attr( $webp_srcset ) . '"';
			}
			if ( ! empty( $sizes ) ) {
				$source_attr .= ' sizes="' . esc_attr( $sizes ) . '"';
			}
			$sources .= '<source ' . $source_attr . ' type="image/webp">';
		}

		if ( empty( $sources ) ) {
			return $img_html;
		}

		// Wrap: <source> elements first, then the original <img> as fallback.
		return '<picture>' . $sources . $img_html . '</picture>';
	}

	/**
	 * Rewrite a WordPress srcset attribute string to point to next-gen variants.
	 *
	 * Replaces .jpg/.jpeg/.png extensions in each srcset candidate URL with the
	 * target extension (.webp or .avif), then returns the rebuilt srcset string.
	 * Only rewrites candidates whose sidecar file actually exists on disk.
	 *
	 * @param string $srcset    Original srcset attribute value.
	 * @param string $extension Target extension without dot ('webp' or 'avif').
	 * @return string Rebuilt srcset, or empty string if no candidates converted.
	 */
	private function build_next_gen_srcset( string $srcset, string $extension ): string {
		if ( empty( $srcset ) ) {
			return '';
		}

		$candidates     = array_map( 'trim', explode( ',', $srcset ) );
		$new_candidates = array();

		foreach ( $candidates as $candidate ) {
			// Each candidate is: "url 123w" or "url 2x".
			$parts = preg_split( '/\s+/', trim( $candidate ), 2 );
			if ( empty( $parts[0] ) ) {
				continue;
			}

			$url        = $parts[0];
			$descriptor = $parts[1] ?? '';
			$url_info   = pathinfo( $url );

			// Only convert JPEG/PNG candidates.
			$ext = strtolower( $url_info['extension'] ?? '' );
			if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ) {
				continue;
			}

			// Check appended first, then replaced extension
			$new_url  = $url . '.' . $extension;
			$new_path = $this->url_to_path( $new_url );

			if ( ! $new_path || ! file_exists( $new_path ) ) {
				$new_url  = $url_info['dirname'] . '/' . $url_info['filename'] . '.' . $extension;
				$new_path = $this->url_to_path( $new_url );
			}

			if ( $new_path && file_exists( $new_path ) ) {
				$new_candidates[] = trim( $new_url . ' ' . $descriptor );
			}
		}

		return implode( ', ', $new_candidates );
	}

	// -------------------------------------------------------------------------
	// URL → Filesystem Path Mapping
	// -------------------------------------------------------------------------

	/**
	 * Convert an uploads-directory URL to its absolute filesystem path.
	 *
	 * Uses wp_upload_dir() for correct multisite / custom upload directory
	 * support rather than a hard-coded '/wp-content/uploads/' substring.
	 *
	 * @param string $url Absolute URL within the uploads directory.
	 * @return string|null Absolute filesystem path, or null if URL not in uploads.
	 */
	private function url_to_path( string $url ): ?string {
		$upload_dir = wp_upload_dir();
		$baseurl    = trailingslashit( $upload_dir['baseurl'] );
		$basedir    = trailingslashit( $upload_dir['basedir'] );

		// Strip query strings / fragments first.
		$url_clean = strtok( $url, '?' );

		if ( 0 === strpos( $url_clean, $baseurl ) ) {
			return $basedir . substr( $url_clean, strlen( $baseurl ) );
		}

		return null;
	}

	// -------------------------------------------------------------------------
	// Server Rule Generators
	// -------------------------------------------------------------------------

	/**
	 * Return Apache / LiteSpeed mod_rewrite rules.
	 *
	 * Rules check %{HTTP_ACCEPT} before serving next-gen alternatives, so
	 * browsers that don't support AVIF/WebP always receive the original format.
	 * LiteSpeed Web Server understands mod_rewrite natively; these rules are
	 * also fully compatible with LiteSpeed Cache (LSCache) without header
	 * collision because Vary: Accept is added via the E=accept:1 environment flag.
	 *
	 * @return string
	 */
	public static function get_apache_rules(): string {
		return <<<'HTACCESS'
# BEGIN OptiForge Image Optimizer Direct Delivery
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Serve AVIF if browser supports it and an .avif file exists
    RewriteCond %{HTTP_ACCEPT} image/avif
    RewriteCond %{REQUEST_FILENAME} ^(.+)\.(jpe?g|png|webp)$
    RewriteCond %{REQUEST_FILENAME}.avif -f
    RewriteRule ^(.+)\.(jpe?g|png|webp)$ $0.avif [T=image/avif,E=accept:1,L]

    # Serve WebP if browser supports it and a .webp file exists
    RewriteCond %{HTTP_ACCEPT} image/webp
    RewriteCond %{REQUEST_FILENAME} ^(.+)\.(jpe?g|png)$
    RewriteCond %{REQUEST_FILENAME}.webp -f
    RewriteRule ^(.+)\.(jpe?g|png)$ $0.webp [T=image/webp,E=accept:1,L]

    # Add Vary: Accept header so CDNs/proxies cache both variants separately
    Header append Vary Accept env=accept
</IfModule>
# END OptiForge Image Optimizer Direct Delivery
HTACCESS;
	}

	/**
	 * Return production-ready Nginx configuration directives.
	 *
	 * Map-based configuration checking Accept header support and matching
	 * the sidecar naming scheme ${uri}${ofio_suffix}.
	 *
	 * Place the map{} blocks in your nginx.conf http{} section, and the
	 * location{} block inside your server{} block.
	 *
	 * @return string
	 */
	public static function get_nginx_rules(): string {
		return <<<'NGINX'
# -----------------------------------------------------------------------
# OptiForge Image Optimizer — Nginx Configuration
# Add the map{} blocks to your http{} context (nginx.conf or conf.d/).
# Add the location{} block to your server{} context (site vhost config).
# -----------------------------------------------------------------------

# http {} context — detect Accept header support:

map $http_accept $ofio_avif_suffix {
    default        "";
    "~*image/avif" ".avif";
}

map $http_accept $ofio_webp_suffix {
    default        "";
    "~*image/webp" ".webp";
}

# server {} context — serve next-gen if supported and file exists:

location ~* ^(/wp-content/uploads/.+)\.(jpe?g|png|webp)$ {
    add_header Vary Accept;

    # Prefer AVIF over WebP; fall back to original if neither exists.
    try_files
        ${uri}${ofio_avif_suffix}
        ${uri}${ofio_webp_suffix}
        $uri
        =404;
}
NGINX;
	}
}