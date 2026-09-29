/**
 * A text field that only ever holds a non-negative decimal number.
 *
 * The "eav" modifier renders pdp_fbt_bundle_discount_percent (see
 * Setup/Patch/Data/CreateFbtBundleDiscountAttribute) as a plain text input,
 * because the attribute is frontend_input 'text' so that "12.5" survives - a
 * digits-only input would reject fractional percents. That leaves letters
 * typeable: the decimal backend silently drops them on save, so "25 abc" looks
 * accepted right up until the value comes back as 25.
 *
 * The validate-number / validate-number-range rules attached alongside this
 * (Ui/DataProvider/Product/Form/Modifier/ManualCarousels::getManualFbtFieldset())
 * already catch that on submit. This stops it a step earlier, at the keystroke,
 * so the field can never display something it won't keep - the rules stay on as
 * the backstop for anything that bypasses the UI.
 *
 * Both entry paths are covered: typing (keypress, which is cancellable and
 * still exposes the character) and pasting/drag-drop (handled after the fact on
 * the value itself, since those deliver whole strings rather than characters).
 */
define([
    'Magento_Ui/js/form/element/abstract'
], function (Abstract) {
    'use strict';

    return Abstract.extend({
        defaults: {
            elementTmpl: 'Ahy_PDPRevamp/form/element/decimal-input'
        },

        /**
         * Rejects a keystroke that could not be part of a decimal number.
         * Control keys (Tab, Enter, arrows, Backspace...) report no printable
         * `key`, so they are left alone. A second decimal point is refused
         * rather than silently accepted and stripped later.
         *
         * @param {Object} ctx
         * @param {KeyboardEvent} event
         * @returns {Boolean} false cancels the keystroke
         */
        onKeyPress: function (ctx, event) {
            var char = event.key;

            if (!char || char.length > 1 || event.ctrlKey || event.metaKey) {
                return true;
            }

            if (char >= '0' && char <= '9') {
                return true;
            }

            if (char === '.') {
                return String(event.target.value || '').indexOf('.') === -1;
            }

            return false;
        },

        /**
         * Last line of defence for input that never went through a keystroke -
         * paste, drag-drop, autofill. Keeps the first run of digits with at
         * most one decimal point and discards the rest, so the field is left
         * holding something the backend will actually store.
         *
         * @param {String} value
         */
        normalize: function (value) {
            var cleaned = String(value == null ? '' : value).replace(/[^0-9.]/g, ''),
                firstDot = cleaned.indexOf('.');

            if (firstDot !== -1) {
                cleaned = cleaned.slice(0, firstDot + 1)
                    + cleaned.slice(firstDot + 1).replace(/\./g, '');
            }

            if (cleaned !== value) {
                this.value(cleaned);
            }
        }
    });
});
