<?php
if (!defined('ABSPATH')) {
    exit;
}

// Redirect to My Account with redirect_to parameter if not logged in or not a customer
if (!is_user_logged_in() || !in_array('customer', wp_get_current_user()->roles)) {
    $my_account_url = get_site_url() . '/my-account';
    if (!$my_account_url || $my_account_url === home_url('/')) {
        error_log('CWR Verify: My Account page not set correctly, defaulting to /my-account');
        $my_account_url = get_site_url() . '/my-account';
    }
    $redirect_url = add_query_arg('redirect_to', '/referral-program', $my_account_url);
    wp_safe_redirect($redirect_url);
    error_log('CWR Verify: Redirecting unauthenticated or non-customer user to ' . $redirect_url);
    exit;
}

$user_id = get_current_user_id();

// Initialize wallet for logged-in user if not exists
if (!function_exists('cwr_wallet_exists') || !cwr_wallet_exists($user_id)) {
    cwr_initialize_wallet_balance($user_id);
}

$referral_code   = cwr_get_user_referral_code($user_id);
$prefilled_code  = isset($_GET['ref']) ? sanitize_text_field($_GET['ref']) : '';

error_log('CWR Verify: Loaded /referral-program for user ID ' . $user_id . ', code: ' . $referral_code);
?>

<?php
if (!defined('ABSPATH')) {
    exit;
}

// Redirect to My Account with redirect_to parameter if not logged in or not a customer
if (!is_user_logged_in() || !in_array('customer', wp_get_current_user()->roles)) {
    $my_account_url = get_site_url() . '/my-account';
    if (!$my_account_url || $my_account_url === home_url('/')) {
        error_log('CWR Verify: My Account page not set correctly, defaulting to /my-account');
        $my_account_url = get_site_url() . '/my-account';
    }
    $redirect_url = add_query_arg('redirect_to', '/referral-program', $my_account_url);
    wp_safe_redirect($redirect_url);
    error_log('CWR Verify: Redirecting unauthenticated or non-customer user to ' . $redirect_url);
    exit;
}

// Initialize wallet for logged-in user if not exists
if (!cwr_wallet_exists(get_current_user_id())) {
    cwr_initialize_wallet_balance(get_current_user_id());
}

$user_id = get_current_user_id();
$prefilled_code = isset($_GET['ref']) ? sanitize_text_field($_GET['ref']) : '';

error_log('CWR Verify: Loaded /referral-program for user ID ' . $user_id);
?>

<section class="referral-section">
    <div class="referral-container">
        
        <!-- Left Side: Redeem Your Code -->
        <div class="referral-left">
            <h2>Verify Your Referral Code</h2>
            <p class="small-text">Unlock $10 credit for you and your friend on your next order.</p>

            <?php
                if ($success = get_transient('cwr_verify_success')) {
                    echo '<div class="notice notice-success" style="background: #e7f3e7; padding: 10px; margin-bottom: 20px; border-left: 4px solid #46b450;">' . esc_html($success) . '</div>';
                    delete_transient('cwr_verify_success');
                }
                if ($error = get_transient('cwr_verify_error')) {
                    echo '<div class="notice notice-error" style="background: #f8e6e6; padding: 10px; margin-bottom: 20px; border-left: 4px solid #dc3232;">' . esc_html($error) . '</div>';
                    delete_transient('cwr_verify_error');
                }
                ?>

            <form method="post" action="">
                <input type="text" name="cwr_referral_code" placeholder="Enter your code" class="referral-input" value="<?php echo esc_attr($prefilled_code); ?>" maxlength="6" required/>
                <?php wp_nonce_field('cwr_verify_referral', 'cwr_verify_nonce'); ?>
                <input type="submit" class="apply-btn" name="cwr_verify_submit" value="<?php esc_attr_e('Verify', 'cwr'); ?>">
            </form>

            <!-- <p class="disclaimer">
                By applying a code you agree to the Whole Earth Gift Referral Program terms. Credits are store credit for future purchases and have no cash value.
            </p> -->
        </div>

         <!-- Right Side: Heads up + How It Works -->
         <div class="referral-right">
            <div class="info-box">
                <p><strong>Heads up:</strong> Credits unlock after the first order is placed successfully.</p>
            </div>

            <h3 class="how-title"><strong>How It Works <span>[ 3 simple steps ]</span>:</strong></h3>

            <div class="step">
                <h4>1. Enter the code</h4>
                <p>Create your account and type the referral code you received from a friend into the box below.</p>
                <p class="note">(You’ll find the code on the Whole Earth Thank You card they give you.)</p>
            </div>

            <div class="step">
                <h4>2. Place your first order</h4>
                <p>Shop your favorite Whole Earth Gift products.</p>
            </div>

            <div class="step">
                <h4>3. Earn rewards</h4>
                <p>Once your order is complete, both <strong>you and your friend</strong> get <strong>$10 store credit</strong> for your next purchase.</p>
            </div>

            <p class="help-text">
                Need help? Contact our support team at
                <a href="mailto:support@wholeearthgift.com">support@wholeearthgift.com</a>
            </p>
        </div>
    </div>
</section>
