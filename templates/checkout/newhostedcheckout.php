<?php
/**
 * Copyright (c) 2022 Mastercard
 * Modified for Visa/Mastercard selection
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * @var Mastercard_Gateway $gateway
 * @var WC_Abstract_Order $order
 * @var string $card_type_selector_html HTML for radio buttons
 */

// --- NEW: Output Card Type Selector ---
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo $card_type_selector_html;
// --- END NEW ---
?>

<?php if ( $gateway->use_embedded() ): ?>
    <div id="embed-target" style="min-height: 400px;"></div> <?php /* Added min-height */ ?>
<?php else: ?>
    <input type="button" class="button alt" id="mpgs_pay_button_hc" value="<?php echo esc_attr__( 'Proceed to Secure Payment', 'mastercard' ) ?>" /> <?php /* Changed label, added class */ ?>
<?php endif; ?>

<script async src="<?php echo esc_url( $gateway->get_hosted_checkout_js() ) ?>"
        data-error="errorCallbackHC"
        data-cancel="cancelCallbackHC">
</script>
<script type="text/javascript">
    function errorCallbackHC(error) {
        var errStr = JSON.stringify(error);
        console.error('MPGS HC Error:', errStr);
        var errorContainer = jQuery('#mpgs-hc-errors');
        if (errorContainer.length === 0) {
            jQuery('<div id="mpgs-hc-errors" class="woocommerce-error" style="margin-top:15px;"></div>').insertBefore('#embed-target, #mpgs_pay_button_hc');
            errorContainer = jQuery('#mpgs-hc-errors');
        }
        var displayError = "<?php echo esc_js(__('An error occurred during payment setup:', 'mastercard')); ?>";
        if (error && error.cause && error.explanation) {
            displayError += ' ' + error.cause + ' - ' + error.explanation;
        } else if (error && error.message) {
            displayError += ' ' + error.message;
        } else {
            displayError += ' ' + errStr;
        }
        errorContainer.html(displayError);
        jQuery('#mpgs_pay_button_hc').prop('disabled', false);
    }

    function cancelCallbackHC() {
        console.log('MPGS HC Cancelled by user.');
        window.location.href = '<?php echo esc_url_raw($order->get_cancel_order_url(wc_get_checkout_url())); ?>';
    }

    (function ($) {
        var sessionKeysToClear = [];
        var checkoutInstance = null;

        function cleanupBrowserSession() {
            var sessionKey, i;
            for (i = 0; i < sessionKeysToClear.length; i++) {
                sessionKey = sessionKeysToClear[i];
                if (sessionStorage.key(sessionKey)) {
                    sessionStorage.removeItem(sessionKey);
                }
            }
        }

        <?php if ( $gateway->use_embedded() ): ?>
            sessionKeysToClear.push('HostedCheckout_sessionId');
        <?php else: ?>
            sessionKeysToClear.push('HostedCheckout_embedContainer');
            var payButton = $('#mpgs_pay_button_hc');

            function togglePayButton(enable) {
                payButton.prop('disabled', !enable);
                if (enable) {
                    payButton.removeClass('disabled');
                } else {
                    payButton.addClass('disabled');
                }
            }
            togglePayButton(false);
        <?php endif; ?>

        function waitFor(name, callback) {
            if (typeof window[name] === "undefined") {
                setTimeout(function () {
                    waitFor(name, callback);
                }, 200);
            } else {
                callback();
            }
        }

        function configureHostedCheckout(sessionData) {
            console.log('MPGS HC: Configuring Checkout.js with session:', sessionData);
            var config = {
                session: {
                    id: sessionData.session.id,
                }
            };

            waitFor('Checkout', function () {
                console.log('MPGS HC: Checkout.js loaded.');
                cleanupBrowserSession();
                try {
                    Checkout.configure(config);
                    checkoutInstance = Checkout;
                    console.log('MPGS HC: Checkout.js configured.');

                    <?php if ( $gateway->use_embedded() ): ?>
                        console.log('MPGS HC: Showing embedded page.');
                        Checkout.showEmbeddedPage('#embed-target');
                    <?php else: ?>
                        togglePayButton(true);
                        console.log('MPGS HC: Pay button enabled for redirect flow.');
                    <?php endif; ?>

                } catch (e) {
                    console.error('MPGS HC: Error configuring or showing Checkout.js', e);
                    errorCallbackHC({message: 'Checkout configuration failed.'});
                }
            });
        }

        function initiateSession() {
            var selectedCardType = $('input[name="mpgs_card_type_selection"]:checked').val();
            if (!selectedCardType) {
                errorCallbackHC({ message: 'Please select Mastercard or Visa.' });
                return;
            }
            console.log('MPGS HC: Selected card type:', selectedCardType);

            <?php if ( $gateway->use_embedded() ): ?>
                $('#embed-target').css('opacity', 0.5);
            <?php else: ?>
                togglePayButton(false);
            <?php endif; ?>

            $('#mpgs-hc-errors').remove();

            var sessionUrl = '<?php echo $gateway->get_create_checkout_session_url( $order->get_id(), "' + selectedCardType + '" ); ?>';
            sessionUrl = sessionUrl.replace('%27 + selectedCardType + %27', selectedCardType);
            console.log('MPGS HC: Requesting session from URL:', sessionUrl);

            $.ajax({
                method: 'GET',
                url: sessionUrl,
                dataType: 'json',
                cache: false
            })
            .done(function(sessionData) {
                console.log('MPGS HC: Session received successfully:', sessionData);
                if (sessionData && sessionData.session && sessionData.session.id && sessionData.successIndicator) {
                    configureHostedCheckout(sessionData);
                    <?php if ( $gateway->use_embedded() ): ?>
                        $('#embed-target').css('opacity', 1);
                    <?php endif; ?>
                } else {
                    console.error('MPGS HC: Invalid session data received:', sessionData);
                    errorCallbackHC({ message: 'Received invalid session data from server.' });
                    <?php if ( $gateway->use_embedded() ): ?>
                        $('#embed-target').css('opacity', 1);
                    <?php endif; ?>
                }
            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                console.error('MPGS HC: Failed to get session:', textStatus, errorThrown, jqXHR.responseText);
                var errorMsg = "<?php echo esc_js(__('Failed to initialize payment session.', 'mastercard')); ?>";
                if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                    errorMsg += ' ' + jqXHR.responseJSON.message;
                } else if (jqXHR.responseText) {
                    try {
                        var errData = JSON.parse(jqXHR.responseText);
                        if(errData && errData.message) errorMsg += ' ' + errData.message;
                    } catch(e) { }
                }
                errorCallbackHC({ message: errorMsg });
                <?php if ( $gateway->use_embedded() ): ?>
                    $('#embed-target').css('opacity', 1);
                <?php endif; ?>
            });
        }

        <?php if ( $gateway->use_embedded() ): ?>
            $('input[name="mpgs_card_type_selection"]').on('change', function() {
                console.log('MPGS HC: Card type changed, re-initiating session.');
                initiateSession();
            });
            console.log('MPGS HC: Initial session initiation for embedded flow.');
            initiateSession();
        <?php else: ?>
            payButton.on('click', function (e) {
                e.preventDefault();
                console.log('MPGS HC: Pay button clicked.');
                if (checkoutInstance) {
                    console.log('MPGS HC: Showing payment page via Checkout.showPaymentPage().');
                    checkoutInstance.showPaymentPage();
                } else {
                    console.warn('MPGS HC: Checkout.js instance not ready yet.');
                    errorCallbackHC({ message: 'Payment gateway is not ready. Please wait a moment and try again.' });
                }
            });
            $('input[name="mpgs_card_type_selection"]').on('change', function() {
                console.log('MPGS HC: Card type changed, re-initiating session for redirect flow config.');
                initiateSession();
            });
            console.log('MPGS HC: Initial session initiation for redirect flow config.');
            initiateSession();
        <?php endif; ?>
    })(jQuery);
</script>
