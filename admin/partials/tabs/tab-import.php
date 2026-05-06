<div class="mm-card">
	<h3>📦 Import Products from Meesho</h3>
	<p class="mm-text-muted">Import products directly from Meesho into WooCommerce with automatic pricing markup.</p>
</div>

<div class="mm-grid mm-grid-2">
	<div class="mm-card">
		<h3>🔗 Method 1: Import by URL</h3>
		<p class="mm-text-muted">Paste a Meesho product URL. Requires Scrapling service to be running.</p>
		<div class="mm-form-row">
			<label class="mm-label">Meesho Product URL</label>
			<input type="text" id="meesho_url" class="mm-input" placeholder="https://www.meesho.com/product-name/p/397655651">
		</div>
		<button class="mm-btn mm-btn-primary" id="btn_import_url">🚀 Import via URL</button>
	</div>

	<div class="mm-card">
		<h3>📋 Method 2: Paste HTML (Fallback)</h3>
		<p class="mm-text-muted">If Scrapling is unavailable, paste the full page source here.</p>
		<div class="mm-form-row">
			<label class="mm-label">HTML Source</label>
			<textarea id="meesho_html" class="mm-textarea" rows="5" placeholder="<html>...</html>"></textarea>
		</div>
		<button class="mm-btn mm-btn-outline" id="btn_import_html">📋 Parse HTML</button>
	</div>
</div>

<div class="mm-card mm-hidden" id="manual_sku_section">
	<h3>⚠️ Manual SKU Entry</h3>
	<p class="mm-text-muted">SKU could not be extracted automatically. Please enter the numeric Meesho SKU.</p>
	<div class="mm-form-row" style="max-width: 300px;">
		<label class="mm-label">Meesho SKU</label>
		<input type="text" id="manual_sku_input" class="mm-input" placeholder="e.g. 397655651">
	</div>
	<button class="mm-btn mm-btn-primary" id="btn_manual_sku">Submit SKU</button>
</div>

<div id="import_results" class="mm-mt-20"></div>
