# Meesho Master v6 — Master Implementation Blueprint & Handoff Checklist

> **Final Version for Handoff.** This document contains 100% of the technical specifications required to complete the Meesho Master v6 plugin.

---

## 1. Immediate Critical Bug Fixes (Status: Completed)
- [x] **Bug 1: Fatal PHP Error (The Main Culprit):** 
  - **Issue:** `class-meesho-seo.php` contained duplicate declarations of `inject_schema()` (Line 51 and 309) and `inject_fallback_meta()` (Line 60 and 319).
  - **Resolution:** Removed the first (older) pair of methods. Retained the second versions as they include JSON-LD validation.
  - **Outcome:** The plugin can now be activated without a fatal crash.
- [x] **Bug 2: Plugin Deletion Blocked:**
  - **Issue:** WordPress prevents plugin deletion if the plugin file triggers a fatal error during loading.
  - **Resolution:** Fixing Bug 1 automatically unblocked the "Delete" button in the WP Admin.
- [x] **Bonus Fix: Empty `uninstall.php` Cleanup:**
  - **Issue:** The original `uninstall.php` was a stub with no cleanup logic.
  - **Resolution:** Implemented a full purge script that drops all 9 custom tables (`mm_scraped_products`, `mm_seo_suggestions`, etc.), deletes all `meesho_master_settings` options, and clears all scheduled cron events.

---

## 2. GitHub Repository Logic Extraction (§8)
Analyze these repositories deeply. Extract only the logic/patterns specified. **Do not copy code.**

### 2.1 [ngstcf/ai-seo-auditor](https://github.com/ngstcf/ai-seo-auditor)
- **Files to Analyze:** `auditor.py` or equivalent logic files.
- **Logic to Extract:** The exhaustive SEO audit checklist. Specifically, extract their word-count thresholds (e.g., what they consider "thin" content) and their keyword density calculations.
- **Implementation:** Update `MM_SEO_Scorer` in PHP to use these deterministic thresholds for the SEO Score (0-100).

### 2.2 [AgriciDaniel/claude-seo](https://github.com/AgriciDaniel/claude-seo)
- **Files to Analyze:** Prompt templates in the `prompts/` directory.
- **Logic to Extract:** Prompt structures for generating "Click-Through Rate (CTR) optimized" meta titles and descriptions.
- **Implementation:** Merge these patterns into the system prompt of `MM_SEO_Analyzer`.

### 2.3 [zubair-trabzada/geo-seo-claude](https://github.com/zubair-trabzada/geo-seo-claude)
- **Files to Analyze:** Logic files related to "Generative Engine Optimization."
- **Logic to Extract:** Rules for "Citability" (e.g., how to structure a paragraph so an AI crawler like Claude will quote it).
- **Implementation:** Use these rules to define the `citability_block` suggestion type in our engine.

### 2.4 [every-app/open-seo](https://github.com/every-app/open-seo)
- **Files to Analyze:** `templates/json-ld/` or similar schema files.
- **Logic to Extract:** Standardized templates for `FAQPage`, `HowTo`, and `Product` schema.
- **Implementation:** Integrate these templates into the `MM_Schema_Generator` class.

### 2.5 [aaron-he-zhu/seo-geo-claude-skills](https://github.com/aaron-he-zhu/seo-geo-claude-skills)
- **Files to Analyze:** Readme and signal documentation.
- **Logic to Extract:** Specific authority signals (e.g., factual density, brand mentions) that trigger positive GEO responses.
- **Implementation:** Add these as scoring factors in the `GEO Score` section of `MM_SEO_Scorer`.

---

## 3. Database Schema Blueprint (§3)
Every table must use the `mm_` prefix and `$wpdb->get_charset_collate()`.

### 3.1 New Tables
1. **mm_seo_suggestions:**
   - `id`: BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
   - `suggestion_type`: VARCHAR(60) (Values: meta_title, meta_desc, content, schema, faq, howto_schema, internal_link, alt_tag, llms_txt, citability_block, statistics_inject)
   - `current_value`: LONGTEXT
   - `suggested_value`: LONGTEXT
   - `safe_to_apply`: TINYINT(1) (Must be 1 for meta/alt/links if confidence >= 85)
   - `status`: VARCHAR(20) (Default: 'pending')
2. **mm_audit_log:**
   - `old_value`: LONGTEXT
   - `new_value`: LONGTEXT
   - `purge_after`: DATETIME (Current time + 15 days)

### 3.2 Migrations
- **Table:** `mm_orders`
- **Columns to Add:** `meesho_order_id` (VARCHAR), `fulfillment_status` (VARCHAR), `sla_flagged` (TINYINT).

---

## 4. Core Engine Logic Specs (§4.3)

### 4.1 Target Keyword Resolution (§4.3.3)
1. Check Yoast Meta: `_yoast_wpseo_title` (priority) -> `_yoast_wpseo_focuskw`.
2. Check RankMath Meta: `rank_math_title` (priority) -> `rank_math_focus_keyword`.
3. Check MM Meta: `_mm_focus_keyword`.
4. Fallback: Extract first 2-3 words from title, excluding stop words: *a, an, the, in, on, of, for, with, to, is, are, was, were, be, at, by, from, and, or, but*.

### 4.2 PHP Scoring Engine — Point Distribution (§4.3.4)
Scoring must be 100% deterministic PHP logic.
- **SEO Score (100 pts):**
  - Meta Title 50-60 chars (+10), Meta Desc 120-160 chars (+5), Keyword in title (+3), Keyword in desc (+2).
  - Exactly 1 H1 (+7), H1 contains keyword (+4), at least 2 H2s (+4).
  - Keyword in first 100 words (+7), density 1-3% (+8).
  - ≥1 internal link (+5), ≥3 links (+10).
  - Posts: ≥500 words (+20). Products: ≥300 words (+20).
  - All images have alt (+8), Schema present (+6), Canonical matches permalink (+6).
