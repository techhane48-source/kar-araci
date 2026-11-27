import openpyxl, sys
out=sys.argv[1]
def mk(name, header, rows):
    wb=openpyxl.Workbook(); ws=wb.active; ws.title='Sheet0'
    ws.append(header)
    for r in rows: ws.append(r)
    wb.save(f'{out}/{name}.xlsx')
# Ürün Komisyon Tarifesi — gerçek dosyanın kolonları (27.11.2026), değerler hesaptan
H=["ÜRÜN İSMİ","BARKOD","SATICI STOK KODU","BEDEN","MODEL KODU","KATEGORİ","MARKA","STOK","1.Fiyat Alt Limit","2.Fiyat Üst Limiti","2.Fiyat Alt Limit","3.Fiyat Üst Limiti","3.Fiyat Alt Limit","4.Fiyat Üst Limiti","Tarih aralığı (3 Gün)","1.KOMİSYON","2.KOMİSYON","3.KOMİSYON","4.KOMİSYON","Tarih aralığı (4 Gün)","1.KOMİSYON","2.KOMİSYON","3.KOMİSYON","4.KOMİSYON","KOMİSYONA ESAS FİYAT","GÜNCEL KOMİSYON","GÜNCEL TSF","Müşterinin Gördüğü Güncel Fiyat","Plus Müşterinin Gördüğü Fiyat","Güncel Uygulanan Promosyonlar","YENİ TSF (FİYAT GÜNCELLE)","Hesaplanan Komisyon (3 Gün)","Hesaplanan Komisyon (4 Gün)","Tarife Seçimi","FIRST EXTERNAL ID","SECOND EXTERNAL ID","FULL EXTERNAL ID","TARİFE GRUBU"]
p3="26 Kasım 08.00-29 Kasım 07.59"; p4="29 Kasım 08.00-3 Aralık 07.59"
mk('komisyon-tarifesi',H,[
 ["8 Adet Bambu Yastık Alezi","bamboyastik8li","","","bambooyastik8li","Yastık Alezi","Navessa","18998.0","799.06","799.05","723.36","723.35","647.66","647.65",p3,"21.0","13.1","8.4","6.1",p4,"21.0","17.6","15.8","13.6","791.06","13.1","799.05","791.06","","Sepette %1 İndirim -7.99₺","","0.0","0.0","7 Günlük Fiyat","","","bh_x","grp1"],
 ["2 Adet Bambu Yastık Alezi","yastikalezi50x70","","","bambooyastik","Yastık Alezi","Navessa","18995.0","282.22","282.21","255.49","255.48","228.75","228.74",p3,"21.0","13.1","8.4","6.1",p4,"21.0","17.6","15.8","13.6","296.9","21.0","299.9","296.9","208.87","Sepette %1 İndirim -3₺","","0.0","0.0","","","","","grp1"],
 ["Şönil Koltuk Örtüsü","kanepecan","","","KANEPEORTUSU","Koltuk Örtüsü","Navessa","18962.0","477.02","477.01","425.45","425.44","379.04","379.03",p3,"21.0","11.9","6.3","6.1",p4,"21.0","17.2","15.0","12.6","670.0","21.0","3350.0","670.0","636.5","Sepette %80 İndirim -2680₺","","0.0","0.0","","","","","grp1"],
 ["Kayıtsız Ürün","YOKBARKOD","","","X","Diğer","Navessa","5","100","99","90","89","80","79",p3,"20","15","10","5",p4,"20","15","10","5","120","20","120","120","","","","0","0","","","","","grp1"],
])
H=["Ürün İsmi","Ürün Id","Barkod","Satıcı Stok Kodu","Beden","Model Kodu","Kategori","Marka","Stok","Güncel TSF","Komisyona Esas Fiyat","Güncel Komisyon","Plus Fiyat Üst Limiti","Tarih Aralığı (3 Gün)","Plus Komisyon Teklifi","Tarih Aralığı (4 Gün)","Plus Komisyon Teklifi","Plus Komisyona Esas Fiyatı","Müşterinin Gördüğü Güncel Fiyat","Plus Müşterinin Gördüğü Fiyat","Güncel Uygulanan Promosyonlar","Plus Fiyat Seçimi","Tarife Seçimi","External Id","Hesaplanan Komisyon (3 Gün)","Hesaplanan Komisyon (4 Gün)","İptal","Tarife Grubu","3 Gün Tarih Aralığı","4 Gün Tarih Aralığı","7 Gün Tarih Aralığı","External Id (3 Gün)","External Id (4 Gün)","External Id (7 Gün)"]
mk('plus-tarifesi',H,[
 ["Şönil Koltuk Örtüsü","1220348676","kanepecan","","","KANEPEORTUSU","Koltuk Örtüsü","Navessa","18962.0","3350.0","670.0","21.0","343.5",p3,"2.7",p4,"7.7","636.5","670.0","636.5","","","","","0.0","0.0","","g","3 Günlük Fiyat","4 Günlük Fiyat","7 Günlük Fiyat","","",""],
 ["8 Adet Bambu Yastık Alezi","1237659924","bamboyastik8li","","","bambooyastik8li","Yastık Alezi","Navessa","18998.0","849.9","791.06","13.1","597.35",p3,"2.7",p4,"9.7","791.06","791.06","","","","","","0.0","0.0","","g","3 Günlük Fiyat","4 Günlük Fiyat","7 Günlük Fiyat","","",""],
])
H=["ÜRÜN İSMİ","BARKOD","BEDEN","MODEL KODU","KATEGORİ","MARKA","STOK","KOMİSYON TARİFESİ","1 YILDIZ ÜST FİYAT","1 YILDIZ ALT FİYAT","2 YILDIZ ÜST FİYAT","2 YILDIZ ALT FİYAT","3 YILDIZ ÜST FİYAT","MÜŞTERİNİN GÖRDÜĞÜ FİYAT","TRENDYOL SATIŞ FİYATI","Müşterinin Gördüğü Güncel Fiyat","Plus Müşterinin Gördüğü Fiyat","Güncel Uygulanan Promosyonlar","YENİ TSF (FİYAT GÜNCELLE)","Tarife Sonuna Kadar Uygula","EXTERNAL ID","TARİFE GRUBU"]
mk('avantajli-etiket',H,[
 ["Çizgili Nevresim","1cmyollugriset","","1CMYOLLUSET","Çift Kişilik Nevresim","Navessa","7.0","Yok","682,88","618,20","618,19","553,50","553,49","520,56","600,00","520.56","","Sepette %1 + %5","","Evet","bh_1","g"],
])
H=["Ürün Id Gizli","Barkod","Ürün Adı","Kategori","Marka","Stok","Mevcut Fiyat","Müşterinin Gördüğü Fiyat","Plus Müşterinin Gördüğü Fiyat","Güncel Uygulanan Promosyonlar","Mevcut Komisyon","Güncellenecek Fiyat","24 Saat Fiyat","3 Saat Fiyat","Senin Belirlediğin Flaş Fiyatı","24 Saat Flaş Başlangıç Tarihi","24 Saat Flaş Bitiş Tarihi","3 Saat Flaş Başlangıç Tarihi","3 Saat Flaş Bitiş Tarihi","Ürün Komisyon Tarife Seçeneği","Kampanyalı Ürün","Ürün Id"]
mk('flas-urunler',H,[
 ["x","4lübambuyastik","4 Adet Bambu Yastık Alezi","Yastık Alezi","Navessa","19000.0","499.9","417.25","-","Flaş Fiyatı: 417.25₺","21.0","","417.25","","","28/11/2026 00:00","28/11/2026 23:59","","","Yok","-","id1"],
 ["x","kanepecan","Şönil Koltuk Örtüsü","Koltuk Örtüsü","Navessa","18962.0","3350.0","670.0","636.5","","21.0","","416.93","","","28/11/2026 00:00","28/11/2026 23:59","","","Var","Kampanyalı Ürün","id2"],
])
