<?php
// Load OpenCart Configuration
require_once('config.php');

// Create database connection
$db = new mysqli(DB_HOSTNAME, DB_USERNAME, DB_PASSWORD, DB_DATABASE, DB_PORT);
if ($db->connect_error) die("<br>Connection failed: " . $db->connect_error);

// Configuration
$baseImagePath = rtrim(DIR_IMAGE, '/') . '/catalog/products/';
$maxImages = 20;
$tablePrefix = DB_PREFIX;
$dryRun = true;

echo '<!DOCTYPE html><html><head><title>Product Image Updater</title></head><body>';
echo '<div style="font-family: Arial, sans-serif; max-width: 800px; margin: 20px auto;">';
echo '<h2>Product Image Migration Tool</h2>';

// Dry Run Analysis
$report = [
    'total' => 0,
    'main_updates' => 0,
    'additions' => 0,
    'deletions' => 0,
    'samples' => []
];

// Get products
$result = $db->query("
    SELECT p.product_id, p.model, p.image AS current_image,
    (SELECT COUNT(*) FROM {$tablePrefix}product_image WHERE product_id = p.product_id) AS existing_images
    FROM {$tablePrefix}product p
");

if (!$result) die("<br>Error: " . $db->error);

$products = $result->fetch_all(MYSQLI_ASSOC);
$report['total'] = count($products);

foreach ($products as $product) {
    $model = $db->real_escape_string($product['model']);
    $cleanModel = preg_replace('/[^a-zA-Z0-9-_]/', '', $model);
    $imageDir = $baseImagePath . $cleanModel . '/';
    
    // Main image check
    $mainImage = 'no_image.png';
    if (!empty($cleanModel)) {
        $mainFile = "{$cleanModel}-01.jpg";
        if (file_exists($imageDir . $mainFile)) {
            $mainImage = "catalog/products/{$cleanModel}/{$mainFile}";
        }
    }
    
    // Additional images check
    $newImages = 0;
    if (!empty($cleanModel) && is_dir($imageDir)) {
        for ($i = 2; $i <= $maxImages; $i++) {
            $num = str_pad($i, 2, '0', STR_PAD_LEFT);
            if (file_exists($imageDir . "{$cleanModel}-{$num}.jpg")) $newImages++;
        }
    }
    
    // Build report
    if ($mainImage !== $product['current_image']) $report['main_updates']++;
    $report['additions'] += $newImages;
    $report['deletions'] += $product['existing_images'];
    
    if (count($report['samples']) < 3) {
        $report['samples'][] = [
            'id' => $product['product_id'],
            'model' => $model,
            'old' => $product['current_image'],
            'new' => $mainImage,
            'add' => $newImages,
            'del' => $product['existing_images']
        ];
    }
}

// Display dry run results
echo '<div style="border: 1px solid #ccc; padding: 20px; margin-bottom: 20px;">';
echo '<h3>Dry Run Report</h3>';
echo "Total products: {$report['total']}<br>";
echo "Main images to update: {$report['main_updates']}<br>";
echo "Additional images to add: {$report['additions']}<br>";
echo "Existing images to delete: {$report['deletions']}<br>";

echo '<h4>Sample Changes:</h4>';
foreach ($report['samples'] as $sample) {
    echo "Product #{$sample['id']} ({$sample['model']})<br>";
    echo "Main image: {$sample['old']} → {$sample['new']}<br>";
    echo "Delete: {$sample['del']} images, Add: {$sample['add']} images<br><br>";
}
echo '</div>';

// Confirmation
echo '<form method="POST">';
echo '<input type="hidden" name="confirm" value="1">';
echo '<b>WARNING:</b> This will make permanent changes to the database.<br>';
echo '<input type="submit" value="CONFIRM CHANGES" style="padding: 10px 20px; background: #dc3545; color: white; border: none; cursor: pointer;">';
echo '</form>';

if (!isset($_POST['confirm'])) {
    echo '</div></body></html>';
    exit;
}

// Execute Changes
echo '<div style="border-top: 2px solid #ccc; margin-top: 20px; padding-top: 20px;">';
echo '<h3>Execution Progress</h3>';

foreach ($products as $product) {
    $productId = $product['product_id'];
    $model = $db->real_escape_string($product['model']);
    $cleanModel = preg_replace('/[^a-zA-Z0-9-_]/', '', $model);
    $imageDir = $baseImagePath . $cleanModel . '/';
    
    // Update main image
    $mainImage = 'no_image.png';
    if (!empty($cleanModel)) {
        $mainFile = "{$cleanModel}-01.jpg";
        if (file_exists($imageDir . $mainFile)) {
            $mainImage = "catalog/products/{$cleanModel}/{$mainFile}";
        }
    }
    
    $db->query("UPDATE {$tablePrefix}product SET image = '{$mainImage}' WHERE product_id = {$productId}");
    
    // Delete existing images
    $db->query("DELETE FROM {$tablePrefix}product_image WHERE product_id = {$productId}");
    
    // Insert new images
    if (!empty($cleanModel) && is_dir($imageDir)) {
        $sortOrder = 0;
        $stmt = $db->prepare("INSERT INTO {$tablePrefix}product_image (product_id, image, sort_order) VALUES (?, ?, ?)");
        
        for ($i = 2; $i <= $maxImages; $i++) {
            $num = str_pad($i, 2, '0', STR_PAD_LEFT);
            $file = "{$cleanModel}-{$num}.jpg";
            
            if (file_exists($imageDir . $file)) {
                $sortOrder++;
                $imagePath = "catalog/products/{$cleanModel}/{$file}";
                $stmt->bind_param("isi", $productId, $imagePath, $sortOrder);
                $stmt->execute();
            }
        }
        $stmt->close();
    }
    
    echo "Updated product #{$productId}<br>";
    flush();
}

echo '<h3>Operation Complete!</h3>';
echo "Main images updated: {$report['main_updates']}<br>";
echo "Additional images added: {$report['additions']}<br>";
echo "Old images removed: {$report['deletions']}<br>";
echo '</div></div></body></html>';