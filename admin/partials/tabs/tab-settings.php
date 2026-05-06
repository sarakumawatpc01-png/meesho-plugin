<?php
$settings = new Meesho_Master_Settings();
$all = $settings->get_all();
$accounts = $settings->get_accounts();
?>
<h3>⚙️ Settings</h3>

<form id="meesho_settings_form" onsubmit="return false;">
<div class="mm-grid mm-grid-2">

	<!-- API Keys -->
	<div class="mm-card">
		<h3>🔑 API Keys & Integrations</h3>
		<div class="mm-form-row">
			<label class="mm-label">OpenRouter API Key</label>
			<input type="password" name="openrouter_api_key" class="mm-input" value="<?php echo esc_attr( $settings->get('openrouter_api_key') ); ?>" placeholder="sk-or-...">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Scrapling Service URL</label>
			<input type="text" name="scrapling_url" class="mm-input" value="<?php echo esc_attr( $all['scrapling_url'] ); ?>">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Scrapling Timeout (seconds)</label>
			<input type="number" name="scrapling_timeout" class="mm-input" value="<?php echo esc_attr( $all['scrapling_timeout'] ); ?>">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Hotjar Site ID</label>
			<input type="text" name="hotjar_site_id" class="mm-input" value="<?php echo esc_attr( $all['hotjar_site_id'] ); ?>">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">DataForSEO Login</label>
			<input type="text" name="dataforseo_login" class="mm-input" value="<?php echo esc_attr( $settings->get('dataforseo_login') ); ?>">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">DataForSEO Password</label>
			<input type="password" name="dataforseo_password" class="mm-input" value="<?php echo esc_attr( $settings->get('dataforseo_password') ); ?>">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Firecrawl API Key</label>
			<input type="password" name="firecrawl_api_key" class="mm-input" value="<?php echo esc_attr( $settings->get('firecrawl_api_key') ); ?>">
		</div>
	</div>

	<!-- Pricing -->
	<div class="mm-card">
		<h3>💰 Pricing Rules</h3>
		<div class="mm-form-row">
			<label class="mm-label">Markup Type</label>
			<select name="pricing_markup_type" class="mm-select">
				<option value="percentage" <?php selected( $all['pricing_markup_type'], 'percentage' ); ?>>Percentage (%)</option>
				<option value="flat" <?php selected( $all['pricing_markup_type'], 'flat' ); ?>>Flat Amount (₹)</option>
			</select>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Markup Value</label>
			<input type="number" name="pricing_markup_value" class="mm-input" value="<?php echo esc_attr( $all['pricing_markup_value'] ); ?>" step="0.01">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Rounding Rule</label>
			<select name="pricing_rounding" class="mm-select">
				<option value="none" <?php selected( $all['pricing_rounding'], 'none' ); ?>>No Rounding</option>
				<option value="1" <?php selected( $all['pricing_rounding'], '1' ); ?>>Round to ₹1</option>
				<option value="5" <?php selected( $all['pricing_rounding'], '5' ); ?>>Round to ₹5</option>
				<option value="9" <?php selected( $all['pricing_rounding'], '9' ); ?>>Round to ₹9 (e.g. 199, 299)</option>
				<option value="10" <?php selected( $all['pricing_rounding'], '10' ); ?>>Round to ₹10</option>
			</select>
		</div>

		<h3 class="mm-mt-20">📦 COD Risk Settings</h3>
		<div class="mm-form-row">
			<label class="mm-label">COD Risk Threshold (₹)</label>
			<input type="number" name="cod_risk_threshold" class="mm-input" value="<?php echo esc_attr( $all['cod_risk_threshold'] ); ?>">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Repeat Order Window (hours)</label>
			<input type="number" name="cod_repeat_window_hrs" class="mm-input" value="<?php echo esc_attr( $all['cod_repeat_window_hrs'] ); ?>">
		</div>
	</div>

	<!-- AI Models -->
	<div class="mm-card">
		<h3>🤖 AI Model Assignments</h3>
		<?php
		$tasks = array( 'seo' => 'SEO Analysis', 'blog' => 'Blog Generation', 'image' => 'Image Generation', 'copilot' => 'Copilot Chat', 'schema' => 'Schema Generation', 'aeo' => 'AEO Analysis', 'geo' => 'GEO Analysis' );
		foreach ( $tasks as $key => $label ) :
		?>
		<div class="mm-form-row">
			<label class="mm-label"><?php echo $label; ?></label>
			<input type="text" name="ai_model_<?php echo $key; ?>" class="mm-input" value="<?php echo esc_attr( $all["ai_model_{$key}"] ); ?>" placeholder="e.g. openai/gpt-4o">
		</div>
		<?php endforeach; ?>
		<div class="mm-form-row">
			<label><input type="checkbox" name="ai_show_free_only" value="yes" <?php checked( $all['ai_show_free_only'], 'yes' ); ?>> Show only free models</label>
		</div>

		<h3 class="mm-mt-20">🤖 Copilot</h3>
		<div class="mm-form-row">
			<label><input type="checkbox" name="copilot_auto_implement" value="yes" <?php checked( $all['copilot_auto_implement'], 'yes' ); ?>> Auto-implement safe suggestions</label>
		</div>
	</div>

	<!-- Automation & Email -->
	<div class="mm-card">
		<h3>⏰ Automation Schedule</h3>
		<div class="mm-form-row">
			<label><input type="checkbox" name="automation_enabled" value="yes" <?php checked( $all['automation_enabled'], 'yes' ); ?>> Enable automated SEO/AEO/GEO processing</label>
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Batch Size (pages per run)</label>
			<input type="number" name="automation_batch_size" class="mm-input" value="<?php echo esc_attr( $all['automation_batch_size'] ); ?>" min="1" max="10">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">API Delay Between Requests (ms)</label>
			<input type="number" name="automation_delay_ms" class="mm-input" value="<?php echo esc_attr( $all['automation_delay_ms'] ); ?>">
		</div>

		<h3 class="mm-mt-20">📧 Email Reports</h3>
		<div class="mm-form-row">
			<label class="mm-label">Recipients (comma-separated)</label>
			<input type="text" name="email_recipients" class="mm-input" value="<?php echo esc_attr( $all['email_recipients'] ); ?>" placeholder="admin@example.com">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">From Email Override</label>
			<input type="email" name="email_from_override" class="mm-input" value="<?php echo esc_attr( $all['email_from_override'] ); ?>">
		</div>
		<div class="mm-form-row">
			<label class="mm-label">Report Frequency</label>
			<select name="email_frequency" class="mm-select">
				<option value="daily" <?php selected( $all['email_frequency'], 'daily' ); ?>>Daily</option>
				<option value="weekly" <?php selected( $all['email_frequency'], 'weekly' ); ?>>Weekly</option>
			</select>
		</div>
		<button type="button" class="mm-btn mm-btn-outline" id="btn_test_email">🧪 Send Test Email</button>
	</div>

