<?php
/**
 * Plugin Name: WooSearch Integration
 * Description: Replaces WooCommerce product search with semantic vector search.
 * Version: 1.3.0
 * Author: WooSearch
 */

if (!defined('ABSPATH')) exit;

// ============================================================================
// Settings Page (Settings > WooSearch)
// ============================================================================
add_action('admin_menu', function () {
    add_options_page(
        'WooSearch Settings',
        'WooSearch',
        'manage_options',
        'woo-search-settings',
        'woo_search_render_settings_page'
    );
});

/**
 * Resolves the bulk import API endpoint.
 */
function woo_search_get_bulk_import_endpoint() {
    $custom = get_option('woo_search_bulk_import_url', '');
    if (!empty($custom)) {
        return $custom;
    }

    $search_url = get_option('woo_search_api_url', '');
    if (empty($search_url)) {
        return '';
    }

    if (preg_match('#/search/?$#', $search_url)) {
        return preg_replace('#/search/?$#', '/bulk-import', $search_url);
    }

    return rtrim($search_url, '/') . '/bulk-import';
}

add_action('admin_init', function () {
    // Search API URL
    register_setting('woo_search_settings', 'woo_search_api_url', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ]);

    // Bulk Import API URL
    register_setting('woo_search_settings', 'woo_search_bulk_import_url', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ]);

    // API Consumer Secret (Optional)
    register_setting('woo_search_settings', 'woo_search_secret_key', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ]);

    add_settings_section(
        'woo_search_main',
        'API Configuration',
        function () {
            echo '<p>Connect your WooSearch AI backend by configuring your API endpoints below.</p>';
        },
        'woo-search-settings'
    );

    add_settings_field(
        'woo_search_api_url',
        'Search API Endpoint',
        function () {
            $url = get_option('woo_search_api_url', '');
            echo '<input type="url" name="woo_search_api_url" value="' . esc_attr($url) . '" class="regular-text" placeholder="http://127.0.0.1:8000/api/search">';
            echo '<p class="description">Endpoint for product search queries (e.g. <code>http://127.0.0.1:8000/api/search</code>).</p>';
        },
        'woo-search-settings',
        'woo_search_main'
    );

    add_settings_field(
        'woo_search_bulk_import_url',
        'Bulk Import Endpoint',
        function () {
            $url = get_option('woo_search_bulk_import_url', '');
            $derived = woo_search_get_bulk_import_endpoint();
            echo '<input type="url" name="woo_search_bulk_import_url" value="' . esc_attr($url) . '" class="regular-text" placeholder="' . esc_attr($derived ? $derived : 'http://127.0.0.1:8000/api/bulk-import') . '">';
            echo '<p class="description">Endpoint for catalog bulk import. Leave blank to auto-derive from Search API endpoint: <code>' . esc_html($derived ? $derived : 'http://127.0.0.1:8000/api/bulk-import') . '</code>.</p>';
        },
        'woo-search-settings',
        'woo_search_main'
    );

    add_settings_field(
        'woo_search_secret_key',
        'Consumer Secret / API Key',
        function () {
            $secret = get_option('woo_search_secret_key', '');
            echo '<input type="password" name="woo_search_secret_key" value="' . esc_attr($secret) . '" class="regular-text" autocomplete="new-password">';
            echo '<p class="description">Optional secret passed as <code>consumer-secret</code> header for authorized endpoints.</p>';
        },
        'woo-search-settings',
        'woo_search_main'
    );
});

