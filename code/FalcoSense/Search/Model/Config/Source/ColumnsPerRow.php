<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class ColumnsPerRow implements OptionSourceInterface
{
    public function toOptionArray(): array
    {
        return [
            ['value' => '2', 'label' => __('2 columns')],
            ['value' => '3', 'label' => __('3 columns')],
            ['value' => '4', 'label' => __('4 columns')],
        ];
    }
}
