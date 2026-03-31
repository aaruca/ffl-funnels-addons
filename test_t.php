<?php
// Load WordPress to access options
require_once('../../../wp-load.php');

\ = get_option('woobooster_settings', []);
\  = trim(\['openai_key'] ?? '');

if (empty(\)) {
    echo "No API Key found.";
    exit;
}

\ = 'Florida';
\ = 'FL';
\ = 'March';
\ = '2026';
\ = "No web search available. Use training knowledge for {\} ({\}) sales tax rates as of {\} {\}.";

\ = "Extract ALL county-level combined sales tax rates (state + county) for {\} ({\}).";
\ = 'Each entry: {"county": "string", "city": null, "rate": 8.25, "zip_patterns": ["123*"]}. The rate field must be a float (percentage, e.g. 8.25 not 0.0825). zip_patterns is an array of WooCommerce postcode wildcards if you know them, otherwise an empty array.';

\ = "You are a US tax data expert. Today is {\} {\}. Extract structured sales tax data from the provided research content. Return ONLY a valid JSON array, no markdown, no explanation.";

\ = "{\}\n\nFormat: {\}\n\nResearch content:\n\n{\}";

\ = wp_remote_post('https://api.openai.com/v1/chat/completions', [
    'body' => wp_json_encode([
        'model'       => 'gpt-4o-mini',
        'messages'    => [
            ['role' => 'system', 'content' => \],
            ['role' => 'user',   'content' => \],
        ],
        'temperature' => 0,
    ]),
    'headers' => [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . \,
    ],
    'timeout' => 90,
]);

if (is_wp_error(\)) {
    echo "WP_Error: " . \->get_error_message();
    exit;
}

\ = wp_remote_retrieve_body(\);
echo substr(\, 0, 500) . "...\n";

\ = json_decode(\, true);
\ = \['choices'][0]['message']['content'] ?? '';
echo "Content Length: " . strlen(\) . "\n";
echo "Parsed as array? " . (is_array(json_decode(\, true)) ? 'Yes' : 'No') . "\n";

?>