- **AEO Score (100 pts):**
  - Direct answers (Regex: `?` + ≤40 word paragraph): 1 found (+15), ≥3 found (+30).
  - FAQ Schema present (+10), H3/H4 with `?` text ≥3 (+10).
  - Avg sentence length ≤20 words (+20).
  - `<ul>` or `<ol>` present (+8), `<table>` present (+7).
- **GEO Score (100 pts):**
  - Citability blocks (120-180 words): 1 found (+15), ≥2 found (+30).
  - Factual density (Regex: `\d+%` or `₹\d+`): ≥3 (+20).
  - Brand name mentions (+10), Author/Date signals (+10).
  - `llms.txt` exists (+10), allows GPTBot/ClaudeBot (+5).

### 4.3 Crawler Data Structure (§4.3.2)
`MM_SEO_Crawler::collect_post_data` must return this exact array:
```php
[
  'post_id' => int,
  'title' => string,
  'content' => string,
  'meta_title' => string,
  'meta_desc' => string,
  'focus_keyword' => string,
  'headings' => ['h1'=>[], 'h2'=>[]],
  'images' => [['src'=>'', 'alt'=>'']],
  'seo_plugin' => 'yoast'|'rankmath'|'aioseo'|'none',
  'gsc_metrics' => ['clicks'=>0, 'impressions'=>0]
]
```

---

## 5. Security & Safety (§4.6, §4.4)
- **Encryption:** AES-256-CBC. Key = `hash_pbkdf2('sha256', AUTH_KEY, SECURE_AUTH_SALT, 1000, 32)`.
- **Copilot Allowlist:**
  - `wp_update_post` (title/content/status).
  - `wc_get_product()->set_regular_price()->save()`.
  - `MM_SEO_Implementor::apply()`.
- **Hard Denials:** Block `DROP`, `TRUNCATE`, `DELETE FROM`, `wp_delete_site`. Scrub secret keys from chat output.

---

## 6. Admin UI Standards (§5)
- **Searchable Page Table:** Vanilla JS `fetch()` to get post list. Filter by title in real-time.
- **Skeleton Loaders:** pulsing CSS: `@keyframes pulse { 0% { opacity: 0.4; } 50% { opacity: 0.8; } 100% { opacity: 0.4; } }`.
- **Mobile Responsive:** 100% width on 375px. Hide non-essential columns on mobile using `@media`.

---

## 7. Automation & Cron (§4.3.9)
- **Morning Scan:** 08:00 IST (Asia/Kolkata).
- **Evening Scan:** 20:00 IST (Asia/Kolkata).
- **Admin Notice:** If `time() - last_run > 90000` seconds, display: "SEO scan hasn't run in 25+ hours. [Run Now]".

### 7.1 Scan Run Sequence (Mandatory)
Every scan (Cron or Manual) must follow this exact sequence:
1. **Initialize Run:** Insert row in `mm_seo_runs` with `status = running`.
2. **Fetch Queue (Priority Order):**
   - **P1:** Posts with NO entry in `mm_seo_post_scores` (Never scanned).
   - **P2:** Posts where `post_modified > last_scanned` date.
   - **P3:** Posts with the oldest `last_scanned` date.
   - *Batch Size: Max 10 items.*
3. **Execute per Post:**
   - `MM_SEO_Crawler::collect_post_data($post_id)` -> Native PHP extraction.
   - `MM_SEO_Scorer::score($data)` -> Deterministic PHP scoring.
   - `MM_SEO_Analyzer::analyze($data)` -> AI logic with `call_with_retry`.
   - `MM_SEO_Implementor::auto_apply()` -> Apply meta/alt/links if safety filter passes.
4. **Finalize Run:** Update `mm_seo_runs` with `status = done`, finished counts, and `finished_at` timestamp.

---

## 8. Product & Order Extension (§4.1, §4.2)
- **Review Import:** Store rating JSON `{"5": X, "4": Y...}`. Use `media_sideload_image()` for review photos.
- **Pricing:** Apply `mm_markup_value` (percentage or flat). Round to nearest 10, 50, or 99 based on `mm_price_round`.
- **Order SLA:** Red flag if `fulfillment_status == 'pending'` AND `created_at < (current_time - 4 hours)`.

---

## 9. Final Testing Checklist (§9)
- [ ] Duplicate PID detection returns error + link.
- [ ] Product variations have correct size/price/SKU.
- [ ] AI failure skips batch, logs error, continues at next cron.
- [ ] Schema validates at schema.org/validator.
- [ ] llms.txt exists at site root with GPTBot/ClaudeBot rules.
- [ ] Undo restores old value within 15-day window.
- [ ] Copilot cannot read `mm_*_key` option values.
- [ ] All queries use `$wpdb->prepare()`.

---

## 10. Handoff Instruction for the Next Agent
1. **Refactor Structure:** Move all logic into root `includes/` using the `MM_` class prefix.
2. **Implement Crypto:** Create `class-crypto.php` first to secure all key fields.
3. **Execute Logic Extraction:** Analyze the 5 GitHub repos and update the Scorer/Analyzer prompts.
4. **Build the SEO Dashboard:** Implement the searchable table and the per-post detail slide-in panel.
5. **Finalize Analytics:** Connect the GSC API and implement the email report cron.
