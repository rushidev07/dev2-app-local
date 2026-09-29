<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Ui\DataProvider\Product\Form\Modifier;

use Ahy\PDPRevamp\Setup\Patch\Data\CreateFbtBundleDiscountAttribute;
use Ahy\PDPRevamp\Setup\Patch\Data\CreateManualCarouselLinkTypes;
use Ahy\PDPRevamp\Setup\Patch\Data\CreateManualFbtLinkType;
use Magento\Catalog\Ui\DataProvider\Product\Form\Modifier\Related;
use Magento\Ui\Component\Form\Fieldset;

/**
 * Adds three more product-picker grids to the same "Related Products, Up-
 * Sells, and Cross-Sells" section Magento already ships, reusing its exact
 * button/modal/grid building blocks (see the inherited protected methods
 * on Related) - one for a manual "Customers Also Bought" override, one for
 * "Adventure Seekers Also Viewed". Both are used by
 * Block\Product\View\CustomersAlsoBought / AdventureSeekersAlsoViewed only
 * as a fallback when Amasty's automatic data has nothing for a product.
 *
 * The third grid feeds the "Frequently Bought Together" section, where the picks
 * are used when the co-purchase query finds nothing for this product - or always,
 * if pdp_fbt_force_manual is set on it.
 */
class ManualCarousels extends Related
{
    public const DATA_SCOPE_MANUAL_CAB = CreateManualCarouselLinkTypes::LINK_TYPE_CODE_MANUAL_CAB;
    public const DATA_SCOPE_MANUAL_ASAV = CreateManualCarouselLinkTypes::LINK_TYPE_CODE_MANUAL_ASAV;
    public const DATA_SCOPE_MANUAL_FBT = CreateManualFbtLinkType::LINK_TYPE_CODE_MANUAL_FBT;

    public function modifyMeta(array $meta)
    {
        $meta = parent::modifyMeta($meta);

        // pdp_fbt_bundle_discount_percent is 'visible' => true (see Setup\Patch\
        // Data\CreateFbtBundleDiscountAttribute), so Magento's core "eav" modifier
        // (sortOrder ~40, runs before this one at 110) already auto-adds its own
        // copy of this field under the "Key Features" group. A hand-coded second
        // copy used to live in the FBT fieldset below instead (see former
        // getBundleDiscountField()) - confirmed by direct DB check to never
        // actually save regardless of its dataScope value. Rather than keep
        // fighting that, pull the real (working) auto-generated node out of Key
        // Features - untouched, including its own dataScope - and place that
        // same node in the FBT fieldset instead. Key Features no longer shows
        // it; the FBT fieldset shows the one that actually persists.
        $bundleDiscountField = $this->extractBundleDiscountField($meta);

        $meta[static::GROUP_RELATED]['children'][$this->scopePrefix . self::DATA_SCOPE_MANUAL_CAB]
            = $this->getManualCabFieldset();
        $meta[static::GROUP_RELATED]['children'][$this->scopePrefix . self::DATA_SCOPE_MANUAL_ASAV]
            = $this->getManualAsavFieldset();
        $meta[static::GROUP_RELATED]['children'][$this->scopePrefix . self::DATA_SCOPE_MANUAL_FBT]
            = $this->getManualFbtFieldset($bundleDiscountField);

        return $meta;
    }

    /**
     * Finds the pdp_fbt_bundle_discount_percent node added anywhere in $meta
     * by core's "eav" modifier, removes it from there, and returns it as-is
     * (dataScope and all) so the caller can re-attach it elsewhere without
     * altering anything that makes it actually save. Returns null if this
     * product's attribute set doesn't carry the attribute at all.
     */
    private function extractBundleDiscountField(array &$meta): ?array
    {
        $code = CreateFbtBundleDiscountAttribute::ATTRIBUTE_CODE;

        foreach ($meta as &$node) {
            if (isset($node['children']) && array_key_exists($code, $node['children'])) {
                $field = $node['children'][$code];
                unset($node['children'][$code]);

                return $field;
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $field = $this->extractBundleDiscountField($node['children']);
                if ($field !== null) {
                    return $field;
                }
            }
        }
        unset($node);

        return null;
    }

    protected function getDataScopes()
    {
        return array_merge(parent::getDataScopes(), [
            self::DATA_SCOPE_MANUAL_CAB,
            self::DATA_SCOPE_MANUAL_ASAV,
            self::DATA_SCOPE_MANUAL_FBT,
        ]);
    }

    protected function getManualCabFieldset(): array
    {
        $content = __(
            'Products shown in this product\'s PDP "Customers Also Bought" section.'
        );

        return [
            'children' => [
                'button_set' => $this->getButtonSet(
                    $content,
                    __('Add Products'),
                    $this->scopePrefix . self::DATA_SCOPE_MANUAL_CAB
                ),
                'modal' => $this->getGenericModal(
                    __('Add Customers Also Bought Products'),
                    $this->scopePrefix . self::DATA_SCOPE_MANUAL_CAB
                ),
                self::DATA_SCOPE_MANUAL_CAB => $this->getGrid($this->scopePrefix . self::DATA_SCOPE_MANUAL_CAB),
            ],
            'arguments' => [
                'data' => [
                    'config' => [
                        'additionalClasses' => 'admin__fieldset-section',
                        'label' => __('Customers Also Bought (Manual Fallback)'),
                        'collapsible' => false,
                        'componentType' => Fieldset::NAME,
                        'dataScope' => '',
                        'sortOrder' => 40,
                    ],
                ],
            ],
        ];
    }

