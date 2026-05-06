# Meesho Master v6 — Updated Gap Analysis Todo (Remaining Work)

> Source of truth: `/home/runner/work/meesho-plugin/meesho-plugin/meesho-master-v6-master-prompt.md`  
> Goal: Track everything still required to fully reach v6 spec.  
> Scope: Only remaining build/fix items (what is missing or only partial in current repo).

---

## 0) Gap Findings Not Covered Properly in Previous Todo

- [ ] **Architecture mismatch is still unresolved:** required `MM_*` class architecture and file layout in `includes/` is not implemented (current code still uses `Meesho_Master_*` modules in `includes/modules/`).
- [ ] **Core v6 classes are missing:** `class-seo-crawler.php`, `class-seo-scorer.php`, `class-seo-safety.php`, `class-seo-implementor.php`, `class-seo-geo.php`, `class-schema-generator.php`, `class-logger.php`, `class-crypto.php`, `class-dataforseo.php`, and required v6 wiring in `class-ajax.php`.
- [ ] **DB schema contract is not aligned:** required `mm_*` table design from v6 prompt is not fully implemented (current DB uses `meesho_*` table naming and different columns).
- [ ] **Security contract is not aligned:** nonce key should be `mm_nonce` (currently `meesho_nonce`), strict secret scrubbing and Copilot hard denials are incomplete.
- [ ] **Scheduler contract is not aligned:** required IST-specific morning/evening events and strict priority queue scan sequence are not implemented as specified.
- [ ] **UI contract is not aligned:** v6 requires vanilla `fetch` + `async/await`; current admin JS is jQuery-based.
- [ ] **llms.txt write path is non-compliant:** must use `WP_Filesystem`; current implementation uses `file_put_contents()`.
- [ ] **v5 → v6 bridge requirements are not implemented:** `mm_seo_audit`, `mm_apply_meta`, `mm_apply_links`, `mm_bulk_meta`, `mm_suggest_links` are not fully wired to v6 data tables/flows.

---

## 1) Agent Execution Backlog (All Tasks I Need To Do)

### 1.1 Architecture + Bootstrap
- [ ] Refactor plugin structure to v6 delivery format in root `includes/` with `MM_` class prefix.
- [ ] Keep backward compatibility with existing v5 behaviors and handlers (no breaking removals/renames).
- [ ] Ensure plugin bootstrap loads all required v6 classes and registers hooks cleanly.

### 1.2 Database & Migrations
- [ ] Implement/align v6 tables: `mm_seo_suggestions`, `mm_seo_post_scores`, `mm_seo_score_history`, `mm_audit_log`, `mm_seo_runs`, `mm_copilot_threads`, `mm_ranking_data`.
- [ ] Add/align required columns on `mm_orders` (`meesho_order_id`, `meesho_tracking_id`, `meesho_account`, `fulfillment_status`, `sla_flagged`, `cod_risk_flag`, `fulfilled_by`).
- [ ] Add indexes and constraints exactly per v6 schema spec.
- [ ] Add version-guarded migration logic and safe upgrades.

### 1.3 Crypto + Settings Hardening
- [ ] Build `MM_Crypto` using AES-256-CBC with key derived from `AUTH_KEY + SECURE_AUTH_SALT` as specified.
- [ ] Migrate encrypted setting keys to v6 naming and wildcard encryption behavior (`*_key`, `*_secret`, `*_credentials`).
- [ ] Add/align all v6 settings keys (`mm_scrapling_url`, `mm_markup_type`, `mm_price_round`, `mm_openrouter_*`, `mm_gsc_credentials`, `mm_hotjar_id`, etc.).
- [ ] Ensure no plaintext secrets are returned in AJAX responses, logs, or UI output.

### 1.4 SEO Crawler + Keyword Resolution
- [ ] Implement `MM_SEO_Crawler::collect_post_data()` with exact required return shape and all keys always present.
- [ ] Implement SEO plugin detection (`yoast|rankmath|aioseo|none`) and read priorities for title/meta/canonical/schema.
- [ ] Implement focus keyword resolution priority and persist fallback result into `_mm_focus_keyword`.
- [ ] Add schema-source detection (`post_meta`, `post_content`, `seo_plugin`) and existing-schema extraction flow.

