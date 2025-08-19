<?php
/*
Plugin Name: WooCommerce Referral- TechiEvolve
Description: A custom referral system for WooCommerce that rewards users for successful referrals.
Version: 1.7
Author: TechiEvolve
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
function cwr_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>WooCommerce Referral- TechiEvolve</strong> requires WooCommerce to be installed and active.</p></div>';
        });
        return false;
    }
    return true;
}

// Enqueue stylesheet
add_action('wp_enqueue_scripts', 'cwr_enqueue_styles');
function cwr_enqueue_styles() {
    // Register and enqueue plugin stylesheet
    wp_enqueue_style(
        'cwr-referral-style', 
        plugin_dir_url(__FILE__) . 'assets/css/style.css', 
        array(), 
        filemtime(plugin_dir_path(__FILE__) . 'assets/css/style.css') // cache-busting
    );

    // SweetAlert2 from CDN
    wp_enqueue_script('sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], '11.7.0', true);
    // SweetAlert2 CSS
    //wp_enqueue_style( 'sweetalert2-css', 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css', array(), '11.7.12' );

    wp_enqueue_script('cwr-referral', plugin_dir_url(__FILE__) . 'assets/js/cwr-referral.js',  ['jquery', 'sweetalert2'], time(), true);

    // Localize ajax data
    wp_localize_script('cwr-referral', 'cwr_ajax', [
        'url'   => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'cwr_verify_nonce' ),
        'verify_text' => __( 'Verify', 'cwr' ),
    ]);

}

// Include WP_List_Table for Affiliates table
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

// Include custom Affiliates table class
require_once plugin_dir_path(__FILE__) . 'includes/class-cwr-affiliates-table.php';

// Create database table on plugin activation
function cwr_create_referral_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'cwr_referrals';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        referrer_id BIGINT(20) UNSIGNED NOT NULL,
        referee_id BIGINT(20) UNSIGNED DEFAULT NULL,
        referee_email VARCHAR(255) DEFAULT NULL,
        order_id BIGINT(20) UNSIGNED DEFAULT NULL,
        credit_amount DECIMAL(10,2) DEFAULT 0.00,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY referrer_id (referrer_id),
        KEY referee_id (referee_id),
        KEY order_id (order_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'cwr_create_referral_table');

// Create Verify Referral Code page on plugin activation
function cwr_create_verify_referral_page() {
    if (!cwr_check_woocommerce()) {
        return;
    }

    $page = [
        'title' => 'Verify Referral Code',
        'slug' => 'referral-program',
        'content' => '[cwr_verify_referral_code]',
    ];

    if (!get_page_by_path($page['slug'])) {
        $page_args = [
            'post_title' => $page['title'],
            'post_name' => $page['slug'],
            'post_content' => $page['content'],
            'post_status' => 'publish',
            'post_type' => 'page',
        ];
        wp_insert_post($page_args);
    }
}
register_activation_hook(__FILE__, 'cwr_create_verify_referral_page');

// Add Referral Dashboard endpoint to My Account
function cwr_add_referral_dashboard_endpoint() {
    add_rewrite_endpoint('referral-dashboard', EP_ROOT | EP_PAGES);
}
add_action('init', 'cwr_add_referral_dashboard_endpoint');

// Register query vars for endpoint
function cwr_referral_dashboard_query_vars($vars) {
    $vars[] = 'referral-dashboard';
    return $vars;
}
add_filter('query_vars', 'cwr_referral_dashboard_query_vars');

// Add Referral Dashboard to My Account menu
function cwr_add_referral_dashboard_to_my_account_menu($items) {
    if (is_user_logged_in() && in_array('customer', wp_get_current_user()->roles)) {
        $new_items = [];
        foreach ($items as $key => $value) {
            $new_items[$key] = $value;
            if ($key === 'dashboard') {
                $new_items['referral-dashboard'] = __('Referrals', 'cwr');
            }
        }
        return $new_items;
    }
    return $items;
}
add_filter('woocommerce_account_menu_items', 'cwr_add_referral_dashboard_to_my_account_menu');

// Render Referral Dashboard content
function cwr_referral_dashboard_content() {
    if (!is_user_logged_in() || !in_array('customer', wp_get_current_user()->roles)) {
        wp_safe_redirect(wc_get_page_permalink('my-account'));
        exit;
    }
    echo do_shortcode('[cwr_referral_dashboard]');
}
add_action('woocommerce_account_referral-dashboard_endpoint', 'cwr_referral_dashboard_content');

// Flush rewrite rules on plugin activation
function cwr_flush_rewrite_rules() {
    cwr_add_referral_dashboard_endpoint();
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'cwr_flush_rewrite_rules');

// Generate and retrieve referral code
function cwr_get_user_referral_code($user_id) {
    $code = get_user_meta($user_id, 'cwr_referral_code', true);
    
    if (empty($code) || strlen($code) !== 6 || !ctype_alnum($code)) {
        // Generate 6-character alphanumeric code
        $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $code = '';
        for ($i = 0; $i < 6; $i++) {
            $code .= $characters[rand(0, strlen($characters) - 1)];
        }
        
        // Ensure code is unique
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM $wpdb->usermeta WHERE meta_key = 'cwr_referral_code' AND meta_value = %s AND user_id != %d",
            $code,
            $user_id
        ));
        
        if ($existing) {
            // Recursively try again if code exists
            return cwr_get_user_referral_code($user_id);
        }
        
        update_user_meta($user_id, 'cwr_referral_code', $code);
        error_log('CWR Referral: Generated new 6-character code ' . $code . ' for user ID ' . $user_id);
    }
    
    return $code;
}

// Get referral link
function cwr_get_user_referral_link($user_id) {
    $referral_code = cwr_get_user_referral_code($user_id);
    return add_query_arg('ref', $referral_code, home_url());
}

// Initialize wallet balance
function cwr_initialize_wallet_balance($user_id) {
    $meta_key = 'cwr_wallet_balance';
    $current_balance = get_user_meta($user_id, $meta_key, true);
    if ($current_balance === '') {
        $updated = update_user_meta($user_id, $meta_key, '0.00');
        if ($updated) {
            error_log('CWR Wallet: Initialized wallet balance for user ID ' . $user_id . ' to 0.00');
        } else {
            error_log('CWR Wallet: Failed to initialize wallet balance for user ID ' . $user_id);
            global $wpdb;
            error_log('CWR Wallet: Last database error: ' . $wpdb->last_error);
        }
    } else {
        error_log('CWR Wallet: Wallet balance already exists for user ID ' . $user_id . ': ' . $current_balance);
    }
}

// Update wallet balance
function cwr_update_wallet_balance($user_id, $amount) {
    $meta_key = 'cwr_wallet_balance';
    cwr_initialize_wallet_balance($user_id); // Ensure wallet is initialized
    $current_balance = floatval(get_user_meta($user_id, $meta_key, true));
    $new_balance = $current_balance + floatval($amount);
    if ($new_balance < 0) {
        error_log('CWR Wallet: Attempted to set negative balance for user ID ' . $user_id . '. Aborted.');
        return false;
    }
    $updated = update_user_meta($user_id, $meta_key, $new_balance);
    if ($updated) {
        error_log('CWR Wallet: Updated balance for user ID ' . $user_id . ': ' . $new_balance . ' (Change: ' . $amount . ')');
    } else {
        error_log('CWR Wallet: Failed to update wallet balance for user ID ' . $user_id);
        global $wpdb;
        error_log('CWR Wallet: Last database error: ' . $wpdb->last_error);
    }
    return $new_balance;
}

// Calculate redeemable wallet amount
function cwr_get_redeemable_amount($user_id) {
    // Get wallet balance from user meta
    $wallet_balance = floatval(get_user_meta($user_id, 'cwr_wallet_balance', true));

    // Maximum redeemable amount per order
    $max_redeem = 30;

    // Return min of wallet balance and max limit
    return min($wallet_balance, $max_redeem);
}

// Add redemption option to checkout
function cwr_add_wallet_redeem_option() {
    if (!is_user_logged_in()) {
        error_log('CWR Wallet: User not logged in, skipping redeem option');
        return;
    }

    if (!cwr_check_woocommerce()) {
        error_log('CWR Wallet: WooCommerce not active, skipping redeem option');
        return;
    }

    $user = wp_get_current_user();
    error_log('CWR Wallet: Checking redeem option for user ID ' . $user->ID . ', roles: ' . implode(', ', (array)$user->roles));
    
    if (!in_array('customer', (array)$user->roles)) {
        error_log('CWR Wallet: User ID ' . $user->ID . ' does not have customer role, skipping redeem option');
        return;
    }

    // Initialize wallet balance to ensure cwr_wallet_balance exists
    cwr_initialize_wallet_balance($user->ID);
    
    $redeemable_amount = cwr_get_redeemable_amount($user->ID);
    if ($redeemable_amount <= 0) {
        error_log('CWR Wallet: No redeemable amount for user ID ' . $user->ID . ', skipping redeem option');
        return;
    }

    $template_path = plugin_dir_path(__FILE__) . 'templates/checkout-redeem-wallet.php';
    if (!file_exists($template_path)) {
        error_log('CWR Wallet: Template file not found at ' . $template_path);
        return;
    }

    error_log('CWR Wallet: Displaying redeem option for user ID ' . $user->ID . ', redeemable amount: ' . $redeemable_amount);
    ob_start();
    include $template_path;
    echo ob_get_clean();
}
add_action('woocommerce_checkout_before_customer_details', 'cwr_add_wallet_redeem_option');

// AJAX handler for discount preview and session update
function cwr_get_wallet_discount_preview() {
    check_ajax_referer('cwr_redeem_wallet', 'nonce');

    if (!is_user_logged_in() || !cwr_check_woocommerce()) {
        wp_send_json_error(['message' => 'Unauthorized or WooCommerce not active.']);
    }

    $user = wp_get_current_user();
    if (!in_array('customer', (array)$user->roles)) {
        wp_send_json_error(['message' => 'Not an authorized user.']);
    }

    $wallet_balance = floatval(get_user_meta($user->ID, 'cwr_wallet_balance', true));

    if ($wallet_balance <= 0) {
        wp_send_json_error(['message' => 'Insufficient wallet balance to redeem.']);
    }

    $redeem_active = isset($_POST['redeem_active']) && $_POST['redeem_active'] === '1';
    $redeem_amount = $redeem_active ? (isset($_POST['redeem_amount']) ? floatval($_POST['redeem_amount']) : 0) : 0;

    $max_redeem = 30;

    if ($redeem_amount > $max_redeem) {
        wp_send_json_error(['message' => 'Max $30 redeemable per order']);
    }

    if ($redeem_amount > $wallet_balance) {
        wp_send_json_error(['message' => "You don't have enough balance in your wallet"]);
    }

    $cart = WC()->cart;
    $cart_total = $cart->get_subtotal();
    $discount = min($redeem_amount, $cart_total);

    WC()->session->set('cwr_redeem_wallet_active', $redeem_active);
    WC()->session->set('cwr_wallet_discount', $redeem_active ? $discount : 0);
    WC()->session->set('cwr_redeem_amount', $redeem_active ? $redeem_amount : 0);

    wp_send_json_success([
        'discount' => wc_price($discount),
        'new_total' => wc_price($cart_total - $discount),
        'message' => $redeem_active ? sprintf(__('Wallet discount of %s applied!', 'cwr'), wc_price($discount), wc_price($cart_total - $discount)) : __('Wallet discount removed.', 'cwr')
    ]);
}
add_action('wp_ajax_cwr_wallet_discount_preview', 'cwr_get_wallet_discount_preview');

// Apply wallet discount to cart for real-time total update
function cwr_apply_wallet_discount() {
    if (!is_user_logged_in() || !cwr_check_woocommerce()) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('customer', (array)$user->roles)) {
        return;
    }

    $redeem_active = WC()->session->get('cwr_redeem_wallet_active', false);
    if (!$redeem_active) {
        return;
    }

    $discount = WC()->session->get('cwr_wallet_discount', 0);

    if ($discount > 0) {
        WC()->cart->add_fee(__('Wallet Discount', 'cwr'), -$discount);
        error_log('CWR Wallet: Applied temporary discount of ' . $discount . ' for user ID ' . $user->ID);
    }
}
add_action('woocommerce_cart_calculate_fees', 'cwr_apply_wallet_discount');

// Apply wallet discount during order creation
function cwr_apply_wallet_discount_to_order($order) {
    if (!is_user_logged_in() || !cwr_check_woocommerce() || !isset($_POST['cwr_redeem_wallet']) || !wp_verify_nonce($_POST['cwr_redeem_nonce'], 'cwr_redeem_wallet')) {
        return;
    }

    $user = wp_get_current_user();
    if (!in_array('customer', (array)$user->roles)) {
        return;
    }

    $redeem_amount = isset($_POST['cwr_redeem_amount']) ? floatval($_POST['cwr_redeem_amount']) : 0;
    if ($redeem_amount <= 0) {
        return;
    }

    $wallet_balance = floatval(get_user_meta($user->ID, 'cwr_wallet_balance', true));
    $max_redeem = 30;
    if ($redeem_amount > $max_redeem || $redeem_amount > $wallet_balance) {
        return;
    }

    $cart_total = WC()->cart->get_subtotal();
    $discount = min($redeem_amount, $cart_total);

    if ($discount > 0) {
        $order->add_fee(__('Wallet Discount', 'cwr'), -$discount);
        $order->update_meta_data('_cwr_wallet_discount', $discount);
        error_log('CWR Wallet: Applied discount of ' . $discount . ' to order for user ID ' . $user->ID);
    }
}
add_action('woocommerce_checkout_create_order', 'cwr_apply_wallet_discount_to_order');

// Deduct wallet balance on order completion
function cwr_deduct_wallet_on_order_complete($order_id) {
    $order = wc_get_order($order_id);
    if ($order->get_status() !== 'completed') {
        return;
    }

    $user_id = $order->get_user_id();
    if (!$user_id) {
        return;
    }

    $discount = floatval($order->get_meta('_cwr_wallet_discount'));
    if ($discount > 0) {
        cwr_update_wallet_balance($user_id, -$discount);
        error_log('CWR Wallet: Deducted ' . $discount . ' from wallet for user ID ' . $user_id . ' on order ' . $order_id);
    }

    // Clear session
    WC()->session->set('cwr_redeem_wallet_active', false);
    WC()->session->set('cwr_wallet_discount', 0);
    WC()->session->set('cwr_redeem_amount', 0);
}
add_action('woocommerce_order_status_completed', 'cwr_deduct_wallet_on_order_complete');

/**
 * Handle referral code verification (AJAX)
 */
