<?php

if ( ! class_exists( 'MM_SEO_Analyzer' ) ) {
class MM_SEO_Analyzer {
private $settings;

public function __construct() {
$this->settings = new Meesho_Master_Settings();
}

public function analyze( $post_data ) {
$api_key = $this->settings->get( 'openrouter_api_key' );
if ( '' === $api_key ) {
return new WP_Error( 'mm_openrouter_missing', 'OpenRouter API key not configured.' );
}

$model = $this->settings->get( 'ai_model_seo' );
if ( '' === $model ) {
$model = 'openai/gpt-4o-mini';
}

$response = $this->call_with_retry( $model, $this->build_messages( $post_data ), $api_key );
if ( is_wp_error( $response ) ) {
if ( 'openai/gpt-4o-mini' !== $model ) {
$response = $this->call_with_retry( 'openai/gpt-4o-mini', $this->build_messages( $post_data ), $api_key );
}
if ( is_wp_error( $response ) ) {
return $response;
}
}

$payload = json_decode( wp_remote_retrieve_body( $response ), true );
$raw     = trim( (string) ( $payload['choices'][0]['message']['content'] ?? '' ) );
$decoded = json_decode( $raw, true );
if ( ! is_array( $decoded ) || ! isset( $decoded['suggestions'] ) || ! is_array( $decoded['suggestions'] ) ) {
return new WP_Error( 'mm_invalid_json', 'AI returned invalid JSON.' );
}

$limit = max( 1, (int) $this->settings->get( 'mm_seo_max_suggestions', 10 ) );
$decoded['suggestions'] = array_slice( $decoded['suggestions'], 0, $limit );
return $decoded['suggestions'];
}

public function call_with_retry( $model, $messages, $api_key ) {
$args = array(
'timeout' => 20,
'headers' => array(
'Authorization' => 'Bearer ' . $api_key,
'Content-Type'  => 'application/json',
),
'body' => wp_json_encode(
array(
'model'       => $model,
'messages'    => $messages,
'temperature' => 0.2,
'max_tokens'  => 2000,
)
),
);
$response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', $args );
if ( is_wp_error( $response ) && false !== stripos( $response->get_error_message(), 'timed out' ) ) {
$response = wp_remote_post( 'https://openrouter.ai/api/v1/chat/completions', $args );
}
return $response;
}

private function build_messages( $post_data ) {
$content_words = preg_split( '/\s+/', (string) ( $post_data['content_text'] ?? '' ) );
$content_text  = implode( ' ', array_slice( $content_words, 0, 1500 ) );
$user_content  = array(
'post_id' => (int) ( $post_data['post_id'] ?? 0 ),
'title' => (string) ( $post_data['title'] ?? '' ),
'meta_title' => (string) ( $post_data['meta_title'] ?? '' ),
'meta_desc' => (string) ( $post_data['meta_desc'] ?? '' ),
'focus_keyword' => (string) ( $post_data['focus_keyword'] ?? '' ),
'word_count' => (int) ( $post_data['word_count'] ?? 0 ),
'content' => $content_text,
'headings' => $post_data['headings'] ?? array(),
'internal_links' => count( $post_data['internal_links'] ?? array() ),
'missing_alts' => (int) ( $post_data['missing_alts'] ?? 0 ),
'schema_source' => (string) ( $post_data['schema_source'] ?? '' ),
);

return array(
array(
'role'    => 'system',
'content' => 'You are an SEO/AEO/GEO content analyst for an Indian WooCommerce store. Analyze the provided page data and return ONLY a valid JSON object. No prose. No markdown code fences. No text before or after the JSON. JSON schema: {"post_id":<integer>,"suggestions":[{"type":"<valid type>","current_value":"<exact current value or empty string>","suggested_value":"<specific recommended replacement>","reasoning":"<one sentence explanation>","priority":"<high|medium|low>","confidence":<integer 0-100>,"safe_to_apply":<true|false>}]} VALID SUGGESTION TYPES: meta_title, meta_desc, alt_tag, internal_link, content, schema, faq, howto_schema, llms_txt, citability_block, statistics_inject SAFE_TO_APPLY RULES — follow exactly, no exceptions: Set safe_to_apply = true ONLY when ALL three conditions are met: (1) type is one of: meta_title, meta_desc, alt_tag, internal_link (2) confidence >= 85 (3) priority = "high". Set safe_to_apply = false for every other type. HARD LIMITS: Maximum 10 suggestions per post; meta_title 50-60 chars; meta_desc 120-160 chars; statistics_inject = one specific factual sentence; citability_block = 120-180 word self-contained factual paragraph. Indian e-commerce context: prices in INR (₹), sizes use Indian standards, audience is Hindi/English bilingual.',
),
array(
'role'    => 'user',
'content' => wp_json_encode( $user_content ),
),
);
}
}
}