### 1.5 Deterministic Scoring Engine
- [ ] Implement `MM_SEO_Scorer` with exact SEO/AEO/GEO point model from v6 prompt.
- [ ] Implement WooCommerce product-specific scoring overrides (300-word threshold, schema requirement, AEO redistribution).
- [ ] Add brand mention, authority signal, factual density, and llms.txt accessibility scoring checks per spec.

### 1.6 AI Analyzer + Failure Handling
- [ ] Implement `MM_SEO_Analyzer::analyze()` and `call_with_retry()` with exact failure behavior (batch skip, retry once on timeout, strict invalid-JSON discard).
- [ ] Implement model fallback to `openai/gpt-4o-mini` when configured model fails/unavailable.
- [ ] Enforce suggestion cap (`mm_seo_max_suggestions`, default 10).
- [ ] Use v6 system prompt contract and strict JSON-only parsing.

### 1.7 Safety + Implementor + Logging
- [ ] Implement `MM_SEO_Safety::can_auto_apply()` and schema exception `can_auto_apply_schema()` exactly per decision table.
- [ ] Implement `MM_SEO_Implementor::apply()` with mandatory pre-write logging and safe write paths.
- [ ] Write meta to MM fallback keys plus active SEO plugin keys simultaneously.
- [ ] Prevent duplicate schema output when SEO plugins already handle schema.
- [ ] Implement `MM_Logger::log_before_change()` and ensure every destructive write logs old/new values.

### 1.8 Scheduler + Run Queue
- [ ] Register IST cron events (`mm_seo_run_morning`, `mm_seo_run_evening`) at configured times.
- [ ] Implement 25+ hour stale-run admin notice and “Run Scan Now” action.
- [ ] Implement required scan sequence with run-row init/finalize and priority queue logic (never-scanned → modified → oldest).
- [ ] Upsert `mm_seo_post_scores` and append `mm_seo_score_history` every run.

### 1.9 GEO Features + llms.txt
- [ ] Move llms.txt generation to `MM_SEO_Geo::generate_llms_txt()` using `WP_Filesystem` only.
- [ ] Generate required llms.txt template (bots, disallows, sitemap, About block, metadata).
- [ ] Implement `statistics_inject` suggestion generation trigger when factual density is low.
- [ ] Implement `citability_block` flow (manual approval only, insertion position rule).

### 1.10 Schema Generator
- [ ] Implement `MM_Schema_Generator` supporting `Article`, `Product`, `FAQ`, `HowTo`, `BreadcrumbList`.
- [ ] Add JSON validation and deduplication rules for same `@type`.
- [ ] Enforce conflict rule: no duplicate wp_head schema when schema is plugin-managed.
- [ ] Enforce Product schema minimum required fields.

### 1.11 Import + Orders Extensions
- [ ] Align duplicate PID checks with v6 requirements (including post meta `_mm_meesho_pid` path and user-facing link flow).
- [ ] Ensure pricing rules map exactly to `mm_markup_type`, `mm_markup_value`, `mm_price_round` semantics.
- [ ] Ensure review import stores full rating JSON and review images via media sideload flow.
- [ ] Implement/align order tracker editable columns, SLA flag logic, and `mm_save_fulfillment` audit logging path.
- [ ] Enforce COD first-order phone risk rule from v6 requirements.

### 1.12 Copilot v6
- [ ] Implement v6 Copilot panel behavior (global admin access, thread persistence in `mm_copilot_threads`, model cache/free-model toggle).
- [ ] Enforce action allowlist and hard denials (`DROP`, `TRUNCATE`, `DELETE FROM`, `wp_delete_site`, secret reads, disabled-copilot flag, bulk delete guard).
- [ ] Enforce destructive-action confirmation even when auto-implement is enabled.
- [ ] Strip secret option values from all Copilot output text before UI render.
- [ ] Log all Copilot executions to `mm_audit_log` and add “Undo last action” flow.

