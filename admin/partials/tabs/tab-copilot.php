<div class="mm-chat-container">
	<div class="mm-chat-header">
		<span>🤖 Meesho Copilot</span>
		<select id="copilot_model_select" class="mm-select" style="width:200px; padding:4px 8px; font-size:12px; border-radius:6px; border:none;">
			<option value="">Default Model</option>
			<option value="openai/gpt-4o">GPT-4o</option>
			<option value="openai/gpt-3.5-turbo">GPT-3.5 Turbo</option>
			<option value="anthropic/claude-3.5-sonnet">Claude 3.5 Sonnet</option>
			<option value="google/gemini-pro">Gemini Pro</option>
			<option value="meta-llama/llama-3-70b-instruct">Llama 3 70B</option>
		</select>
	</div>
	<div class="mm-chat-messages" id="copilot_chat_history">
		<div class="mm-chat-msg mm-chat-msg-bot">
			👋 Hello! I'm your Meesho Master Copilot. I can help you manage products, optimize SEO, track orders, and more. How can I help today?
		</div>
	</div>
	<div class="mm-chat-input-area">
		<input type="text" id="copilot_input" placeholder="Ask Copilot anything..." autocomplete="off">
		<button class="mm-btn mm-btn-primary" id="btn_copilot_send">Send</button>
	</div>
</div>

<?php
$settings = new Meesho_Master_Settings();
$auto = $settings->get( 'copilot_auto_implement' );
?>
<div class="mm-card mm-mt-20 mm-flex-between">
	<div>
		<strong>Auto-Implement Mode:</strong>
		<span class="mm-badge <?php echo $auto === 'yes' ? 'mm-badge-success' : 'mm-badge-info'; ?>">
			<?php echo $auto === 'yes' ? 'ON — Safe actions auto-applied' : 'OFF — All actions need approval'; ?>
		</span>
	</div>
	<p class="mm-text-muted" style="margin:0; font-size:12px;">Change in Settings tab</p>
</div>
