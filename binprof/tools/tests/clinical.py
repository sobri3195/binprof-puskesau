"""Run only against the local synthetic database, after smoke_admin.py and map_fixtures.sql."""
from datetime import datetime
import re
import sys
from zoneinfo import ZoneInfo

from smoke_admin import Client, PW

SUP_A = "clinical-a@example.invalid"
SUP_B = "clinical-b@example.invalid"
BACKUP = "clinical-review@example.invalid"
WRITER = "clinical-writer@example.invalid"
READER = "clinical-reader@example.invalid"
REASON = "DATA DEMO untuk pengujian rujukan resmi"
EMAILS = {SUP_A: "Supervisor A", SUP_B: "Supervisor B", BACKUP: "Peninjau A", WRITER: "Penulis A", READER: "Pembaca B"}
TODAY = datetime.now(ZoneInfo("Asia/Jakarta")).date().isoformat()


def account(email):
    client = Client()
    client.login(email)
    return client


def setup_fixtures():
    admin = account("admin@example.invalid")
    for email, name in EMAILS.items():
        admin.post("/app/users/save", {
            "name": f"DATA DEMO {name}", "email": email, "role": "operator", "active": 1, "password": PW,
        })
    admin.post("/app/personel/save", {
        "name": "DATA DEMO Pasien Lokal", "nrp": "DEMO-CLINIC", "unit_id": 1, "facility_id": 1,
        "profession_id": 1, "active": 1, "bp": "unknown", "nik": "0000000000000039",
    })


def assign_privilege(client, facility, email, status=303, *, write=0, export=0, approve=0, active=1):
    client.post("/app/erm/privileges", {
        "workplace": facility, "email": email, "active": active,
        "read": 1, "write": write, "export": export, "approve": approve,
    }, status=status)


def clinical(client, operation, workplace, *, expected=200, purpose="referral", **values):
    return client.post(f"/app/erm/{operation}", {
        "workplace": workplace, "purpose": purpose, "reason": REASON, **values,
    }, status=expected)


def key_from(body, field, expected_count=1):
    results = set(re.findall(fr'name="{field}" value="([a-f0-9]{{32}})"'.encode(), body))
    assert len(results) == expected_count, (field, results)
    return next(iter(results)).decode()


def attach(client, context, payload, expected=200, filename="lampiran.pdf"):
    boundary = b"BINPROF-DEMO-CLINICAL"
    data = b""
    for key, value in {**context, "csrf": client.csrf}.items():
        data += b"--" + boundary + (
            f'\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'
        ).encode()
    data += b"--" + boundary + (
        f'\r\nContent-Disposition: form-data; name="document"; filename="{filename}"\r\n'
        "Content-Type: application/pdf\r\n\r\n"
    ).encode() + payload + b"\r\n--" + boundary + b"--\r\n"
    return client.call("/app/erm/upload", data, status=expected,
                       content_type="multipart/form-data; boundary=" + boundary.decode())


