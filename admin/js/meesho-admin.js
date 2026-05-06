(function () {
'use strict';

const MM = window.MeeshoMaster = window.MeeshoMaster || {};
let currentCopilotThread = '';

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
const escapeHtml = (value) => {
const div = document.createElement('div');
div.textContent = value == null ? '' : String(value);
return div.innerHTML;
};

MM.toast = function (msg, type = 'info') {
let container = $('.mm-toast-container');
if (!container) {
container = document.createElement('div');
container.className = 'mm-toast-container';
document.body.appendChild(container);
}
const toast = document.createElement('div');
toast.className = 'mm-toast mm-toast-' + type;
toast.textContent = msg;
container.appendChild(toast);
setTimeout(() => toast.remove(), 4000);
};

MM.copyText = function (text, label) {
navigator.clipboard.writeText(text || '').then(() => MM.toast((label || 'Text') + ' copied!', 'success'));
};

MM.confirm = function (title, message, onConfirm) {
const overlay = document.createElement('div');
overlay.className = 'mm-modal-overlay active';
overlay.innerHTML = '<div class="mm-modal"><h3>' + escapeHtml(title) + '</h3><p>' + escapeHtml(message) + '</p><p class="mm-text-muted">This can be undone within 15 days.</p><div class="mm-modal-actions"><button class="mm-btn mm-btn-outline" data-mm-cancel>Cancel</button><button class="mm-btn mm-btn-danger" data-mm-confirm>Confirm</button></div></div>';
document.body.appendChild(overlay);
$('[data-mm-cancel]', overlay).addEventListener('click', () => overlay.remove());
$('[data-mm-confirm]', overlay).addEventListener('click', () => {
overlay.remove();
onConfirm();
});
};

MM.showSkeleton = function (selector, rows = 3) {
const target = $(selector);
if (!target) return;
target.innerHTML = Array.from({ length: rows }).map(() => '<div class="mm-skeleton-row"><div class="mm-skeleton"></div><div class="mm-skeleton"></div><div class="mm-skeleton"></div></div>').join('');
};

MM.ajax = async function (action, data = {}, onSuccess, onError) {
try {
let payload;
if (typeof data === 'string') {
payload = new URLSearchParams(data);
} else {
payload = new URLSearchParams();
Object.entries(data).forEach(([key, value]) => payload.append(key, value == null ? '' : value));
}
payload.set('action', action);
payload.set('nonce', meesho_ajax.nonce);
const response = await fetch(meesho_ajax.ajax_url, {
method: 'POST',
headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
body: payload.toString(),
credentials: 'same-origin'
});
const json = await response.json();
if (!json.success) {
const message = typeof json.data === 'object' && json.data ? (json.data.message || JSON.stringify(json.data)) : (json.data || 'Request failed');
MM.toast(message, 'error');
if (onError) onError(json.data);
throw new Error(message);
}
if (onSuccess) onSuccess(json.data);
return json.data;
} catch (error) {
if (!onError) {
MM.toast(error.message || 'Request failed. Please try again.', 'error');
}
throw error;
}
};

MM.loadOrders = function (page = 1) {
MM.showSkeleton('#orders_table_body');
MM.ajax('meesho_get_orders', { page, status: $('#order_status_filter')?.value || '', search: $('#order_search')?.value || '' }, (data) => {
MM._currentOrders = data.orders || [];
const html = !MM._currentOrders.length ? '<tr><td colspan="8" style="text-align:center;">No orders found.</td></tr>' : MM._currentOrders.map((o) => {
const slaClass = o.sla_status === 'breached' ? 'mm-sla-breached' : '';
const codClass = o.cod_risk === 'high' ? 'mm-cod-high' : '';
const badge = o.fulfillment_status === 'delivered' ? 'success' : (['cancelled', 'returned'].includes(o.fulfillment_status) || o.sla_status === 'breached' ? 'danger' : (o.fulfillment_status === 'dispatched' ? 'purple' : 'info'));
const itemsHtml = (o.items || []).map((item) => '<strong>' + escapeHtml(item.name) + '</strong><br><small>SKU: ' + escapeHtml(item.sku || '-') + ' | Size: ' + escapeHtml(item.size || '-') + ' | Qty: ' + escapeHtml(item.qty) + '</small>').join('<br>') || '-';
const address = o.address || '';
const customerHtml = '<strong>' + escapeHtml(o.customer_name || '-') + '</strong> <button class="mm-btn-icon mm-btn-sm" data-copy="' + escapeHtml(o.customer_name || '') + '" data-label="Name">📋</button><br><small>📱 ' + escapeHtml(o.phone || '-') + ' <button class="mm-btn-icon mm-btn-sm" data-copy="' + escapeHtml(o.phone || '') + '" data-label="Phone">📋</button></small><br><small>📍 ' + escapeHtml(address.substring(0, 40)) + (address.length > 40 ? '...' : '') + ' <button class="mm-btn-icon mm-btn-sm" data-copy="' + escapeHtml(address.replace(/\n/g, ' ')) + '" data-label="Address">📋</button></small>';
let meeshoSku = '';
(o.items || []).forEach((item) => { if (item.sku && !meeshoSku) meeshoSku = item.sku.split('-')[0]; });
let actions = '<button class="mm-btn mm-btn-sm mm-btn-outline" data-order-edit="' + o.id + '">✏️ Edit</button>';
if (o.fulfillment_status === 'pending' && meeshoSku) actions += ' <a href="https://www.meesho.com/p/' + encodeURIComponent(meeshoSku) + '" target="_blank" class="mm-btn mm-btn-sm mm-btn-primary">🛒 Order on Meesho</a>';
return '<tr class="' + slaClass + ' ' + codClass + '"><td>#' + escapeHtml(o.wc_order_id) + '<br><small>' + escapeHtml(o.created_at || '') + '</small></td><td>' + itemsHtml + '</td><td>' + customerHtml + '</td><td>' + escapeHtml(o.payment_method || '-') + (o.cod_risk === 'high' ? '<br><span class="mm-badge mm-badge-danger">⚠ RISK</span>' : '') + '<br><small>₹' + escapeHtml(o.order_total || '0') + '</small></td><td><span class="mm-badge mm-badge-' + badge + '">' + escapeHtml((o.fulfillment_status || '').replace(/_/g, ' ')) + '</span>' + (o.meesho_order_id ? '<br><small>M: ' + escapeHtml(o.meesho_order_id) + '</small>' : '') + (o.tracking_id ? '<br><small>T: ' + escapeHtml(o.tracking_id) + '</small>' : '') + '</td><td><small>' + escapeHtml(o.account_used || '-') + '</small></td><td>' + (o.sla_status === 'breached' ? '<span class="mm-badge mm-badge-danger">⏰ BREACHED</span>' : '<span class="mm-badge mm-badge-success">OK</span>') + '</td><td>' + actions + '</td></tr>';
}).join('');
$('#orders_table_body').innerHTML = html;
});
};

MM.openOrderEdit = function (id) {
const order = (MM._currentOrders || []).find((item) => Number(item.id) === Number(id));
if (!order) return MM.toast('Order data not found', 'error');
$('#oe-order-id').textContent = order.wc_order_id;
$('#oe-status').value = order.fulfillment_status;
$('#oe-meesho-id').value = order.meesho_order_id || '';
$('#oe-tracking').value = order.tracking_id || '';
$('#oe-notes').value = '';
$('#order-edit-modal').dataset.orderId = id;
MM.ajax('meesho_get_accounts', {}, (accounts) => {
$('#oe-account').innerHTML = '<option value="">Select account...</option>' + (accounts || []).map((account) => '<option value="' + escapeHtml(account.label) + '" ' + (order.account_used === account.label ? 'selected' : '') + '>' + escapeHtml(account.label) + ' (***' + escapeHtml(account.phone) + ')</option>').join('');
});
$('#order-edit-modal').classList.add('active');
};

MM.submitOrderEdit = function () {
MM.ajax('meesho_update_order', {
order_id: $('#order-edit-modal').dataset.orderId,
fulfillment_status: $('#oe-status').value,
meesho_order_id: $('#oe-meesho-id').value,
tracking_id: $('#oe-tracking').value,
account_used: $('#oe-account').value,
notes: $('#oe-notes').value
}, (msg) => {
MM.toast(msg, 'success');
$('#order-edit-modal').classList.remove('active');
MM.loadOrders();
});
};

MM.loadSuggestions = function () {
MM.showSkeleton('#seo_suggestions_body');
MM.ajax('meesho_get_suggestions', { priority: $('#seo_priority_filter')?.value || '', type: $('#seo_type_filter')?.value || '' }, (rows) => {
$('#seo_suggestions_body').innerHTML = !rows.length ? '<tr><td colspan="6" style="text-align:center;">No pending suggestions.</td></tr>' : rows.map((row) => '<tr><td>' + escapeHtml(row.post_id) + '</td><td><span class="mm-badge mm-badge-purple">' + escapeHtml(row.type) + '</span></td><td><small><strong>Current:</strong> ' + escapeHtml((row.current_value || '').substring(0, 50) || 'empty') + '<br><strong>→</strong> ' + escapeHtml((row.suggested_value || '').substring(0, 80)) + '</small></td><td>' + escapeHtml(row.confidence) + '%</td><td><span class="mm-badge mm-badge-' + (row.priority === 'high' ? 'danger' : (row.priority === 'medium' ? 'warning' : 'info')) + '">' + escapeHtml(row.priority) + '</span></td><td><button class="mm-btn mm-btn-sm mm-btn-success" data-apply-suggestion="' + row.id + '">✅ Apply</button> <button class="mm-btn mm-btn-sm mm-btn-outline" data-reject-suggestion="' + row.id + '">❌ Reject</button></td></tr>').join('');
});
};

MM.applySuggestion = (id) => MM.ajax('meesho_apply_suggestion', { suggestion_id: id }, () => { MM.toast('Applied', 'success'); MM.loadSuggestions(); });
MM.rejectSuggestion = (id) => MM.ajax('meesho_reject_suggestion', { suggestion_id: id }, () => { MM.toast('Rejected', 'info'); MM.loadSuggestions(); });
MM.applyAllSafe = () => MM.confirm('Apply All Safe Fixes', 'This will auto-apply all safe high-priority suggestions.', () => MM.ajax('meesho_apply_all_safe', {}, (msg) => { MM.toast(msg, 'success'); MM.loadSuggestions(); }));
MM.loadLogs = function (page = 1) {
MM.showSkeleton('#logs_table_body');
MM.ajax('meesho_get_logs', { page, action_type: $('#log_type_filter')?.value || '', source: $('#log_source_filter')?.value || '' }, (data) => {
$('#logs_table_body').innerHTML = !(data.logs || []).length ? '<tr><td colspan="6" style="text-align:center;">No logs found.</td></tr>' : data.logs.map((log) => '<tr><td>' + escapeHtml(log.created_at) + '</td><td>' + escapeHtml(log.action_type) + '</td><td>' + escapeHtml(log.post_id || '-') + '</td><td><span class="mm-badge mm-badge-purple">' + escapeHtml(log.source) + '</span></td><td><small>' + escapeHtml((log.old_value || '').substring(0, 50) || '-') + '</small></td><td>' + (log.undoable && !log.undone && log.old_value !== null ? '<button class="mm-btn mm-btn-sm mm-btn-outline" data-undo-log="' + log.id + '">↩️ Undo</button>' : '<span class="mm-text-muted">Expired</span>') + '</td></tr>').join('');
});
};
MM.undoAction = (id) => MM.confirm('Undo Action', 'Restore the previous value?', () => MM.ajax('meesho_undo_action', { log_id: id }, (msg) => { MM.toast(msg, 'success'); MM.loadLogs(); }));

MM.appendCopilotMessage = function (role, html) {
const history = $('#copilot_chat_history');
if (!history) return;
const wrapper = document.createElement('div');
wrapper.className = 'mm-chat-msg ' + (role === 'user' ? 'mm-chat-msg-user' : 'mm-chat-msg-bot');
wrapper.innerHTML = html;
history.appendChild(wrapper);
history.scrollTop = history.scrollHeight;
};

MM.sendCopilotMessage = function () {
const input = $('#copilot_input');
if (!input || !input.value.trim()) return;
const msg = input.value.trim();
MM.appendCopilotMessage('user', escapeHtml(msg));
input.value = '';
MM.appendCopilotMessage('bot', '<div id="copilot-typing"><div class="mm-skeleton" style="width:200px;height:14px;"></div></div>');
MM.ajax('meesho_copilot_chat', { message: msg, model: $('#copilot_model_select')?.value || '', thread_key: currentCopilotThread }, (data) => {
currentCopilotThread = data.thread_key || currentCopilotThread;
$('#copilot-typing')?.parentElement?.remove();
const actions = (data.actions || []).map((action) => '<button class="mm-btn mm-btn-sm mm-btn-outline mm-mt-10" data-copilot-action="' + escapeHtml(JSON.stringify(action).replace(/"/g, '&quot;')) + '">Apply: ' + escapeHtml(action.action || 'Action') + '</button>').join('');
MM.appendCopilotMessage('bot', escapeHtml(data.reply).replace(/\n/g, '<br>') + actions);
if ((data.auto_applied || []).length) MM.toast(data.auto_applied.length + ' actions auto-applied', 'success');
}, () => $('#copilot-typing')?.parentElement?.remove());
};

MM.loadCopilotHistory = function () {
MM.ajax('meesho_copilot_history', { thread_key: currentCopilotThread }, (data) => {
if (!Array.isArray(data) || !$('#copilot_chat_history')) return;
$('#copilot_chat_history').innerHTML = '';
data.forEach((item) => MM.appendCopilotMessage(item.role === 'user' ? 'user' : 'bot', escapeHtml(item.content || '').replace(/\n/g, '<br>')));
});
};
MM.undoLastCopilot = () => MM.ajax('meesho_copilot_undo_last', {}, (msg) => MM.toast(msg, 'success'));

MM.saveSettings = function () {
const form = $('#meesho_settings_form');
if (!form) return;
const payload = new URLSearchParams(new FormData(form)).toString();
MM.ajax('meesho_save_settings', payload, (msg) => MM.toast(msg, 'success'));
};
MM.saveAccounts = function () {
const accounts = $$('.meesho-acc-label').map((field, index) => ({
label: field.value,
email: $$('.meesho-acc-email')[index].value,
phone: $$('.meesho-acc-phone')[index].value,
notes: $$('.meesho-acc-notes')[index].value
}));
MM.ajax('meesho_save_accounts', { accounts: JSON.stringify(accounts) }, (msg) => MM.toast(msg, 'success'));
};
MM.generateHeatmapInsights = () => MM.ajax('meesho_get_heatmap_insights', {}, (data) => {
const target = $('#heatmap_insights');
if (!target) return;
target.innerHTML = !(data.insights || []).length ? '<p>No insights available.</p>' : data.insights.map((insight, index) => '<div class="mm-card mm-mb-10"><div class="mm-flex-between"><span>' + escapeHtml(insight.suggestion || '') + '</span><span class="mm-badge mm-badge-' + ((insight.priority === 'high') ? 'danger' : (insight.priority === 'medium' ? 'warning' : 'info')) + '">' + escapeHtml(insight.priority || 'low') + '</span></div><div class="mm-mt-10"><button class="mm-btn mm-btn-sm mm-btn-primary" data-dismiss-heatmap="' + index + '">Dismiss</button></div></div>').join('');
});
MM.addKeyword = () => MM.ajax('meesho_add_keyword', { keyword: $('#new_keyword')?.value || '' }, (data) => { MM.toast('Keyword tracked: ' + data.keyword, 'success'); MM.loadRankings(); });
MM.loadRankings = () => MM.ajax('meesho_get_rankings', {}, (rows) => {
const target = $('#rankings_list');
if (!target) return;
target.innerHTML = !(rows || []).length ? '<p class="mm-text-muted">Tracked keywords will appear here.</p>' : '<table class="mm-table"><thead><tr><th>Keyword</th><th>Page</th><th>Position</th><th>Impressions</th><th>CTR</th><th>Date</th></tr></thead><tbody>' + rows.map((row) => '<tr><td>' + escapeHtml(row.keyword) + '</td><td><small>' + escapeHtml(row.page_url || '') + '</small></td><td>' + escapeHtml(row.position) + '</td><td>' + escapeHtml(row.impressions) + '</td><td>' + escapeHtml(row.ctr) + '</td><td>' + escapeHtml(row.recorded_at) + '</td></tr>').join('') + '</tbody></table>';
});

document.addEventListener('click', (event) => {
const copy = event.target.closest('[data-copy]');
if (copy) return MM.copyText(copy.getAttribute('data-copy'), copy.getAttribute('data-label'));
const orderEdit = event.target.closest('[data-order-edit]');
if (orderEdit) return MM.openOrderEdit(orderEdit.getAttribute('data-order-edit'));
const applySuggestion = event.target.closest('[data-apply-suggestion]');
if (applySuggestion) return MM.applySuggestion(applySuggestion.getAttribute('data-apply-suggestion'));
const rejectSuggestion = event.target.closest('[data-reject-suggestion]');
if (rejectSuggestion) return MM.rejectSuggestion(rejectSuggestion.getAttribute('data-reject-suggestion'));
const undoLog = event.target.closest('[data-undo-log]');
if (undoLog) return MM.undoAction(undoLog.getAttribute('data-undo-log'));
const copilotAction = event.target.closest('[data-copilot-action]');
if (copilotAction) return MM.ajax('meesho_copilot_apply', { action_data: copilotAction.getAttribute('data-copilot-action').replace(/&quot;/g, '"') }, (msg) => MM.toast(msg, 'success'));
});

document.addEventListener('DOMContentLoaded', () => {
$('#btn_import_url')?.addEventListener('click', async () => {
const button = $('#btn_import_url');
const url = $('#meesho_url')?.value.trim();
if (!url) return MM.toast('Please enter a Meesho URL', 'error');
button.disabled = true; button.textContent = 'Importing...';
await MM.ajax('meesho_import_url', { url }, (data) => {
button.disabled = false; button.textContent = '🚀 Import via URL';
if (data.status === 'duplicate') {
$('#import_results').innerHTML = '<div class="mm-card"><h3>⚠️ Duplicate Found</h3><p>' + escapeHtml(data.message) + '</p></div>';
} else if (data.status === 'sku_missing') {
$('#manual_sku_section')?.classList.remove('mm-hidden');
MM.toast('SKU could not be extracted. Please enter it manually.', 'error');
} else {
MM.toast(data.message, 'success');
$('#import_results').innerHTML = '<div class="mm-card"><p>✅ ' + escapeHtml(data.message) + '</p></div>';
}
}, () => { button.disabled = false; button.textContent = '🚀 Import via URL'; });
});
$('#btn_import_html')?.addEventListener('click', async () => {
const button = $('#btn_import_html');
const html = $('#meesho_html')?.value.trim();
if (!html) return MM.toast('Please paste HTML source', 'error');
button.disabled = true; button.textContent = 'Parsing...';
await MM.ajax('meesho_import_html', { html, product_url: $('#meesho_url')?.value || '' }, (data) => {
button.disabled = false; button.textContent = '📋 Parse HTML';
if (data.status === 'sku_missing') $('#manual_sku_section')?.classList.remove('mm-hidden');
$('#import_results').innerHTML = '<div class="mm-card"><p>✅ ' + escapeHtml(data.message || 'Done') + '</p></div>';
}, () => { button.disabled = false; button.textContent = '📋 Parse HTML'; });
});
$('#btn_manual_sku')?.addEventListener('click', () => MM.ajax('meesho_manual_sku', { sku: $('#manual_sku_input')?.value || '', product_data: '{}' }, (data) => { MM.toast(data.message || 'Imported', 'success'); $('#manual_sku_section')?.classList.add('mm-hidden'); }));
$('#btn_copilot_send')?.addEventListener('click', MM.sendCopilotMessage);
$('#copilot_input')?.addEventListener('keypress', (event) => { if (event.key === 'Enter') { event.preventDefault(); MM.sendCopilotMessage(); } });
$('#btn_copilot_undo')?.addEventListener('click', MM.undoLastCopilot);
$('#btn_save_settings')?.addEventListener('click', (event) => { event.preventDefault(); MM.saveSettings(); });
$('#btn_test_email')?.addEventListener('click', () => MM.ajax('meesho_test_email', {}, (msg) => MM.toast(msg, 'success')));
$('#btn_generate_llms')?.addEventListener('click', () => MM.ajax('meesho_generate_llms_txt', {}, (data) => { MM.toast(data.message || 'llms.txt generated', 'success'); const pre = $('#llms_preview'); if (pre && data.content) pre.textContent = data.content; }));
$('#btn_save_accounts')?.addEventListener('click', MM.saveAccounts);
$('#btn_generate_heatmap')?.addEventListener('click', MM.generateHeatmapInsights);
$('#btn_add_keyword')?.addEventListener('click', MM.addKeyword);
$('#btn_send_report')?.addEventListener('click', () => MM.ajax('meesho_send_report', {}, (msg) => MM.toast(msg, 'success')));
const params = new URLSearchParams(window.location.search);
const tab = params.get('tab') || 'import';
if (tab === 'orders') MM.loadOrders();
if (tab === 'seo') MM.loadSuggestions();
if (tab === 'logs') MM.loadLogs();
if (tab === 'analytics') MM.loadRankings();
});
})();
