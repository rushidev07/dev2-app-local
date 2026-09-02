<?php
declare(strict_types=1);

namespace Ahy\ThemeCustomization\Plugin;

/**
 * Magento\Ui\Component\Listing\Columns\Column::applySorting() calls strtoupper() on
 * $sorting['direction'] with no guard on its shape, crashing with a TypeError whenever a
 * request supplies a non-scalar value for sorting[direction] or sorting[field]
 * (e.g. ?sorting[direction][]=asc from a malformed/crafted grid URL). Sanitize the value
 * at its source (Context::getRequestParam) so core code always sees a plain scalar.
 */
class SanitizeUiComponentSortingParam
{
    public function afterGetRequestParam($subject, $result, $key, $defaultValue = null)
    {
        if ($key !== 'sorting' || !is_array($result)) {
            return $result;
        }

        foreach (['field', 'direction'] as $subKey) {
            if (isset($result[$subKey]) && !is_scalar($result[$subKey])) {
                $result[$subKey] = null;
            }
        }

        return $result;
    }
}
