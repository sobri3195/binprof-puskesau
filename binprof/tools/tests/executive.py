"""E2E fixtures for Peta Kendali. Use a clean disposable database with migrations 002–007."""
from datetime import timedelta
import json
import re
import urllib.parse

from personnel_records import (
    ADMIN, API, DATE, DAY, OP, PAST, SOON, link, login, rec, record_master, review, upload, user_id, version,
)
from retirement import action, estimate, person
from smoke_admin import Client, PW

HOME = "/app/peta-kendali"
DATA = "/api/executive/data"
SOURCE = HOME + "/sumber"
WORK = HOME + "/arahan"
VIEWER = "viewer@example.invalid"
LEADER = "executive-leader@example.invalid"
OUTSIDE = "executive-outsider@example.invalid"
REVIEWER = "executive-review@example.invalid"


def create_account(admin, email, role, units=(), facilities=()):
    admin.post("/app/users/save", dict(name="DATA DEMO " + email, email=email, password=PW,
                                      role=role, active=1, **{"units[]": units, "facilities[]": facilities}))
    return login(email)


def aggregate(client, **filters):
    return json.loads(client.call(link(DATA, **filters))[1])


def outcome(client, directive, action_name, expected=303, **payload):
    body = client.call(link(WORK, id=directive))[1]
    client.post(WORK + "/action", dict(id=directive, version=version(body), action=action_name, **payload), expected)


def id_from_redirect(headers):
    return urllib.parse.parse_qs(urllib.parse.urlsplit(headers["Location"]).query)["id"][0]


def source_record(client, step):
    page = client.call(link(SOURCE, facility=1))[1]
    matches = re.findall(rb'<form\b.*?</form>', page, re.S)
    return next(re.search(rb'name="record" value="(\d+)"', form)[1].decode() for form in matches
                if f'name="action" value="{step}"'.encode() in form)