def run_tests():
    admin = account("admin@example.invalid")
    viewer = account("viewer@example.invalid")
    unauth = Client()
    unauth.call("/app/erm", status=303)
    for client in (admin, viewer):
        _, body = client.call("/app/erm", status=403)
        assert b"DATA DEMO Pasien Lokal" not in body
    lab = account(SUP_A)
    receiving = account(SUP_B)
    assign_privilege(lab, 1, SUP_A, status=422)
    assign_privilege(lab, 2, WRITER, status=403, write=1)
    assign_privilege(lab, 1, BACKUP, approve=1)
    assign_privilege(lab, 1, WRITER, write=1)
    assign_privilege(receiving, 2, READER, export=1)
    assign_privilege(receiving, 2, SUP_A, export=1)
    second = account(BACKUP)
    writer = account(WRITER)
    reader = account(READER)
    writer.call("/app/erm")
    reader.call("/app/erm")
    lab.call("/app/erm")
    lab.post("/app/erm/care", {
        "workplace": 1, "email": SUP_A, "decision": "assign", "days": 7, "nrp": "DEMO-CLINIC",
    }, 422)
    _, assigned = lab.post("/app/erm/care", {
        "workplace": 1, "email": WRITER, "decision": "assign", "days": 7, "nrp": "DEMO-CLINIC",
    }, 200)
    patient = re.search(rb'Kode pasien untuk rujukan resmi</dt><dd>([a-f0-9]{32})</dd>', assigned)[1].decode()
    context = {"patient": patient}
    wrong_patient = {"patient": "90909090909090909090909090909090"}

    _, search = clinical(writer, "search", 1, method="nik", query="0000000000000039")
    assert b"DATA DEMO Pasien Lokal" in search
    assert key_from(search, "patient") == patient
    _, search = clinical(reader, "search", 2, method="nrp", query="DEMO-CLINIC")
    assert b"DATA DEMO Pasien Lokal" not in search
    clinical(writer, "read", 2, expected=403, **context)
    clinical(writer, "read", 1, expected=403, **wrong_patient)
    clinical(reader, "read", 2, expected=403, **context)
    clinical(admin, "read", 1, expected=403, **context)

    content = {
        "kind": "rikkes", "encounter_on": TODAY, "year": TODAY[:4], "rikkes_kind": "periodic",
        "summary": "DATA DEMO <rekam lokal>", "diagnoses": "DATA DEMO 233", "conclusion": "fit",
    }
    clinical(writer, "save", 1, decision="draft", **context, **content)
    _, timeline = clinical(writer, "read", 1, **context)
    assert b"DATA DEMO &lt;rekam lokal&gt;" in timeline and b"DATA DEMO <rekam lokal>" not in timeline
    record = key_from(timeline,"id")
    pdf = admin.call("/app/pelatihan?format=pdf")[1]
    upload_context = {"workplace":1,"purpose":"referral","reason":REASON, "id":record, "version":1, **context}
    attach(writer, upload_context, b"DATA DEMO bukan PDF", expected=422)
    attach(writer, upload_context, pdf)
    _, timeline = clinical(writer, "read", 1, **context)
    file_id = key_from(timeline, "file")
    assert clinical(writer, "download", 1, id=record, version=1, file=file_id, **context)[1] == pdf

    # The same actor has separate privileges at A and B. They still cannot approve their own request.
    clinical(lab, "request", 2, expected=303, source=1, scope="read", **context)
    _, inbox = second.call("/app/erm?workplace=1")
    self_request = key_from(inbox,"id")
    lab.post("/app/erm/decision", {"workplace":1,"id":self_request,"decision":"approve"}, 403)
    second.post("/app/erm/decision", {"workplace":1,"id":self_request,"decision":"reject"})

    clinical(reader, "request", 2, expected=303, source=1, scope="read", **context)
    clinical(reader, "request", 2, expected=303, source=1, scope="read", **context)
    _, inbox = lab.call("/app/erm?workplace=1")
    request = key_from(inbox,"id")
    lab.post("/app/erm/decision", {"workplace":1,"id":request,"decision":"approve"})
    _, draft_read = clinical(reader, "read", 2, **context)
    assert b"DATA DEMO Pasien Lokal" in draft_read and b"DATA DEMO 233" not in draft_read
    clinical(reader, "download", 2, expected=404, id=record, version=1, file=file_id, **context)
    clinical(reader, "read", 2, expected=403, purpose="rikkes", **context)
    clinical(reader, "save", 2, expected=403, decision="draft", **context, **content)
    clinical(reader, "export", 2, expected=403, destination=2, **context)

    clinical(writer, "save", 1, decision="sign", id=record, version=1, **context, **content)
    clinical(writer, "save", 1, expected=409, decision="draft", id=record, version=1, **context, **content)
    clinical(writer, "save", 1, expected=409, decision="draft", id=record, version=2, **context, **content)
    attach(writer, {**upload_context,"version":2}, pdf, expected=409)
    _, signed = clinical(reader, "read", 2, **context)
    assert b"DATA DEMO 233" in signed and b"Ditandatangani internal" in signed
    assert clinical(reader, "download", 2, id=record, version=2, file=file_id, **context)[1] == pdf
    clinical(writer, "save", 1, decision="amend", id=record, version=2, amend_reason="DATA DEMO koreksi resmi",
             **context, **{**content,"diagnoses":"DATA DEMO koreksi 234"})
    _, amended = clinical(reader, "read", 2, **context)
    assert b"Diamendemen" in amended and b"DATA DEMO koreksi 234" in amended and b"DATA DEMO 233" not in amended
    _, history = clinical(reader, "history", 2, id=record, **context)
    assert b"DATA DEMO 233" in history and b"DATA DEMO koreksi 234" in history and b"Versi 1" not in history
    clinical(reader, "download", 2, expected=404, id=record, version=2, file=file_id, **context)

    clinical(reader, "request", 2, expected=303, source=1, scope="export", **context)
    _, inbox = lab.call("/app/erm?workplace=1")
    pending = re.findall(rb'name="id" value="([a-f0-9]{32})"[\s\S]*?name="decision" value="approve"', inbox)
    assert len(pending) == 1
    # The approved read grant may precede the pending row in the inbox.
    forms = re.findall(rb'<form[^>]+action="/app/erm/decision"[^>]*>.*?</form>', inbox, flags=re.S)
    export_request = key_from(next(form for form in forms if b'name="decision" value="approve"' in form), "id")
    lab.post("/app/erm/decision", {"workplace":1,"id":export_request,"decision":"approve"})
    headers, summary = clinical(reader, "export", 2, destination=2, **context)
    assert summary.startswith(b"%PDF") and "attachment" in headers["Content-Disposition"]
    assert "no-store" in headers["Cache-Control"]
    lab.post("/app/erm/decision", {"workplace":1,"id":request,"decision":"revoke"})
    clinical(reader, "read", 2, expected=403, **context)
    clinical(reader, "download", 2, expected=403, id=record, version=3, file=file_id, **context)
    assert clinical(reader, "export", 2, destination=2, **context)[1].startswith(b"%PDF")

    _, audit = lab.call("/app/erm/audit?workplace=1")
    assert b"grant_revoke" in audit and b"assignment_assign" in audit and b"DATA DEMO 233" not in audit
    admin.call("/app/erm/audit?workplace=1",status=403)
    # Identity entered through a new password or email must be reappointed to regain clinical access.
    _, users = admin.call("/app/users?per=100")
    row = next(row for row in re.findall(rb'<tr>.*?</tr>',users,re.S) if WRITER.encode() in row)
    writer_id = re.search(rb'href="/app/users\?id=(\d+)"',row)[1].decode()
    admin.post("/app/users/save", {
        "id":writer_id, "version":1, "name":"DATA DEMO Penulis A", "email":WRITER, "role":"operator", "active":1,
        "password":PW,
    })
    writer.call("/app/erm", status=303)
    signed_out = account(WRITER)
    signed_out.call("/app/erm", status=403)
    print("Smoke klinis: akses terpisah, pencarian, persetujuan, draf/versi, berkas, ekspor, pencabutan dan audit lulus.")


if __name__ == "__main__":
    if sys.argv[1:] == ["seed"]:
        setup_fixtures()
    elif sys.argv[1:] == []:
        run_tests()
    else:
        raise SystemExit("Penggunaan: clinical.py [seed]")
