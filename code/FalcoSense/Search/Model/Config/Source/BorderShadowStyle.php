<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class BorderShadowStyle implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'none', 'label' => __('None')],
            ['value' => 'border', 'label' => __('Border only')],
            ['value' => 'shadow', 'label' => __('Shadow only')],
            ['value' => 'both', 'label' => __('Border and shadow')],
        ];
    }
}
