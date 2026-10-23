"""Offline regression checks; synthetic responses, no provider/DB access.
Run: python3 tests/gateway_polling_test.py
"""
import json
import os
from pathlib import Path
import subprocess
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BIN", "/opt/data/bin/php")

def endpoint(remote, done=None):
    source = (ROOT / "pages/payments/gateway_status.php").read_text()
    source = "\n".join(line for line in source.splitlines()[1:]
                       if not line.startswith("require_once"))
    fixture = {"remote": remote, "done": done}
    bootstrap = r'''$f=json_decode(stream_get_contents(STDIN),true);
function auth_check() {} function auth_role() {return 'admin';}
function csrf_token() {return 'test';}
function gateway_find_order($id) {return ['status'=>'PENDING','channel_id'=>7,'api_key'=>'mock','merchant_id'=>'mock','mode'=>'production','provider'=>'gopay','total_amount'=>10002];}
function gateway_status($channel,$id) {global $f; if (($channel['id']??0)!==7) return ['ok'=>false]; return $f['remote'];}
function gateway_finalize($id,$amount) {global $f; return $f['done'];}
$_SERVER['REQUEST_METHOD']='POST'; $_POST=['csrf_token'=>'test','order_id'=>'test'];
'''
    result = subprocess.run([PHP, "-r", bootstrap + source], input=json.dumps(fixture), text=True, capture_output=True, check=True)
    return json.loads(result.stdout)

r = endpoint({"ok": False, "error": "SECRET provider response"})
assert r["status"] == "UNKNOWN", r
assert "SECRET" not in json.dumps(r), r
assert r.get("error"), r
r = endpoint({"ok": True, "data": {"status": "PENDING"}})
assert r["status"] == "PENDING", r
r = endpoint({"ok": True, "data": {"status": "PAID", "total_amount": 10002}}, {"ok": True, "payment_id": 42})
assert r["status"] == "PAID" and r["payment_id"] == 42, r
r = endpoint({"ok": True, "data": {"status": "PAID", "total_amount": 10002}}, {"ok": False, "error": "SECRET SQL"})
assert r["status"] == "UNKNOWN" and "SECRET" not in json.dumps(r), r
print("PASS: endpoint errors, channel identity, pending, paid, finalization failure")

page = (ROOT / "pages/payments/gateway_pay.php").read_text()
assert "APP_URL . '/pages/payments/gateway_status.php'" not in page, "polling must stay on the current origin"
script = page.split("const label =", 1)[1].split("</script>", 1)[0]
js = "const label =" + script
harness = r"""
const assert = require('node:assert/strict');
const labelMock = {textContent: ''};
let timers = 0;
const document = {getElementById: () => labelMock};
const cfg = {url: 'gateway_status.php', detail: 'detail.php', orderId: 'test', csrf: 'test'};
const location = {href: ''};
const setTimeout = () => { timers++; };
let response;
const fetch = async () => { if (response instanceof Error) throw response; return response; };
""" + js + r"""
(async () => {
 for (const bad of [new Error('offline'), {ok:false,json:async()=>({status:'UNKNOWN'})}, {ok:true,json:async()=>{throw Error('html');}}, {ok:true,json:async()=>({})}]) {
  response=bad; labelMock.textContent=''; const before=timers; await poll();
  assert.match(labelMock.textContent,/Jangan bayar ulang/); assert.equal(timers,before+1);
 }
 response={ok:true,json:async()=>({status:'PENDING'})}; await poll();
 assert.match(labelMock.textContent,/Menunggu/);
 response={ok:true,json:async()=>({status:'PAID',payment_id:42})}; await poll();
 assert.equal(location.href,'detail.php?id=42');
 console.log('PASS: browser network/HTTP/JSON errors, retry, recovery, paid redirect');
})().catch(e=>{console.error(e);process.exitCode=1;});
"""
subprocess.run(["node", "-e", harness], check=True)

# Exercise real HTTP/parser code against deliberately synthetic error envelopes.
class Mock(BaseHTTPRequestHandler):
    payload = {}
    def do_GET(self):
        self.send_response(200)
        self.end_headers()
        self.wfile.write(json.dumps(self.payload).encode())
    do_POST = do_GET
    def log_message(self, *args):
        pass

server = HTTPServer(("127.0.0.1", 0), Mock)
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
url = f"http://127.0.0.1:{server.server_port}"
env = dict(os.environ, GOPAY_API_URL=url, SHOPEEPAY_API_URL=url)
try:
    for provider in ("gopay", "shopeepay"):
        for payload in ({"unexpected": []}, {"code": "123", "msg": "SECRET"}, {"code": "123", "data": {"transactions": []}}):
            Mock.payload = payload
            call = "gq_check_gopay('{\"Authorization\":\"mock\"}',10002,time()-60,[])" if provider == "gopay" else "gq_check_shopeepay('mock',10002,time()-60)"
            source = "require 'includes/payment_gateway_direct.php'; echo json_encode(" + call + ");"
            output = subprocess.check_output([PHP, "-r", source], cwd=ROOT, env=env, text=True)
            result = json.loads(output)
            assert not result["ok"] and not result["paid"], (provider, payload, result)
        Mock.payload = {"data": []} if provider == "gopay" else {"code": 0, "data": {"transactionList": []}}
        result = json.loads(subprocess.check_output([PHP, "-r", source], cwd=ROOT, env=env, text=True))
        assert result["ok"] and result["status"] == "PENDING" and not result["paid"], result
    print("PASS: both providers reject unknown/error envelopes; recognized empty lists stay PENDING")
finally:
    server.shutdown()
    server.server_close()


