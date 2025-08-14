<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

$user = wp_get_current_user();
error_log('CWR Wallet: Entering checkout-redeem-wallet.php for user ID ' . $user->ID);

// Ensure user has customer role
if (!in_array('customer', (array)$user->roles)) {
    error_log('CWR Wallet: User ID ' . $user->ID . ' does not have customer role in checkout-redeem-wallet.php');
    return;
}

// Initialize wallet balance
cwr_initialize_wallet_balance($user->ID);

$redeemable_amount = cwr_get_redeemable_amount($user->ID);
error_log('CWR Wallet: Redeemable amount for user ID ' . $user->ID . ': ' . $redeemable_amount);

if ($redeemable_amount <= 0) {
    error_log('CWR Wallet: No redeemable amount for user ID ' . $user->ID . ', exiting checkout-redeem-wallet.php');
    return;
}
?>
<div class="cwr-redeem-wallet" style="margin-bottom: 20px; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
    <h3><?php esc_html_e('Redeem Wallet Balance', 'cwr'); ?></h3>
    <p><?php printf(esc_html__('You have %s available to redeem (Max $30 redeemable per order).', 'cwr'), wc_price($redeemable_amount)); ?></p>
    <p>
        <input type="checkbox" id="cwr_redeem_wallet" name="cwr_redeem_wallet" value="1" <?php checked(WC()->session->get('cwr_redeem_wallet_active', false)); ?>>
        <label for="cwr_redeem_wallet"><?php printf(esc_html__('Apply to this order?', 'cwr'), wc_price($redeemable_amount)); ?></label>
        <?php wp_nonce_field('cwr_redeem_wallet', 'cwr_redeem_nonce', true); ?>
    </p>
    <div id="cwr-discount-preview" style="margin-top: 10px; color: #36604E;"></div>
</div>
<script>
jQuery(document).ready(function($) {
    $('#cwr_redeem_wallet').on('change', function() {
        var redeemActive = $(this).is(':checked') ? '1' : '0';
        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
                action: 'cwr_wallet_discount_preview',
                nonce: $('#cwr_redeem_nonce').val(),
                redeem_active: redeemActive
            },
            success: function(response) {
                if (response.success) {
                    $('#cwr-discount-preview').html(response.data.message).show();
                    $(document.body).trigger('update_checkout');
                } else {
                    $('#cwr-discount-preview').html('<p style="color: red;">' + response.data.message + '</p>').show();
                }
            },
            error: function() {
                $('#cwr-discount-preview').html('<p style="color: red;">Error updating discount. Please try again.</p>').show();
            }
        });
    });
});
</script>