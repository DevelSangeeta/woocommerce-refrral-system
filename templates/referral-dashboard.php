<?php
if (!defined('ABSPATH')) {
    exit;
}

// Redirect to My Account if not logged in or not a customer
if (!is_user_logged_in() || !in_array('customer', wp_get_current_user()->roles)) {
    $my_account_link = get_site_url() . '/my-account';
    wp_safe_redirect($my_account_link);
    exit;
}

$user = wp_get_current_user();
$user_id = $user->ID;

// Initialize wallet for logged-in user
cwr_initialize_wallet_balance($user_id);

$referral_code = cwr_get_user_referral_code($user_id);
$referral_link = cwr_get_user_referral_link($user_id);
$wallet_balance = floatval(get_user_meta($user_id, 'cwr_wallet_balance', true));
$redeemable_amount = cwr_get_redeemable_amount($user_id); // Use plugin's function for consistency

global $wpdb;
$referrals = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}cwr_referrals WHERE referrer_id = %d AND order_id IS NOT NULL",
    $user_id
));
$total_referrals = count($referrals);
$total_earnings = array_sum(array_column($referrals, 'credit_amount'));
$has_verified = $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$wpdb->prefix}cwr_referrals WHERE referee_id = %d",
    $user_id
));

error_log('CWR Dashboard: Loaded for user ID ' . $user_id . ', code: ' . $referral_code . ', link: ' . $referral_link);
?>

<div class="cwr-referral-dashboard" style="max-width: 800px; margin: 0 auto; padding: 20px;">
    <h2 style="text-align: center;">Referral Dashboard</h2>
    
    <div class="dashboard-welcome" style="margin-bottom: 20px;">
        <p>Welcome, <?php echo esc_html($user->display_name); ?>!</p>
    </div>
    
    <div class="referral-info" style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 5px; margin-bottom: 20px;">
        <h3>Your Referral Info</h3>
        <?php if ($referral_code && $referral_link): ?>
            <div style="display: flex; align-items: start; gap: 10px;">
                <p><strong>Referral Code:</strong><span style="display: inline-block; padding: 3px 10px; border: 1px solid #366059; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.5); font-weight: bold; margin-left: 3px;"><?php echo esc_html($referral_code); ?></span></p>
                <button type="button" id="cwr-copy-code" style="padding: 5px 10px; background: #0073aa; color: white; border: none; border-radius: 4px; cursor: pointer;">Copy</button>
                <p id="cwr-code-copy-message" style="display: none; color: green; margin-top: 5px;">Copied!</p>
            </div>
            <p><strong>Referral Link:</strong></p>
            <div style="display: flex; align-items: center; gap: 10px;">
                <input type="text" id="cwr-referral-link" value="<?php echo esc_url($referral_link); ?>" readonly style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px;">
                <button type="button" id="cwr-copy-link" style="padding: 8px 16px; background: #0073aa; color: white; border: none; border-radius: 4px; cursor: pointer;">Copy</button>
            </div>
            <p id="cwr-copy-message" style="display: none; color: green; margin-top: 5px;">Copied!</p>
            <p style="margin-top: 10px;">Share this link or code with others! They can visit the <a href="<?php echo esc_url(get_site_url() . '/referral-program'); ?>">Verify Referral Code</a> page to enter your code and join your referral network.</p>
        <?php else: ?>
            <p style="color: red;">Error: Unable to generate referral code or link. Please contact support.</p>
            <?php if (current_user_can('manage_options')): ?>
                <p style="color: orange; font-size: 12px;">Check debug.log for referral code generation issues.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <div class="referral-stats" style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 5px;">
        <h3>Referral Statistics</h3>
        <p><strong>Total Successful Referrals:</strong> <?php echo esc_html($total_referrals); ?> (Orders placed by referred users)</p>
        <p><strong>Total Referral Earnings:</strong> <?php echo wc_price($total_earnings); ?> (Credits from referrals)</p>
        <p><strong>Current Wallet Balance:</strong> <?php echo wc_price($wallet_balance); ?> (Max $30 redeemable per order)</p>
        <!-- <p><strong>Redeemable Amount:</strong> <?php //echo $redeemable_amount > 0 ? wc_price($redeemable_amount) . ' (30% of total credit, available at checkout)' : 'Balance must be at least $10 to redeem'; ?></p> -->
        <!-- <p><strong>Referral Code Status:</strong> <?php //echo $has_verified ? 'Verified' : '<a href="' . get_site_url() . '/referral-program">Verify a referral code</a>'; ?></p> -->
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    function copyToClipboard(text, messageSelector) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                $(messageSelector).show();
                setTimeout(function() {
                    $(messageSelector).hide();
                }, 2000);
            }).catch(function(err) {
                console.error('Failed to copy: ', err);
                alert('Failed to copy. Please copy manually.');
            });
        } else {
            var $temp = $('<input>');
            $('body').append($temp);
            $temp.val(text).select();
            document.execCommand('copy');
            $temp.remove();
            $(messageSelector).show();
            setTimeout(function() {
                $(messageSelector).hide();
            }, 2000);
        }
    }

    $('#cwr-copy-link').on('click', function() {
        copyToClipboard($('#cwr-referral-link').val(), '#cwr-copy-message');
    });

    $('#cwr-copy-code').on('click', function() {
        copyToClipboard('<?php echo esc_js($referral_code); ?>', '#cwr-code-copy-message');
    });
});
</script>