<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get product data from RepairShopr API
 * 
 * @param string $sku SKU to look up
 * @return array|false Product data or false on failure
 */
function get_repairshopr_product($sku) {
    static $apiCallCounter = 0;
    static $batchStartTime = null;
    $maxCallsPerInterval = REPAIRSHOPR_SYNC_API_CALLS_PER_MINUTE;
    $intervalLengthInSeconds = REPAIRSHOPR_SYNC_RATE_LIMIT_SECONDS;

    // Initialize the batch timer if not set
    if ($batchStartTime === null) {
        $batchStartTime = microtime(true);
    }

    $currentTime = microtime(true);
    $elapsed = $currentTime - $batchStartTime;

    // Reset counter if interval has passed
    if ($elapsed > $intervalLengthInSeconds) {
        $apiCallCounter = 0;
        $batchStartTime = microtime(true);
    }

    // Wait if we've reached the rate limit
    if ($apiCallCounter >= $maxCallsPerInterval) {
        $waitTime = $intervalLengthInSeconds - $elapsed;
        if ($waitTime > 0) {
            error_log('API limit reached, waiting for ' . $waitTime . ' seconds to continue.');
            usleep($waitTime * 1000000);
        }
        $apiCallCounter = 0;
        $batchStartTime = microtime(true);
    }

    // Get API key from settings
    $api_key = get_option(REPAIRSHOPR_SYNC_OPTION_KEY);
    if ((defined('REPAIRSHOPR_SYNC_SECRET') || defined('AUTH_KEY')) && !empty($api_key)) {
        $secret = defined('REPAIRSHOPR_SYNC_SECRET') ? REPAIRSHOPR_SYNC_SECRET : (defined('AUTH_KEY') ? AUTH_KEY : '');
        if (!empty($secret)) {
            $api_key = openssl_decrypt($api_key, 'AES-256-CBC', $secret, 0, substr(hash('sha256', $secret), 0, 16));
        }
    }
    if (empty($api_key)) {
        error_log('RepairShopr API key not configured');
        return false;
    }

    $api_url = "https://dataforgesys.repairshopr.com/api/v1/products?id=" . urlencode($sku);
    $response = wp_remote_get($api_url, [
        'headers' => [
            'Authorization' => $api_key,
            'Accept' => 'application/json'
        ]
    ]);

    $apiCallCounter++;

    if (is_wp_error($response)) {
        error_log('Failed to retrieve product from RepairShopr for SKU: ' . $sku . ' | Error: ' . $response->get_error_message());
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (isset($data['error']) && strpos($body, 'high number of requests') !== false) {
        error_log('Rate limit reached, pausing for ' . $intervalLengthInSeconds . ' seconds.');
        usleep($intervalLengthInSeconds * 1000000);
        $apiCallCounter = 0;
        $batchStartTime = microtime(true);
        return false;
    }

    if (isset($data['products']) && is_array($data['products']) && !empty($data['products'][0])) {
        return $data['products'][0];
    } else {
        error_log('No matching product found in RepairShopr for SKU: ' . $sku . ' or the "products" key is missing or empty in the API response.');
        return false;
    }
}
