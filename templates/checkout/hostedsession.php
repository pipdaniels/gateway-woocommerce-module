<?php
/**
 * Copyright (c) 2019-2022 Mastercard
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
 * @var WC_Payment_Gateway_CC $cc_form
 * @var bool $display_tokenization
 * @var string $card_type_selector_html HTML for radio buttons
 * @var array $mpgs_merchant_ids MIDs for mastercard and visa
 * @var string $mpgs_session_js_template URL template for session.js
 * @var int $mpgs_api_version_num API Version number
 */

// Get MIDs safely
$merchant_ids = isset($mpgs_merchant_ids) ? $mpgs_merchant_ids : [];
$mastercard_mid = $merchant_ids[Mastercard_Gateway::CARD_TYPE_MASTERCARD] ?? '';
$visa_mid = $merchant_ids[Mastercard_Gateway::CARD_TYPE_VISA] ?? '';

// Get JS template URL safely
$session_js_template = isset($mpgs_session_js_template) ? $mpgs_session_js_template : '';
$api_version_num = isset($mpgs_api_version_num) ? $mpgs_api_version_num : '69'; // Default if not passed

// Stop if essential data is missing
if (empty($mastercard_mid) && empty($visa_mid)) {
    echo '<div class="woocommerce-error">' . esc_html__('Payment gateway configuration error: Merchant ID missing.', 'mastercard') . '</div>';
    return;
}
if (empty($session_js_template)) {
    echo '<div class="woocommerce-error">' . esc_html__('Payment gateway configuration error: Session JS URL missing.', 'mastercard') . '</div>';
    return;
}

// Determine initially selected card type and corresponding MID
$initial_card_type = Mastercard_Gateway::CARD_TYPE_MASTERCARD; // Default to MC
$initial_mid = !empty($mastercard_mid) ? $mastercard_mid : $visa_mid; // Use MC if available, else Visa
$initial_session_js_url = str_replace('{merchantIdPlaceholder}', $initial_mid, $session_js_template);

// Load session.js dynamically based on initial MID
?>
<script src="<?php echo esc_url($initial_session_js_url); ?>"></script>

<?php if ($gateway->use_3dsecure_v1() || $gateway->use_3dsecure_v2()): ?>
    <script src="<?php echo esc_url($gateway->get_threeds_js()); ?>"></script>
<?php endif; ?>

<style id="antiClickjack">body { display: none !important; }</style>

<div id="3DSUI" style="width:100%; min-height:400px;"></div>

<?php
// Output Card Type Selector
echo $card_type_selector_html;
?>

<form class="mpgs_hostedsession wc-payment-form" action="<?php echo esc_url($gateway->get_payment_return_url($order->get_id())); ?>" method="post">
    <div class="payment_box payment_method_<?php echo esc_attr($gateway->id); ?>">
        <?php
        // Output CC fields (number, expiry, CVC) using the $cc_form object
        $cc_form->payment_fields();

        // Display Save card checkbox if supported and enabled
        if ($display_tokenization) {
            $cc_form->tokenization_script();
            $cc_form->saved_payment_methods();
            $cc_form->save_payment_method_checkbox();
        }
        ?>
    </div>

    <input type="hidden" name="session_id" id="mpgs_session_id" value="" />
    <input type="hidden" name="session_version" id="mpgs_session_version" value="" />
    <input type="hidden" name="check_3ds_enrollment" id="mpgs_check_3ds" value="" />
    <input type="hidden" name="card_type" id="mpgs_card_type_hidden" value="<?php echo esc_attr($initial_card_type); ?>" />

    <div id="hostedsession_errors" style="color: red; display: none;" class="woocommerce-error"></div>

    <div class="clear"></div>

    <p class="form-row form-row-wide">
        <button type="button" class="button alt" id="mpgs_pay_button_hs" onclick="mpgsPayWithSelectedInstrument()">
            <?php echo esc_html__('Pay Securely', 'mastercard'); ?>
        </button>
    </p>
    <div class="clear"></div>
</form>

