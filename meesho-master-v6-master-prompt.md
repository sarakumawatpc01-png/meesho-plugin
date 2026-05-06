# Meesho Master WordPress Plugin — v6 Master Implementation Prompt

> **For any AI agent, developer, or engineer continuing this project.**  
> This document is the single source of truth. Read it fully before writing a single line of code.  
> Version: **6.0.0** — supersedes all previous prompt documents.

---

## 0. What Already Exists (v5 Baseline)

The uploaded `meesho-v5.zip` is the working foundation. **Do not rebuild it. Extend it.**

| File | What it does |
|------|-------------|
| `meesho-master.php` | Plugin bootstrap, defines `MM_VERSION = 5.0.0`, autoloads all classes |
| `includes/class-db.php` | DB helpers: `install`, `insert`, `update`, `delete`, `get_row`, `get_results`, `count` |
| `includes/class-scraper.php` | Meesho HTML/JSON scraping, short-link resolution, bulk queue, `process_next()` |
| `includes/class-openrouter.php` | OpenRouter API wrapper — chat, SEO audit, meta gen, internal-link suggestions, image gen |
| `includes/class-woo-import.php` | Creates WooCommerce variable products with size variations, SKU, price, stock |
| `includes/class-seo-manager.php` | `audit_post()`, `apply_meta()`, `suggest_links()`, writes to Yoast/RankMath/AIOSEO |
| `includes/class-order-tracker.php` | Captures WooCommerce orders into `mm_orders`, syncs status, formats addresses |
| `includes/class-ajax.php` | All `wp_ajax_mm_*` handlers (nonce + cap check). **Existing v5 handlers must stay working.** |
| `includes/class-blog-writer.php` | AI blog generation + WP publish |
| `includes/class-landing-page.php` | AI landing page generation + WP publish |
| `admin/class-admin.php` | Admin menu registration |
| `assets/js/admin.js` | Vanilla `fetch`-based AJAX, UI state management |
| `assets/css/admin.css` | Admin stylesheet |

**Existing DB tables** (prefix `mm_`):  
`scraped_products`, `scrape_queue`, `seo_tasks`, `blog_posts`, `orders`, `landing_pages`

**Existing v5 AJAX handlers that must be preserved AND wired to the new v6 engine:**  
`mm_seo_audit`, `mm_apply_meta`, `mm_apply_links`, `mm_bulk_meta`, `mm_suggest_links`  
These must now write results to `mm_seo_suggestions` and `mm_audit_log` (see §4.3.8).

**Rule:** Never delete or rename anything from v5. Bump version constant to `6.0.0`.

---

## 1. Project Summary

**Meesho Master** is a complete Meesho-to-WooCommerce automation and management suite for Indian e-commerce sellers. It runs inside WordPress (including shared hosting) and combines:

- Meesho product scraping → WooCommerce import
- Order fulfillment tracking
- AI-powered content, SEO, AEO, GEO automation
- Copilot (AI site assistant)
- Analytics (heatmaps, rankings, email reports)

**Primary constraints that must never be violated:**
1. Must work on shared hosting (low RAM, no exec/shell, no persistent processes)
2. All destructive actions are reversible within 15 days
3. No feature depends on a single external service — every critical path has a fallback
4. All API keys are encrypted at rest; Copilot cannot read or display them
5. AI output is never applied directly — always passes through PHP safety filter first

---

## 2. Architecture Principles

```
WordPress Admin UI  (vanilla JS / CSS — no build step)
        │  AJAX (nonce + capability check on every request)
        ▼
MM_Ajax::handle_*()          ← single entry point for all actions
        │
   ┌────┴────────────────────────────────────────────────┐
   │  Core Classes (PHP 8.0+, stateless, no globals)     │
   │  MM_Scraper     MM_Woo_Import    MM_Order_Tracker    │
   │  MM_SEO_Crawler MM_SEO_Analyzer  MM_SEO_Scorer       │
   │  MM_SEO_Safety  MM_SEO_Implementor MM_SEO_Geo        │
   │  MM_Schema_Generator MM_Copilot  MM_Analytics        │
   │  MM_OpenRouter  MM_DB  MM_Logger  MM_Undo  MM_Crypto │
   └─────────────────────────────────────────────────────┘
        │
   WordPress / WooCommerce APIs  (update_post_meta, wp_update_post, etc.)
        │
   External (optional): OpenRouter · GSC · Hotjar · DataForSEO (stub only)
```

- **Batch everything.** Never scan the whole site in one AJAX call. Max 5–10 items per batch.
- **All AI responses must be JSON.** PHP validates structure before any value is used.
- **Log before you change.** Every write records old value in `mm_audit_log` first.
- **Fail gracefully.** If OpenRouter is down, queue the task. If GSC is down, show cached data.
- **No blocking.** All external HTTP calls are triggered by user action or WP-Cron only.

---

## 3. New DB Tables (v6 additions)

Run via `MM_DB::install()` using `dbDelta()`. All tables use `$wpdb->get_charset_collate()`.  
For columns added to existing tables, use `ALTER TABLE … ADD COLUMN IF NOT EXISTS` wrapped in a version-check guard.

