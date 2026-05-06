<?php

class Meesho_Master {
protected $plugin_name;
protected $version;

public function __construct() {
$this->plugin_name = 'meesho-master';
$this->version     = MEESHO_MASTER_VERSION;

$this->load_dependencies();
MM_DB::maybe_upgrade();
$this->define_admin_hooks();
$this->define_public_hooks();
$this->define_cron_hooks();
}

private function load_dependencies() {
require_once MEESHO_MASTER_PLUGIN_DIR . 'admin/class-meesho-admin.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-db.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-crypto.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-logger.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-dataforseo.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-seo-crawler.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-seo-scorer.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-seo-safety.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-seo-geo.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-schema-generator.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-seo-implementor.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/class-mm-seo-analyzer.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-settings.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-undo.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-import.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-orders.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-seo.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-seo-analyzer.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-copilot.php';
require_once MEESHO_MASTER_PLUGIN_DIR . 'includes/modules/class-meesho-analytics.php';
}

private function define_admin_hooks() {
$plugin_admin = new Meesho_Master_Admin( $this->get_plugin_name(), $this->get_version() );
add_action( 'admin_menu', array( $plugin_admin, 'add_plugin_admin_menu' ) );
add_action( 'admin_enqueue_scripts', array( $plugin_admin, 'enqueue_styles' ) );
add_action( 'admin_enqueue_scripts', array( $plugin_admin, 'enqueue_scripts' ) );

new Meesho_Master_Settings();
new Meesho_Master_Undo();
new Meesho_Master_Import();
new Meesho_Master_Orders();
new Meesho_Master_SEO();
new Meesho_Master_Copilot();
new Meesho_Master_Analytics();
}

private function define_public_hooks() {
add_action( 'wp_head', array( 'Meesho_Master_SEO', 'inject_schema' ) );
add_action( 'wp_head', array( 'Meesho_Master_SEO', 'inject_fallback_meta' ) );
add_shortcode( 'meesho_reviews', array( $this, 'shortcode_reviews' ) );
}

private function define_cron_hooks() {
add_action( 'admin_notices', array( $this, 'check_wp_cron_health' ) );
}

public function check_wp_cron_health() {
if ( ! current_user_can( 'manage_options' ) ) {
return;
}
if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
echo '<div class="notice notice-warning is-dismissible"><p><strong>Meesho Master:</strong> WP Cron is disabled. SEO automation and reports may not run.</p></div>';
}
}

public function shortcode_reviews( $atts ) {
$atts = shortcode_atts( array( 'sku' => '' ), $atts );
$sku  = $atts['sku'];
if ( empty( $sku ) && function_exists( 'wc_get_product' ) && is_singular( 'product' ) ) {
$sku = get_post_meta( get_the_ID(), '_meesho_sku', true );
}
if ( empty( $sku ) ) {
return '<p>No Meesho SKU found.</p>';
}

$breakdown = get_post_meta( get_the_ID(), '_meesho_rating_breakdown', true );
$avg       = get_post_meta( get_the_ID(), '_meesho_avg_rating', true );
$count     = get_post_meta( get_the_ID(), '_meesho_review_count', true );
if ( ! is_array( $breakdown ) ) {
$breakdown = array( '5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0 );
}

$colors = array( '5' => '#10B981', '4' => '#34D399', '3' => '#F59E0B', '2' => '#F97316', '1' => '#EF4444' );
$html   = '<div class="meesho-review-breakdown" style="max-width:400px; font-family:Arial,sans-serif;">';
$html  .= '<div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">';
$html  .= '<span style="font-size:36px; font-weight:700; color:#1E293B;">' . esc_html( $avg ?: '0' ) . '</span>';
$html  .= '<div><div style="color:#F59E0B; font-size:18px;">★★★★★</div>';
$html  .= '<small style="color:#64748B;">' . intval( $count ) . ' reviews</small></div></div>';
for ( $star = 5; $star >= 1; $star-- ) {
$pct   = intval( $breakdown[ (string) $star ] ?? 0 );
$html .= '<div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">';
$html .= '<span style="width:16px; font-size:13px; font-weight:600;">' . $star . '</span>';
$html .= '<div style="flex:1; height:10px; background:#E2E8F0; border-radius:5px; overflow:hidden;">';
$html .= '<div style="width:' . $pct . '%; height:100%; background:' . $colors[ (string) $star ] . '; border-radius:5px;"></div></div>';
$html .= '<span style="width:32px; font-size:12px; color:#64748B; text-align:right;">' . $pct . '%</span>';
$html .= '</div>';
}
$html .= '</div>';

global $wpdb;
$reviews = $wpdb->get_results(
$wpdb->prepare(
'SELECT * FROM ' . MM_DB::table( 'reviews' ) . ' WHERE meesho_sku = %s ORDER BY id DESC LIMIT 5',
$sku
)
);
if ( $reviews ) {
$html .= '<div style="margin-top:16px;">';
foreach ( $reviews as $review ) {
$stars  = str_repeat( '★', intval( $review->star_rating ) ) . str_repeat( '☆', 5 - intval( $review->star_rating ) );
$date   = $review->review_date ? mysql2date( 'd/m/Y', $review->review_date ) : '';
$html  .= '<div style="border-top:1px solid #E2E8F0; padding:10px 0;">';
$html  .= '<strong>' . esc_html( $review->reviewer_name ) . '</strong> <span style="color:#F59E0B;">' . $stars . '</span>';
$html  .= '<br><small style="color:#64748B;">' . esc_html( $date ) . '</small>';
if ( ! empty( $review->review_text ) ) {
$html .= '<p style="margin:4px 0 0; font-size:14px;">' . esc_html( $review->review_text ) . '</p>';
}
$html .= '</div>';
}
$html .= '</div>';
}

return $html;
}

public function run() {}
public function get_plugin_name() { return $this->plugin_name; }
public function get_version() { return $this->version; }
}