<script type="text/javascript">
    // Anti-clickjacking
    if (self === top) {
        var antiClickjack = document.getElementById("antiClickjack");
        if (antiClickjack && antiClickjack.parentNode) {
            antiClickjack.parentNode.removeChild(antiClickjack);
        }
    } else {
        top.location = self.location;
    }

    function hsFieldMap() {
        return {
            cardNumber: "#mpgs_gateway-card-number",
            number: "#mpgs_gateway-card-number",
            securityCode: "#mpgs_gateway-card-cvc",
            expiryMonth: "#mpgs_gateway-card-expiry-month",
            expiryYear: "#mpgs_gateway-card-expiry-year"
        };
    }

    function hsErrorsMap() {
        return {
            cardNumber: "<?php echo esc_js(__('Invalid Card Number', 'woocommerce')); ?>",
            securityCode: "<?php echo esc_js(__('Invalid Security Code', 'woocommerce')); ?>",
            expiryMonth: "<?php echo esc_js(__('Invalid Expiry Month', 'woocommerce')); ?>",
            expiryYear: "<?php echo esc_js(__('Invalid Expiry Year', 'woocommerce')); ?>"
        };
    }

    function mpgsPayWithSelectedInstrument() {
        console.log('MPGS HS: Pay button clicked.');
        var payButton = jQuery('#mpgs_pay_button_hs');
        var errorsContainer = jQuery('#hostedsession_errors');

        payButton.prop('disabled', true).addClass('disabled');
        errorsContainer.hide().empty();

        var selectedCardType = jQuery('input[name="mpgs_card_type_selection"]:checked').val();
        if (!selectedCardType) {
            errorsContainer.text("<?php echo esc_js(__('Please select Mastercard or Visa.', 'mastercard')); ?>").show();
            payButton.prop('disabled', false).removeClass('disabled');
            return;
        }
        console.log('MPGS HS: Selected card type:', selectedCardType);

        var selectedTokenInput = jQuery('input.woocommerce-SavedPaymentMethods-tokenInput:checked');
        var paymentMethod = 'new';
        var sourceId = undefined;

        if (selectedTokenInput.length > 0 && selectedTokenInput.val() !== 'new') {
            paymentMethod = 'token';
            sourceId = selectedTokenInput.val();
            console.log('MPGS HS: Using saved payment method ID:', sourceId);
            PaymentSession.updateSessionFromForm('card', undefined, sourceId);
        } else {
            console.log('MPGS HS: Using new card details.');
            PaymentSession.updateSessionFromForm('card');
        }
    }

    (function ($) {
        var merchantIds = <?php echo json_encode($mpgs_merchant_ids); ?>;
        var sessionJsTemplate = '<?php echo esc_url($session_js_template); ?>';
        var currentMid = '<?php echo esc_js($initial_mid); ?>';
        var currentCardType = '<?php echo esc_js($initial_card_type); ?>';
        var isSessionJsLoaded = true;
        var paymentSessionInstanceId = 'new';
        var apiVersion = <?php echo esc_js($api_version_num); ?>;

        var paymentSessionLoaded = {};
        var payButton = $('#mpgs_pay_button_hs');
        var errorsContainer = $('#hostedsession_errors');
        var hsLoadingFailedMsg = "<?php echo esc_js(__('Error initializing payment session. Please check card details or try again.', 'mastercard')); ?>";

        $('input.woocommerce-SavedPaymentMethods-tokenInput').on('change', function () {
            $('.token-cvc').hide();
            $('input[id^="mpgs_gateway-saved-card-cvc-"]').val('');

            var selectedTokenId = $(this).val();
            console.log('MPGS HS: Token selection changed to:', selectedTokenId);

            if (selectedTokenId && selectedTokenId !== 'new') {
                $('#token-cvc-' + selectedTokenId).show();
                createSessionAndConfigure();
            } else {
                paymentSessionInstanceId = 'new';
                createSessionAndConfigure();
            }
        });

        $('input[name="mpgs_card_type_selection"]').on('change', function () {
            var newCardType = $(this).val();
            console.log('MPGS HS: Card type changed to:', newCardType);

            $('#mpgs_card_type_hidden').val(newCardType);

            var newMid = '';
            if (newCardType === 'visa' && merchantIds.visa) {
                newMid = merchantIds.visa;
            } else if (newCardType === 'mastercard' && merchantIds.mastercard) {
                newMid = merchantIds.mastercard;
            } else {
                newMid = merchantIds.mastercard ? merchantIds.mastercard : merchantIds.visa;
                console.warn('MPGS HS: MID for selected type', newCardType, 'is missing. Falling back to MID:', newMid);
            }

            if (newMid && newMid !== currentMid) {
                console.log('MPGS HS: Merchant ID changed from', currentMid, 'to', newMid, '. Reloading session.js...');
                currentMid = newMid;
                currentCardType = newCardType;
                isSessionJsLoaded = false;
                paymentSessionLoaded = {};

                $('script[src*="/session.js"]').remove();

                var newSessionJsUrl = sessionJsTemplate.replace('{merchantIdPlaceholder}', currentMid);
                console.log('MPGS HS: Loading new session.js from:', newSessionJsUrl);
                $.getScript(newSessionJsUrl)
                    .done(function () {
                        console.log('MPGS HS: New session.js loaded successfully.');
                        isSessionJsLoaded = true;
                        createSessionAndConfigure();
                    })
                    .fail(function (jqxhr, settings, exception) {
                        console.error('MPGS HS: Failed to load new session.js:', exception);
                        isSessionJsLoaded = false;
                        errorsContainer.text("<?php echo esc_js(__('Error loading payment script. Please refresh and try again.', 'mastercard')); ?>").show();
                        payButton.prop('disabled', true).addClass('disabled');
                    });
            } else if (newMid === currentMid) {
                currentCardType = newCardType;
                console.log('MPGS HS: MID did not change. Reconfiguring PaymentSession if needed.');
                createSessionAndConfigure();
            }
        });

        function createSessionAndConfigure() {
            if (!isSessionJsLoaded) {
                console.warn('MPGS HS: Attempted to configure PaymentSession, but session.js is not loaded.');
                setTimeout(createSessionAndConfigure, 500);
                return;
            }

            if (typeof PaymentSession === 'undefined') {
                console.error('MPGS HS: PaymentSession object not found. session.js might have failed to load or initialize.');
                errorsContainer.text("<?php echo esc_js(__('Payment script failed to load. Please refresh.', 'mastercard')); ?>").show();
                payButton.prop('disabled', true).addClass('disabled');
                return;
            }

            payButton.prop('disabled', true).addClass('disabled');
            errorsContainer.hide().empty();
            console.log('MPGS HS: Creating/Getting MPGS session...');

            var selectedTokenInput = $('input.woocommerce-SavedPaymentMethods-tokenInput:checked');
            paymentSessionInstanceId = 'new';
            if (selectedTokenInput.length > 0 && selectedTokenInput.val() !== 'new') {
                paymentSessionInstanceId = selectedTokenInput.val();
            }

            if (paymentSessionLoaded[paymentSessionInstanceId] === true) {
                console.log('MPGS HS: PaymentSession already configured for instance:', paymentSessionInstanceId);
                payButton.prop('disabled', false).removeClass('disabled');
                return;
            }

            var sessionUrl = '<?php echo $gateway->get_create_session_url($order->get_id(), "' + currentCardType + '"); ?>';
            sessionUrl = sessionUrl.replace('%27 + currentCardType + %27', currentCardType);
            console.log('MPGS HS: Requesting session from URL:', sessionUrl);

            $.ajax({
                url: sessionUrl,
                method: 'get',
                dataType: 'json',
                cache: false
            })
                .done(function (response) {
                    if (response && response.session && response.session.id) {
                        console.log('MPGS HS: Session created/retrieved successfully:', response.session.id);
                        $('#mpgs_session_id').val(response.session.id);

                        if (paymentSessionInstanceId === 'new') {
                            initializeNewPaymentSession(response.session.id);
                        } else {
                            initializeTokenPaymentSession(response.session.id, paymentSessionInstanceId);
                        }
                        payButton.prop('disabled', false).removeClass('disabled');
                    } else {
                        console.error('MPGS HS: Invalid session response:', response);
                        errorsContainer.text(hsLoadingFailedMsg + ' (Invalid session data)').show();
                        payButton.prop('disabled', true).addClass('disabled');
                    }
                })
                .fail(function (jqXHR, textStatus, errorThrown) {
                    console.error('MPGS HS: Failed to create session:', textStatus, errorThrown, jqXHR.responseText);
                    errorsContainer.text(hsLoadingFailedMsg + ' (Network error)').show();
                    payButton.prop('disabled', true).addClass('disabled');
                });
        }

        createSessionAndConfigure();
    })(jQuery);
</script>