function cwr_handle_verify_referral_code() {
    global $wpdb;

    // Security check
    if ( ! isset($_POST['cwr_verify_nonce']) || ! wp_verify_nonce($_POST['cwr_verify_nonce'], 'cwr_verify_referral_nonce') ) {
        wp_send_json_error(['message' => __('Invalid request.', 'cwr'), 'type'=> __('req-error.', 'cwr')]);
    }

    // Must be logged in
    if ( ! is_user_logged_in() ) {
        wp_send_json_error(['message' => __('You must be logged in to verify a referral code.', 'cwr'), 'type'=> __('login-error.', 'cwr')]);
    }

    $user_id       = get_current_user_id();
    $referral_code = isset($_POST['cwr_referral_code']) ? sanitize_text_field($_POST['cwr_referral_code']) : '';

    if ( empty($referral_code) ) {
        wp_send_json_error([
        'message' => __('The referral code you entered is not valid. Please try again.', 'cwr'), 
        'title' => __('Invalid Referral Code'),
        'type'=> __('code-error.', 'cwr')
    ]);
}

// Check if user has already been referred
if ( get_user_meta($user_id, 'cwr_referred_by', true) ) {
    wp_send_json_error([
        'message' => __('You’ve already been referred. Referral can only be used once.', 'cwr'), 
        'title' => __('Already Referred'),
        'type'=> __('attempt-error.', 'cwr')
        ]);
    }
    
    // Find user with referral code
    $referrer = get_users([
        'meta_key'   => 'cwr_referral_code',
        'meta_value' => $referral_code,
        'number'     => 1,
        'fields'     => 'ID',
    ]);
    
    if ( empty($referrer) ) {

        wp_send_json_error([
            'message' => __('The referral code you entered is not valid. Please try again.', 'cwr'), 
            'title' => __('Invalid Referral Code'),
            'type'=> __('code-error.', 'cwr')
        ]);
    }

    $referrer_id = $referrer[0];

    // Prevent self-referral
    if ( $referrer_id == $user_id ) {
        wp_send_json_error([
            'message' => __('You cannot refer yourself. Please use a friend’s referral code.', 'cwr'), 
            'title' => __('Self-Referral Not Allowed'),
            'type'=> __('self-error.', 'cwr')
        ]);
    }

    // Mark referral
    update_user_meta($user_id, 'cwr_referred_by', $referrer_id);

    // Insert referral record
    $dataEntry = $wpdb->insert(
        $wpdb->prefix . 'cwr_referrals',
        [
            'referrer_id'   => $referrer_id,
            'referee_id'    => $user_id,
            'referee_email' => get_userdata($user_id)->user_email,
            'created_at'    => current_time('mysql'),
        ],
        ['%d', '%d', '%s', '%s']
    );
    $insert_query = $wpdb->last_query; // store it immediately

    // Initialize wallets
    cwr_initialize_wallet_balance($user_id);     // Referee
    cwr_initialize_wallet_balance($referrer_id); // Referrer

    // Send only ONE response
    if ( $dataEntry ) {
        wp_send_json_success([
            'message'     => __('Complete your first order to get $10 credit.', 'cwr'),
            'title' => __('Success! Referral Code Verified'),
            'type'=> __('code-success.', 'cwr'),
            'last_query'  => $insert_query,
            'last_error'  => $wpdb->last_error,
        ]);
    } else {
        wp_send_json_error([
            'message'    => __('Failed to record referral.', 'cwr'),
            'type'=> __('db-error.', 'cwr'),
            'last_query' => $insert_query,
            'last_error' => $wpdb->last_error,
        ]);
    }
}
add_action('wp_ajax_cwr_verify_referral_code', 'cwr_handle_verify_referral_code');
add_action('wp_ajax_nopriv_cwr_verify_referral_code', 'cwr_handle_verify_referral_code');


