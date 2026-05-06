<?php $settings = new Meesho_Master_Settings(); ?>
<div class="mm-grid mm-grid-2">
	<div class="mm-card">
		<h3>🔥 Heatmaps (Hotjar)</h3>
		<?php
		$site_id = $settings->get( 'hotjar_site_id' );
		if ( ! empty( $site_id ) ) :
		?>
			<iframe src="https://insights.hotjar.com/sites/<?php echo intval( $site_id ); ?>/heatmaps" 
				style="width:100%; height:400px; border:1px solid var(--mm-border); border-radius:8px;" loading="lazy"></iframe>
			<div class="mm-mt-10">
				<button class="mm-btn mm-btn-primary"
					onclick="MeeshoMaster.ajax('meesho_get_heatmap_insights',{},function(d){
						var html='';
						(d.insights||[]).forEach(function(i){
							html+='<div class=\'mm-flex-between mm-mb-10\'><span>'+i.suggestion+'</span><span class=\'mm-badge mm-badge-'+(i.priority==='high'?'danger':i.priority==='medium'?'warning':'info')+'\'>'+i.priority+'</span></div>';
						});
						$('#heatmap_insights').html(html||'<p>No insights available.</p>');
					})">
					🤖 Generate AI Insights
				</button>
			</div>
			<div id="heatmap_insights" class="mm-mt-10"></div>
		<?php else : ?>
			<p class="mm-text-muted">Configure your Hotjar Site ID in Settings to enable heatmaps.</p>
		<?php endif; ?>
	</div>

	<div class="mm-card">
		<h3>📈 Ranking Tracking (Google Search Console)</h3>
		<?php if ( ! empty( $settings->get( 'gsc_refresh_token' ) ) ) : ?>
			<div class="mm-form-row mm-flex mm-gap-10">
				<input type="text" id="new_keyword" class="mm-input" placeholder="Enter a keyword to track...">
				<button class="mm-btn mm-btn-primary"
					onclick="MeeshoMaster.ajax('meesho_add_keyword',{keyword:$('#new_keyword').val()},function(d){MeeshoMaster.toast('Keyword tracked: '+d.keyword,'success');})">
					Add
				</button>
			</div>
			<div id="rankings_list" class="mm-mt-10">
				<p class="mm-text-muted">Tracked keywords will appear here.</p>
			</div>
		<?php else : ?>
			<p class="mm-text-muted">Connect Google Search Console in Settings to track rankings.</p>
		<?php endif; ?>
	</div>
</div>

<div class="mm-card mm-mt-20">
	<h3>📧 Email Reports</h3>
	<div class="mm-flex mm-gap-10">
		<button class="mm-btn mm-btn-primary"
			onclick="MeeshoMaster.ajax('meesho_send_report',{},function(m){MeeshoMaster.toast(m,'success');})">
			📤 Send Report Now
		</button>
		<button class="mm-btn mm-btn-outline" id="btn_test_email">🧪 Send Test Email</button>
	</div>
</div>