def main():
    admin, writer, viewer = login(ADMIN), login(OP), login(VIEWER)
    create_account(admin, LEADER, "executive_leader", facilities=[1])
    outside = create_account(admin, OUTSIDE, "executive_leader", facilities=[2])
    reviewer = create_account(admin, REVIEWER, "unit_admin", units=[1])
    leader = Client()
    leader.call("/login")
    headers, _ = leader.post("/login", dict(email=LEADER, password=PW))
    assert headers["Location"] == HOME
    assert b"Peta Kendali Pimpinan" in leader.call(HOME)[1]
    Client().call(DATA, status=401)
    viewer.call(DATA, status=403)
    viewer.call(HOME + "/pdf", status=403)
    writer.call(SOURCE, status=403)
    outside.call(link(SOURCE, facility=1), status=404)

    baseline = aggregate(leader)
    assert baseline["kpi"]["facilities"] == 1 and [r["id"] for r in baseline["rows"]] == [1]
    assert baseline["rows"][0]["condition"] == "gray"
    assert baseline["kpi"]["shortage"] is None
    assert "Personel A" not in json.dumps(baseline) and "DEMO-1" not in json.dumps(baseline)
    assert aggregate(leader, facility_id=2)["rows"] == [] and aggregate(outside)["rows"][0]["id"] == 2
    assert aggregate(leader, q="NONEXISTENT")["kpi"]["facilities"] == 0
    assert aggregate(leader, facility_id=1, profession_id=9999)["kpi"]["active"] is None
    historic = aggregate(leader, mode="history", date=PAST)
    assert historic["status"] and historic["kpi"]["facilities"] is None
    delta = aggregate(leader, after=baseline["revision"])
    assert not delta["changed"] and "rows" not in delta and delta["session_seconds"] > 0
    assert aggregate(outside, after=baseline["revision"])["changed"]

    pic = user_id(admin, OP)
    personnel = person(admin, "EKSEKUTIF")
    category = record_master(admin, "certificates", "executive-required", "DATA DEMO Kompetensi Wajib")
    certificate = rec(writer, personnel, "certificates", dict(category_id=category, title="DATA DEMO Sertifikat Rahasia",
        document_no="DEMO-CERT-EXEC-01", issued_on=PAST, valid_from=PAST, validity_kind="dated", expires_on=SOON))
    review(reviewer, personnel, "certificates", certificate)
    decree_category = record_master(admin, "decrees", "executive-retirement", "DATA DEMO SK Proyeksi")
    decree = rec(writer, personnel, "decrees", dict(category_id=decree_category, document_no="DEMO-SK-EXEC-01",
        title="DATA DEMO Keputusan", issuer="DATA DEMO Lembaga", decreed_on=PAST, effective_on=SOON))
    review(reviewer, personnel, "decrees", decree)
    estimate(writer, personnel, pic_id=pic, decree_id=decree)
    action(writer, "decision", personnel, action="submit")
    action(reviewer, "decision", personnel, action="verify")
    action(admin, "decision", personnel, action="approve")

    standards = {"valid_from": PAST, "source": "Register sintetik sumber organisasi"}
    reviewer.post(SOURCE, dict(facility=1, action="rule", label="DEMO-V1", freshness_days=90, critical_gap=1,
                               certificate_category_id=category, certificate_window=31, **standards))
    before_review = aggregate(leader, after=baseline["revision"])
    assert before_review["changed"] and before_review["kpi"]["shortage"] is None
    target = before_review["kpi"]["doctors"] + 1
    reviewer.post(SOURCE, dict(facility=1, action="target", profession_id=1, target_count=target,
                               label="DEMO-T1", **standards))
    reviewer.post(SOURCE, dict(facility=1, action="roster", roster_on=DAY, pic=pic, source="DEMO pemeriksaan roster"))
    for step in ("verify_rule","verify_target","verify_roster"):
        record = source_record(leader, step)
        reviewer.post(SOURCE, dict(facility=1, action=step, record=record), 409)
        leader.post(SOURCE, dict(facility=1, action=step, record=record))
    measured = aggregate(leader)
    row = measured["rows"][0]
    assert row["gap"] == 1 and row["assessments"]["staffing"]["level"] == "red", row
    assert row["assessments"]["staffing"]["version"] == "DEMO-V1"
    assert row["assessments"]["certificate"]["actual"] == 1
    assert measured["kpi"]["shortage"] == 1 and measured["kpi"]["near"] == 1
    assert measured["kpi"]["active"] == sum(r["totals"]["active"] for r in measured["rows"])
    assert [period["months"] for period in measured["timeline"]] == [3, 6, 12, 24]
    projected = aggregate(leader, mode="projection", months=3, profession_id=1)
    assert projected["kpi"]["doctors"] == measured["kpi"]["doctors"] - 1
    assert projected["rows"][0]["gap"] == 2
    assert aggregate(leader, metric="shortage")["kpi"]["facilities"] == 1
    assert aggregate(leader, specialty_id=9999)["kpi"]["shortage"] is None

    event = json.loads(admin.post("/api/maps/events", dict(facility=1, action="create", category="operations", severity="critical"),
                                  status=200)[1])["id"]
    assert aggregate(leader)["rows"][0]["assessments"]["event"]["level"] == "gray"
    admin.post(SOURCE, dict(facility=1, action="verify_event", event=event, source="DEMO register gangguan"), 409)
    leader.post(SOURCE, dict(facility=1, action="verify_event", event=event, source="DEMO register gangguan"))
    verified = aggregate(leader)
    assert verified["priorities"][0]["kind"] == "event" and verified["priorities"][0]["level"] == "red"
    assert len(verified["priorities"]) <= 5

    details = dict(facility=1, title="DATA DEMO Arahan privat", instruction="DATA DEMO Jangan tampil di mode rapat",
                   kind="retirement", severity="critical", due_on=SOON, plan_reference="DATA DEMO Berita acara")
    leader.post(WORK + "/save", dict(**details, pic=user_id(admin, OUTSIDE)), 422)
    leader.post(WORK + "/save", dict(**details, pic=pic, csrf="invalid"), 403)
    directive = id_from_redirect(leader.post(WORK + "/save", dict(**details, pic=pic))[0])
    url = link(WORK, id=directive)
    writer.call(url, status=404)
    outside.call(url, status=404)
    outcome(leader, directive, "issue")
    assert b"Ada pembaruan tindak lanjut" in writer.call("/app/notifikasi")[1]
    outcome(writer, directive, "read")
    outcome(writer, directive, "accept")
    outcome(writer, directive, "work")
    bad = writer.call(url)[1]
    pdf = leader.call(HOME + "/pdf")[1]
    assert pdf.startswith(b"%PDF-")
    upload(writer, WORK + "/upload", dict(id=directive, version=version(bad)), b"bukan PDF", 422)
    upload(writer, WORK + "/upload", dict(id=directive, version=version(bad)), pdf, filename="data-demo-bukti.pdf")
    current = writer.call(url)[1]
    assert b"Dikerjakan" in current and b"Ajukan verifikasi" in current
    file = re.search(rb'file=([0-9a-f]{32})', current)[1].decode()
    download = link(WORK + "/file", id=directive, file=file)
    outside.call(download, status=404)
    headers, body = leader.call(download)
    assert body == pdf and "private" in headers["Cache-Control"]
    outcome(writer, directive, "submit")
    latest = version(leader.call(url)[1])
    writer.post(WORK + "/action", dict(id=directive, version=latest, action="finish"), 403)
    outcome(leader, directive, "return", 422)
    outcome(leader, directive, "return", note="DATA DEMO Perbaiki bukti tindak lanjut.")
    outcome(writer, directive, "submit")
    outcome(leader, directive, "finish")
    assert b"Selesai" in leader.call(url)[1]
    assert aggregate(leader)["progress"]["done"] == 1
    assert aggregate(leader)["rows"][0]["replacement"] is True
    assert "Arahan privat" not in json.dumps(aggregate(leader)) and "Jangan tampil" not in leader.call(HOME)[1].decode()

    preset = json.loads(leader.post("/api/executive/preset", dict(action="save", name="DATA DEMO Preset",
        facility_id=1, mode="projection", months=3, layer="staffing", display="map"), 200)[1])
    outside.post("/api/executive/preset", dict(action="delete", id=preset["id"]), 404)
    assert preset["settings"]["facility_id"] == 1 and "rows" not in preset["settings"]
    leader.post(HOME + "/snapshot", {})
    snapshot = aggregate(leader, mode="history", date=DAY)
    assert snapshot["rows"][0]["gap"] == 1 and "events" not in snapshot["rows"][0]
    assert aggregate(leader, mode="history", date=DAY, profession_id=1)["status"]
    person(admin, "SOURCE-ROTATION")
    refreshed = aggregate(leader, after=verified["revision"])
    assert refreshed["changed"] and refreshed["rows"][0]["assessments"]["quality"]["level"] == "gray"
    leader.post(HOME + "/snapshot", {})
    assert aggregate(leader, mode="history", date=DAY)["rows"][0]["gap"] == 1

    # A second proof for the same person replaces their expiring evidence
    # without covering anyone else on the active roster.
    renewed = rec(writer, personnel, "certificates", dict(category_id=category,title="DATA DEMO Perpanjangan",
        document_no="DEMO-CERT-EXEC-RENEWED",issued_on=PAST,valid_from=PAST,validity_kind="dated",
        expires_on=(DATE+timedelta(days=365)).isoformat()))
    review(reviewer, personnel, "certificates", renewed)
    current = aggregate(leader)["rows"][0]
    assessment = current["assessments"]["certificate"]
    assert current["totals"]["active"] > 1
    assert assessment["level"] == "gray" and assessment["actual"] == 0
    assert f'1 dari {current["totals"]["active"]} personel' in assessment["reason"]

    leader.post("/logout", {})
    leader.call(DATA, status=401)
    print("Peta Kendali: scope dan landing, KPI, data kosong, aturan dan target, sertifikat, kejadian, proyeksi, arahan, dokumen, preset, snapshot, delta, PDF dan sesi lulus.")


if __name__ == "__main__":
    main()