```sql
-- SEO/AEO/GEO suggestions queue
mm_seo_suggestions (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id         BIGINT UNSIGNED NOT NULL,
  suggestion_type VARCHAR(60) NOT NULL,
  -- Valid types: meta_title | meta_desc | content | schema | faq | howto_schema
  --              internal_link | alt_tag | llms_txt | citability_block | statistics_inject
  current_value   LONGTEXT,
  suggested_value LONGTEXT,
  reasoning       TEXT,
  priority        VARCHAR(10)  DEFAULT 'medium',   -- high | medium | low
  confidence      TINYINT      DEFAULT 0,          -- 0-100
  safe_to_apply   TINYINT(1)   DEFAULT 0,
  status          VARCHAR(20)  DEFAULT 'pending',  -- pending | approved | rejected | applied | undone
  seo_score       TINYINT      DEFAULT 0,
  aeo_score       TINYINT      DEFAULT 0,
  geo_score       TINYINT      DEFAULT 0,
  run_id          BIGINT UNSIGNED DEFAULT 0,
  created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_post   (post_id),
  INDEX idx_status (status),
  INDEX idx_type   (suggestion_type(30)),
  INDEX idx_run    (run_id)
)

-- Per-post current scores (leaderboard / dashboard view)
mm_seo_post_scores (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id       BIGINT UNSIGNED NOT NULL UNIQUE,
  post_type     VARCHAR(30)  DEFAULT 'post',
  seo_score     TINYINT      DEFAULT 0,
  aeo_score     TINYINT      DEFAULT 0,
  geo_score     TINYINT      DEFAULT 0,
  focus_keyword VARCHAR(200) DEFAULT '',
  last_scanned  DATETIME,
  scan_count    INT UNSIGNED DEFAULT 0,
  INDEX idx_post      (post_id),
  INDEX idx_seo       (seo_score),
  INDEX idx_last_scan (last_scanned)
)

-- Score history snapshots (for trend charts)
mm_seo_score_history (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id     BIGINT UNSIGNED NOT NULL,
  seo_score   TINYINT DEFAULT 0,
  aeo_score   TINYINT DEFAULT 0,
  geo_score   TINYINT DEFAULT 0,
  run_id      BIGINT UNSIGNED DEFAULT 0,
  recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_post (post_id),
  INDEX idx_date (recorded_at)
)

-- Unified action audit log (all modules write here)
mm_audit_log (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  action_type    VARCHAR(60) NOT NULL,
  -- Examples: seo_apply | seo_undo | copilot_edit | product_delete | order_update | schema_apply
  target_type    VARCHAR(30)  DEFAULT '',
  target_id      BIGINT UNSIGNED DEFAULT 0,
  suggestion_id  BIGINT UNSIGNED DEFAULT 0,   -- links to mm_seo_suggestions.id if applicable
  old_value      LONGTEXT,
  new_value      LONGTEXT,
  actor          VARCHAR(30)  DEFAULT 'manual', -- manual | ai_auto | copilot | scheduler
  actor_user_id  BIGINT UNSIGNED DEFAULT 0,
  note           TEXT,
  undoable       TINYINT(1)   DEFAULT 1,
  undone         TINYINT(1)   DEFAULT 0,
  purge_after    DATETIME,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_target     (target_type(20), target_id),
  INDEX idx_undoable   (undoable, undone),
  INDEX idx_suggestion (suggestion_id)
)

-- SEO scan run history
mm_seo_runs (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trigger_type        VARCHAR(20) DEFAULT 'cron',  -- cron | manual | user_selected
  posts_scanned       INT DEFAULT 0,
  suggestions_created INT DEFAULT 0,
  suggestions_applied INT DEFAULT 0,
  failed_posts        INT DEFAULT 0,
  status              VARCHAR(20) DEFAULT 'running', -- running | done | partial_fail
  error_log           TEXT,
  started_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
  finished_at         DATETIME
)

-- Copilot conversation threads
mm_copilot_threads (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  thread_key VARCHAR(60) UNIQUE,
  title      TEXT,
  messages   LONGTEXT,    -- JSON array of {role, content, timestamp, action_taken?}
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)

-- Ranking tracker
mm_ranking_data (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  keyword     VARCHAR(300),
  page_url    TEXT,
  position    FLOAT,
  impressions INT DEFAULT 0,
  clicks      INT DEFAULT 0,
  ctr         FLOAT,
  source      VARCHAR(20) DEFAULT 'gsc',
  recorded_at DATE,
  INDEX idx_keyword (keyword(100)),
  INDEX idx_date    (recorded_at)
)

-- v6 columns added to existing mm_orders via ALTER TABLE ADD COLUMN IF NOT EXISTS:
-- meesho_order_id    VARCHAR(100) DEFAULT ''
-- meesho_tracking_id VARCHAR(100) DEFAULT ''
-- meesho_account     TINYINT DEFAULT 1
-- fulfillment_status VARCHAR(40) DEFAULT 'pending'
-- sla_flagged        TINYINT(1) DEFAULT 0
-- cod_risk_flag      TINYINT(1) DEFAULT 0
-- fulfilled_by       BIGINT UNSIGNED DEFAULT 0
```

---

## 4. Module Specifications

### 4.1 Product Scraping & Import (extend v5)

No breaking changes to `class-scraper.php`. Additions only:

**Scrapling microservice:** Add `MM_Scraper::scrape_via_scrapling(string $url): array|WP_Error`.  
`POST {mm_scrapling_url}/scrape` → `{"url": "..."}`. Expects same JSON shape as `parse_from_browser_html`.  
If `mm_scrapling_url` is empty or `wp_remote_post` returns a `WP_Error`, auto-fall back to HTML paste method silently.

**Duplicate prevention:** Before inserting to `mm_scraped_products`, check `meesho_pid` against both `mm_scraped_products.meesho_pid` and `_mm_meesho_pid` in `wp_postmeta`. If found, return `WP_Error` with link to existing product.

**Review import:** Store rating breakdown as JSON `{"5": 68, "4": 22, "3": 6, "2": 2, "1": 2}`. Import review images to WP Media Library via `media_sideload_image()`, attach to product.

**Pricing rules (applied in `MM_Woo_Import` at import time):**  
`mm_markup_type` (percentage|flat) · `mm_markup_value` · `mm_price_round` (none|nearest_10|nearest_50|nearest_99)

---

### 4.2 Order Management (extend v5)

**Order Tracker UI — full column set:**

| Column | Source | Notes |
|--------|--------|-------|
| Product (name + thumbnail) | `woo_product_id` | |
| Size / Color | `mm_orders.size / color` | |
| SKU | `_mm_meesho_pid`-size | |
| Customer name | WC billing | |
| Phone | WC billing | 📋 copy button |
| Address | WC billing | 📋 copy button |
| Payment | `mm_orders.payment_method` | COD / Prepaid badge |
| Fulfillment status | `fulfillment_status` | Dropdown: Pending → Ordered on Meesho → Tracking Received → Dispatched → Delivered |
| Meesho Order ID | `meesho_order_id` | Editable, saves on blur |
| Tracking ID | `meesho_tracking_id` | Editable, saves on blur |
| Account used | `meesho_account` | Dropdown: Account 1–4 (labels in settings) |
| SLA | Computed | Red flag if `fulfillment_status = pending` AND `created_at < NOW() - 4 hours` |
| COD risk | `cod_risk_flag` | Flag if COD + first-ever order from that phone number |
| Open on Meesho | Link | `https://www.meesho.com/p/{meesho_pid}` — new tab |

**Fulfillment save:** `mm_save_fulfillment` AJAX handler — updates all editable fields + writes to `mm_audit_log` (`action_type = order_update`).

---

### 4.3 SEO + AEO + GEO Engine (new in v6)

> This is the most complex module. Every sub-section is a hard requirement.

---

#### 4.3.1 Failure Handling Rules — Read This First

These three rules govern the entire SEO engine. Enforce in PHP via a central `MM_SEO_Analyzer::call_with_retry()` wrapper used by every AI call:

