<?php
/* Score dashboard */
global $wpdb;
$avg_seo = round( floatval( $wpdb->get_var( $wpdb->prepare( "SELECT AVG(CAST(meta_value AS DECIMAL(5,1))) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_meesho_seo_score' ) ) ) );
$avg_aeo = round( floatval( $wpdb->get_var( $wpdb->prepare( "SELECT AVG(CAST(meta_value AS DECIMAL(5,1))) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_meesho_aeo_score' ) ) ) );
$avg_geo = round( floatval( $wpdb->get_var( $wpdb->prepare( "SELECT AVG(CAST(meta_value AS DECIMAL(5,1))) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_meesho_geo_score' ) ) ) );
$pending  = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}meesho_seo_suggestions WHERE status = %s", 'pending' ) ) );
$applied  = intval( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}meesho_seo_suggestions WHERE status = %s", 'applied' ) ) );

if ( ! function_exists( 'mm_score_class' ) ) {
	function mm_score_class( $s ) { return $s >= 70 ? 'score-high' : ( $s >= 40 ? 'score-med' : 'score-low' ); }
}
?>

<!-- Score Dashboard -->
<div class="mm-grid mm-grid-4 mm-mb-20">
	<div class="mm-stat-card">
		<div class="mm-stat-value"><?php echo $avg_seo; ?></div>
		<div class="mm-stat-label">Avg SEO Score</div>
		<div class="mm-score-bar mm-mt-10"><div class="mm-score-bar-track"><div class="mm-score-bar-fill <?php echo mm_score_class($avg_seo); ?>" style="width:<?php echo $avg_seo; ?>%"></div></div></div>
	</div>
	<div class="mm-stat-card">
		<div class="mm-stat-value"><?php echo $avg_aeo; ?></div>
		<div class="mm-stat-label">Avg AEO Score</div>
		<div class="mm-score-bar mm-mt-10"><div class="mm-score-bar-track"><div class="mm-score-bar-fill <?php echo mm_score_class($avg_aeo); ?>" style="width:<?php echo $avg_aeo; ?>%"></div></div></div>
	</div>
	<div class="mm-stat-card">
		<div class="mm-stat-value"><?php echo $avg_geo; ?></div>
		<div class="mm-stat-label">Avg GEO Score</div>
		<div class="mm-score-bar mm-mt-10"><div class="mm-score-bar-track"><div class="mm-score-bar-fill <?php echo mm_score_class($avg_geo); ?>" style="width:<?php echo $avg_geo; ?>%"></div></div></div>
	</div>
	<div class="mm-stat-card">
		<div class="mm-stat-value"><?php echo $pending; ?></div>
		<div class="mm-stat-label">Pending Suggestions</div>
		<p class="mm-text-muted" style="font-size:11px; margin:4px 0 0;"><?php echo $applied; ?> applied total</p>
	</div>
</div>

<!-- Filters -->
<div class="mm-flex-between mm-mb-20">
	<h3 style="margin:0;">🔍 SEO / AEO / GEO Suggestions</h3>
	<div class="mm-flex mm-gap-10">
		<select id="seo_priority_filter" class="mm-select" style="width:140px;" onchange="MeeshoMaster.loadSuggestions()">
			<option value="">All Priorities</option>
			<option value="high">High</option>
			<option value="medium">Medium</option>
			<option value="low">Low</option>
		</select>
		<select id="seo_type_filter" class="mm-select" style="width:160px;" onchange="MeeshoMaster.loadSuggestions()">
			<option value="">All Types</option>
			<option value="meta_title">Meta Title</option>
			<option value="meta_desc">Meta Description</option>
			<option value="alt_tag">Alt Tag</option>
			<option value="schema">Schema</option>
			<option value="faq">FAQ</option>
			<option value="content">Content</option>
			<option value="internal_link">Internal Link</option>
			<option value="citability_block">Citability</option>
		</select>
		<button class="mm-btn mm-btn-success" onclick="MeeshoMaster.applyAllSafe()">✅ Apply All Safe Fixes</button>
		<button class="mm-btn mm-btn-outline" onclick="MeeshoMaster.loadSuggestions()">🔄 Refresh</button>
	</div>
</div>

<!-- Suggestions Table -->
<div class="mm-card" style="padding:0; overflow-x:auto;">
	<table class="mm-table">
		<thead>
			<tr>
				<th>Post ID</th>
				<th>Type</th>
				<th>Current → Suggested</th>
				<th>Confidence</th>
				<th>Priority</th>
				<th>Actions</th>
			</tr>
		</thead>
		<tbody id="seo_suggestions_body">
			<tr><td colspan="6" style="text-align:center; padding:30px;" class="mm-text-muted">Loading suggestions...</td></tr>
		</tbody>
	</table>
</div>

<!-- Bottom tools -->
<div class="mm-grid mm-grid-2 mm-mt-20">
	<div class="mm-card">
		<h3>🤖 Manual Crawl</h3>
		<p class="mm-text-muted">Run a SEO/AEO/GEO batch analysis now (processes 5-10 pages).</p>
		<button class="mm-btn mm-btn-primary" onclick="MeeshoMaster.ajax('meesho_run_seo_crawl',{},function(m){MeeshoMaster.toast(m,'success');MeeshoMaster.loadSuggestions();location.reload();})">
			▶️ Run Batch Now
		</button>
	</div>
	<div class="mm-card">
		<h3>📄 llms.txt</h3>
		<p class="mm-text-muted">Generate or update the AI crawler access rules file at <code>/llms.txt</code>.</p>
		<button class="mm-btn mm-btn-outline" id="btn_generate_llms">Generate llms.txt</button>
	</div>
</div>
