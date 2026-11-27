"""Run legacy end-to-end flows against a new disposable loopback database only."""
import json, os, subprocess, tempfile, time, urllib.request
from pathlib import Path
root=Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix="karfiyat-legacy-") as temp:
    folder=Path(temp)
    (folder/".isolated-http-test").touch()
    prepend=folder/"test-config.php"
    prepend.write_text("<?php define('DB_PATH', "+json.dumps(str(folder/"app.sqlite"))+");")
    env={k:v for k,v in os.environ.items() if not k.startswith("TRENDYOL_")}
    env["KAR_TEST_ISOLATED"]="1"
    php=["php","-d","auto_prepend_file="+str(prepend)]
    with (folder/"server.log").open("w+") as log:
        proc=subprocess.Popen(php+["-S","127.0.0.1:8090","-t",str(root/"public_html")],cwd=root,env=env,stdout=log,stderr=log)
        try:
            for attempt in range(50):
                if proc.poll() is not None: raise RuntimeError("Isolated server exited")
                try:
                    with urllib.request.urlopen("http://127.0.0.1:8090/kayit.php",timeout=1) as response:
                        if response.status==200: break
                except OSError: time.sleep(.2)
            else: raise RuntimeError("Isolated server not ready")
            results=[]
            for script in ("test-akislar.php","test-toplu-analiz.php"):
                results.append(subprocess.run(php+[str(root/"scripts"/script)],cwd=root,env=env,timeout=120).returncode)
            if any(results): raise SystemExit(1)
        finally:
            proc.terminate()
            proc.wait(timeout=10)
