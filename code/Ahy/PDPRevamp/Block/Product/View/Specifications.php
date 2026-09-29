<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Block\Product\View;

use Ahy\PDPRevamp\Model\Product\SpecificationsResolver;
use Hyva\Theme\Model\ViewModelRegistry;
use Hyva\Theme\ViewModel\CurrentProduct;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

/**
 * PDP "Specifications" tab (see product/view/specifications.phtml). Reads
 * the pdp_specifications textarea attribute when the admin has filled it in
 * (one "Label: Value" pair per line), otherwise falls back to whatever
 * label/value <table> is already in the product's plain Description field -
 * see Model\Product\SpecificationsResolver for that split, same pattern as
 * KeyFeaturesResolver uses for the Key Features tile grid.
 */
class Specifications extends Template
{
    private ViewModelRegistry $viewModelRegistry;
    private SpecificationsResolver $specificationsResolver;

    public function __construct(
        Context $context,
        ViewModelRegistry $viewModelRegistry,
        SpecificationsResolver $specificationsResolver,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->viewModelRegistry = $viewModelRegistry;
        $this->specificationsResolver = $specificationsResolver;
    }

    public function getProduct(): Product
    {
        /** @var CurrentProduct $currentProduct */
        $currentProduct = $this->viewModelRegistry->require(CurrentProduct::class);
        return $currentProduct->get();
    }

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function getSpecifications(): array
    {
        $product = $this->getProduct();

        return $this->specificationsResolver->getSpecifications(
            $product,
            (string) $product->getData('description')
        );
    }
}
