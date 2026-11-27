<?php
declare(strict_types=1);
function ui_icon(string $key):string{
    $icons=['dashboard'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>','orders'=>'<path d="M6 3 3 7v14h18V7l-3-4ZM3 7h18M8 11a4 4 0 0 0 8 0"/>','products'=>'<path d="m12 3 9 5-9 5-9-5 9-5ZM3 8v9l9 5 9-5V8M12 13v9M7.5 5.5l9 5"/>','price'=>'<path d="m20 13-7 7a2 2 0 0 1-3 0l-7-7V3h10l7 7a2 2 0 0 1 0 3Z"/><circle cx="7.5" cy="7.5" r="1"/>','finance'=>'<path d="M3 3v18h18M7 14l4-4 4 4 6-7"/>','settings'=>'<circle cx="12" cy="12" r="3"/><path d="m10 3-1 3-3 1-3 3 3 2v3l-1 3 3 2 3-1 3 2 3-2 1-3 3-1v-4l-3-1-1-3-3-2-2 1Z"/>','store'=>'<path d="M3 9h18l-2-6H5L3 9Zm1 0v12h16V9M9 21v-7h6v7"/>','bell'=>'<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>','user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>','menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>','chevron'=>'<path d="m9 18 6-6-6-6"/>','wallet'=>'<rect x="3" y="5" width="18" height="14" rx="3"/><path d="M16 10h5v4h-5a2 2 0 0 1 0-4Z"/>','check'=>'<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>'];
    return '<svg class="ui-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">'.($icons[$key]??$icons['dashboard']).'</svg>';
}
function ui_navigation(bool $admin):array{
    $n=[
    'dashboard'=>['Genel Bakış','dashboard.php',['dashboard.php'=>'Genel Bakış','uyarilar.php'=>'Uyarılar']],
    'orders'=>['Siparişler','canli-performans.php',['canli-performans.php'=>'Siparişler','iade-kontrol.php'=>'İadeler','kargo-kontrol.php'=>'Kargo kontrolü']],
    'products'=>['Ürünler','urunler.php',['urunler.php'=>'Ürünlerim','maliyet-senaryosu.php'=>'Toplu maliyet','maliyet-gecmisi.php'=>'Maliyet geçmişi','perde-maliyeti.php'=>'Perde maliyeti']],
    'price'=>['Fiyat ve Kampanya','promosyon-analiz.php',['promosyon-analiz.php'=>'Komisyon ve kampanyalar','kar-marji.php'=>'Kâr marjı','toplu-analiz.php'=>'Toplu analiz','kar-hedefleri.php'=>'Kâr hedefleri','pazar-zekasi.php'=>'Pazar zekâsı']],
    'finance'=>['Finans ve Raporlar','raporlar.php',['raporlar.php'=>'Finans raporları','hakedis-desi.php'=>'Hakediş ve desi','banka-mutabakat.php'=>'Banka kontrolü']],
    'settings'=>['Ayarlar','magaza.php',['magaza.php'=>'Mağaza bağlantısı','hesabim.php'=>'Hesabım','odeme.php'=>'Abonelik']]];
    if($admin)$n['settings'][2]['admin.php']='Yönetici paneli';return $n;
}
function ui_kpi(string $icon,string $label,string $value,string $sub,string $tone=''):void{
    ?><article class="ui-kpi"><span class="ui-kpi-icon"><?=ui_icon($icon)?></span><div class="ui-kpi-label"><?=h($label)?></div><strong class="ui-kpi-value <?=h($tone)?>"><?=h($value)?></strong><p class="ui-muted"><?=h($sub)?></p></article><?php
}
function ui_status(string $raw):array{
    return match(strtolower($raw)){
      'created','awaiting','unpacked'=>['Yeni sipariş','neutral'],
      'picking','invoiced','packed','readytoship'=>['Hazırlanıyor','info'],
      'shipped','atcollectionpoint'=>['Kargoda','info'],
      'delivered'=>['Teslim edildi','success'],
      'cancelled','canceled','unsupplied'=>['İptal edildi','neutral'],
      'returned'=>['İade edildi','warning'],
      'undelivered'=>['Teslim edilemedi','danger'],
      default=>[$raw!==''?$raw:'Bilinmiyor','neutral']};
}
