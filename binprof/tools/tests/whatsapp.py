"""Webhook/Cloud API integration fixture. The transport only records synthetic payloads."""
from datetime import datetime, timedelta
import hashlib
import hmac
import json
import os
import re
import shlex
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
from zoneinfo import ZoneInfo

from smoke_admin import BASE, Client

SETTINGS = "/app/whatsapp"
HOOK = "/webhooks/whatsapp"
OPERATOR = "operator@example.invalid"
VIEWER = "viewer@example.invalid"
SECRET = b"synthetic-whatsapp-webhook-fixture"
VERIFY = "synthetic-whatsapp-verification"
OP_SENDER = {"from": "620000000001", "from_user_id": "TEST.BSUID.000A"}
VIEWER_SENDER = {"from": "620000000002"}
FIXTURE = shlex.split(os.environ.get("BINPROF_WA_TEST_COMMAND", "php tools/tests/whatsapp_fixture.php"))
sequence = 0


def local(*args):
    output = subprocess.check_output([*FIXTURE, *args], text=True)
    return json.loads(output)


def signed(value, *, signature=None, raw=None, expected=200, business_id="2222222222", phone_id="1111111111"):
    body = raw if raw is not None else json.dumps({
        "object": "whatsapp_business_account", "entry": [{"id": business_id, "changes": [{
            "field": "messages", "value": {"metadata": {"phone_number_id": phone_id}, **value}
        }]}]
    }, ensure_ascii=False).encode()
    digest = signature if signature is not None else "sha256=" + hmac.new(SECRET,body,hashlib.sha256).hexdigest()
    request = urllib.request.Request(BASE+HOOK, body, {
        "Content-Type": "application/json", "X-Hub-Signature-256": digest,
    })
    try:
        response = urllib.request.urlopen(request,timeout=20)
    except urllib.error.HTTPError as error:
        response = error
    returned = response.read()
    assert response.code == expected, (response.code, returned)
    if expected == 200:
        assert json.loads(returned) == {"accepted": True}
    return body


def chat(sender, text, provider_id=None, **kwargs):
    global sequence
    sequence += 1
    identifier = provider_id or f"wamid.test.in.{sequence:04d}"
    body = {"messages": [{"id": identifier,"timestamp": str(int(time.time())),"type":"text",
                           "text":{"body":text}, **sender}],
            "contacts":[{"profile":{"name":"DATA DEMO Super Admin"}}]}
    raw = signed(body,**kwargs)
    return identifier, raw


def receipt(provider_id, status, opaque=None):
    data = {"id":provider_id,"timestamp":str(int(time.time())),"status":status}
    if opaque:
        data["biz_opaque_callback_data"] = opaque
    signed({"statuses":[data]})


def answer(sender, text, *, mode="accept"):
    identifier, _ = chat(sender,text)
    messages = local("run",mode)
    assert len(messages) == 1, (text,messages)
    message = messages[0]
    assert message["to"] == sender.get("from_user_id",sender.get("from"))
    return identifier, message


def make_code(client):
    client.call(SETTINGS)
    client.post(SETTINGS+"/code",{})
    _, view = client.call(SETTINGS)
    return re.search(rb'TAUT ([A-F0-9]{12})',view)[1].decode()


def confirmation(client):
    _, view = client.call(SETTINGS)
    assert b"Konfirmasi penautan" in view
    return re.search(rb'name="id" value="([a-f0-9]{32})"',view)[1].decode()


def account(email):
    client = Client()
    client.login(email)
    return client


def preferences(client, *, opt=1, operations=1, data=1, training=1, muted=0, quiet_start="", quiet_end=""):
    client.post(SETTINGS+"/preferences", {
        "opt_in":opt,"operations":operations,"data":data,"training":training,"muted":muted,
        "quiet_start":quiet_start,"quiet_end":quiet_end,
    })


def create_event(admin, facility, category):
    return json.loads(admin.post("/api/maps/events",{
        "action":"create","facility":facility,"category":category,"severity":"warning",
    },status=200)[1])["id"]