// Apply credits on order status change
function cwr_apply_referral_credits($order_id, $old_status, $new_status) {
    $settings = get_option('cwr_general_settings', [
        'wallet_credit_type' => 'flat',
        'wallet_credit_amount' => 0,
        'who_gets_credit' => 'both',
        'first_order_only' => 0,
        'order_status' => 'completed',
    ]);

    if ($new_status !== $settings['order_status']) {
        return;
    }

    $order = wc_get_order($order_id);
    $user_id = $order->get_user_id();
    if (!$user_id) {
        return;
    }

    global $wpdb;
    $referral = $wpdb->get_row($wpdb->prepare(
        "SELECT id, referrer_id, referee_id FROM {$wpdb->prefix}cwr_referrals WHERE referee_id = %d AND order_id IS NULL",
        $user_id
    ));

    if (!$referral) {
        return;
    }

    if ($settings['first_order_only']) {
        $orders = wc_get_orders(['customer_id' => $user_id, 'limit' => -1, 'status' => ['wc-completed', 'wc-processing']]);
        if (count($orders) > 1) {
            return;
        }
    }

    $amount = floatval($settings['wallet_credit_amount']);
    if ($settings['wallet_credit_type'] === 'percentage') {
        $amount = ($amount / 100) * $order->get_total();
    }

    if (in_array($settings['who_gets_credit'], ['referrer', 'both'])) {
        $referrer = get_userdata($referral->referrer_id);
        if ($referrer) {
            cwr_update_wallet_balance($referral->referrer_id, $amount);
            $wpdb->update(
                $wpdb->prefix . 'cwr_referrals',
                ['order_id' => $order_id, 'credit_amount' => $amount, 'created_at' => current_time('mysql')],
                ['id' => $referral->id],
                ['%d', '%f', '%s'],
                ['%d']
            );
            error_log('CWR Referral: Credited wallet for referrer ID ' . $referral->referrer_id . ': ' . $amount);
        }
    }

    if (in_array($settings['who_gets_credit'], ['referee', 'both'])) {
        $referee = get_userdata($referral->referee_id);
        if ($referee) {
            cwr_update_wallet_balance($referral->referee_id, $amount);
            error_log('CWR Referral: Credited wallet for referee ID ' . $referral->referee_id . ': ' . $amount);
        }
    }
}
add_action('woocommerce_order_status_changed', 'cwr_apply_referral_credits', 10, 3);

