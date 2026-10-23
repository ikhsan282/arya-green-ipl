"""Regresi bentuk respons ShopeePay Partner (data.list) — tanpa jaringan.

Bentuk diambil dari respons asli portal:
  {"code":0,"data":{"total":"1","list":[{"amount":"10.002","status":3,"transactionType":1,...}]}}
"""
import json
import re
import subprocess
import sys
import tempfile
import os

SRC = os.path.join(os.path.dirname(__file__), "..", "includes", "payment_gateway_direct.php")
PHP = "/opt/data/bin/php"

HARNESS = r"""
$src = file_get_contents(getenv('SRC'));
// Ambil hanya badan fungsi yang diuji; sisanya butuh DB/koneksi.
preg_match('/function qris_parse_tlv.*?\n}/s', $src, $m1);
preg_match('/function gq_check_shopeepay.*?\n}/s', $src, $m2);
eval($m1[0] . "\n" . $m2[0]);
// Ganti HTTP dengan respons tetap.
$json = json_decode(getenv('RESP'), true);
function gq_http_curl($m, $u, $h, $b = null) { global $json; return ['ok'=>true,'http'=>200,'error'=>null,'json'=>$json]; }
$r = gq_check_shopeepay('B:tok', (int)getenv('AMOUNT'), (int)getenv('SINCE'));
echo json_encode(['ok'=>$r['ok'],'status'=>$r['status'],'id'=>$r['trx']['transactionId'] ?? null,'error'=>$r['error']]);
"""


def run(resp: dict, amount: int, since: int = 0) -> dict:
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False) as f:
        f.write("<?php\n" + HARNESS)
        path = f.name
    env = dict(os.environ, SRC=SRC, RESP=json.dumps(resp), AMOUNT=str(amount), SINCE=str(since), PATH=os.environ["PATH"])
    out = subprocess.run([PHP, path], capture_output=True, text=True, env=env)
    os.unlink(path)
    assert out.stdout.strip(), out.stderr
    return json.loads(out.stdout)


def tx(amount="10.002", status=3, ttype=1, ts=1792767631, tid="120732794979072755"):
    return {"transactionId": tid, "amount": amount, "status": status,
            "transactionType": ttype, "createTime": ts}


def main():
    cases = []

    # 1. list kosong (null) + total 0 → PENDING, bukan "tidak dikenali"
    r = run({"code": 0, "msg": "", "data": {"total": "0", "totalNetSales": "0", "totalCompletedCount": "0"}}, 10002)
    cases.append((r["ok"] is True and r["status"] == "PENDING", f"list absen + total 0 -> PENDING ({r})"))

    # 2. bentuk asli dengan transaksi cocok → PAID (titik ribuan tidak boleh memotong nominal)
    r = run({"code": 0, "msg": "", "data": {"total": "1", "list": [tx()]}}, 10002)
    cases.append((r["status"] == "PAID" and r["id"] == "120732794979072755", f"10.002 cocok -> PAID ({r})"))

    # 3. nominal berbeda → PENDING
    r = run({"code": 0, "msg": "", "data": {"total": "1", "list": [tx()]}}, 10301)
    cases.append((r["status"] == "PENDING", f"nominal beda -> PENDING ({r})"))

    # 4. status belum berhasil (1) → UNKNOWN, tidak diklaim lunas
    r = run({"code": 0, "msg": "", "data": {"total": "1", "list": [tx(status=1)]}}, 10002)
    cases.append((r["ok"] is False and r["status"] == "UNKNOWN", f"status 1 -> UNKNOWN ({r})"))

    # 5. transaksi lebih tua dari jendela → PENDING
    r = run({"code": 0, "msg": "", "data": {"total": "1", "list": [tx()]}}, 10002, since=1792767631 + 120)
    cases.append((r["status"] == "PENDING", f"di luar jendela -> PENDING ({r})"))

    # 6. token mati → error terbaca
    r = run({"code": 200020, "msg": "invalid token, err", "data": None}, 10002)
    cases.append((r["ok"] is False and "invalid token" in (r["error"] or ""), f"token mati -> error ({r})"))

    # 7. bentuk tak dikenal tetap ditolak (bukan diam-diam PENDING)
    r = run({"code": 0, "msg": "", "data": {"total": "1", "whatever": [1]}}, 10002)
    cases.append((r["ok"] is False and r["status"] == "UNKNOWN", f"bentuk asing -> UNKNOWN ({r})"))

    failed = [msg for ok, msg in cases if not ok]
    for ok, msg in cases:
        print(("PASS " if ok else "FAIL ") + msg)
    print(f"{len(cases) - len(failed)}/{len(cases)} lulus")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
