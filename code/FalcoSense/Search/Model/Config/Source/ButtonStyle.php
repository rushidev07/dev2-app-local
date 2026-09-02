<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ButtonStyle implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => 'outline', 'label' => __('Outline (transparent background, colored border/text)')],
            ['value' => 'filled', 'label' => __('Filled (solid background, white text)')],
        ];
    }
}
