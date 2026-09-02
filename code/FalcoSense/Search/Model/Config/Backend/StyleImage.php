<?php
declare(strict_types=1);

namespace FalcoSense\Search\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Image;
use Magento\Framework\App\ResourceConnection;

/**
 * Image-upload counterpart to StyleValue: handles the actual file upload via
 * the parent Image/File backend (which persists the resulting filename to
 * core_config_data as usual), then mirrors that filename into
 * falcosense_style_value so the frontend Reader can resolve it.
 */
class StyleImage extends Image
{
    use MirrorsStyleValueTrait;

    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\App\Config\ScopeConfigInterface $config,
        \Magento\Framework\App\Cache\TypeListInterface $cacheTypeList,
        \Magento\MediaStorage\Model\File\UploaderFactory $uploaderFactory,
        \Magento\Config\Model\Config\Backend\File\RequestData\RequestDataInterface $requestData,
        \Magento\Framework\Filesystem $filesystem,
        private readonly ResourceConnection $resourceConnection,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $uploaderFactory,
            $requestData,
            $filesystem,
            $resource,
            $resourceCollection,
            $data
        );
    }

    protected function _getAllowedExtensions()
    {
        return ['jpg', 'jpeg', 'gif', 'png', 'svg'];
    }

    public function afterSave()
    {
        parent::afterSave();

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
