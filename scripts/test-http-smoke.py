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
            for path in ("urunler.php","dashboard.php","magaza.php","kar-marji.php","canli-performans.php","raporlar.php","pazar-zekasi.php","hakedis-desi.php","promosyon-analiz.php","toplu-analiz.php","maliyet-gecmisi.php","kar-hedefleri.php"):
                status, page = request(path)
                assert status == 200 and len(page) > 500 and "<html" in page.lower(), (path, status, page[:150])
                print("PASS HTTP", path)
            for kind in ("order","product","category","return","ads","campaign","buybox","claims","order_finance"):
                status, page = request("raporlar.php?r=" + kind)
                assert status == 200 and "<html" in page.lower(), (kind, status)
                status, csv = request("raporlar.php?r=" + kind + "&export=1")
                assert status == 200 and csv.startswith("\ufeff"), (kind, status)
                print("PASS report view and CSV", kind)
            print("PASS registration, CSRF rejection, saved VAT and withholding settings")
        finally:
            proc.terminate()
            proc.wait(timeout=10)
            log.seek(0)
            print(log.read()[-5000:])
