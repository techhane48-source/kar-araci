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
            opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
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
                con.execute("UPDATE products SET barcode='CI001' WHERE user_id=? AND name='CI product'", (uid,))
                now=int(time.time())
                lines=json.dumps([{"barcode":"CI001","quantity":1,"price":717.74,"line_id":"L1","commission_rate":19,"vat_rate":20,"cost_snapshot":400,"cost_vat_snapshot":10}])
                con.execute("INSERT INTO orders(user_id,marketplace,order_number,package_number,status,total_price,order_date,lines,currency_code,country_code,last_modified_ms) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                            (uid,"trendyol","CI-ORDER","CI-PACKAGE","Delivered",717.74,now,lines,"TRY","TR",now*1000))
                raw=json.dumps({"id":"CI-INVOICE","affiliate":"TRENDYOLTR","country":"TR","debt":65,"credit":0})
                con.execute("INSERT INTO trendyol_financial_documents(user_id,external_id,query_type,transaction_type,transaction_date,raw_json) VALUES(?,?,?,?,?,?)",
                            (uid,"CI-INVOICE","DeductionInvoices","Kargo Faturası",now,raw))
                con.execute("INSERT INTO trendyol_cargo_items(user_id,invoice_serial,row_number,parcel_id,order_number,shipment_type,amount,desi) VALUES(?,?,?,?,?,?,?,?)",
                            (uid,"CI-INVOICE",0,"CI-PARCEL","CI-ORDER","Gönderi Kargo Bedeli",65,3))
                sale=json.dumps({"id":"CI-SALE","barcode":"CI001","orderNumber":"CI-ORDER","shipmentPackageId":"CI-PACKAGE","affiliate":"TRENDYOLTR","country":"TR","credit":717.74,"debt":0,"commissionAmount":136.3706,"sellerRevenue":581.3694})
                con.execute("INSERT INTO settlements(user_id,marketplace,external_id,order_number,shipment_package_id,query_type,raw_json,finance_scope) VALUES(?,?,?,?,?,?,?,?)",
                            (uid,"trendyol","CI-SALE","CI-ORDER","CI-PACKAGE","Sale",sale,"domestic"))
            for path in ("urunler.php","dashboard.php","magaza.php","kar-marji.php","canli-performans.php","raporlar.php","pazar-zekasi.php","hakedis-desi.php","promosyon-analiz.php","toplu-analiz.php","maliyet-gecmisi.php","kar-hedefleri.php","uyarilar.php","kargo-kontrol.php","banka-mutabakat.php","iade-kontrol.php"):
                status, page = request(path)
                assert status == 200 and len(page) > 500 and "<html" in page.lower(), (path, status, page[:150])
                print("PASS HTTP", path)
            for kind in ("order","product","category","return","ads","campaign","buybox","claims","order_finance","period_finance","product_finance","product_cost_evidence"):
                status, page = request("raporlar.php?r=" + kind)
                assert status == 200 and "<html" in page.lower(), (kind, status)
                status, csv = request("raporlar.php?r=" + kind + "&export=1")
                assert status == 200 and csv.startswith("\ufeff"), (kind, status)
                print("PASS report view and CSV", kind)
            status, csv = request("uyarilar.php?export=csv")
            assert status == 200 and csv.startswith("\ufeff") and "Uyarılar" in csv
            print("PASS alert CSV")
            status, page = request("dashboard.php")
            assert status == 200 and "Tahmini Sipariş Kârı" in page and "717,74" in page, "Populated dashboard missing data"
            status, csv = request("raporlar.php?r=order_finance&export=1")
            assert status == 200 and "CI-ORDER" in csv and "116.37" in csv, "Populated financial report missing known subtotal"
            status, csv = request("raporlar.php?r=product_cost_evidence&export=1")
            assert status == 200 and "CI001" in csv and "116.37" in csv, "Product cost evidence missing single-barcode subtotal"
            status, page = request("kargo-kontrol.php?q=CI-PARCEL")
            assert status == 200 and "CI-PARCEL" in page, "Populated cargo page missing parcel"
            status, csv = request("kargo-kontrol.php?q=CI-PARCEL&export=csv")
            assert status == 200 and "CI-PARCEL" in csv and "Beklenen desi" in csv, "Cargo CSV missing record"
            print("PASS populated dashboard, order finance and cargo views")
            print("PASS registration, CSRF rejection, saved VAT and withholding settings")
        finally:
            proc.terminate()
            proc.wait(timeout=10)
            log.seek(0)
            print(log.read()[-5000:])
