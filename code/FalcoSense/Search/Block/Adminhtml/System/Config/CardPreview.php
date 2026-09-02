<?php
declare(strict_types=1);

namespace FalcoSense\Search\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Live preview of the storefront product card's Add-to-Cart button, rendered
 * inline in the admin config form. Reads the sibling fields' current DOM
 * values on input/change (no save required) so the merchant sees the effect
 * of a change immediately, for both the simple-product (Add) and
 * configurable-product (Options) button variants.
 */
class CardPreview extends Field
{
    private const FIELD_PREFIX = 'smart_search_add_to_cart_button_';

    public function render(AbstractElement $element): string
    {
        $borderRadiusId = self::FIELD_PREFIX . 'border_radius';
        $buttonStyleId = self::FIELD_PREFIX . 'button_style';
        $iconId = self::FIELD_PREFIX . 'icon';
        $labelId = self::FIELD_PREFIX . 'label_text';
        $optionsLabelId = self::FIELD_PREFIX . 'options_label_text';
        $cardRadiusId = 'smart_search_product_card_corner_radius';
        $cardBorderShadowId = 'smart_search_product_card_border_shadow_style';

        return <<<HTML
<tr>
    <td colspan="4" style="padding:8px 0 16px;">
        <div style="font-weight:700;color:#1e3a5f;font-size:13px;margin-bottom:10px;">Live Preview</div>
        <div style="display:flex;gap:24px;flex-wrap:wrap;">
            <div>
                <div style="font-size:11px;color:#6b7280;margin-bottom:6px;">Simple product</div>
                <div id="ahyCardPreviewSimpleCard" style="width:220px;height:478px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;display:flex;flex-direction:column;overflow:hidden;">
                    <div style="width:100%;height:244px;flex-shrink:0;background:#f3f4f6;padding:12px;box-sizing:border-box;">
                        <div style="width:100%;height:100%;background:#e5e7eb;border-radius:4px;"></div>
                    </div>
                    <div style="flex:1;display:flex;flex-direction:column;padding:0 16px 16px;">
                        <div style="font-size:14px;font-weight:800;color:#0d2f47;margin-top:8px;line-height:1.3;">Sample Product</div>
                        <div style="font-size:11px;color:#6b7280;margin-top:4px;">Sold By Sample Seller</div>
                        <div style="font-size:18px;font-weight:800;color:#0d2f47;margin-top:10px;">$29.95</div>
                        <div style="flex:1;"></div>
                        <div style="font-size:11px;font-weight:600;color:#111827;margin-bottom:8px;">FREE Shipping</div>
                        <button type="button" id="ahyCardPreviewAddBtn" style="width:100%;font-size:13px;font-weight:700;padding:8px 10px;border:2px solid #0d2f47;display:flex;align-items:center;justify-content:center;gap:6px;cursor:default;box-sizing:border-box;">
                            <img id="ahyCardPreviewAddIcon" src="" alt="" style="width:14px;height:14px;object-fit:contain;display:none;" />
                            <svg id="ahyCardPreviewAddIconDefault" style="width:14px;height:14px;flex-shrink:0;" fill="none" stroke="#0d2f47" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                            <span id="ahyCardPreviewAddLabel">Add</span>
                        </button>
                    </div>
                </div>
            </div>
            <div>
                <div style="font-size:11px;color:#6b7280;margin-bottom:6px;">Configurable product</div>
                <div id="ahyCardPreviewConfigCard" style="width:220px;height:478px;background:#fff;border:1px solid #e5e7eb;border-radius:6px;display:flex;flex-direction:column;overflow:hidden;">
                    <div style="width:100%;height:244px;flex-shrink:0;background:#f3f4f6;padding:12px;box-sizing:border-box;">
                        <div style="width:100%;height:100%;background:#e5e7eb;border-radius:4px;"></div>
                    </div>
                    <div style="flex:1;display:flex;flex-direction:column;padding:0 16px 16px;">
                        <div style="font-size:14px;font-weight:800;color:#0d2f47;margin-top:8px;line-height:1.3;">Sample Product</div>
                        <div style="font-size:11px;color:#6b7280;margin-top:4px;">Sold By Sample Seller</div>
                        <div style="font-size:12px;font-weight:700;color:#dc2626;text-transform:uppercase;margin-top:10px;">From</div>
                        <div style="font-size:18px;font-weight:800;color:#0d2f47;">$29.95</div>
                        <div style="flex:1;"></div>
                        <div style="font-size:11px;font-weight:600;color:#111827;margin-bottom:8px;">FREE Shipping</div>
                        <button type="button" id="ahyCardPreviewOptionsBtn" style="width:100%;font-size:13px;font-weight:700;padding:8px 10px;border:2px solid #0d2f47;cursor:default;box-sizing:border-box;">
                            <span id="ahyCardPreviewOptionsLabel">Options</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </td>
</tr>
<tr>
    <td colspan="4" style="padding:0;">
        <script>
        (function () {
            function \$(id) { return document.getElementById(id); }

            function update() {
                var radiusEl = \$('{$borderRadiusId}');
                var styleEl = \$('{$buttonStyleId}');
                var labelEl = \$('{$labelId}');
                var optionsLabelEl = \$('{$optionsLabelId}');
                var cardRadiusEl = \$('{$cardRadiusId}');
                var cardBorderShadowEl = \$('{$cardBorderShadowId}');

                var radius = radiusEl && radiusEl.value !== '' ? radiusEl.value : '9999';
                var filled = styleEl && styleEl.value === 'filled';
                var label = labelEl && labelEl.value !== '' ? labelEl.value : 'Add';
                var optionsLabel = optionsLabelEl && optionsLabelEl.value !== '' ? optionsLabelEl.value : 'Options';
                var cardRadius = cardRadiusEl && cardRadiusEl.value !== '' ? cardRadiusEl.value : '0';
                var cardBorderShadow = cardBorderShadowEl ? cardBorderShadowEl.value : 'none';

                var bg = filled ? '#0d2f47' : 'transparent';
                var fg = filled ? '#ffffff' : '#0d2f47';

                [\$('ahyCardPreviewAddBtn'), \$('ahyCardPreviewOptionsBtn')].forEach(function (btn) {
                    if (!btn) return;
                    btn.style.borderRadius = radius + 'px';
                    btn.style.background = bg;
                    btn.style.color = fg;
                });

                var cardBorder = (cardBorderShadow === 'border' || cardBorderShadow === 'both') ? '1px solid #e5e7eb' : 'none';
                var cardShadow = (cardBorderShadow === 'shadow' || cardBorderShadow === 'both') ? '0 1px 3px rgba(0,0,0,0.1), 0 1px 2px rgba(0,0,0,0.06)' : 'none';
                [\$('ahyCardPreviewSimpleCard'), \$('ahyCardPreviewConfigCard')].forEach(function (card) {
                    if (!card) return;
                    card.style.borderRadius = cardRadius + 'px';
                    card.style.border = cardBorder;
                    card.style.boxShadow = cardShadow;
                });

                var addIconDefault = \$('ahyCardPreviewAddIconDefault');
                if (addIconDefault) addIconDefault.setAttribute('stroke', fg);

                if (\$('ahyCardPreviewAddLabel')) \$('ahyCardPreviewAddLabel').textContent = label;
                if (\$('ahyCardPreviewOptionsLabel')) \$('ahyCardPreviewOptionsLabel').textContent = optionsLabel;
            }

            function updateIconPreview() {
                var iconInput = \$('{$iconId}');
                var img = \$('ahyCardPreviewAddIcon');
                var fallback = \$('ahyCardPreviewAddIconDefault');
                if (!iconInput || !img || !fallback) return;

                var file = iconInput.files && iconInput.files[0];
                if (!file) return;

                var reader = new FileReader();
                reader.onload = function (e) {
                    img.src = e.target.result;
                    img.style.display = '';
                    fallback.style.display = 'none';
                };
                reader.readAsDataURL(file);
            }

            document.addEventListener('DOMContentLoaded', function () {
                ['{$borderRadiusId}', '{$buttonStyleId}', '{$labelId}', '{$optionsLabelId}', '{$cardRadiusId}', '{$cardBorderShadowId}'].forEach(function (id) {
                    var el = \$(id);
                    if (el) {
                        el.addEventListener('input', update);
                        el.addEventListener('change', update);
                    }
                });

                var iconInput = \$('{$iconId}');
                if (iconInput) iconInput.addEventListener('change', updateIconPreview);

                update();
            });
        })();
        </script>
    </td>
</tr>
HTML;
    }
}
