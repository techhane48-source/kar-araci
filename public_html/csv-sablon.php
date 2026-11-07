<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/functions.php';

// Örnek CSV şablonu indirir (Pro gerektirmez; sadece format göstermek için)
$u = require_login();

// UTF-8 BOM ekle ki Excel Türkçe karakterleri doğru açsın
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="karfiyat-urunler-ornek.csv"');
echo "\xEF\xBB\xBF"; // UTF-8 BOM — Excel Türkçe karakterleri doğru açsın

$rows = [
    ['name', 'marketplace', 'category', 'cost', 'price', 'cargo', 'service_fee', 'stopaj', 'return_rate'],
    ['Kadın Bot 38', 'trendyol', 'Ayakkabı & Çanta', '200', '400', '50', '8', '3', '3'],
    ['Nevresim Takımı', 'hepsiburada', 'Ev Tekstili', '150', '350', '40', '6', '2', '2'],
    ['Kablosuz Kulaklık', 'amazon', 'Elektronik (genel)', '300', '550', '25', '5', '4', '4'],
    ['Akıllı Saat', 'n11', 'Kol Saati', '500', '900', '30', '5', '6', '3'],
];

$out = fopen('php://output', 'w');
foreach ($rows as $row) {
    fputcsv($out, $row, ',', '"', '\\');
}
fclose($out);