function woo_search_render_settings_page() {
    if (!current_user_can('manage_options')) return;
    $bulk_endpoint = woo_search_get_bulk_import_endpoint();
    ?>
    <div class="wrap">
        <h1>WooSearch Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('woo_search_settings');
            do_settings_sections('woo-search-settings');
            submit_button();
            ?>
        </form>

        <hr style="margin: 30px 0;">

        <h2>Store Onboarding & Catalog Synchronization</h2>
        <p>Synchronize your store's published products with the WooSearch semantic vector database. Essential when connecting WooSearch to a new store or syncing full catalog updates.</p>

        <div class="card" style="max-width: 680px; padding: 20px; margin-top: 15px;">
            <h3 style="margin-top: 0;">Bulk Import Products</h3>
            <p>Triggers the backend bulk import endpoint to fetch all published products from WooCommerce, generate embeddings, and populate Qdrant vector storage.</p>
            <p><strong>Target Endpoint:</strong> <code><?php echo esc_html($bulk_endpoint ? $bulk_endpoint : 'Not configured'); ?></code></p>

            <p style="margin-top: 20px;">
                <button type="button" id="woo-search-bulk-import-btn" class="button button-primary button-hero" <?php disabled(empty($bulk_endpoint)); ?>>
                    <span class="dashicons dashicons-database-import" style="vertical-align: text-top; margin-right: 6px;"></span>Bulk Import Products
                </button>
                <span id="woo-search-bulk-import-spinner" class="spinner" style="float: none; vertical-align: middle; margin-left: 10px;"></span>
            </p>

            <?php if (empty($bulk_endpoint)) : ?>
                <p class="description" style="color: #d63638;">Please save your Search API Endpoint above to enable bulk import.</p>
            <?php endif; ?>

            <div id="woo-search-bulk-import-status" style="margin-top: 15px; display: none;"></div>
        </div>
    </div>

    <script>
    jQuery(function ($) {
        $('#woo-search-bulk-import-btn').on('click', function (e) {
            e.preventDefault();

            if (!confirm('Start bulk import of all published products into WooSearch? This will re-index your catalog into the vector store. Depending on store size, this can take a few moments.')) {
                return;
            }

            var $btn = $(this);
            var $spinner = $('#woo-search-bulk-import-spinner');
            var $status = $('#woo-search-bulk-import-status');

            $btn.prop('disabled', true);
            $spinner.addClass('is-active');
            $status.removeClass('notice-success notice-error')
                   .addClass('notice notice-info inline')
                   .html('<p><strong>Bulk import in progress:</strong> Fetching products and computing vector embeddings. Please keep this page open...</p>')
                   .show();

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 360000,
                data: {
                    action: 'woo_search_bulk_import',
                    nonce: '<?php echo wp_create_nonce("woo_search_bulk_import_nonce"); ?>'
                },
                success: function (res) {
                    if (res.success) {
                        var msg = (res.data && res.data.message) ? res.data.message : 'Bulk import completed successfully!';
                        $status.removeClass('notice-info notice-error')
                               .addClass('notice notice-success inline')
                               .html('<p><strong>Success!</strong> ' + $('<div/>').text(msg).html() + '</p>');
                    } else {
                        var err = (res.data && res.data.message) ? res.data.message : 'An error occurred during bulk import.';
                        $status.removeClass('notice-info notice-success')
                               .addClass('notice notice-error inline')
                               .html('<p><strong>Import Failed:</strong> ' + $('<div/>').text(err).html() + '</p>');
                    }
                },
                error: function (xhr, status, error) {
                    var detail = error || status || 'Server error';
                    $status.removeClass('notice-info notice-success')
                           .addClass('notice notice-error inline')
                           .html('<p><strong>Request Error:</strong> ' + $('<div/>').text(detail).html() + '. Please check server logs and API connectivity.</p>');
                },
                complete: function () {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                }
            });
        });
    });
    </script>
    <?php
}

// Show admin notice if API URL is not configured.
add_action('admin_notices', function () {
    $url = get_option('woo_search_api_url', '');
    if (!empty($url)) return;

    $settings_url = admin_url('options-general.php?page=woo-search-settings');
    echo '<div class="notice notice-warning"><p>';
    echo '<strong>WooSearch:</strong> Search API endpoint is not configured. ';
    echo '<a href="' . esc_url($settings_url) . '">Set it up now →</a>';
    echo '</p></div>';
});

// Add a quick "Settings" link on the Plugins page.
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $url = admin_url('options-general.php?page=woo-search-settings');
    array_unshift($links, '<a href="' . esc_url($url) . '">Settings</a>');
    return $links;
});

