<?php
use Magento\Framework\App\Bootstrap;

require __DIR__ . '/../../../../bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$obj = $bootstrap->getObjectManager();

$state = $obj->get(\Magento\Framework\App\State::class);
$state->setAreaCode('adminhtml');

$resource = $obj->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();

$csvFile = 'app/code/Ahy/UpdateProductsCSV/csv-files/faqs/final_sheet_faqs.csv';
$storeId = 1;

if (!file_exists($csvFile)) {
    exit("CSV not found\n");
}

// ── Clear existing FAQ data (keep IDs 1–6, customer questions) ───────────────
echo "Clearing existing FAQ data (keeping IDs 1-6)...\n";
$connection->delete('amasty_faq_question_product', 'question_id > 6');
$connection->delete('amasty_faq_question_store',   'question_id > 6');
$connection->delete('amasty_faq_question',          'question_id > 6');
echo "Tables cleared.\n";

$rows = array_map('str_getcsv', file($csvFile));
$header = array_map('strtolower', array_shift($rows));

foreach ($rows as $row) {
    $data = array_combine($header, $row);

    $sku = trim($data['sku']);
    echo "Processing SKU: $sku\n";

    // Get product IDs (including -1, -2)
    $select = $connection->select()
        ->from('catalog_product_entity', 'entity_id')
        ->where('sku = ?', $sku)
        ->orWhere('sku LIKE ?', $sku . '-%');

    $productIds = $connection->fetchCol($select);

    if (empty($productIds)) {
        echo "No products found for SKU: $sku\n";
        continue;
    }

    // Loop through FAQ columns (1–10)
    for ($i = 1; $i <= 10; $i++) {

        $question = trim($data["faq_{$i}_question"] ?? '');
        $answer   = trim($data["faq_{$i}_answer"] ?? '');

        if (empty($question) || empty($answer)) {
            continue; // skip empty FAQs
        }

        try {
            // ✅ Generate next question_id
            $questionId = (int)$connection->fetchOne(
                "SELECT MAX(question_id) FROM amasty_faq_question"
            ) + 1;

            $urlKey = preg_replace('/[^a-z0-9]+/', '-', strtolower($question)) . ".html";

            // Insert FAQ
            $connection->insert('amasty_faq_question', [
                'question_id' => $questionId,
                'title' => $question,
                'answer' => $answer,
                'visibility' => 1,
                'status' => 1,
                'position' => $i,
                'url_key' => $urlKey,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'is_show_full_answer' => 0
            ]);

            // Store link
            $connection->insert('amasty_faq_question_store', [
                'question_id' => $questionId,
                'store_id' => $storeId
            ]);

            // Link to products
            foreach ($productIds as $productId) {
                $connection->insertOnDuplicate('amasty_faq_question_product', [
                    'question_id' => $questionId,
                    'product_id' => $productId
                ]);
            }

            echo "Inserted FAQ ($i) for SKU $sku\n";

        } catch (\Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }
}

echo "Done.\n";