// Add settings page
function cwr_add_admin_menu() {
    add_menu_page(
        __('Referral Plugin Settings', 'cwr'),
        __('Referral Plugin', 'cwr'),
        'manage_options',
        'cwr-settings',
        'cwr_settings_page_callback',
        'dashicons-groups',
        30
    );

    add_submenu_page(
        'cwr-settings',
        __('General Settings', 'cwr'),
        __('General Settings', 'cwr'),
        'manage_options',
        'cwr-general-settings',
        'cwr_general_settings_page_callback'
    );

    add_submenu_page(
        'cwr-settings',
        __('Affiliates', 'cwr'),
        __('Affiliates', 'cwr'),
        'manage_options',
        'cwr-settings-affiliates',
        'cwr_affiliates_page_callback'
    );

    remove_submenu_page('cwr-settings', 'cwr-settings');
}
add_action('admin_menu', 'cwr_add_admin_menu');

// Register settings
function cwr_register_settings() {
    register_setting('cwr_general_settings_group', 'cwr_general_settings', [
        'sanitize_callback' => 'cwr_sanitize_general_settings',
    ]);

    add_settings_section(
        'cwr_general_settings_section',
        __('General Referral Settings', 'cwr'),
        function() {
            echo '<p>' . esc_html__('Configure the referral program settings below.', 'cwr') . '</p>';
        },
        'cwr-general-settings'
    );

    add_settings_field(
        'cwr_wallet_credit_type',
        __('Wallet Credit Type', 'cwr'),
        'cwr_wallet_credit_type_callback',
        'cwr-general-settings',
        'cwr_general_settings_section'
    );

    add_settings_field(
        'cwr_wallet_credit_amount',
        __('Wallet Credit Amount', 'cwr'),
        'cwr_wallet_credit_amount_callback',
        'cwr-general-settings',
        'cwr_general_settings_section'
    );

    add_settings_field(
        'cwr_who_gets_credit',
        __('Who Gets Credit', 'cwr'),
        'cwr_who_gets_credit_callback',
        'cwr-general-settings',
        'cwr_general_settings_section'
    );

    add_settings_field(
        'cwr_first_order_only',
        __('Use for First Order Only', 'cwr'),
        'cwr_first_order_only_callback',
        'cwr-general-settings',
        'cwr_general_settings_section'
    );

    add_settings_field(
        'cwr_order_status',
        __('Order Status', 'cwr'),
        'cwr_order_status_callback',
        'cwr-general-settings',
        'cwr_general_settings_section'
    );
}
add_action('admin_init', 'cwr_register_settings');