// ============================================================================
// Bulk Import AJAX Handler
// ============================================================================
add_action('wp_ajax_woo_search_bulk_import', function () {
    check_ajax_referer('woo_search_bulk_import_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized: Insufficient permissions.']);
    }

    $endpoint = woo_search_get_bulk_import_endpoint();
    if (empty($endpoint)) {
        wp_send_json_error(['message' => 'API endpoint is not configured. Please save your API endpoint first.']);
    }

    $headers = [
        'Accept' => 'application/json',
    ];
    $secret = get_option('woo_search_secret_key', '');
    if (!empty($secret)) {
        $headers['consumer-secret'] = $secret;
        $headers['consumer_secret'] = $secret;
    }

    // Call the bulk import endpoint. Support long timeout (300s) for vector generation.
    $response = wp_remote_get($endpoint, [
        'timeout' => 300,
        'headers' => $headers,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error([
            'message' => 'Could not connect to backend bulk import endpoint: ' . $response->get_error_message()
        ]);
    }

    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($code >= 400) {
        $error_detail = 'Backend returned HTTP ' . $code;
        if (is_array($data)) {
            if (!empty($data['detail'])) {
                $error_detail .= ': ' . (is_string($data['detail']) ? $data['detail'] : wp_json_encode($data['detail']));
            } elseif (!empty($data['message'])) {
                $error_detail .= ': ' . $data['message'];
            }
        } elseif (!empty($body)) {
            $error_detail .= ': ' . substr(strip_tags($body), 0, 200);
        }
        wp_send_json_error(['message' => $error_detail]);
    }

    $message = 'Bulk import completed successfully.';
    if (is_array($data) && !empty($data['message'])) {
        $message = $data['message'];
    } elseif (is_array($data) && isset($data['count'])) {
        $message = sprintf('Successfully imported and indexed %d product(s).', intval($data['count']));
    }

    wp_send_json_success(['message' => $message, 'data' => $data]);
});

// ============================================================================
// Search API Integration
// ============================================================================

/**
 * Fetch product IDs from the search API. Cached per request.
 */
function woo_search_get_api_ids() {
    static $result = null;
    static $fetched = false;

    if ($fetched) return $result;
    $fetched = true;

    $api_url = get_option('woo_search_api_url', '');
    if (empty($api_url)) return null;

    $search_term = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    if ($search_term === '') return null;

    $headers = ['Content-Type' => 'application/json'];
    $secret = get_option('woo_search_secret_key', '');
    if (!empty($secret)) {
        $headers['consumer-secret'] = $secret;
        $headers['consumer_secret'] = $secret;
    }

    $response = wp_remote_post($api_url, [
        'timeout' => 15,
        'body'    => wp_json_encode(['query' => $search_term]),
        'headers' => $headers,
    ]);

    if (is_wp_error($response)) return null;
    if (wp_remote_retrieve_response_code($response) >= 400) return null;

    $decoded = json_decode(wp_remote_retrieve_body($response), true);

    $ids = [];
    if (is_array($decoded)) {
        if (isset($decoded['ids']) && is_array($decoded['ids'])) {
            $ids = array_map('intval', $decoded['ids']);
        } elseif (isset($decoded['products']) && is_array($decoded['products'])) {
            $ids = array_map('intval', $decoded['products']);
        } elseif (isset($decoded['items']) && is_array($decoded['items'])) {
            $ids = array_map('intval', $decoded['items']);
        } elseif (array_filter($decoded, 'is_numeric')) {
            $ids = array_map('intval', $decoded);
        }
    }

    $result = $ids;
    return $result;
}

// 1. Feed search IDs into WooCommerce's product query via loop_shop_post_in
add_filter('loop_shop_post_in', function ($post_in) {
    if (is_admin() || !is_search()) return $post_in;

    $ids = woo_search_get_api_ids();
    if ($ids === null) return $post_in;
    if (empty($ids)) return [0];

    return $ids;
});

// 2. Preserve ranking order
add_action('woocommerce_product_query', function ($query) {
    if (!is_search()) return;

    $ids = woo_search_get_api_ids();
    if ($ids === null) return;

    if (empty($ids)) {
        $query->set('post__in', [0]);
        return;
    }

    $query->set('post__in', $ids);
    $query->set('orderby', 'post__in');
});

// 3. Remove native full-text SQL matching so semantic matches aren't filtered out
add_filter('posts_search', function ($search, $query) {
    if (is_admin()) return $search;

    $pt = $query->get('post_type');
    $is_product = ($pt === 'product') || (is_array($pt) && in_array('product', $pt, true));
    if (!$is_product) return $search;
    if ($query->get('s') === '') return $search;

    $ids = woo_search_get_api_ids();
    if ($ids === null) return $search;

    return '';
}, 10, 2);

// 4. Ensure search forms include post_type=product
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script('jquery');
    wp_add_inline_script('jquery', <<<'JS'
jQuery(function ($) {
    $(document).on('submit', 'form.wp-block-search, form[role="search"], form.search-form', function () {
        var $form = $(this);
        if (!$form.find('input[name="post_type"]').length) {
            $form.append('<input type="hidden" name="post_type" value="product">');
        }
    });
});
JS
    );
});