<?php
use Magento\Framework\App\Bootstrap;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;

require __DIR__ . '/../../../../bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$om = $bootstrap->getObjectManager();

/** Area code */
$state = $om->get(\Magento\Framework\App\State::class);
try {
    $state->setAreaCode('adminhtml');
} catch (\Exception $e) {}

/** Services */
$productRepository = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
$collectionFactory = $om->get(CollectionFactory::class);

$storeId = 0;

/** Collection: SKUs starting with hid- */
$collection = $collectionFactory->create();
$collection
    ->addAttributeToSelect('sku')
    ->addFieldToFilter('sku', ['like' => 'hid-%']);

echo "Found " . $collection->getSize() . " SKUs starting with hid-\n";

foreach ($collection as $item) {
    echo "Processing SKU: $sku\n";
    $sku = $item->getSku();

    try {
        $product = $productRepository->get($sku, false, $storeId);
        $product->setStoreId($storeId);

        $encodedHtml = (string)$product->getDescription();
        if (trim($encodedHtml) === '') {
            echo "⏩ Empty description, skipped\n";
            continue;
        }

        $html = html_entity_decode($encodedHtml, ENT_QUOTES | ENT_HTML5);

        libxml_use_internal_errors(true);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'),LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $xpath = new DOMXPath($dom);

        foreach ($xpath->query('//style') as $styleTag) {
            $styleTag->parentNode->removeChild($styleTag);
        }

        foreach ($xpath->query('//*[@style]') as $node) {
            $node->removeAttribute('style');
        }

        foreach ($xpath->query('//*[@class]') as $node) {
            $classes = explode(' ', $node->getAttribute('class'));
            $classes = array_filter($classes, fn($c) => trim($c) !== 'container');
            if (!empty($classes)) {
                $node->setAttribute('class', implode(' ', $classes));
            } else {
                $node->removeAttribute('class');
            }
        }

        foreach ($xpath->query('//p') as $p) {
            if (trim($p->textContent) === '' && !$p->hasChildNodes()) {
                $p->parentNode->removeChild($p);
            }
        }

        $h2s = $xpath->query('//h2');
        $firstH2 = true;

        foreach ($h2s as $h2) {
            if ($firstH2) {
                $firstH2 = false;
                continue;
            }

            $h3 = $dom->createElement('h3', $h2->textContent);
            $h2->parentNode->replaceChild($h3, $h2);
        }

        $images = $xpath->query('//img');

        for ($i = 0; $i < $images->length; $i++) {
            $img = $images->item($i);
            $next = $img->nextSibling;

            while ($next && $next->nodeType === XML_TEXT_NODE && trim($next->textContent) === '') {
                $next = $next->nextSibling;
            }

            if ($next && $next->nodeName === 'img') {
                $wrapper = $dom->createElement('div');
                $wrapper->setAttribute('class', 'flex justify-around w-full');
                $wrapper->setAttribute('style', 'background-color: #ff3c00;');

                $parent = $img->parentNode;
                $parent->insertBefore($wrapper, $img);

                while ($img && $img->nodeName === 'img') {
                    $nextImg = $img->nextSibling;
                    while ($nextImg && $nextImg->nodeType === XML_TEXT_NODE && trim($nextImg->textContent) === '') {
                        $nextImg = $nextImg->nextSibling;
                    }

                    $existingStyle = $img->getAttribute('style');
                    $img->setAttribute(
                        'style',
                        trim($existingStyle . '; margin-block: 0 !important;')
                    );

                    $wrapper->appendChild($img);
                    $img = $nextImg;
                }
            }
        }

        $finalHtml = $dom->saveHTML();

        if (trim($finalHtml) === trim($encodedHtml)) {
            echo "⏩ No changes\n";
            continue;
        }

        $product->setDescription($finalHtml);
        $productRepository->save($product);

        echo "✅ Updated\n";

    } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
        echo "❌ Product not found\n";
    } catch (\Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}

echo "Done.\n";