// Sanitize settings
function cwr_sanitize_general_settings($input) {
    $sanitized = [];

    $sanitized['wallet_credit_type'] = in_array($input['wallet_credit_type'], ['percentage', 'flat']) ? $input['wallet_credit_type'] : 'flat';
    $sanitized['wallet_credit_amount'] = floatval($input['wallet_credit_amount']) >= 0 ? floatval($input['wallet_credit_amount']) : 0;
    $sanitized['who_gets_credit'] = in_array($input['who_gets_credit'], ['referrer', 'referee', 'both']) ? $input['who_gets_credit'] : 'both';
    $sanitized['first_order_only'] = isset($input['first_order_only']) ? 1 : 0;
    $sanitized['order_status'] = in_array($input['order_status'], ['processing', 'completed']) ? $input['order_status'] : 'completed';

    return $sanitized;
}

// Settings field callbacks
function cwr_wallet_credit_type_callback() {
    $options = get_option('cwr_general_settings', []);
    $value = isset($options['wallet_credit_type']) ? $options['wallet_credit_type'] : 'flat';
    ?>
    <select name="cwr_general_settings[wallet_credit_type]">
        <option value="percentage" <?php selected($value, 'percentage'); ?>><?php esc_html_e('Percentage', 'cwr'); ?></option>
        <option value="flat" <?php selected($value, 'flat'); ?>><?php esc_html_e('Flat Amount', 'cwr'); ?></option>
    </select>
    <p class="description"><?php esc_html_e('Select whether the credit is a percentage of the order or a flat amount.', 'cwr'); ?></p>
    <?php
}

