<?php
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;

require __DIR__ . '/../../../../bootstrap.php';

$params = $_SERVER;
$bootstrap = Bootstrap::create(BP, $params);
$obj = $bootstrap->getObjectManager();

try {
    $obj->get(State::class)->setAreaCode('adminhtml');
} catch (\Magento\Framework\Exception\LocalizedException $e) {
    // Area code already set
}

$relativeFolder = '/../../../../../pub/media/klevu_images/1200X1200/flxpoint_images';
$inputDir = realpath(__DIR__ . $relativeFolder);
$outputDir = $inputDir;

if (!$inputDir || !is_dir($inputDir)) {
    die("❌ Image directory not found at: " . realpath(__DIR__ . $relativeFolder) . "\n");
}

$jpegQuality = 50; // JPEG quality: 0 (lowest) to 100 (best quality, largest size)
$webpQuality = 50; // WebP quality: 0 (lowest) to 100 (best quality, largest size)

$files = glob($inputDir . '/*.{jpg,jpeg,png}', GLOB_BRACE);

foreach ($files as $filePath) {
    $info = pathinfo($filePath);
    $webpPath = $outputDir . '/' . $info['filename'] . '.webp';

    if (file_exists($webpPath)) {
        echo "✅ WebP exists for {$info['basename']}, skipping conversion.\n";
        continue;
    }

    $mime = mime_content_type($filePath);

    switch ($mime) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($filePath);
            if (!$image) {
                echo "Failed to load JPEG: $filePath\n";
                continue 2;
            }
            break;

        case 'image/png':
            $image = imagecreatefrompng($filePath);
            if (!$image) {
                echo "Failed to load PNG: $filePath\n";
                continue 2;
            }
            break;

        default:
            echo "Unsupported MIME type: $mime for file $filePath\n";
            continue 2;
    }

    // --- Create WebP version ---
    $tmpWebp = tempnam(sys_get_temp_dir(), 'webp_');
    if (!$tmpWebp) {
        echo "Failed to create temp file for WebP conversion\n";
        imagedestroy($image);
        continue;
    }

    if (!imagewebp($image, $tmpWebp, $webpQuality)) {
        echo "Failed to convert {$info['basename']} to WebP\n";
        unlink($tmpWebp);
        imagedestroy($image);
        continue;
    }
    imagedestroy($image);

    if (file_exists($webpPath)) {
        $existingSize = filesize($webpPath);
        $newSize = filesize($tmpWebp);

        if ($newSize < $existingSize) {
            if (rename($tmpWebp, $webpPath)) {
                chmod($webpPath, 0644);
                echo "Replaced WebP for {$info['basename']} (new smaller: {$newSize} bytes)\n";
            } else {
                echo "Failed to replace WebP file: $webpPath\n";
                unlink($tmpWebp);
            }
        } else {
            echo "Skipped replacing WebP {$info['basename']} (existing is smaller or equal)\n";
            unlink($tmpWebp);
        }
    } else {
        if (rename($tmpWebp, $webpPath)) {
            chmod($webpPath, 0644);
            echo "Created WebP for {$info['basename']}\n";
        } else {
            echo "Failed to save WebP for {$info['basename']}\n";
            unlink($tmpWebp);
        }
    }
}
?>
