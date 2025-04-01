// Modified for Visa/Mastercard dual credentials
				
/*
 * Copyright (c) 2019-2023 Mastercard
 * Modified for Visa/Mastercard dual credentials
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
 *
 */
jQuery(function ($) {
    'use strict';
    var wc_mastercard_admin = {
        init: function () {
            // --- Get all relevant fields using their NEW IDs ---
            // Sandbox Toggle
            var sandbox_toggle = $('#woocommerce_mpgs_gateway_sandbox');

            // Mastercard Fields
            var live_mc_username = $('#woocommerce_mpgs_gateway_username_mastercard').closest('tr'),
                live_mc_password = $('#woocommerce_mpgs_gateway_password_mastercard').closest('tr'),
                sandbox_mc_username = $('#woocommerce_mpgs_gateway_sandbox_username_mastercard').closest('tr'),
                sandbox_mc_password = $('#woocommerce_mpgs_gateway_sandbox_password_mastercard').closest('tr');

            // Visa Fields
            var live_visa_username = $('#woocommerce_mpgs_gateway_username_visa').closest('tr'),
                live_visa_password = $('#woocommerce_mpgs_gateway_password_visa').closest('tr'),
                sandbox_visa_username = $('#woocommerce_mpgs_gateway_sandbox_username_visa').closest('tr'),
                sandbox_visa_password = $('#woocommerce_mpgs_gateway_sandbox_password_visa').closest('tr');

            // Integration Method Fields
            var method_select = $('#woocommerce_mpgs_gateway_method'),
                threedsecure = $('#woocommerce_mpgs_gateway_threedsecure').closest('tr'),
                hc_interaction = $('#woocommerce_mpgs_gateway_hc_interaction').closest('tr'),
                saved_cards = $('#woocommerce_mpgs_gateway_saved_cards').closest('tr');
                // hc_type (legacy) is removed from PHP, so no JS needed for it

            // Gateway URL Fields
            var gateway_url_select = $('#woocommerce_mpgs_gateway_gateway_url'),
                custom_gateway_url = $('#woocommerce_mpgs_gateway_custom_gateway_url').closest('tr');


            // --- Function to handle Sandbox Toggle ---
            function toggleSandboxFields() {
                 if (sandbox_toggle.is(':checked')) {
                    // Show Sandbox, Hide Live
                    sandbox_mc_username.show();
                    sandbox_mc_password.show();
                    sandbox_visa_username.show();
                    sandbox_visa_password.show();
                    live_mc_username.hide();
                    live_mc_password.hide();
                    live_visa_username.hide();
                    live_visa_password.hide();
                } else {
                    // Show Live, Hide Sandbox
                    sandbox_mc_username.hide();
                    sandbox_mc_password.hide();
                    sandbox_visa_username.hide();
                    sandbox_visa_password.hide();
                    live_mc_username.show();
                    live_mc_password.show();
                    live_visa_username.show();
                    live_visa_password.show();
                }
            }

            // --- Function to handle Integration Method Toggle ---
            function toggleMethodFields() {
                var selectedMethod = method_select.val();
                if (selectedMethod === 'newhostedcheckout') {
                    // New Hosted Checkout
                    threedsecure.hide();
                    hc_interaction.show(); // Show HC Interaction (Embedded/Redirect)
                    saved_cards.hide();
                } else if (selectedMethod === 'hostedsession') {
                     // Hosted Session
                    threedsecure.show(); // Show 3DS setting
                    hc_interaction.hide();
                    saved_cards.show(); // Show Saved Cards setting
                } else {
                    // Default or unknown - hide method-specific fields
                    threedsecure.hide();
                    hc_interaction.hide();
                    saved_cards.hide();
                }
            }

            // --- Function to handle Gateway URL Toggle ---
             function toggleGatewayUrlField() {
                  if (gateway_url_select.val() === 'custom') {
                    custom_gateway_url.show();
                } else {
                    custom_gateway_url.hide();
                }
             }

            // --- Initial setup on page load ---
            toggleSandboxFields();
            toggleMethodFields();
            toggleGatewayUrlField();

            // --- Event Handlers ---
            sandbox_toggle.on('change', toggleSandboxFields);
            method_select.on('change', toggleMethodFields);
            gateway_url_select.on('change', toggleGatewayUrlField);
        }
    };
    wc_mastercard_admin.init();
});