function cwr_wallet_credit_amount_callback() {
    $options = get_option('cwr_general_settings', []);
    $value = isset($options['wallet_credit_amount']) ? floatval($options['wallet_credit_amount']) : 0;
    ?>
    <span style="display: inline-block; vertical-align: middle; line-height: 28px;">$</span>
    <input type="number" step="0.01" min="0" name="cwr_general_settings[wallet_credit_amount]" value="<?php echo esc_attr($value); ?>" style="width: 100px;" />
    <p class="description"><?php esc_html_e('Enter the credit amount (e.g., 10 for $10 or 10% depending on credit type).', 'cwr'); ?></p>
    <?php
}

function cwr_who_gets_credit_callback() {
    $options = get_option('cwr_general_settings', []);
    $value = isset($options['who_gets_credit']) ? $options['who_gets_credit'] : 'both';
    ?>
    <select name="cwr_general_settings[who_gets_credit]">
        <option value="referrer" <?php selected($value, 'referrer'); ?>><?php esc_html_e('Referrer', 'cwr'); ?></option>
        <option value="referee" <?php selected($value, 'referee'); ?>><?php esc_html_e('Referee', 'cwr'); ?></option>
        <option value="both" <?php selected($value, 'both'); ?>><?php esc_html_e('Both', 'cwr'); ?></option>
    </select>
    <p class="description"><?php esc_html_e('Select who receives the wallet credit.', 'cwr'); ?></p>
    <?php
}

