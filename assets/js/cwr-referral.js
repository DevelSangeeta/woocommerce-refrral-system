jQuery(document).ready(function($) {

    // Force uppercase input
    $('#cwr_referral_code').on('keyup', function() {
        $(this).val($(this).val().toUpperCase());
    });

    // Submit handler
    $('#cwr-verify-referral-form').on('submit', function(e) {
        e.preventDefault();

        var $form = $(this);
        var referralCode = $('#cwr_referral_code').val();
        var nonce = jQuery('#cwr_verify_nonce').val();

        $form.find('.apply-btn').prop('disabled', true).val('Verifying...');

        $.ajax({
            url: cwr_ajax.url,
            type: 'POST',
            data: {
                action: 'cwr_verify_referral_code',
                cwr_referral_code: referralCode,
                cwr_verify_nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    Swal.fire({
                        icon: 'success',
                        title: response.data.title,
                        text: response.data.message,
                        confirmButtonText: 'Shop Now',
                        confirmButtonColor: '#46b450'
                    }).then((result) => {
                        if (result.isConfirmed) {
                          window.location.href = "/product-category/buy-kratom/";
                        };
                    })
                } else {
                    // var iconType = 'error';
                    // switch (response.data.type) {
                    //     case 'req-error': 
                    //         iconType = '⚠️';
                    //         break;
                    //     case 'login-error': 
                    //         iconType = '🚫';
                    //         break;
                    //     case 'code-error': 
                    //         iconType = '⚠️';
                    //         break;
                    //     case 'attempt-error': 
                    //         iconType = 'ℹ️';
                    //         break;
                    //     case 'self-error': 
                    //         iconType = '⚠️';
                    //         break;
                    //     case 'req-error': 
                    //         iconType = '⚠️';
                    //         break;
                    // }
                    Swal.fire({
                        icon: 'error',
                        title: response.data.title,
                        text: response.data.message,
                        confirmButtonColor: '#dc3232'
                    });
                }
            },
            error: function(response) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Error verifying code. Please try again.',
                    confirmButtonColor: '#dc3232'
                });
            },
            complete: function() {
                $form.find('.apply-btn').prop('disabled', false).val(cwr_ajax.verify_text);
            }
        });
    });
});
