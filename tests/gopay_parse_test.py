"""Regresi parser transaksi GoPay Merchant (`transactions` + `gross_amount` dalam sen).

Bentuk diambil dari respons asli api.gojekapi.com:
  {"from":0,"size":5,"total":3,"transactions":[{"gross_amount":1000100,"transaction_status":"SETTLEMENT",
   "transaction_time":"2026-10-23T22:23:23+07:00","order_id":"QRIS-..."}]}
"""
import json
import os
import subprocess
import sys
import tempfile

SRC = os.path.join(os.path.dirname(__file__), "..", "includes", "payment_gateway_direct.php")
PHP = "/opt/data/bin/php"

HARNESS = r"""
$src = file_get_contents(getenv('SRC'));
// qris_parse_tlv hanya untuk memenuhi dependensi file; yang diuji gq_check_gopay.
preg_match('/function gq_check_gopay.*?\n}/s', $m);
eval($m[0]);
$json = json_decode(getenv('RESP'), true);
function gq_http_curl($m, $u, $h, $b = null) { global $json; return ['ok'=>true,'http'=>200,'error'=>null,'json'=>$json]; }
function gq_channel_config($c) { return ['merchant_ids'=>'G906677299']; }
$r = gq_check_gopay('{"authorization":"Bearer x"}', (int)getenv('AMOUNT'), (int)getenv('SINCE'), ['provider'=>'gopay']);
echo json_encode(['ok'=>$r['ok'],'status'=>$r['status'],'order'=>$r['trx']['order_id'] ?? null,'error'=>$r['error']]);
"""

INNER = HARNESS.replace("preg_match('/function gq_check_gopay.*?\\n}/s', $m);",
                        "preg_match('/function gq_check_gopay.*?\\n}\\n/s', $src, $m);")


def tx(gross=1000100, status="SETTLEMENT", ts="2026-10-23T22:23:23+07:00", oid="QRIS-0820261023152323wKKCaxRmC2ID"):
    return {"id": "01a16970", "order_id": oid, "transaction_status": status,
            "transaction_time": ts, "gross_amount": gross, "real_gross_amount": gross,
            "payment_type": "QRIS", "merchant_id": "G906677299"}


def run(resp, amount, since=0):
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as f:
        f.write("<?php\n" + INNER)
        path = f.name
    env = dict(os.environ, SRC=SRC, RESP=json.dumps(resp), AMOUNT=str(amount), SINCE=str(since))
    out = subprocess.run([PHP, path], capture_output=True, text=True, env=env)
    os.unlink(path)
    assert out.stdout.strip(), out.stderr
    return json.loads(out.stdout)


def main():
    since = 1792765200  # 2026-10-23 22:20 WIB
    cases = []
    env = lambda txs, total=None: {"from": 0, "size": len(txs), "total": len(txs) if total is None else total,
                                  "transactions": txs}

    # 1. sen → rupiah: 1000100 = Rp10.001
    r = run(env([tx()]), 10001, since)
    cases.append((r["status"] == "PAID" and r["order"].startswith("QRIS-"), f"gross_amount sen 1000100 -> Rp10.001 PAID ({r})"))

    # 2. nominal tidak cocok
    r = run(env([tx()]), 10999, since)
    cases.append((r["status"] == "PENDING", f"nominal beda -> PENDING ({r})"))

    # 3. status belum settle
    r = run(env([tx(status="PENDING")]), 10001, since)
    cases.append((r["status"] == "PENDING", f"status PENDING -> PENDING ({r})"))

    # 4. transaksi lebih tua dari jendela
    r = run(env([tx()]), 10001, 1792767600 + 86400)
    cases.append((r["status"] == "PENDING", f"di luar jendela -> PENDING ({r})"))

    # 5. envelope kosong tetap PENDING
    r = run(env([], total=0), 10001, since)
    cases.append((r["ok"] and r["status"] == "PENDING", f"tanpa transaksi -> PENDING ({r})"))

    # 6. envelope asing ditolak
    r = run({"unexpected": []}, 10001, since)
    cases.append((r["ok"] is False and r["status"] == "UNKNOWN", f"envelope asing -> UNKNOWN ({r})"))

    # 7. dua transaksi: yang cocok harus dipilih
    r = run(env([tx(gross=1010000, oid="QRIS-A"), tx(gross=1000100, oid="QRIS-B")]), 10001, since)
    cases.append((r["order"] == "QRIS-B", f"pilih transaksi yang cocok ({r})"))

    failed = [m for ok, m in cases if not ok]
    for ok, m in cases:
        print(("PASS " if ok else "FAIL ") + m)
    print(f"{len(cases) - len(failed)}/{len(cases)} lulus")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