function cwr_first_order_only_callback() {
    $options = get_option('cwr_general_settings', []);
    $value = isset($options['first_order_only']) ? $options['first_order_only'] : 0;
    ?>
    <input type="checkbox" name="cwr_general_settings[first_order_only]" value="1" <?php checked($value, 1); ?> />
    <p class="description"><?php esc_html_e('Check to apply credit only to the referee\'s first order.', 'cwr'); ?></p>
    <?php
}

function cwr_order_status_callback() {
    $options = get_option('cwr_general_settings', []);
    $value = isset($options['order_status']) ? $options['order_status'] : 'completed';
    ?>
    <select name="cwr_general_settings[order_status]">
        <option value="processing" <?php selected($value, 'processing'); ?>><?php esc_html_e('Processing', 'cwr'); ?></option>
        <option value="completed" <?php selected($value, 'completed'); ?>><?php esc_html_e('Completed', 'cwr'); ?></option>
    </select>
    <p class="description"><?php esc_html_e('Select the order status that triggers the credit.', 'cwr'); ?></p>
    <?php
}

// Settings page callback
function cwr_settings_page_callback() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Referral Plugin Settings', 'cwr'); ?></h1>
        <p><?php esc_html_e('Welcome to the Referral Plugin settings. Use the menu to navigate through different sections.', 'cwr'); ?></p>
    </div>
    <?php
}

