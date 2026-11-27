"""Local HTTP smoke test. Isolated DB, no live API credentials or marketplace calls."""
import http.cookiejar
import json
import os
import re
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

root = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix="karfiyat-http-") as tmp:
    tmp = Path(tmp)
    db = tmp / "app.sqlite"
    prepend = tmp / "test-config.php"
    prepend.write_text("<?php define('DB_PATH', " + json.dumps(str(db)) + ");")
    with (tmp / "php.log").open("w+") as log:
        proc = subprocess.Popen(["php", "-d", "auto_prepend_file=" + str(prepend), "-S", "127.0.0.1:18765", "-t", str(root / "public_html")], stdout=log, stderr=log, cwd=root)
        try:
            base = "http://127.0.0.1:18765/"
            jar = http.cookiejar.CookieJar()
            opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
            def request(path, data=None, as_json=False):
                body = None if data is None else (json.dumps(data).encode() if as_json else urllib.parse.urlencode(data).encode())
                req = urllib.request.Request(base + path, data=body)
                if as_json:
                    req.add_header("Content-Type", "application/json")
                try:
                    with opener.open(req, timeout=10) as resp:
                        return resp.status, resp.read().decode()
                except urllib.error.HTTPError as e:
                    return e.code, e.read().decode()
            for _ in range(30):
                try:
                    status, page = request("kayit.php")
                    break
                except OSError:
                    time.sleep(.2)
            assert status == 200, "Registration page failed"
            csrf = re.search(r'name="csrf" value="([0-9a-f]+)"', page).group(1)
            status, page = request("kayit.php", {"csrf":csrf,"name":"CI","email":"http-test@example.invalid","password":"synthetic-password-123"})
            assert status == 200 and 'id="save-btn"' in page, "Registration did not log in"
            csrf = re.search(r'csrf:\s*"([0-9a-f]+)"', page).group(1)
            for control in ("cost-vat","negative-vat","stopaj-rate"):
                assert 'id="' + control + '"' in page, f"Missing {control}"
            data = {"marketplace":"trendyol","category":"Giyim","name":"CI product","cost":400,"price":717.74,"cargo":65,"rate":19,"kdv":20,"cost_vat":10,"service_fee":13.19,"stopaj_mode":"manual","stopaj":0}
            status, body = request("api-save.php", data, True)
            assert status == 403, "Missing CSRF accepted"
            data["csrf"] = csrf
            status, body = request("api-save.php", data, True)
            assert status == 200 and json.loads(body)["ok"], body
            with sqlite3.connect(db) as con:
                row = con.execute("SELECT cost_vat,kdv,stopaj_mode,stopaj FROM products WHERE name='CI product'").fetchone()
                assert row == (10.0,20.0,"manual",0.0), row
                con.execute("UPDATE users SET plan='pro' WHERE email='http-test@example.invalid'")
                uid = con.execute("SELECT id FROM users WHERE email='http-test@example.invalid'").fetchone()[0]
                con.execute("INSERT INTO integration_bindings(user_id,marketplace,seller_id,stage) VALUES(?,?,?,?)", (uid,"trendyol","123",1))
                con.execute("UPDATE products SET barcode='CI001' WHERE user_id=? AND name='CI product'", (uid,))
                now=int(time.time())
                lines=json.dumps([{"barcode":"CI001","quantity":1,"price":717.74,"line_id":"L1","commission_rate":19,"vat_rate":20,"cost_snapshot":400,"cost_vat_snapshot":10}])
                con.execute("INSERT INTO orders(user_id,marketplace,order_number,package_number,status,total_price,order_date,lines,currency_code,country_code,last_modified_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                            (uid,"trendyol","CI-ORDER","CI-PACKAGE","Delivered",717.74,now,lines,"TRY","TR",now*1000))
                raw=json.dumps({"id":"CI-INVOICE","sellerId":123,"affiliate":"TRENDYOLTR","country":"TR","debt":65,"credit":0})
                con.execute("INSERT INTO trendyol_financial_documents(user_id,external_id,query_type,transaction_type,transaction_date,raw_json) VALUES(?,?,?,?,?,?)",
                            (uid,"CI-INVOICE","DeductionInvoices","Kargo Faturası",now,raw))
                con.execute("INSERT INTO trendyol_cargo_items(user_id,invoice_serial,row_number,parcel_id,order_number,shipment_type,amount,desi) VALUES(?,?,?,?,?,?,?,?)",
                            (uid,"CI-INVOICE",0,"CI-PARCEL","CI-ORDER","Gönderi Kargo Bedeli",65,3))
                sale=json.dumps({"id":"CI-SALE","sellerId":123,"barcode":"CI001","orderNumber":"CI-ORDER","shipmentPackageId":"CI-PACKAGE","affiliate":"TRENDYOLTR","country":"TR","credit":717.74,"debt":0,"commissionAmount":136.3706,"sellerRevenue":581.3694})
                con.execute("INSERT INTO settlements(user_id,marketplace,external_id,order_number,shipment_package_id,query_type,raw_json,finance_scope) VALUES(?,?,?,?,?,?,?,?)",
                            (uid,"trendyol","CI-SALE","CI-ORDER","CI-PACKAGE","Sale",sale,"domestic"))
            for path in ("urunler.php","dashboard.php","magaza.php","kar-marji.php","canli-performans.php","raporlar.php","pazar-zekasi.php","hakedis-desi.php","promosyon-analiz.php","toplu-analiz.php","maliyet-gecmisi.php","kar-hedefleri.php","uyarilar.php","kargo-kontrol.php","banka-mutabakat.php","iade-kontrol.php"):
                status, page = request(path)
                assert status == 200 and len(page) > 500 and "<html" in page.lower(), (path, status, page[:150])
                print("PASS HTTP", path)
            for kind in ("order","product","category","return","ads","campaign","buybox","claims","order_finance","period_finance","product_finance","product_cost_evidence","cargo_effective"):
                status, page = request("raporlar.php?r=" + kind)
                assert status == 200 and "<html" in page.lower(), (kind, status)
                status, csv = request("raporlar.php?r=" + kind + "&export=1")
                assert status == 200 and csv.startswith("\ufeff"), (kind, status)
                print("PASS report view and CSV", kind)
            status, csv = request("uyarilar.php?export=csv")
            assert status == 200 and csv.startswith("\ufeff") and "Uyarılar" in csv
            print("PASS alert CSV")
            status, page = request("dashboard.php")
            assert status == 200 and "Tahmini Sipariş Kârı" in page and "717,74" in page and "İadeler ve stok değer kaybı" in page and 'id="main-content"' in page, "Populated dashboard missing data"
            status, csv = request("raporlar.php?r=order_finance&export=1")
            assert status == 200 and "CI-ORDER" in csv and "116.37" in csv, "Populated financial report missing known subtotal"
            status, csv = request("raporlar.php?r=product_cost_evidence&export=1")
            assert status == 200 and "CI001" in csv and "116.37" in csv, "Product cost evidence missing single-barcode subtotal"
            status, page = request("kargo-kontrol.php?q=CI-PARCEL")
            assert status == 200 and "CI-PARCEL" in page, "Populated cargo page missing parcel"
            status, csv = request("kargo-kontrol.php?q=CI-PARCEL&export=csv")
            assert status == 200 and "CI-PARCEL" in csv and "Beklenen desi" in csv, "Cargo CSV missing record"
            status, page = request("maliyet-senaryosu.php")
            assert status == 200 and "Özel maliyet senaryosu" in page
            status, page = request("maliyet-senaryosu.php", {"csrf":csrf,"phrase":"CI product","cost":"100","vat":"","days":"30","action":"preview"})
            assert status == 200 and "CI001" in page
            status, page = request("maliyet-senaryosu.php", {"csrf":csrf,"phrase":"CI product","cost":"100","vat":"","days":"30","action":"calculate","barcodes[]":"CI001"})
            assert status == 200 and "Senaryo sonucu" in page and "617,74" in page and "vergi sonrası tahmin gösterilmedi" in page
            status, page = request("maliyet-senaryosu.php", {"csrf":csrf,"phrase":"CI product","cost":"100","vat":"10","days":"30","action":"calculate","barcodes[]":"OTHER"})
            assert status == 200 and "Ürün seçimi geçersiz" in page
            with sqlite3.connect(db) as con:
                assert con.execute("SELECT cost FROM products WHERE barcode='CI001'").fetchone()[0] == 400
            status, page = request("maliyet-senaryosu.php", {"csrf":csrf,"phrase":"CI product","cost":"100","vat":"10","days":"30","action":"save_group","barcodes[]":"CI001","date":"2025-01-01"})
            assert status == 200 and "açık onay gerekli" in page
            payload={"csrf":csrf,"phrase":"CI product","cost":"100","vat":"10","days":"30","action":"save_group","barcodes[]":"CI001","date":"2025-01-01","confirm_save":"1"}
            status, page = request("maliyet-senaryosu.php", payload)
            assert status == 200 and "Maliyet geçmişi" in page
            status, page = request("maliyet-senaryosu.php", payload)
            assert status == 200
            with sqlite3.connect(db) as con:
                assert con.execute("SELECT COUNT(*) FROM product_cost_history WHERE user_id=? AND barcode='CI001'",(uid,)).fetchone()[0] == 1
                assert con.execute("SELECT cost FROM products WHERE barcode='CI001'").fetchone()[0] == 400
            from datetime import datetime
            from zoneinfo import ZoneInfo
            payload.update({"date":datetime.now(ZoneInfo("Europe/Istanbul")).date().isoformat(),"update_catalog":"1"})
            status, page = request("maliyet-senaryosu.php", payload)
            assert status == 200 and "Maliyet geçmişi" in page
            with sqlite3.connect(db) as con:
                assert con.execute("SELECT cost,cost_vat,price FROM products WHERE user_id=? AND barcode='CI001'",(uid,)).fetchone() == (100.0,10.0,717.74)
                assert con.execute("SELECT COUNT(*) FROM product_cost_history WHERE user_id=? AND barcode='CI001'",(uid,)).fetchone()[0] == 2
            # Conflicting dated periods are intentionally rejected, even with a replacement flag.
            payload.update({"date":"","first_sale":"1","cost":"90","replace":"1"})
            status, page = request("maliyet-senaryosu.php",payload)
            assert status == 200 and "Farklı tarihli maliyet kaydı var" in page
            print("PASS first-sale baseline conflict blocked")
            with sqlite3.connect(db) as con:
                pid=con.execute("SELECT id FROM products WHERE user_id=? AND barcode='CI001'",(uid,)).fetchone()[0]
                con.execute("INSERT INTO products(user_id,marketplace,name,barcode,category,rate,cost,price) VALUES(?,?,?,?,?,?,?,?)",(uid,"trendyol","CI direct","CI002","Test",19,0,200))
                direct_id=con.execute("SELECT id FROM products WHERE user_id=? AND barcode='CI002'",(uid,)).fetchone()[0]
            status, page = request("urunler.php")
            assert status == 200 and "Toplu maliyet gir" in page and "Maliyeti kaydet" in page
            direct={"action":"direct_cost","pid":str(direct_id),"cost":"80","vat":"10","scope":"first_sale"}
            status, page=request("urunler.php",direct)
            assert status==403
            direct["csrf"]=csrf
            status, page=request("urunler.php",dict(direct,vat="15"))
            assert status==200 and "%1, %10 veya %20" in page
            with sqlite3.connect(db) as con:
                assert con.execute("SELECT cost FROM products WHERE id=?",(direct_id,)).fetchone()[0]==0
            assert '<select name="vat" required>' in page and all('value="'+v+'"' in page for v in ["1","10","20"])
            status, page=request("urunler.php",direct)
            assert status==200 and "Maliyet ve alış KDV oranı kaydedildi." in page
            with sqlite3.connect(db) as con:
                assert con.execute("SELECT cost,cost_vat,price FROM products WHERE id=?",(direct_id,)).fetchone()==(80.0,10.0,200.0)
                assert con.execute("SELECT source FROM product_cost_history WHERE user_id=? AND barcode='CI002'",(uid,)).fetchone()==("first_sale",)
            # The same priced package must contribute to the daily and period profit.
            status, page=request("canli-performans.php?days=7")
            assert status==200 and 'data-profit-count="1"' in page, "Daily profit aggregation missing"
            total=re.search(r'id="period-profit">([^<]+)',page).group(1)
            assert total not in ("—","₺0,00"), total
            with sqlite3.connect(db) as con:
                for i in range(52):
                    con.execute("INSERT INTO orders(user_id,marketplace,order_number,package_number,status,total_price,order_date,lines,currency_code,country_code,last_modified_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                                (uid,"trendyol",f"CI-OLD-{i:02}",f"CI-POLD-{i:02}","Cancelled",100,now-20*86400,lines,"TRY","TR",now*1000))
                con.execute("INSERT INTO orders(user_id,marketplace,order_number,package_number,status,total_price,order_date,lines,currency_code,country_code,last_modified_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                            (uid,"trendyol","CI-OLDER-UNKNOWN","CI-PUNKNOWN","Created",100,now-21*86400,lines,"TRY","TR",now*1000))
            status, page=request("canli-performans.php?days=7")
            assert status==200 and "CI-OLD-" not in page
            status, page=request("canli-performans.php?days=30")
            assert status==200 and "54 kayıtlı paket" in page and "Sonraki sayfa" in page and 'data-profit-count="0"' in page
            assert re.search(r'data-profit-count="0"[^>]*>—</td>',page)
            status, page=request("canli-performans.php?days=30&page=2")
            assert status==200 and "CI-OLDER-UNKNOWN" in page and "Önceki sayfa" in page and "Son 30 Gün" in page
            assert "Paket iptal edildi" in page and 'id="closed-loss-summary"' in page
            print("PASS performance daily profit, unknown coverage and 30-day pagination")
            print("PASS direct cost entry without file and explicit history scope")
            print("PASS explicit current catalog update preserves selling price")
            print("PASS explicit dated group cost confirmation and idempotent save")
            print("PASS private scenario preview, selection, unknown VAT and unchanged persisted cost")
            status, page = request("iade-kontrol.php")
            assert status == 200 and "normal iadeler için işlem gerekmez" in page
            with sqlite3.connect(db) as con:
                con.execute("INSERT INTO trendyol_claim_items(user_id,claim_id,item_id,order_number,outbound_package,return_package,line_id,barcode,product_name,status,reason,platform_reason,created_ms,modified_ms,group_line_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                            (uid,"CI-CLAIM","CI-ITEM","CI-ORDER","CI-PACKAGE","CI-RETURN","L1","CI001","CI product","Accepted","","",now*1000,now*1000,""))
            status, page = request("iade-kontrol.php")
            assert status == 200 and "Otomatik: satışa uygun varsayımı" in page and "Satılamaz olarak işaretle" in page
            if os.environ.get("KAR_BROWSER_TEST") == "1":
                from playwright.sync_api import sync_playwright
                with sync_playwright() as pw:
                    browser = pw.chromium.launch(headless=True)
                    context = browser.new_context(viewport={"width":390,"height":844})
                    context.route("**/*", lambda route: route.continue_() if route.request.url.startswith(base) else route.abort())
                    context.add_cookies([{"name":c.name,"value":c.value,"url":base} for c in jar])
                    view = context.new_page()
                    for width in (390, 1440):
                        view.set_viewport_size({"width":width,"height":900})
                        view.goto(base+"dashboard.php",wait_until="domcontentloaded")
                        assert view.locator("#returns-overview").is_visible()
                        assert view.evaluate("document.documentElement.scrollWidth <= innerWidth + 2"), ("dashboard overflow",width)
                    view.set_viewport_size({"width":390,"height":844})
                    view.goto(base+"iade-kontrol.php",wait_until="domcontentloaded")
                    view.get_by_role("button",name="Satılamaz olarak işaretle").click()
                    view.get_by_text("Satılamaz olarak işaretlendi; stok değer kaybı",exact=False).wait_for()
                    view.get_by_role("button",name="İşareti kaldır").click()
                    view.get_by_text("Otomatik: satışa uygun varsayımı",exact=False).wait_for()
                    browser.close()
                print("PASS Chromium desktop/mobile dashboard and one-click return exception")
            print("PASS exception-only return interface")
            print("PASS populated dashboard, order finance and cargo views")
            print("PASS registration, CSRF rejection, saved VAT and withholding settings")
        finally:
            proc.terminate()
            proc.wait(timeout=10)
            log.seek(0)
            print(log.read()[-5000:])