    protected function getManualAsavFieldset(): array
    {
        $content = __(
            'Products shown in this product\'s PDP "Adventure Seekers Also Viewed" section.'
        );

        return [
            'children' => [
                'button_set' => $this->getButtonSet(
                    $content,
                    __('Add Products'),
                    $this->scopePrefix . self::DATA_SCOPE_MANUAL_ASAV
                ),
                'modal' => $this->getGenericModal(
                    __('Add Adventure Seekers Also Viewed Products'),
                    $this->scopePrefix . self::DATA_SCOPE_MANUAL_ASAV
                ),
                self::DATA_SCOPE_MANUAL_ASAV => $this->getGrid($this->scopePrefix . self::DATA_SCOPE_MANUAL_ASAV),
            ],
            'arguments' => [
                'data' => [
                    'config' => [
                        'additionalClasses' => 'admin__fieldset-section',
                        'label' => __('Adventure Seekers Also Viewed (Manual Fallback)'),
                        'collapsible' => false,
                        'componentType' => Fieldset::NAME,
                        'dataScope' => '',
                        'sortOrder' => 50,
                    ],
                ],
            ],
        ];
    }

    protected function getManualFbtFieldset(?array $bundleDiscountField): array
    {
        $content = __(
            'Shown in this product\'s PDP "Frequently Bought Together" section when no purchase history '
            . 'is available for it. Set "Always Use My FBT Picks" on this product to use these instead '
            . 'of purchase history. Drag to order - the section shows the first few. Only '
            . 'in-stock, priced products can be added by the one-click bundle button.'
        );

        $children = [
            'button_set' => $this->getButtonSet(
                $content,
                __('Add Products'),
                $this->scopePrefix . self::DATA_SCOPE_MANUAL_FBT
            ),
            'modal' => $this->getGenericModal(
                __('Add Frequently Bought Together Products'),
                $this->scopePrefix . self::DATA_SCOPE_MANUAL_FBT
            ),
            self::DATA_SCOPE_MANUAL_FBT => $this->getGrid($this->scopePrefix . self::DATA_SCOPE_MANUAL_FBT),
        ];

        // Null only if this product's attribute set doesn't carry the
        // attribute at all - see extractBundleDiscountField().
        if ($bundleDiscountField !== null) {
            $bundleDiscountField['arguments']['data']['config']['sortOrder'] = 70;
            // A field's dataScope is composed with its ancestors', and the
            // result becomes its provider link ('<provider>:<dataScope>').
            // Core's "eav" modifier sets no dataScope on the fields it
            // generates: it sets dataScope='product' on the *group* container
            // (Eav::modifyMeta(), DATA_SCOPE_PRODUCT), which composes under the
            // form's own 'data' to give each field 'data.product.<code>' - the
            // path the provider actually stores product data at.
            //
            // GROUP_RELATED and the fieldset below it both have dataScope=''
            // (Related::DATA_SCOPE), so they contribute nothing: a node
            // re-parented here keeps whatever it declares, verbatim. Hence the
            // full absolute path, exactly as core does for this same section's
            // own grids ('data.links', Related::getGrid()). A bare 'product.'
            // prefix is NOT enough - it links to 'product.<code>', where the
            // provider holds nothing, and the value is silently dropped on save.
            $bundleDiscountField['arguments']['data']['config']['dataScope']
                = 'data.product.' . CreateFbtBundleDiscountAttribute::ATTRIBUTE_CODE;

            // The attribute is a plain text input (frontend_input 'text', so it
            // can hold "12.5" - see CreateFbtBundleDiscountAttribute), and the
            // "eav" modifier attaches no validation of its own, so letters are
            // accepted and silently discarded by the decimal backend on save.
            // validate-number rejects anything non-numeric while still allowing
            // the decimal point; validate-digits would reject "12.5" too, and
            // validate-number-range alone passes text through. Merged so any
            // rule the attribute itself contributes (e.g. required-entry) is
            // kept rather than replaced.
            $existingValidation = $bundleDiscountField['arguments']['data']['config']['validation'] ?? [];
            $bundleDiscountField['arguments']['data']['config']['validation'] = array_merge(
                $existingValidation,
                [
                    'validate-number' => true,
                    'validate-number-range' => '0-100',
                ]
            );

            // ...and refuse the keystroke in the first place, so the field can
            // never show a value it won't keep. The rules above stay on as the
            // backstop for anything that reaches the form without typing.
            $bundleDiscountField['arguments']['data']['config']['component']
                = 'Ahy_PDPRevamp/js/form/element/decimal-input';
            $bundleDiscountField['arguments']['data']['config']['elementTmpl']
                = 'Ahy_PDPRevamp/form/element/decimal-input';

            $children[CreateFbtBundleDiscountAttribute::ATTRIBUTE_CODE] = $bundleDiscountField;
        }

        return [
            'children' => $children,
            'arguments' => [
                'data' => [
                    'config' => [
                        'additionalClasses' => 'admin__fieldset-section',
                        'label' => __('Frequently Bought Together (Manual)'),
                        'collapsible' => false,
                        'componentType' => Fieldset::NAME,
                        'dataScope' => '',
                        'sortOrder' => 60,
                    ],
                ],
            ],
        ];
    }
}