### 1.13 Analytics v6
- [ ] Heatmap tab: embed Hotjar iframe and AI analysis cards with Apply/Dismiss actions.
- [ ] Ranking tracker: implement weekly top-50 keyword ingestion, competitor comparison support, 24h caching.
- [ ] Align storage to `mm_ranking_data` schema.
- [ ] Enhance email reports to include full v6 metrics set and sender fallback behavior.

### 1.14 Admin UI v6
- [ ] Convert new JS implementation to vanilla `fetch` + `async/await` (no new jQuery-based flows).
- [ ] Implement SEO score dashboard table requirements (sorting, pending counts, actions, scan selected, export CSV, plugin status banner).
- [ ] Implement suggestions queue filters (post type/priority/type/status/score range) and bulk reject.
- [ ] Implement per-post slide-in panel (score factor breakdown, trend chart, suggestions, audit history, undo actions).
- [ ] Ensure 375px mobile usability, skeletons, toasts, confirmation modal, pagination defaults, and progress bars.

### 1.15 v5 Handler Bridge Work
- [ ] Wire `mm_seo_audit` to scorer + post-score upsert + score-history insert.
- [ ] Wire `mm_apply_meta` / `mm_apply_links` / `mm_bulk_meta` to `mm_audit_log` writes.
- [ ] Wire `mm_suggest_links` to `mm_seo_suggestions` (`internal_link`, `pending`).
- [ ] Preserve old handler behavior while adding v6 table writes.

### 1.16 Security + Performance Compliance
- [ ] Standardize all AJAX handlers to `check_ajax_referer('mm_nonce','nonce')` + `current_user_can('manage_options')`.
- [ ] Ensure all DB queries use `$wpdb->prepare()` and remove raw interpolated queries.
- [ ] Remove any synchronous external HTTP calls from restricted hooks.
- [ ] Add transient caching requirements (GSC 24h, models 1h, llms check 6h).
- [ ] Enforce request memory/performance constraints and batch limits.

### 1.17 Undo, Retention, and Purges
- [ ] Implement v6 `MM_Undo::revert()` + `revert_last()` behavior with `undone` tracking.
- [ ] Implement weekly purge logic for old audit snapshots (`old_value = NULL`, `undoable = 0`).
- [ ] Implement 90-day `mm_seo_score_history` purge.
- [ ] Implement soft-delete status `mm_deleted` and restore flow to `publish`.

### 1.18 External Logic Extraction Tasks
- [ ] Analyze `ngstcf/ai-seo-auditor` and map deterministic scoring/checklist gaps.
- [ ] Analyze `AgriciDaniel/claude-seo` prompt patterns and merge into analyzer prompt.
- [ ] Analyze `zubair-trabzada/geo-seo-claude` for citability + crawler-accessibility logic.
- [ ] Analyze `every-app/open-seo` JSON-LD template patterns for schema completeness.
- [ ] Analyze `aaron-he-zhu/seo-geo-claude-skills` for missing SEO+GEO authority signals.
- [ ] Create `MM_DataForSEO` stub interface per v6 spec (non-implemented methods returning `WP_Error`).

---

## 2) Validation & QA Checklist (Must Pass Before Marking v6 Complete)

### 2.1 Functional
- [ ] Import URL flow and HTML fallback both pass end-to-end.
- [ ] Duplicate PID handling returns explicit error with link.
- [ ] Order tracker v6 column behaviors pass.
- [ ] SEO run queue and cron/manual scan flows pass.
- [ ] llms.txt generation and GEO scoring checks pass.

### 2.2 Safety
- [ ] Non-safe suggestion types never auto-apply.
- [ ] Schema auto-apply only when schema exception conditions pass.
- [ ] Copilot cannot reveal encrypted keys/secrets.
- [ ] Undo works inside 15-day window and fails correctly after expiry.

### 2.3 Security/Performance
- [ ] No plaintext keys in options/logs/AJAX.
- [ ] All write paths log old/new values first.
- [ ] All DB access paths are prepared/sanitized.
- [ ] Request memory and batch limits are within v6 targets.

---

## 3) Definition of Done for This Todo File

- [ ] Every v6 master prompt requirement has a mapped implementation task in this file.
- [ ] No remaining high-level requirement exists only in master prompt without a todo item here.
- [ ] This file is used as the execution backlog until all items are completed.