// General settings page callback
function cwr_general_settings_page_callback() {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('General Settings', 'cwr'); ?></h1>
        <?php if (isset($_GET['settings-updated'])): ?>
            <div class="updated notice is-dismissible">
                <p><?php esc_html_e('Settings saved successfully.', 'cwr'); ?></p>
            </div>
        <?php endif; ?>
        <form method="post" action="options.php">
            <?php
            settings_fields('cwr_general_settings_group');
            do_settings_sections('cwr-general-settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// Affiliates page callback
function cwr_affiliates_page_callback() {
    $affiliates_table = new CWR_Affiliates_Table();
    $affiliates_table->prepare_items();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Affiliates', 'cwr'); ?></h1>
        <form method="get">
            <input type="hidden" name="page" value="cwr-settings-affiliates">
            <?php $affiliates_table->display(); ?>
        </form>
    </div>
    <?php
}

// Register shortcodes
function cwr_referral_dashboard_shortcode() {
    ob_start();
    include plugin_dir_path(__FILE__) . 'templates/referral-dashboard.php';
    return ob_get_clean();
}
add_shortcode('cwr_referral_dashboard', 'cwr_referral_dashboard_shortcode');

function cwr_verify_referral_code_shortcode() {
    ob_start();
    include plugin_dir_path(__FILE__) . 'templates/verify-referral-code.php';
    return ob_get_clean();
}
add_shortcode('cwr_verify_referral_code', 'cwr_verify_referral_code_shortcode');

// Redirect after login to preserve /referral-program
add_filter('woocommerce_login_redirect', 'cwr_login_redirect', 10, 2);
function cwr_login_redirect($redirect, $user) {
    if (isset($_GET['redirect_to']) && $_GET['redirect_to'] === '/referral-program') {
        $redirect = home_url('/referral-program');
        error_log('CWR Redirect: Redirecting user ID ' . $user->ID . ' to /referral-program after login');
    }
    return $redirect;
}

// Redirect after registration to preserve /referral-program
add_filter('woocommerce_registration_redirect', 'cwr_registration_redirect', 10, 1);
function cwr_registration_redirect($redirect) {
    if (isset($_GET['redirect_to']) && $_GET['redirect_to'] === '/referral-program') {
        $redirect = home_url('/referral-program');
        error_log('CWR Redirect: Redirecting new user to /referral-program after registration');
    }
    return $redirect;
}

// Add instruction message on My Account page for verify-referral-code redirect
add_action('woocommerce_before_my_account', 'cwr_add_verify_instruction_message', 20);
add_action('woocommerce_account_content', 'cwr_add_verify_instruction_message', 20); // Fallback hook
function cwr_add_verify_instruction_message() {
    $redirect_to = isset($_GET['redirect_to']) ? sanitize_text_field($_GET['redirect_to']) : '';
    $is_not_logged_in = !is_user_logged_in();
    $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $is_my_account = is_page('my-account') || is_page(wc_get_page_id('my-account'));
    
    error_log('CWR My Account: Checking instruction message. redirect_to="' . $redirect_to . '", is_not_logged_in=' . ($is_not_logged_in ? 'true' : 'false') . ', is_my_account=' . ($is_my_account ? 'true' : 'false') . ', current_url=' . $current_url);
    
    if (in_array($redirect_to, ['/referral-program', urlencode('/referral-program'), 'referral-program']) && $is_not_logged_in && $is_my_account) {
        echo '<div class="cwr-notice cwr-notice-info" style="background: #b1c2a0a6!important; padding: 10px !important; margin-bottom: 20px !important; border-left: 4px solid #37604e !important;">';
        echo '<p style="font-size: inherit; font-weight:600;">' . esc_html__('Please register or log in before applying your referral code.', 'cwr') . '</p>';
        echo '</div>';
        error_log('CWR My Account: Displayed instruction message for verify-referral-code redirect');
    }
}

// Fallback: Add instruction message via the_content filter
add_filter('the_content', 'cwr_add_verify_instruction_to_page_content');
function cwr_add_verify_instruction_to_page_content($content) {
    $redirect_to = isset($_GET['redirect_to']) ? sanitize_text_field($_GET['redirect_to']) : '';
    $is_not_logged_in = !is_user_logged_in();
    $is_my_account = is_page('my-account') || is_page(wc_get_page_id('my-account'));
    $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    
    error_log('CWR My Account: Checking the_content filter. redirect_to="' . $redirect_to . '", is_not_logged_in=' . ($is_not_logged_in ? 'true' : 'false') . ', is_my_account=' . ($is_my_account ? 'true' : 'false') . ', current_url=' . $current_url);
    
    if (in_array($redirect_to, ['/referral-program', urlencode('/referral-program'), 'referral-program']) && $is_not_logged_in && $is_my_account) {
        $notice = '<div class="cwr-notice cwr-notice-info" style="background: #b1c2a0a6!important; padding: 10px !important; margin-bottom: 20px !important; border-left: 4px solid #37604e !important;">';
        $notice .= '<p style="font-size: inherit; font-weight:600;">' . esc_html__('Please register or log in before applying your referral code.', 'cwr') . '</p>';
        $notice .= '</div>';
        error_log('CWR My Account: Displayed instruction message via the_content filter');
        return $notice . $content;
    }
    return $content;
}

?>