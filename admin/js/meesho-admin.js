/**
 * Meesho Master v6 — Admin JavaScript
 * AJAX handlers, toast notifications, copilot chat, import flow, order management.
 */
(function($) {
'use strict';

var MM = window.MeeshoMaster = {};

/* ============================================================
   Toast Notifications
   ============================================================ */
MM.toast = function(msg, type) {
	type = type || 'info';
	var $container = $('.mm-toast-container');
	if (!$container.length) {
		$('body').append('<div class="mm-toast-container"></div>');
		$container = $('.mm-toast-container');
	}
	var $t = $('<div class="mm-toast mm-toast-' + type + '">' + msg + '</div>');
	$container.append($t);
	setTimeout(function(){ $t.remove(); }, 4000);
};

/* ============================================================
   Clipboard helper
   ============================================================ */
MM.copyText = function(text, label) {
	navigator.clipboard.writeText(text).then(function() {
		MM.toast((label || 'Text') + ' copied!', 'success');
	});
};

/* ============================================================
   Confirmation Modal
   ============================================================ */
MM.confirm = function(title, message, onConfirm) {
	var html = '<div class="mm-modal-overlay active" id="mm-confirm-modal">'
		+ '<div class="mm-modal"><h3>' + title + '</h3><p>' + message
		+ '</p><p class="mm-text-muted">This can be undone within 15 days.</p>'
		+ '<div class="mm-modal-actions">'
		+ '<button class="mm-btn mm-btn-outline" id="mm-modal-cancel">Cancel</button>'
		+ '<button class="mm-btn mm-btn-danger" id="mm-modal-confirm">Confirm</button>'
		+ '</div></div></div>';
	$('body').append(html);
	$('#mm-modal-cancel').on('click', function(){ $('#mm-confirm-modal').remove(); });
	$('#mm-modal-confirm').on('click', function(){ $('#mm-confirm-modal').remove(); onConfirm(); });
};

/* ============================================================
   Skeleton Loader helpers
   ============================================================ */
MM.showSkeleton = function(sel, rows) {
	rows = rows || 3;
	var html = '';
	for (var i = 0; i < rows; i++) {
		html += '<div class="mm-skeleton-row"><div class="mm-skeleton"></div><div class="mm-skeleton"></div><div class="mm-skeleton"></div></div>';
	}
	$(sel).html(html);
};

/* ============================================================
   Generic AJAX helper
   ============================================================ */
MM.ajax = function(action, data, onSuccess, onError) {
	data = data || {};
	data.action = action;
	data.nonce = meesho_ajax.nonce;
	$.post(meesho_ajax.ajax_url, data, function(resp) {
		if (resp.success) {
			if (onSuccess) onSuccess(resp.data);
		} else {
			var msg = typeof resp.data === 'object' ? (resp.data.message || JSON.stringify(resp.data)) : resp.data;
			MM.toast(msg, 'error');
			if (onError) onError(resp.data);
		}
	}).fail(function() {
		MM.toast('Request failed. Please try again.', 'error');
	});
};

/* ============================================================
   IMPORT TAB
   ============================================================ */
$(document).on('click', '#btn_import_url', function() {
	var url = $('#meesho_url').val().trim();
	if (!url) { MM.toast('Please enter a Meesho URL', 'error'); return; }
	$(this).prop('disabled', true).text('Importing...');
	MM.ajax('meesho_import_url', { url: url }, function(data) {
		$('#btn_import_url').prop('disabled', false).text('🚀 Import via URL');
		if (data.status === 'duplicate') {
			$('#import_results').html(
				'<div class="mm-card"><h3>⚠️ Duplicate Found</h3><p>' + data.message + '</p>'
				+ '<button class="mm-btn mm-btn-primary mm-btn-sm" onclick="MeeshoMaster.toast(\'Overwrite not yet wired\',\'info\')">Overwrite</button> '
				+ '<button class="mm-btn mm-btn-outline mm-btn-sm" onclick="$(\'#import_results\').html(\'\')">Skip</button></div>'
			);
		} else if (data.status === 'sku_missing') {
			$('#manual_sku_section').removeClass('mm-hidden');
			MM.toast('SKU could not be extracted. Please enter it manually.', 'error');
		} else {
			MM.toast(data.message, 'success');
			$('#import_results').html('<div class="mm-card"><p>✅ ' + data.message + '</p></div>');
		}
	}, function(err) {
		$('#btn_import_url').prop('disabled', false).text('🚀 Import via URL');
		if (err && err.fallback) {
			$('#import_results').html('<div class="mm-card" style="border-left:3px solid var(--mm-warning); padding:12px"><strong>⚠️ Scrapling unavailable.</strong> Please use the HTML paste method on the right.</div>');
		}
	});
});

$(document).on('click', '#btn_import_html', function() {
	var html = $('#meesho_html').val().trim();
	if (!html) { MM.toast('Please paste HTML source', 'error'); return; }
	$(this).prop('disabled', true).text('Parsing...');
	MM.ajax('meesho_import_html', { html: html, product_url: $('#meesho_url').val() || '' }, function(data) {
		$('#btn_import_html').prop('disabled', false).text('📋 Parse HTML');
		if (data.status === 'duplicate') {
			$('#import_results').html('<div class="mm-card"><h3>⚠️ Duplicate: ' + data.message + '</h3></div>');
		} else if (data.status === 'sku_missing') {
			$('#manual_sku_section').removeClass('mm-hidden');
			MM.toast('SKU could not be extracted. Please enter it manually.', 'error');
		} else {
			MM.toast(data.message, 'success');
			$('#import_results').html('<div class="mm-card"><p>✅ ' + data.message + '</p></div>');
		}
	}, function() {
		$('#btn_import_html').prop('disabled', false).text('📋 Parse HTML');
	});
});

$(document).on('click', '#btn_manual_sku', function() {
	var sku = $('#manual_sku_input').val().trim();
	if (!sku || !/^\d+$/.test(sku)) { MM.toast('Enter a valid numeric SKU', 'error'); return; }
	MM.ajax('meesho_manual_sku', { sku: sku, product_data: '{}' }, function(data) {
		MM.toast(data.message || 'Imported', 'success');
		$('#manual_sku_section').addClass('mm-hidden');
	});
});

/* ============================================================
   ORDERS TAB — enhanced with search, address copy, Order on Meesho
   ============================================================ */
MM._currentOrders = []; // cache for edit modal

MM.loadOrders = function(page) {
	page = page || 1;
	MM.showSkeleton('#orders_table_body');
	MM.ajax('meesho_get_orders', {
		page: page,
		status: $('#order_status_filter').val() || '',
		search: $('#order_search').val() || ''
	}, function(data) {
		MM._currentOrders = data.orders || [];
		var html = '';
		if (!data.orders || data.orders.length === 0) {
			html = '<tr><td colspan="8" style="text-align:center;">No orders found.</td></tr>';
		} else {
			data.orders.forEach(function(o) {
				var sla_class = o.sla_status === 'breached' ? 'mm-sla-breached' : '';
				var cod_class = o.cod_risk === 'high' ? 'mm-cod-high' : '';

				// Status badge color
				var sc = 'info';
				if (o.fulfillment_status === 'delivered') sc = 'success';
				else if (o.fulfillment_status === 'cancelled' || o.fulfillment_status === 'returned') sc = 'danger';
				else if (o.sla_status === 'breached') sc = 'danger';
				else if (o.fulfillment_status === 'dispatched') sc = 'purple';

				// Product info with SKU and sizes
				var items_html = '-';
				var meesho_sku = '';
				if (o.items && o.items.length) {
					items_html = o.items.map(function(i){
						if (i.sku) meesho_sku = i.sku.split('-')[0]; // parent SKU
						return '<strong>' + i.name + '</strong><br><small>SKU: ' + (i.sku||'-') + ' | Size: ' + (i.size||'-') + ' | Qty: ' + i.qty + '</small>';
					}).join('<br>');
				}

				// Customer with clipboard buttons for name, phone, address
				var addr = o.address || '';
				var cust_html = '<strong>' + (o.customer_name||'-') + '</strong>'
					+ ' <button class="mm-btn-icon mm-btn-sm" title="Copy name" onclick="MeeshoMaster.copyText(\'' + (o.customer_name||'').replace(/'/g,"\\'") + '\',\'Name\')">📋</button>'
					+ '<br><small>📱 ' + (o.phone||'-')
					+ ' <button class="mm-btn-icon mm-btn-sm" title="Copy phone" onclick="MeeshoMaster.copyText(\'' + (o.phone||'') + '\',\'Phone\')">📋</button></small>'
					+ '<br><small>📍 ' + addr.substring(0,40) + (addr.length > 40 ? '...' : '')
					+ ' <button class="mm-btn-icon mm-btn-sm" title="Copy address" onclick="MeeshoMaster.copyText(\'' + addr.replace(/'/g,"\\'").replace(/\n/g,' ') + '\',\'Address\')">📋</button></small>';

				// SLA badge
				var sla_html = o.sla_status === 'breached'
					? '<span class="mm-badge mm-badge-danger">⏰ BREACHED</span>'
					: '<span class="mm-badge mm-badge-success">OK</span>';

				// Actions column
				var actions_html = '<button class="mm-btn mm-btn-sm mm-btn-outline" onclick="MeeshoMaster.openOrderEdit(' + o.id + ')">✏️ Edit</button>';
				// "Order on Meesho" button — only for pending
				if (o.fulfillment_status === 'pending' && meesho_sku) {
					actions_html += ' <a href="https://www.meesho.com/p/' + meesho_sku + '" target="_blank" class="mm-btn mm-btn-sm mm-btn-primary">🛒 Order on Meesho</a>';
				}

				html += '<tr class="' + sla_class + ' ' + cod_class + '">'
					+ '<td>#' + o.wc_order_id + '<br><small>' + (o.created_at||'') + '</small></td>'
					+ '<td>' + items_html + '</td>'
					+ '<td>' + cust_html + '</td>'
					+ '<td>' + (o.payment_method || '-')
					  + (o.cod_risk === 'high' ? '<br><span class="mm-badge mm-badge-danger">⚠ RISK</span>' : '')
					  + '<br><small>₹' + (o.order_total || '0') + '</small></td>'
					+ '<td><span class="mm-badge mm-badge-' + sc + '">' + o.fulfillment_status.replace(/_/g,' ') + '</span>'
					  + (o.meesho_order_id ? '<br><small>M: ' + o.meesho_order_id + '</small>' : '')
					  + (o.tracking_id ? '<br><small>T: ' + o.tracking_id + '</small>' : '') + '</td>'
					+ '<td><small>' + (o.account_used || '-') + '</small></td>'
					+ '<td>' + sla_html + '</td>'
					+ '<td>' + actions_html + '</td>'
					+ '</tr>';
			});
		}
		$('#orders_table_body').html(html);
	});
};

/* Order Edit Modal */
MM.openOrderEdit = function(id) {
	var o = MM._currentOrders.find(function(x){ return x.id == id; });
	if (!o) { MM.toast('Order data not found', 'error'); return; }

	$('#oe-order-id').text(o.wc_order_id);
	$('#oe-status').val(o.fulfillment_status);
	$('#oe-meesho-id').val(o.meesho_order_id || '');
	$('#oe-tracking').val(o.tracking_id || '');
	$('#oe-notes').val('');
	$('#order-edit-modal').data('order-id', id);

	// Load accounts into dropdown
	MM.ajax('meesho_get_accounts', {}, function(accs) {
		var opts = '<option value="">Select account...</option>';
		(accs || []).forEach(function(a, i) {
			var sel = (o.account_used === a.label) ? 'selected' : '';
			opts += '<option value="' + a.label + '" ' + sel + '>' + a.label + ' (***' + a.phone + ')</option>';
		});
		$('#oe-account').html(opts);
	});

	$('#order-edit-modal').addClass('active');
};

MM.submitOrderEdit = function() {
	var id = $('#order-edit-modal').data('order-id');
	MM.ajax('meesho_update_order', {
		order_id: id,
		fulfillment_status: $('#oe-status').val(),
		meesho_order_id: $('#oe-meesho-id').val(),
		tracking_id: $('#oe-tracking').val(),
		account_used: $('#oe-account').val(),
		notes: $('#oe-notes').val()
	}, function(msg) {
		MM.toast(msg, 'success');
		$('#order-edit-modal').removeClass('active');
		MM.loadOrders();
	});
};

/* ============================================================
   SEO TAB — with type filter
   ============================================================ */
MM.loadSuggestions = function() {
	MM.showSkeleton('#seo_suggestions_body');
	MM.ajax('meesho_get_suggestions', {
		priority: $('#seo_priority_filter').val() || '',
		type: $('#seo_type_filter').val() || ''
	}, function(data) {
		var html = '';
		if (!data || data.length === 0) {
			html = '<tr><td colspan="6" style="text-align:center;">No pending suggestions.</td></tr>';
		} else {
			data.forEach(function(s) {
				var pb = '<span class="mm-badge mm-badge-' +
					(s.priority === 'high' ? 'danger' : s.priority === 'medium' ? 'warning' : 'info') +
					'">' + s.priority + '</span>';
				html += '<tr>'
					+ '<td>' + s.post_id + '</td>'
					+ '<td><span class="mm-badge mm-badge-purple">' + s.type + '</span></td>'
					+ '<td><small><strong>Current:</strong> ' + ((s.current_value || '').substring(0, 50) || '<em>empty</em>')
					  + '<br><strong>→</strong> ' + ((s.suggested_value || '').substring(0, 80)) + '</small></td>'
					+ '<td>' + s.confidence + '%</td>'
					+ '<td>' + pb + '</td>'
					+ '<td>'
					+ '<button class="mm-btn mm-btn-sm mm-btn-success" onclick="MeeshoMaster.applySuggestion(' + s.id + ')">✅ Apply</button> '
					+ '<button class="mm-btn mm-btn-sm mm-btn-outline" onclick="MeeshoMaster.rejectSuggestion(' + s.id + ')">❌ Reject</button>'
					+ '</td></tr>';
			});
		}
		$('#seo_suggestions_body').html(html);
	});
};

MM.applySuggestion = function(id) {
	MM.ajax('meesho_apply_suggestion', { suggestion_id: id }, function(msg) {
		MM.toast(msg, 'success');
		MM.loadSuggestions();
	});
};

MM.rejectSuggestion = function(id) {
	MM.ajax('meesho_reject_suggestion', { suggestion_id: id }, function() {
		MM.toast('Rejected', 'info');
		MM.loadSuggestions();
	});
};

MM.applyAllSafe = function() {
	MM.confirm('Apply All Safe Fixes', 'This will auto-apply all high-priority suggestions with confidence ≥ 85%.', function() {
		MM.ajax('meesho_apply_all_safe', {}, function(msg) { MM.toast(msg, 'success'); MM.loadSuggestions(); });
	});
};

/* ============================================================
   COPILOT TAB
   ============================================================ */
$(document).on('click', '#btn_copilot_send', function() {
	var msg = $('#copilot_input').val().trim();
	if (!msg) return;
	$('#copilot_chat_history').append('<div class="mm-chat-msg mm-chat-msg-user">' + $('<span>').text(msg).html() + '</div>');
	$('#copilot_input').val('');
	$('#copilot_chat_history').append('<div class="mm-chat-msg mm-chat-msg-bot" id="copilot-typing"><div class="mm-skeleton" style="width:200px;height:14px;"></div></div>');
	$('#copilot_chat_history').scrollTop($('#copilot_chat_history')[0].scrollHeight);

	MM.ajax('meesho_copilot_chat', { message: msg, model: $('#copilot_model_select').val() || '' }, function(data) {
		$('#copilot-typing').remove();
		$('#copilot_chat_history').append('<div class="mm-chat-msg mm-chat-msg-bot">' + data.reply.replace(/\n/g, '<br>') + '</div>');
		$('#copilot_chat_history').scrollTop($('#copilot_chat_history')[0].scrollHeight);
		if (data.auto_applied && data.auto_applied.length) {
			MM.toast(data.auto_applied.length + ' actions auto-applied', 'success');
		}
	}, function() {
		$('#copilot-typing').remove();
	});
});

$(document).on('keypress', '#copilot_input', function(e) {
	if (e.which === 13) { e.preventDefault(); $('#btn_copilot_send').click(); }
});

/* ============================================================
   LOGS TAB
   ============================================================ */
MM.loadLogs = function(page) {
	page = page || 1;
	MM.showSkeleton('#logs_table_body');
	MM.ajax('meesho_get_logs', { page: page, action_type: $('#log_type_filter').val() || '', source: $('#log_source_filter').val() || '' }, function(data) {
		var html = '';
		if (!data.logs || data.logs.length === 0) {
			html = '<tr><td colspan="6" style="text-align:center;">No logs found.</td></tr>';
		} else {
			data.logs.forEach(function(l) {
				var can_undo = l.old_value !== '[Expired]';
				html += '<tr>'
					+ '<td>' + l.created_at + '</td>'
					+ '<td>' + l.action_type + '</td>'
					+ '<td>' + (l.post_id || '-') + '</td>'
					+ '<td><span class="mm-badge mm-badge-purple">' + l.source + '</span></td>'
					+ '<td><small>' + (l.old_value === '[Expired]' ? '<em>Expired</em>' : (l.old_value||'').substring(0,50)) + '</small></td>'
					+ '<td>' + (can_undo ? '<button class="mm-btn mm-btn-sm mm-btn-outline" onclick="MeeshoMaster.undoAction(' + l.id + ')">↩️ Undo</button>' : '<span class="mm-text-muted">Expired</span>') + '</td>'
					+ '</tr>';
			});
		}
		$('#logs_table_body').html(html);
	});
};

MM.undoAction = function(id) {
	MM.confirm('Undo Action', 'Are you sure you want to undo this action? The original value will be restored.', function() {
		MM.ajax('meesho_undo_action', { log_id: id }, function(msg) {
			MM.toast(msg, 'success');
			MM.loadLogs();
		});
	});
};

/* ============================================================
   SETTINGS TAB
   ============================================================ */
$(document).on('click', '#btn_save_settings', function(e) {
	e.preventDefault();
	var data = $('#meesho_settings_form').serialize();
	MM.ajax('meesho_save_settings', data, function(msg) { MM.toast(msg, 'success'); });
});

$(document).on('click', '#btn_test_email', function() {
	MM.ajax('meesho_test_email', {}, function(msg) { MM.toast(msg, 'success'); });
});

$(document).on('click', '#btn_generate_llms', function() {
	MM.ajax('meesho_generate_llms_txt', {}, function(msg) { MM.toast(msg, 'success'); });
});

/* ============================================================
   Auto-load on tab render
   ============================================================ */
$(document).ready(function() {
	var params = new URLSearchParams(window.location.search);
	var tab = params.get('tab') || 'import';
	if (tab === 'orders') MM.loadOrders();
	if (tab === 'seo') MM.loadSuggestions();
	if (tab === 'logs') MM.loadLogs();
});

})(jQuery);