def main():
    # Provider verification and signed raw request boundary.
    mode = urllib.parse.urlencode({"hub.mode":"subscribe","hub.verify_token":VERIFY,"hub.challenge":"43210"})
    assert Client().call(HOOK+"?"+mode)[1] == b"43210"
    Client().call(HOOK+"?"+mode.replace(VERIFY,"rejected"),status=403)
    signed({},raw=b"{}",signature="sha256="+"0"*64,expected=403)
    signed({},raw=b"<invalid json>",expected=400)
    foreign, _ = chat(OP_SENDER,"Berapa dokter aktif?",phone_id="3333333333")
    assert local("run") == [] and local("lookup",foreign) is False

    admin, operator, viewer = account("admin@example.invalid"), account(OPERATOR), account(VIEWER)
    operator.post(SETTINGS+"/code",{"csrf":"wrong"},status=403)
    operator.post(SETTINGS+"/preferences",{"opt_in":"1"},status=409)
    admin.post("/app/master/save",dict(kind="training_types",name="BTCLS",code="btcls",active=1))
    admin.post("/app/master/save",dict(kind="specialties",name="Jantung",id=1,code="map-demo",active=1))
    today = datetime.now(ZoneInfo("Asia/Jakarta")).date()
    last_month = (today.replace(day=1)-timedelta(days=1)).replace(day=1)
    admin.post("/app/pelatihan/save",{
        "personnel_id":"11111111111111111111111111111111","type_id":2,"organizer":"DATA DEMO Unit A",
        "completed":1,"started_on":last_month.isoformat(),"expires_on":(today+timedelta(days=10)).isoformat(),
    })

    unlinked, raw = chat(OP_SENDER,"Berapa dokter aktif di satuan saya?")
    signed({},raw=raw)  # Replay the exact same signed body.
    sent = local("run")
    assert len(sent) == 1 and "Tautkan akun" in sent[0]["text"]["body"]
    assert sent[0]["to"] == OP_SENDER["from_user_id"]
    assert local("run") == []

    code = make_code(operator)
    assert b"TAUT "+code.encode() not in operator.call(SETTINGS)[1]
    identifier, claim = answer(OP_SENDER,"TAUT "+code)
    assert "konfirmasi" in claim["text"]["body"]
    claim_id = confirmation(operator)
    viewer.call(SETTINGS)
    viewer.post(SETTINGS+"/confirm",{"id":claim_id},status=409)
    assert "Tautkan akun" in answer(OP_SENDER,"Berapa dokter aktif di satuan saya?")[1]["text"]["body"]
    operator.post(SETTINGS+"/confirm",{"id":claim_id})
    operator.post(SETTINGS+"/confirm",{"id":claim_id},status=409)
    _, linked = operator.call(SETTINGS)
    assert b"Tertaut" in linked and OP_SENDER["from_user_id"].encode() not in linked

    # An identity reported with a different BSUID cannot use a matching phone.
    other = {"from":OP_SENDER["from"],"from_user_id":"TEST.BSUID.FOREIGN"}
    assert "Tautkan akun" in answer(other,"Berapa dokter aktif?")[1]["text"]["body"]

    viewer_code = make_code(viewer)
    assert answer(VIEWER_SENDER,"TAUT "+viewer_code)[1]["to"] == VIEWER_SENDER["from"]
    viewer.post(SETTINGS+"/confirm",{"id":confirmation(viewer)})
    assert "tidak memiliki izin" in answer(VIEWER_SENDER,"Siapa yang belum mengikuti BTCLS?")[1]["text"]["body"]

    identifier, message = answer(OP_SENDER,"Berapa dokter aktif di satuan saya?")
    text = message["text"]["body"]
    assert "2 dokter aktif" in text and "Cakupan:" in text and "Data diperiksa:" in text, text
    assert local("lookup",identifier)["state"] == "accepted"
    outbound = local("lookup",identifier)
    receipt(outbound["provider_id"],"sent")
    receipt(outbound["provider_id"],"delivered")
    receipt(outbound["provider_id"],"read")
    receipt(outbound["provider_id"],"sent")  # A late callback cannot regress read.
    local("run")
    assert local("lookup",identifier)["state"] == "read"

    text = answer(OP_SENDER,"Siapa yang belum mengikuti BTCLS?")[1]["text"]["body"]
    assert "catatan selesai BTCLS" in text and "missing_training=2" in text and "Spesialis A" not in text
    portal = operator.call("/app/personel?state=active&missing_training=2")[1]
    assert b"Perawat A" in portal and b"Spesialis A" not in portal
    text = answer(OP_SENDER,"Sertifikat apa yang habis dalam 30 hari?")[1]["text"]["body"]
    assert "1 catatan sertifikat" in text and "expiry_days=30" in text
    assert "1 catatan, 1 tercatat selesai" in answer(OP_SENDER,"Buatkan rekap diklat bulan lalu.")[1]["text"]["body"]
    assert "Pilih periode" in answer(OP_SENDER,"Buatkan rekap diklat")[1]["text"]["body"]
    assert "sebutkan" in answer(OP_SENDER,"Tampilkan fasilitas itu di peta.")[1]["text"]["body"].lower()
    mapped = answer(OP_SENDER,"Fasilitas mana yang memiliki spesialis jantung?")[1]["text"]["body"]
    assert "DATA DEMO Fasilitas 0" in mapped and "DATA DEMO Fasilitas 1" not in mapped
    assert "specialty_id=1" in answer(OP_SENDER,"Tampilkan fasilitas itu di peta.")[1]["text"]["body"]
    assert "Pengajuan akun Anda" in answer(OP_SENDER,"Bagaimana status mutasi?")[1]["text"]["body"]
    local("cooldown")
    assert "penugasan klinis" in answer(OP_SENDER,"Buka rincian pasien E-RM")[1]["text"]["body"]

    retry_id, _ = answer(OP_SENDER,"Berapa perawat aktif?",mode="throttle")
    waiting = local("lookup",retry_id)
    assert waiting["state"] == "queued" and waiting["attempts"] == 1 and waiting["error_kind"] == "provider_throttle"
    assert local("run") == []
    local("due",waiting["id"])
    assert len(local("run")) == 1
    assert local("lookup",retry_id)["state"] == "accepted" and local("lookup",retry_id)["attempts"] == 2

    uncertain_id, message = answer(OP_SENDER,"Buka peta",mode="uncertain")
    unclear = local("lookup",uncertain_id)
    assert unclear["state"] == "unknown" and unclear["provider_id"] is None
    assert local("run") == []
    receipt("wamid.fixture.uncertain","delivered",opaque=message["biz_opaque_callback_data"])
    local("run")
    assert local("lookup",uncertain_id)["state"] == "delivered"
    denied_id, _ = answer(OP_SENDER,"Buka peta",mode="reject")
    assert local("lookup",denied_id)["state"] == "failed"

    now = datetime.now(ZoneInfo("Asia/Jakarta"))
    preferences(operator,quiet_start=(now-timedelta(minutes=5)).strftime("%H:%M"),
                quiet_end=(now+timedelta(minutes=5)).strftime("%H:%M"))
    event1 = create_event(admin,1,"operations")
    outside = create_event(admin,2,"operations")
    assert local("run") == []
    assert local("map",outside) == []
    muted_hour = local("map",event1)[0]
    assert muted_hour["state"] == "queued" and muted_hour["error_kind"] == "quiet_hours"
    preferences(operator)
    local("due",muted_hour["id"])
    notifications = local("run")
    assert len(notifications) == 1 and notifications[0]["type"] == "template"
    assert "components" not in notifications[0]["template"]
    assert local("run") == []
    priority = answer(OP_SENDER,"Apa notifikasi prioritas hari ini?")[1]["text"]["body"]
    assert "1 kejadian peta prioritas aktif" in priority, priority

    preferences(operator,data=0)
    event2 = create_event(admin,1,"data")
    assert local("run") == []
    assert local("map",event2)[0]["state"] == "skipped"
    event3 = create_event(admin,1,"training")
    local("run","queue")
    assert local("map",event3)[0]["state"] == "queued"
    assert "dihentikan" in answer(OP_SENDER,"STOP")[1]["text"]["body"]
    assert local("map",event3)[0]["state"] == "skipped"

    local("cooldown")
    pending, _ = chat(OP_SENDER,"Berapa dokter aktif?")
    local("run","queue")
    _, users = admin.call("/app/users?per=100")
    row = next(row for row in re.findall(rb'<tr>.*?</tr>',users,re.S) if OPERATOR.encode() in row)
    operator_id = re.search(rb'href="/app/users\?id=(\d+)"',row)[1].decode()
    admin.post("/app/users/save",{
        "id":operator_id,"version":1,"name":"DATA DEMO Operator","email":OPERATOR,"role":"viewer","active":1,
    })
    assert local("run") == []
    assert local("lookup",pending)["state"] == "skipped"
    assert "Tautkan akun" in answer(OP_SENDER,"Berapa dokter aktif?")[1]["text"]["body"]
    viewer_pending, _ = chat(VIEWER_SENDER,"Berapa dokter aktif?")
    local("run","queue")
    viewer.post(SETTINGS+"/unlink",{})
    assert local("run") == []
    assert local("lookup",viewer_pending)["state"] == "skipped"

    print("WhatsApp: signature, deduplikasi, kode/konfirmasi, BSUID/telepon, scope, intent, map, STOP, retry, status dan pencabutan lulus.")


if __name__ == "__main__":
    main()