| Event | Response |
|-------|----------|
| AI API call fails (non-timeout) | Skip the **entire current batch**. Log to `mm_seo_runs.error_log`. Do NOT retry. Continue at next scheduled run. |
| API timeout | Retry the **same request exactly once** after 3-second sleep. If second attempt also times out → mark post as `failed` in run log, continue to next post. |
| AI returns invalid / non-parseable JSON | **Discard entirely.** Do not salvage partial data. Log `json_parse_failed` to run error log. Continue to next post. |

---

#### 4.3.2 Crawler — Data Collection

**Class:** `MM_SEO_Crawler`

```php
public static function collect_post_data(int $post_id): array
```

Returns this exact structure (all keys always present, empty string/array if not found):

```php
[
  'post_id'        => int,
  'post_type'      => string,           // post | page | product
  'title'          => string,
  'content'        => string,           // raw post_content HTML
  'content_text'   => string,           // strip_tags() version
  'word_count'     => int,
  'focus_keyword'  => string,           // resolved via §4.3.3
  'meta_title'     => string,
  'meta_desc'      => string,
  'canonical_url'  => string,
  'headings'       => ['h1'=>[], 'h2'=>[], 'h3'=>[], 'h4'=>[], 'h5'=>[], 'h6'=>[]],
  'images'         => [['src'=>'', 'alt'=>'', 'attachment_id'=>0], ...],
  'internal_links' => [['text'=>'', 'url'=>''], ...],
  'existing_schema'=> string,           // JSON string or '' if none
  'schema_source'  => string,           // 'post_content'|'post_meta'|'seo_plugin'|''
  'seo_plugin'     => string,           // 'yoast'|'rankmath'|'aioseo'|'none'
  'woo_data'       => [],               // only if product: price, sku, categories, attributes
  'gsc_metrics'    => [],               // if GSC connected: clicks, impressions, position, ctr
]
```

**Meta reading priority (read-only):**  
1. Yoast: `_yoast_wpseo_title`, `_yoast_wpseo_metadesc`  
2. RankMath: `rank_math_title`, `rank_math_description`  
3. AIOSEO: `_aioseo_title`, `_aioseo_description`  
4. Fallback: `_mm_seo_title`, `_mm_seo_desc`

**Canonical URL (read-only):**  
`_yoast_wpseo_canonical` → `rank_math_canonical_url` → `get_permalink($post_id)`

**Existing schema extraction — check all three sources:**  
1. `get_post_meta($post_id, '_mm_schema_json', true)`  
2. Parse `post_content` for `<script type="application/ld+json">` via `DOMDocument` (fallback: regex)  
3. Check if active SEO plugin controls schema for this post

**Active SEO plugin detection:**
```php
public static function detect_seo_plugin(): string {
    if (defined('WPSEO_VERSION'))   return 'yoast';
    if (defined('RANK_MATH_VERSION')) return 'rankmath';
    if (defined('AIOSEO_VERSION'))  return 'aioseo';
    return 'none';
}
```

**Restrictions:** Only `get_post()`, `get_post_meta()`, `get_posts()`, `get_the_content()`, native PHP. No `wp_remote_get()` to own site. No `file_get_contents()`.

---

#### 4.3.3 Target Keyword Resolution

Before scoring, resolve the focus keyword in this priority order:

