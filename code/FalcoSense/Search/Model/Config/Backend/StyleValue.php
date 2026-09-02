<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;

/**
 * Persists a system.xml field into falcosense_style_value instead of core_config_data.
 * The field's group id must match a falcosense_style_component.code and the field id
 * must match a falcosense_style_attribute.code, e.g. path "smart_search/add_to_cart_button/border_radius".
 */
class StyleValue extends Value
{
    use MirrorsStyleValueTrait;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        private readonly ResourceConnection $resourceConnection,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    public function beforeSave()
    {
        $value = (string)$this->getValue();
        $inputType = $this->resolveAttribute($this->resourceConnection, $this->getPath())['input_type'];

        if ($inputType === 'slider' && $value !== '' && !preg_match('/^\d{1,4}$/', $value)) {
            throw new LocalizedException(__('This value must be a whole number of pixels (0-9999).'));
        }

        if ($inputType === 'text' && mb_strlen($value) > 50) {
            throw new LocalizedException(__('This value must be 50 characters or fewer.'));
        }

        return parent::beforeSave();
    }

    public function afterSave()
    {
        $attributeId = $this->resolveAttribute($this->resourceConnection, $this->getPath())['attribute_id'];

        $this->mirrorStyleValue(
            $this->resourceConnection,
            (int)$attributeId,
            (string)$this->getScope(),
            (int)$this->getScopeId(),
            (string)$this->getValue()
        );

        return $this;
    }
}