</div><!-- /.mm-grid -->

<!-- llms.txt -->
<div class="mm-card mm-mt-20">
	<h3>🤖 llms.txt Configuration</h3>
	<div class="mm-form-row">
		<label class="mm-label">AI Bot Access Rules</label>
		<textarea name="llms_txt_config" class="mm-textarea" rows="8"><?php echo esc_textarea( $all['llms_txt_config'] ); ?></textarea>
	</div>
	<button type="button" class="mm-btn mm-btn-outline" id="btn_generate_llms">📄 Generate llms.txt</button>
</div>

<div class="mm-card mm-mt-20">
	<h3>🤖 llms.txt Preview</h3>
	<pre id="llms_preview" style="max-height:240px; overflow:auto; white-space:pre-wrap; background:#0f172a; color:#e2e8f0; padding:12px; border-radius:8px;"><?php echo esc_html( MM_SEO_Geo::get_llms_txt_content() ); ?></pre>
</div>

<div class="mm-mt-20">
	<button type="submit" class="mm-btn mm-btn-primary" id="btn_save_settings">💾 Save All Settings</button>
</div>
</form>

<!-- Meesho Accounts Section -->
<div class="mm-card mm-mt-20">
	<h3>🏪 Meesho Seller Accounts (up to 4)</h3>
	<p class="mm-text-muted">Account credentials are encrypted at rest.</p>
	<?php for ( $i = 0; $i < 4; $i++ ) : $acc = $accounts[$i] ?? array(); ?>
	<div style="border:1px solid var(--mm-border); border-radius:8px; padding:12px; margin-bottom:12px;">
		<strong>Account <?php echo $i + 1; ?></strong>
		<div class="mm-grid mm-grid-4 mm-mt-10">
			<div class="mm-form-row"><label class="mm-label">Label</label><input type="text" class="mm-input meesho-acc-label" value="<?php echo esc_attr( $acc['label'] ?? '' ); ?>"></div>
			<div class="mm-form-row"><label class="mm-label">Email</label><input type="email" class="mm-input meesho-acc-email" value="<?php echo esc_attr( $acc['email'] ?? '' ); ?>"></div>
			<div class="mm-form-row"><label class="mm-label">Phone</label><input type="text" class="mm-input meesho-acc-phone" value="<?php echo esc_attr( $acc['phone'] ?? '' ); ?>"></div>
			<div class="mm-form-row"><label class="mm-label">Notes</label><input type="text" class="mm-input meesho-acc-notes" value="<?php echo esc_attr( $acc['notes'] ?? '' ); ?>"></div>
		</div>
	</div>
	<?php endfor; ?>
	<button type="button" class="mm-btn mm-btn-outline" id="btn_save_accounts">💾 Save Accounts</button>
</div>
