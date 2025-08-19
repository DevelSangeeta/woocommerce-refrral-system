<?php
if (!defined('ABSPATH')) {
    exit;
}

$user = wp_get_current_user();
error_log('CWR Wallet: Entering checkout-redeem-wallet.php for user ID ' . $user->ID);

if (!in_array('customer', (array)$user->roles)) {
    error_log('CWR Wallet: User ID ' . $user->ID . ' does not have customer role in checkout-redeem-wallet.php');
    return;
}

cwr_initialize_wallet_balance($user->ID);

$wallet_balance = floatval(get_user_meta($user->ID, 'cwr_wallet_balance', true));
$redeemable_amount = cwr_get_redeemable_amount($user->ID);
error_log('CWR Wallet: Redeemable amount for user ID ' . $user->ID . ': ' . $redeemable_amount);

if ($redeemable_amount <= 0) {
    error_log('CWR Wallet: No redeemable amount for user ID ' . $user->ID . ', exiting checkout-redeem-wallet.php');
    return;
}

$session_redeem_amount = WC()->session->get('cwr_redeem_amount', 0);
?>
<div class="cwr-redeem-wallet" style="margin-bottom: 20px; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
    <h3><?php esc_html_e('Redeem Wallet Balance', 'cwr'); ?></h3>
    <p><?php printf(esc_html__('You have %s in your wallet.', 'cwr'), wc_price($wallet_balance)); ?></p>
    <p class="amount-entry">
        <label for="cwr_redeem_amount"><?php esc_html_e('How Much You Want To Redeem (Max $30 redeemable per order):', 'cwr'); ?></label>
        <input type="number" id="cwr_redeem_amount" name="cwr_redeem_amount" min="0" step="0.01" value="<?php echo ($session_redeem_amount !=0) ? esc_attr($session_redeem_amount) : ''; ?>" placeholder="Please enter amount">
    </p>
    <div id="cwr-redeem-error" style="color: red; margin-bottom: 10px;"></div>
    <div id="cwr-remaining-balance" style="margin-bottom: 10px;"></div>
    <p>
        <input type="checkbox" id="cwr_redeem_wallet" name="cwr_redeem_wallet" value="1" <?php checked(WC()->session->get('cwr_redeem_wallet_active', false)); ?>>
        <label for="cwr_redeem_wallet"><?php esc_html_e('Apply to this order?', 'cwr'); ?></label>
        <input type="hidden" id="cwr_redeem_nonce" name="cwr_redeem_nonce" value="<?php echo wp_create_nonce('cwr_redeem_wallet'); ?>">
    </p>
    <div id="cwr-discount-preview" style="margin-top: 10px; color: #36604E;"></div>
</div>
<script>
jQuery(document).ready(function($) {
    var walletBalance = <?php echo json_encode($wallet_balance); ?>;
    var maxRedeemPerOrder = 30;
    var currencyFormatter = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });

    function validateAndUpdate(apply) {
        var amount = parseFloat($('#cwr_redeem_amount').val()) || 0;
        var error = '';

        if (amount > maxRedeemPerOrder) {
            error = 'Max $30 redeemable per order';
        } else if (amount > walletBalance) {
            error = "You don't have enough balance in your wallet";
        }

        $('#cwr-redeem-error').html(error);

        if (error) {
            $('#cwr-remaining-balance').html('');
            if (apply) {
                updateDiscount(0);
            }
            return false;
        } else {
            var remaining = walletBalance - amount;
            $('#cwr-remaining-balance').html('Remaining wallet balance: ' + currencyFormatter.format(remaining));
            if (apply) {
                updateDiscount(amount);
            }
            return true;
        }
    }

    function updateDiscount(redeemAmount) {
        $.ajax({
            url: '<?php echo admin_url('admin-ajax.php'); ?>',
            type: 'POST',
            data: {
                action: 'cwr_wallet_discount_preview',
                nonce: $('#cwr_redeem_nonce').val(),
                redeem_active: redeemAmount > 0 ? '1' : '0',
                redeem_amount: redeemAmount
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
    }

    $('#cwr_redeem_amount').on('input', function() {
        validateAndUpdate($('#cwr_redeem_wallet').is(':checked'));
    });

    $('#cwr_redeem_wallet').on('change', function() {
        if (this.checked) {
            validateAndUpdate(true);
        } else {
            updateDiscount(0);
        }
    });

    $('#cwr_redeem_amount').trigger('input');
});
</script>