1. `_yoast_wpseo_focuskw` post meta (if Yoast active)
2. `rank_math_focus_keyword` post meta (if RankMath active)
3. `_mm_focus_keyword` post meta (MM's stored keyword)
4. If still empty: extract first 2–3 meaningful words from post title (exclude stop words: a, an, the, in, on, of, for, with, to, is, are, was, were, be, at, by, from, and, or, but)
5. Store the resolved keyword in `_mm_focus_keyword` for future runs

---

#### 4.3.4 PHP Scoring Engine

**Class:** `MM_SEO_Scorer`  
All scoring is deterministic PHP. **Do not ask AI to score.** AI only generates suggestions. PHP scores the crawler data.

**SEO Score (0–100):**

| Factor | Max pts | Rule |
|--------|---------|------|
| Meta optimization | 20 | Title 50–60 chars (+10), desc 120–160 chars (+5), keyword in title (+3), keyword in desc (+2) |
| Heading structure | 15 | Exactly 1 H1 (+7), H1 contains keyword (+4), at least 2 H2s (+4) |
| Keyword usage | 15 | Keyword in first 100 words (+7), density 1–3% (+8) |
| Internal links | 10 | ≥1 link (+5), ≥3 links (+10) |
| Content quality | 20 | Posts: ≥500 words (+20), 300–499 (+12), <300 (+0). Products: ≥300 words (+20), 150–299 (+12) |
| Technical | 20 | All images have alt (+8), schema present (+6), canonical matches permalink (+6) |

**AEO Score (0–100):**

| Factor | Max pts | Rule |
|--------|---------|------|
| Direct answers | 30 | Regex: find `?` sentence followed by paragraph ≤40 words. 1 found (+15), ≥3 found (+30) |
| FAQ presence | 20 | FAQ schema in `existing_schema` (+10), H3/H4 with `?` text ≥3 (+10) |
| Clarity | 20 | `preg_split('/[.!?]+/', $content_text)` — avg sentence length ≤20 words (+20), ≤30 words (+10) |
| Structure | 15 | `<ul>` or `<ol>` present (+8), `<table>` present (+7) |
| Snippet readiness | 15 | Definition-style paragraph in first 200 words ≤50 words (+8), list or table in first 200 words (+7) |

**GEO Score (0–100):**

| Factor | Max pts | Rule |
|--------|---------|------|
| Citability blocks | 30 | Self-contained paragraph 100–200 words (split by `</p>`). 1 found (+15), ≥2 found (+30) |
| Factual density | 20 | Count regex `\d+%` or `\d+\s*(crore\|lakh\|million\|billion\|rupees\|₹\|rs)` or `\b\d{3,}\b`. ≥3 (+20), 1–2 (+10) |
| Brand mentions | 10 | `get_bloginfo('name')` appears in `content_text` case-insensitive (+10) |
| Authority signals | 10 | Author name, date, or source citation found in content (+10) |
| Structure | 15 | Proper heading hierarchy (+8) + any schema markup present (+7) |
| AI crawler accessibility | 15 | `llms.txt` exists at site root (+10), allows GPTBot and ClaudeBot (+5) |

**WooCommerce product-specific overrides:**
- Content quality threshold: 300 words = full score (not 500)
- Technical score: Product schema is mandatory — no Product schema = 0 pts for technical factor
- Meta desc: must contain a price or number to earn full meta desc points
- AEO direct answers (30 pts): skip for products — redistribute those 30 pts proportionally to FAQ (+15) and Structure (+15)

---

#### 4.3.5 AI Analyzer

**Class:** `MM_SEO_Analyzer`  
**Method:** `MM_SEO_Analyzer::analyze(array $post_data): array|WP_Error`  
Uses `MM_OpenRouter::chat()` with model from `mm_openrouter_model_seo` setting.  
**max_tokens:** 2000 for full SEO analysis, 500 for meta-only requests.  
**Model fallback:** If configured model errors or is unavailable, retry with `openai/gpt-4o-mini`.

**System prompt (use this exactly, expand where noted):**

```
You are an SEO/AEO/GEO content analyst for an Indian WooCommerce store.
Analyze the provided page data and return ONLY a valid JSON object.
No prose. No markdown code fences. No text before or after the JSON.

JSON schema:
{
  "post_id": <integer>,
  "suggestions": [
    {
      "type": "<valid type>",
      "current_value": "<exact current value or empty string>",
      "suggested_value": "<specific recommended replacement>",
      "reasoning": "<one sentence explanation>",
      "priority": "<high|medium|low>",
      "confidence": <integer 0-100>,
      "safe_to_apply": <true|false>
    }
  ]
}

VALID SUGGESTION TYPES:
meta_title, meta_desc, alt_tag, internal_link,
content, schema, faq, howto_schema,
llms_txt, citability_block, statistics_inject

SAFE_TO_APPLY RULES — follow exactly, no exceptions:
- Set safe_to_apply = true ONLY when ALL three conditions are met:
    (1) type is one of: meta_title, meta_desc, alt_tag, internal_link
    (2) confidence >= 85
    (3) priority = "high"
- Set safe_to_apply = false for every other type without exception
- NEVER set safe_to_apply = true for: content, schema, faq, howto_schema, llms_txt,
  citability_block, statistics_inject

HARD LIMITS:
- Maximum 10 suggestions per post
- meta_title: must be 50-60 characters
- meta_desc: must be 120-160 characters
- statistics_inject: suggest ONE specific factual sentence with a real data point relevant to the topic
- citability_block: write a complete 120-180 word self-contained factual paragraph

Indian e-commerce context: prices in INR (₹), sizes use Indian standards, audience is Hindi/English bilingual.
```

**User message construction:**  
Truncate `content` to first 1500 words if longer.  
Do not send raw `gsc_metrics` — summarize as: `"GSC data: position {X}, {Y} clicks, {Z} impressions last 28 days"`.

---

#### 4.3.6 PHP Safety Filter

**Class:** `MM_SEO_Safety`

```php
public static function can_auto_apply(array $suggestion): bool {
    $auto_apply_types = ['meta_title', 'meta_desc', 'alt_tag', 'internal_link'];
    return (
        (bool) $suggestion['safe_to_apply'] === true
        && (int) $suggestion['confidence'] >= 85
        && $suggestion['priority'] === 'high'
        && in_array($suggestion['type'], $auto_apply_types, true)
    );
}

/**
 * Schema exception: schema CAN auto-apply ONLY when ALL conditions pass.
 * This is the only override to the standard safe_to_apply = false rule for schema.
 */
public static function can_auto_apply_schema(array $suggestion, string $existing_schema): bool {
    if ((int) $suggestion['confidence'] < 85) return false;
    if (!empty($existing_schema)) return false;          // existing schema = always manual
    $decoded = json_decode($suggestion['suggested_value'], true);
    if ($decoded === null) return false;                 // invalid JSON-LD = reject
    return true;
}
```

**Auto-apply decision table (PHP enforces, not AI):**

| Type | Auto-apply? | Condition |
|------|-------------|-----------|
| `meta_title` | Yes, if safety passes | confidence ≥85, priority = high |
| `meta_desc` | Yes, if safety passes | confidence ≥85, priority = high |
| `alt_tag` | Yes, if safety passes | confidence ≥85, priority = high |
| `internal_link` | Yes, if safety passes | confidence ≥85, priority = high |
| `schema` | Yes, via `can_auto_apply_schema()` only | No existing schema + valid JSON-LD + confidence ≥85 |
| `content` | **Never** | Always manual approval |
| `faq` | **Never** | Always manual approval |
| `howto_schema` | **Never** | Always manual approval |
| `llms_txt` | **Never** | Always manual (site-wide impact) |
| `citability_block` | **Never** | Always manual approval |
| `statistics_inject` | **Never** | Always manual approval |

---

#### 4.3.7 Implementation Engine

**Class:** `MM_SEO_Implementor`

**Before every write:**
```php
MM_Logger::log_before_change(
    action_type:   'seo_apply',
    target_type:   'post',
    target_id:     $post_id,
    suggestion_id: $suggestion_id,  // link back to mm_seo_suggestions.id
    old_value:     $current_value,
    new_value:     $suggested_value,
    actor:         'ai_auto' | 'manual'
);
```

**Writing meta — write to ALL active SEO plugin keys simultaneously:**
```php
// Always write to MM fallback keys
update_post_meta($post_id, '_mm_seo_title', $meta_title);
update_post_meta($post_id, '_mm_seo_desc',  $meta_desc);

// Write to whichever SEO plugin is active
switch (MM_SEO_Crawler::detect_seo_plugin()) {
    case 'yoast':
        update_post_meta($post_id, '_yoast_wpseo_title',    $meta_title);
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
        break;
    case 'rankmath':
        update_post_meta($post_id, 'rank_math_title',       $meta_title);
        update_post_meta($post_id, 'rank_math_description', $meta_desc);
        break;
    case 'aioseo':
        update_post_meta($post_id, '_aioseo_title',         $meta_title);
        update_post_meta($post_id, '_aioseo_description',   $meta_desc);
        break;
}
```

**Writing alt tags:**  
`update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt))`  
If no `attachment_id` (external/CDN image): generate a `content` suggestion to add alt inline instead.

**Writing internal links:**  
`wp_update_post(['ID' => $post_id, 'post_content' => $new_content])`  
Only append link at the end of a relevant paragraph. Never inject mid-sentence.

**Writing schema:**  
Save to `update_post_meta($post_id, '_mm_schema_json', $validated_json)`.  
Output via `wp_head` **only if** `detect_seo_plugin() === 'none'` OR active SEO plugin is not outputting schema for this specific post (check `schema_source !== 'seo_plugin'`). Never output duplicate schema — if Yoast/RankMath already outputs schema for this post, do not add another `<script type="application/ld+json">` block.

---

#### 4.3.8 Wiring v5 AJAX Handlers to v6 Engine

These existing v5 handlers must now also write to v6 tables:

| v5 Handler | What to add in v6 |
|------------|------------------|
| `mm_seo_audit` | After `MM_SEO_Manager::audit_post()`: calculate scores via `MM_SEO_Scorer`, upsert `mm_seo_post_scores`, insert `mm_seo_score_history` snapshot |
| `mm_apply_meta` | After applying meta: write to `mm_audit_log` with `suggestion_id = 0`, `actor = manual` |
| `mm_apply_links` | Same as above |
| `mm_bulk_meta` | Same as above, loop all posts |
| `mm_suggest_links` | Store suggestions in `mm_seo_suggestions` with `type = internal_link`, `status = pending` |

---

#### 4.3.9 Automation Scheduler

**Cron events** (registered on plugin activation):
- `mm_seo_run_morning` → 08:00 IST (`Asia/Kolkata`)
- `mm_seo_run_evening` → 20:00 IST

**Shared hosting safety:** WP-Cron is unreliable on low-traffic sites.  
On every admin page load: if `time() - get_option('mm_seo_last_run_ts') > 90000` (25 hours), show admin notice: *"SEO scan hasn't run in 25+ hours. [Run Now]"*.  
"Run Scan Now" button triggers `mm_run_seo_scan` AJAX handler immediately.

**Each run — exact sequence:**

1. Insert row in `mm_seo_runs` with `status = running`, `trigger_type = cron|manual|user_selected`
2. Fetch up to 10 posts in priority order:
   - Priority 1: posts with no entry in `mm_seo_post_scores` (never scanned)
   - Priority 2: posts where `post_modified > mm_seo_post_scores.last_scanned`
   - Priority 3: posts with oldest `last_scanned` date
   - Post types: `post`, `page`, `product` (published only)
3. For each post:
   - `MM_SEO_Crawler::collect_post_data($post_id)`
   - `MM_SEO_Scorer::score($data)` → PHP scores (no API call)
   - Upsert `mm_seo_post_scores`, insert `mm_seo_score_history`
   - `MM_SEO_Analyzer::analyze($data)` via OpenRouter (wrapped in `call_with_retry()`)
   - Insert valid suggestions to `mm_seo_suggestions` (max `mm_seo_max_suggestions` per post, default 10)
   - For suggestions passing safety filter: auto-apply via `MM_SEO_Implementor`
   - Sleep 1 second before next API call (rate limiting)
4. `UPDATE mm_seo_runs SET status = done|partial_fail, finished_at = NOW()` with final counts
5. `update_option('mm_seo_last_run_ts', time())`

---

#### 4.3.10 GEO-Specific Features

**llms.txt generator** (`MM_SEO_Geo::generate_llms_txt()`):  
Uses `WP_Filesystem` API (never `file_put_contents`). Creates/overwrites `/llms.txt` at WordPress ABSPATH.

```
# {site_name} — AI Crawler Access Policy
# {site_tagline}
# Last updated: {Y-m-d}
# Contact: {admin_email}

User-agent: GPTBot
Allow: /
Disallow: /wp-admin/
Disallow: /cart/
Disallow: /checkout/
Disallow: /my-account/
Disallow: /?s=

User-agent: ClaudeBot
Allow: /
Disallow: /wp-admin/
Disallow: /cart/
Disallow: /checkout/
Disallow: /my-account/
Disallow: /?s=

User-agent: PerplexityBot
Allow: /
Disallow: /wp-admin/

User-agent: Googlebot
Allow: /

Sitemap: {site_url}/sitemap.xml

# About {site_name}
# {2-3 sentence description: site_tagline + top 3 WooCommerce category names}
```

Admin UI: shows current llms.txt content in a `<pre>` block, "Regenerate" button, last-generated timestamp.  
GEO score (AI crawler accessibility +15 pts) checks this file exists and allows GPTBot + ClaudeBot.

**Statistics injection** (`suggestion_type = statistics_inject`):  
Triggered when `MM_SEO_Scorer` finds factual density score < 10 (no numbers in content).  
AI suggests one specific factual sentence with a real number relevant to the topic.  
Never auto-applies. User approves and manually inserts where appropriate.

**Citability blocks** (`suggestion_type = citability_block`):  
AI writes a complete 120–180 word self-contained factual paragraph.  
User approves. Implementor prepends after the first `</p>` in post content.  
Never auto-applies.

---

#### 4.3.11 Schema Generator

**Class:** `MM_Schema_Generator`  
Supported types: `Article`, `Product`, `FAQ`, `HowTo`, `BreadcrumbList`

**Rules:**
1. Build as PHP array → `json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`
2. Validate: `json_decode($output) !== null` — if null, discard entirely
3. **Deduplication:** If `existing_schema` contains same `@type`, generate `suggestion_type = schema` with note "update existing" — never silently overwrite
4. **Conflict check:** If `schema_source = 'seo_plugin'`, do not output via `wp_head` for that post
5. **Product schema minimum required fields:** `@type`, `name`, `description`, `image`, `offers` (with `price`, `priceCurrency = "INR"`, `availability`), `brand`, `sku`

---

### 4.4 AI Copilot (new in v6)

**Class:** `MM_Copilot`

**Interface:** Full-screen slide-in panel accessible from all WP admin pages (floating trigger button).  
Supports: text, image upload (base64 → vision model), file upload (text content in prompt).  
Conversation history stored in `mm_copilot_threads`.  
Model selector with "Show free models only" toggle. Model list cached 1 hour in transient.

**Allowlisted actions:**

| Action | Implementation |
|--------|---------------|
| Publish/unpublish post/page/product | `wp_update_post` |
| Edit post title or content | `wp_update_post` + log |
| Apply SEO suggestion | `MM_SEO_Implementor::apply()` |
| Update product price/stock | `wc_get_product()` → `set_regular_price()` → `save()` |
| Create blog post | `MM_Blog_Writer::generate()` |
| Create landing page | `MM_Landing_Page::generate()` |
| Search orders | `MM_Order_Tracker::get_orders()` |

**PHP-enforced hard denials (check before executing any Copilot action):**
- Action string contains: `DROP`, `TRUNCATE`, `DELETE FROM`, `wp_delete_site`
- Option key requested matches: `/^mm_.*(key|secret|credentials)/i` or WordPress core auth keys
- `mm_copilot_enabled` option is `false`
- Bulk delete of >5 items without per-item confirmation

**Tool-call execution pattern:**
1. Build system prompt with allowlist as structured tool definitions
2. OpenRouter returns `{"action": "...", "params": {...}, "explanation": "...", "is_destructive": true|false}`
3. PHP validates `action` against allowlist — if not found, return refusal message
4. `is_destructive = true` → always show confirmation card regardless of auto-implement setting
5. `mm_copilot_auto_implement = true` AND `is_destructive = false` → execute immediately
6. Strip any known secret option values from response text before displaying to user
7. Log every execution to `mm_audit_log`

**Undo button:** Always visible in chat: "↩ Undo last action" → `MM_Undo::revert_last(get_current_user_id())`

---

### 4.5 Analytics (new in v6)

#### Heatmap Tab
Embed `<iframe src="https://insights.hotjar.com/sites/{mm_hotjar_id}/heatmaps" loading="lazy">`.  
"Analyse with AI" button → sends 30-day GSC summary + page URLs to OpenRouter → returns priority action list as JSON → cards with badges (High/Medium/Low) + Apply/Dismiss per card.

#### Ranking Tracker
GSC API via `mm_gsc_credentials` (AES-encrypted JSON service account key).  
Fetches top 50 keywords weekly via `searchanalytics.query`. Stores in `mm_ranking_data`.  
UI: keyword · page · position · Δ vs last week · impressions · CTR.  
"Add target keyword" manually. Up to 5 competitor root domains for side-by-side comparison.  
Cache GSC responses 24h in transients.

#### Email Reports
Via `wp_mail()`. Daily + weekly.  
From: `mm_report_from_email` → fallback `get_option('admin_email')`.  
Contents: GSC traffic · orders + revenue · ranking Δ · SEO score changes · top/bottom 5 pages · AI actions taken.  
"Send Test Email" button in settings.  
Template: single-column inline-CSS HTML, works on Gmail/Outlook mobile.

---

### 4.6 Settings Page (extend v5)

| Setting key | Type | Description |
|-------------|------|-------------|
| `mm_scrapling_url` | URL | Scrapling microservice endpoint |
| `mm_markup_type` | select | percentage / flat |
| `mm_markup_value` | float | Markup amount |
| `mm_price_round` | select | none / nearest_10 / nearest_50 / nearest_99 |
| `mm_meesho_accounts` | JSON | Array of 4 account label strings |
| `mm_openrouter_key` | text (encrypted) | OpenRouter API key |
| `mm_openrouter_model_seo` | select | Default model for SEO analysis |
| `mm_openrouter_model_blog` | select | Default model for blog writing |
| `mm_openrouter_model_copilot` | select | Default model for Copilot |
| `mm_openrouter_model_image` | select | Default model for image gen |
| `mm_gsc_credentials` | textarea (encrypted) | GSC service account JSON key |
| `mm_hotjar_id` | text | Hotjar Site ID |
| `mm_report_recipients` | text | Comma-separated emails |
| `mm_report_from_email` | email | Sender address for reports |
| `mm_copilot_enabled` | toggle | Enable/disable Copilot entirely |
| `mm_copilot_auto_implement` | toggle | Auto-execute non-destructive Copilot actions |
| `mm_seo_auto_apply` | toggle | Auto-apply safe SEO fixes (meta/alt/links) |
| `mm_seo_run_time_morning` | time | First daily scan time (default 08:00 IST) |
| `mm_seo_run_time_evening` | time | Second daily scan time (default 20:00 IST) |
| `mm_seo_max_suggestions` | number | Max suggestions per post per run (default 10) |

**Encryption (`MM_Crypto`):** AES-256-CBC, key derived from `AUTH_KEY + SECURE_AUTH_SALT`.  
Apply to all options matching: `*_key`, `*_secret`, `*_credentials`.

---

### 4.7 Undo / Restore System

**Class:** `MM_Undo`

**Every destructive write calls:**
```php
MM_Logger::log_before_change(
    string $action_type,
    string $target_type,
    int    $target_id,
    mixed  $old_value,
    mixed  $new_value,
    int    $suggestion_id = 0,  // link to mm_seo_suggestions.id if applicable
    string $actor = 'manual'
): int  // returns log row ID
```

**Soft delete:** Never hard-delete products. `post_status = 'mm_deleted'` (register custom status on `init`). Restore: set back to `publish`.

**Revert:** `MM_Undo::revert(int $log_id)` — reads `old_value`, reapplies via same write path, sets `undone = 1`.  
If `old_value` is null (purged): return error "This action can no longer be undone — the 15-day window has expired."

**Purge cron** (`mm_purge_old_logs`, weekly):  
`UPDATE mm_audit_log SET old_value = NULL, undoable = 0 WHERE purge_after < NOW()`  
Row is kept for audit. Only `old_value` is nulled.

**Score history purge** (same weekly cron):  
`DELETE FROM mm_seo_score_history WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 90 DAY)`

---

## 5. Admin UI Requirements

### Tab structure
```
Meesho Master  (top-level WP admin menu)
├── Import Products       scraper + queue + product library
├── Order Tracker         fulfillment dashboard
├── SEO Engine            score dashboard + suggestions queue + bulk actions
├── Copilot               floating panel (accessible from all admin pages)
├── Analytics
│   ├── Heatmap           Hotjar embed + AI insights
│   ├── Rankings          GSC keyword tracker
│   └── Reports           email report settings + preview
├── Blog & Pages          blog writer + landing page builder
├── Logs & Undo           audit log with undo buttons
└── Settings              all configuration
```

### UI standards (all tabs)
- **Toast notifications** — top-right, auto-dismiss 4s
- **Skeleton loaders** — grey pulsing placeholders; never blank screen
- **Confirmation modal** — all destructive actions (delete, bulk content apply, schema apply)
- **Mobile responsive** — usable on 375px viewport
- **Progress bars** — for all batch operations
- Bulk-action tables: search, filter dropdowns, select-all, pagination (25/page default)
- **Vanilla JS only** — no new jQuery dependency, use `fetch` + `async/await`

### SEO Engine tab — full specification

**Score Dashboard (default view):**
- Sortable table: Post title · Post type · SEO · AEO · GEO (colour-coded: 0–49 red, 50–74 yellow, 75–100 green) · Last scanned · Pending suggestions · Actions
- "Scan Selected" button: checkbox-select specific posts → scan those only (not next-in-queue)
- "Run Scan Now" button: next batch of 10, shows last-run timestamp
- "Export Scores CSV" button
- SEO plugin status banner at top: *"Active SEO plugin: Yoast SEO ✓ — MM will write to Yoast meta keys"*

**Suggestions Queue (second sub-tab):**
- Table: Page · Type · Current Value (truncated) · Suggested Value (truncated) · Priority · Confidence · Status · Actions
- Filter bar: post type · priority · suggestion type · status · score range slider
- "Apply All Safe Fixes" button — applies all `safe_to_apply = true AND status = pending` with confirmation modal listing exact changes
- "Bulk Reject" for selected rows

**Per-post detail panel (slide-in on row click):**
- Score breakdown table (each scoring factor with points earned vs max)
- Score trend chart (`<canvas>` line chart, data from `mm_seo_score_history`)
- All suggestions for this post (approve/reject individually)
- Audit history (from `mm_audit_log`)
- Undo button per applied change

---

## 6. Security Requirements

- Every AJAX handler: `check_ajax_referer('mm_nonce', 'nonce')` + `current_user_can('manage_options')`
- All user input sanitized: `sanitize_text_field`, `sanitize_textarea_field`, `sanitize_url`, `wp_kses_post`
- All DB queries: `$wpdb->prepare()` — zero raw string interpolation
- API keys: never in AJAX responses, never in HTML source, never in log entries
- Copilot: strip any occurrence of secret option values from AI response text before display
- File writes (llms.txt): `WP_Filesystem` API only
- Schema JSON saved to post meta: `wp_kses($json, [])` before saving if HTML is present

---

## 7. Performance Requirements

- Max memory per AJAX request: target <32MB
- No synchronous HTTP in `admin_init`, `init`, or `wp_head`
- All OpenRouter / GSC calls: cron or user-triggered only
- Cache: GSC 24h, OpenRouter models 1h, llms.txt existence check 6h — all transients
- SEO batch: max 10 posts per cron/AJAX call; JS polls at 500ms intervals between batches
- `mm_seo_score_history`: auto-purge rows older than 90 days (weekly cron)

---

## 8. External Repository Logic — Extraction Guide

Read these repos. Extract only the specified logic. Do not copy code. Do not replicate architecture.

| Repo | What to extract |
|------|----------------|
| `ngstcf/ai-seo-auditor` | Audit checklist items → check against §4.3.2 crawler fields; scoring weight adjustments |
| `AgriciDaniel/claude-seo` | Meta generation and keyword analysis prompt patterns → adapt for §4.3.5 system prompt |
| `zubair-trabzada/geo-seo-claude` | GEO citability rules and AI-crawler-accessibility checklist → validate §4.3.10 |
| `every-app/open-seo` | JSON-LD template patterns → validate `MM_Schema_Generator` completeness |
| `aaron-he-zhu/seo-geo-claude-skills` | Combined SEO+GEO signals → check against scoring factors, add any missing |

**Extraction checklist per repo:**
1. Scoring formulas/weights → compare to §4.3.4, update if more accurate
2. Audit rules → add missing checks to `collect_post_data()`
3. JSON-LD patterns → add missing schema types to `MM_Schema_Generator`
4. Prompt structures → merge useful patterns into §4.3.5 system prompt
5. API patterns → add to `MM_DataForSEO` stub

**DataForSEO stub** (`class-dataforseo.php` — not implemented, interface only):
```php
class MM_DataForSEO {
    public static function get_keyword_data(string $keyword, string $location = 'India'): array|WP_Error {
        return new WP_Error('not_implemented', 'DataForSEO not yet configured.');
    }
    public static function get_serp_results(string $keyword): array|WP_Error {
        return new WP_Error('not_implemented', 'DataForSEO not yet configured.');
    }
}
```

---

## 9. Testing Checklist

Run on WooCommerce store with 100+ products, `WP_DEBUG = true`, `WP_DEBUG_LOG = true`.

**Scraping & Import**
- [ ] URL method with Scrapling works end-to-end
- [ ] HTML paste fallback works with zero external services
- [ ] Duplicate PID detection returns error + link to existing product
- [ ] Product variations have correct size/price/stock/SKU
- [ ] Out-of-stock variations are greyed out and not purchasable
- [ ] Pricing markup and rounding apply correctly at import

**Order Management**
- [ ] Orders captured automatically on WooCommerce checkout
- [ ] All v6 columns display correctly (meesho_order_id, account, fulfillment_status, etc.)
- [ ] SLA flag appears for orders pending >4 hours
- [ ] Fulfillment save writes to `mm_audit_log`
- [ ] COD risk flag triggers for first-time phone numbers

**SEO Engine — Core**
- [ ] Crawler returns all required fields including existing schema
- [ ] Keyword resolution follows correct priority order (Yoast → RankMath → title)
- [ ] PHP scoring returns expected values for a known test post
- [ ] WooCommerce product uses 300-word threshold (not 500)
- [ ] Product with no schema gets 0 for technical factor

**SEO Engine — AI & Failures**
- [ ] AI returns valid JSON matching expected schema
- [ ] Malformed JSON discarded, engine continues gracefully, run log updated
- [ ] API timeout triggers exactly one retry then skips post
- [ ] AI failure skips entire batch, logs to `mm_seo_runs.error_log`
- [ ] Model fallback triggers when primary model unavailable

**SEO Engine — Safety**
- [ ] Safety filter never auto-applies: content, faq, llms_txt, citability_block, statistics_inject
- [ ] Schema `can_auto_apply_schema()`: auto-applies when no existing schema + valid JSON + confidence ≥85
- [ ] Schema `can_auto_apply_schema()`: does NOT auto-apply when existing schema is present
- [ ] Manual approval applies change, writes `suggestion_id` to `mm_audit_log`

**SEO Engine — Data Tables**
- [ ] `mm_seo_post_scores` upserts correctly after each scan
- [ ] `mm_seo_score_history` grows with each scan
- [ ] Score trend chart displays correctly in per-post detail panel
- [ ] Max suggestions cap (default 10) respected per post per run

**SEO Engine — Scheduler & UI**
- [ ] Cron fires at configured IST times
- [ ] Admin notice appears when last run >25 hours ago
- [ ] "Run Scan Now" works when cron hasn't fired
- [ ] "Scan Selected Posts" scans only selected posts (not next-in-queue)
- [ ] SEO plugin status banner shows correct active plugin
- [ ] v5 handlers (`mm_apply_meta`, etc.) still work and write to `mm_audit_log`

**Schema & GEO**
- [ ] Schema validates at schema.org/validator
- [ ] Deduplication: existing same `@type` → update suggestion, not new creation
- [ ] Schema not output via `wp_head` when Yoast/RankMath handles it
- [ ] llms.txt at site root contains all required bot entries + disallows + About section
- [ ] GEO score: AI crawler accessibility pts awarded correctly
- [ ] Statistics inject suggestion generated for content with no numbers

**Undo / Audit**
- [ ] Undo restores old value within 15-day window
- [ ] Post 15-day: `old_value` null, log row exists, undo shows expired message
- [ ] `mm_audit_log.suggestion_id` correctly populated when applying SEO suggestion
- [ ] Soft delete: `post_status = mm_deleted`; restore reverts to `publish`
- [ ] 90-day score history purge runs without error

**Copilot**
- [ ] Cannot read or display `mm_*_key` option values
- [ ] Non-allowlisted actions refused with explanation
- [ ] Destructive actions always show confirmation card (even if auto_implement = true)

**General**
- [ ] All API keys AES-encrypted in `wp_options`
- [ ] No PHP warnings/errors with WP_DEBUG = true
- [ ] Memory per request under 32MB (`memory_get_peak_usage()`)
- [ ] Activates and deactivates cleanly on shared hosting
- [ ] All queries use `$wpdb->prepare()`

---

## 10. Delivery Format

```
meesho-master/
├── meesho-master.php                  version = 6.0.0
├── includes/
│   ├── class-db.php                   + v6 tables, ALTER TABLE for mm_orders
│   ├── class-scraper.php              + Scrapling, duplicate check
│   ├── class-openrouter.php           + list_models(), vision, model fallback
│   ├── class-woo-import.php           + pricing rules
│   ├── class-order-tracker.php        + fulfillment v6 columns
│   ├── class-seo-crawler.php          NEW: collect_post_data(), detect_seo_plugin()
│   ├── class-seo-analyzer.php         NEW: analyze(), call_with_retry()
│   ├── class-seo-scorer.php           NEW: score(), all PHP scoring logic
│   ├── class-seo-safety.php           NEW: can_auto_apply(), can_auto_apply_schema()
│   ├── class-seo-implementor.php      NEW: apply(), multi-plugin meta writing
│   ├── class-seo-geo.php              NEW: generate_llms_txt(), citability, stats inject
│   ├── class-schema-generator.php     NEW: Article/Product/FAQ/HowTo/Breadcrumb
│   ├── class-copilot.php              NEW: chat, tool-call, allowlist enforcement
│   ├── class-analytics.php            NEW: GSC, Hotjar insights, email reports
│   ├── class-logger.php               NEW: log_before_change(), log()
│   ├── class-undo.php                 NEW: revert(), revert_last()
│   ├── class-crypto.php               NEW: encrypt(), decrypt() AES-256-CBC
│   ├── class-dataforseo.php           NEW: stub class only
│   ├── class-seo-manager.php          UPDATED: wired to v6 tables per §4.3.8
│   ├── class-blog-writer.php          minor updates
│   ├── class-landing-page.php         minor updates
│   └── class-ajax.php                 UPDATED: all new v6 handlers + v5 bridges
├── admin/
│   └── class-admin.php                UPDATED: new tab registration
├── assets/
│   ├── js/admin.js                    UPDATED: all new UI components
│   └── css/admin.css                  UPDATED
└── readme.txt
```

---

## 11. What NOT to Do

- ❌ Do not rebuild v5 from scratch — extend only
- ❌ Do not use `shell_exec`, `exec`, `passthru`, `system`, or any shell function
- ❌ Do not `wp_remote_get()` or `file_get_contents()` your own site
- ❌ Do not make synchronous HTTP calls in WordPress hooks
- ❌ Do not store plaintext API keys in `wp_options`
- ❌ Do not use jQuery for new v6 JS — vanilla `fetch` + `async/await` only
- ❌ Do not add npm/webpack/build pipeline — plain files only
- ❌ Do not copy code from the referenced GitHub repos
- ❌ Do not auto-apply content rewrites, FAQs, citability blocks, llms.txt, or statistics
- ❌ Do not auto-apply schema if the post already has schema of same `@type`
- ❌ Do not output schema via `wp_head` if Yoast/RankMath already handles it for that post
- ❌ Do not write any value without first logging `old_value` to `mm_audit_log`
- ❌ Do not trust AI output for safety decisions — PHP safety filter is always authoritative
- ❌ Do not retry a failed API request more than once

---

## 12. Gap Analysis Reference (What Was Fixed vs Previous Version)

This version resolves all 29 gaps identified in the gap analysis between the original two specification documents and the v1 master prompt. Key fixes:

| # | Gap | Fix location |
|---|-----|-------------|
| 1 | Schema auto-apply exception missing | §4.3.6 `can_auto_apply_schema()` |
| 2 | Failure handling had no dedicated section | §4.3.1 (new dedicated section) |
| 3 | "Retry once" was vague | §4.3.1: "exactly once after 3s pause" |
| 4 | Suggestion type enum incomplete | §3 DB table + §4.3.5 prompt: added `citability_block`, `statistics_inject`, `howto_schema` |
| 5 | "Stale" undefined | §4.3.9: `post_modified > last_scanned` |
| 6 | Scanner priority queue undefined | §4.3.9: never-scanned → recently-modified → oldest |
| 7 | No max suggestions cap | §4.3.9 + settings: `mm_seo_max_suggestions`, default 10 |
| 8 | v5 AJAX handlers not wired to v6 | §4.3.8: explicit bridge table |
| 9 | Keyword determination missing | §4.3.3: priority resolution + `_mm_focus_keyword` storage |
| 10 | Schema extraction method unspecified | §4.3.2: DOMDocument + `_mm_schema_json` meta |
| 11 | Canonical URL check unspecified | §4.3.2: 3-source resolution chain |
| 12 | SEO plugin conflict indicator missing | §5 SEO Engine tab: status banner |
| 13 | Schema deduplication missing | §4.3.11: same `@type` → update suggestion |
| 14 | Schema output conflict | §4.3.7: `wp_head` only when `schema_source != seo_plugin` |
| 15 | llms.txt incomplete | §4.3.10: `# About`, `Disallow` for WooCommerce URLs |
| 16 | Statistics injection missing | §4.3.10 + §4.3.6 table: `statistics_inject` type |
| 17 | Brand mentions as separate GEO signal | §4.3.4: explicit `brand mentions` row in GEO scoring |
| 18 | WooCommerce-specific SEO rules missing | §4.3.4: product overrides section |
| 19 | `mm_seo_post_scores` table missing | §3: new table added |
| 20 | Score history missing | §3: `mm_seo_score_history` table + §4.3.9 populates it |
| 21 | "Scan Selected Posts" missing | §4.3.9 + §5 SEO Engine tab |
| 22 | Per-post detail panel missing | §5 SEO Engine tab |
| 23 | Model fallback unspecified | §4.3.5: fallback to `openai/gpt-4o-mini` |
| 24 | DataForSEO stub undefined | §8: stub class with method signatures |
| 25 | Audit log missing `suggestion_id` | §3: column added to `mm_audit_log` |
| 26 | Post scan tracking via scores table | §3: `mm_seo_post_scores` with `last_scanned` |
| 27 | AEO direct answers detection method | §4.3.4: regex pattern specified |
| 28 | Cron unreliability on shared hosting | §4.3.9: admin notice + manual trigger |
| 29 | OpenRouter token budget unspecified | §4.3.5: 2000 for analysis, 500 for meta |

---

*This document was compiled from: the v5 codebase (`meesho-v5.zip`), the Meesho Master v6 vision document, and the SEO+AEO+GEO Automation Engine specification. All 29 gaps have been resolved.*
