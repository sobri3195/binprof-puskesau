"""Synthetic end-to-end retirement fixtures for the disposable local database."""
from datetime import timedelta
import json
import os
import re
import shlex
import subprocess

from personnel_records import (
    ADMIN, API, DATE, DAY, OP, PAST, SOON, detail, file_from, get_file, grant, link,
    login, profile, rec, record_master, review, save_edit, upload, user_id, version,
)
from smoke_admin import Client, PW, location_id

BASE = "/app/pensiun"
VIEWER = "viewer@example.invalid"
REVIEWER = "retirement-review@example.invalid"
SECOND = "retirement-manager@example.invalid"
TARGET = "retirement-target@example.invalid"
NOTICE = b"Ada jadwal pensiun dalam periode pengingat Anda"
CLI = shlex.split(os.environ.get("BINPROF_RETIREMENT_CLI", "php tools/retirement-cron.php"))


def cron(applied=0, blocked=0):
    result = subprocess.run(CLI, check=True, capture_output=True, text=True)
    assert f"{applied} pensiun diterapkan, {blocked} perlu ditinjau" in result.stdout, result


def tab(client, pid, expected=200, **params):
    return profile(client, pid, "retirement", expected=expected, **params)[1]


def current(client, pid):
    body = tab(client, pid)
    return {
        "personnel": pid, "retirement": re.search(rb'name="retirement" value="([a-f0-9]{32})"', body)[1].decode(),
        "version": version(body),
    }


def action(client, path, pid, expected=303, **params):
    client.post(BASE + "/" + path, {**current(client, pid), **params}, expected)


def user(admin, address, role, units=None):
    admin.post("/app/users/save", {
        "name": "DATA DEMO " + address, "email": address, "role": role, "active": 1, "password": PW,
        "units[]": units or [],
    })
    return login(address)


def estimate(writer, pid, method="unknown", **params):
    writer.post(BASE + "/save", {
        "personnel": pid, "category": "DATA DEMO Kategori", "pic_id": params.pop("pic_id"),
        "projection_method": method, **params,
    })


def person(admin, label, unit=1, active=1):
    return location_id(admin.post("/app/personel/save", {
        "name": "DATA DEMO Pensiun " + label, "nrp": "RET-DEMO-" + label, "unit_id": unit, "facility_id": unit,
        "profession_id": 1, "active": active, "bp": "unknown", "position_title": "DATA DEMO Jabatan terakhir",
    })[0])


def badge(client, pid, label):
    return f">{label}<".encode() in profile(client, pid)[1]


def map_summary(client, facility=1, **filters):
    data = json.loads(client.call(link("/api/maps/detail", facility=facility, **filters))[1])
    return data, data["retirement"]


def roster(client, group, **params):
    return client.call(link(BASE, group=group, **params))[1]


def set_checklist(writer, pid, label):
    body = tab(writer, pid)
    row = next(row for row in re.findall(rb'<details[^>]*><summary>Ubah checklist.*?</form></details>', body, re.S) if label in row and b'name="item"' in row)
    item = re.search(rb'name="item" value="(\d+)"', row)[1].decode()
    action(writer, "checklist", pid, item=item, completed_on=DAY, item_note="DATA DEMO Bukti diperiksa")


def main():
    admin, writer, viewer = login(ADMIN), login(OP), login(VIEWER)
    reviewer = user(admin, REVIEWER, "unit_admin", [1])
    manager = user(admin, SECOND, "super_admin")
    target = user(admin, TARGET, "operator", [1])
    pic = user_id(admin, OP)
    uid = user_id(admin, TARGET)
    outside = person(admin, "OUT", 2)
    unknown = person(admin, "KOSONG", active=0)
    forecast = person(admin, "PROYEKSI")
    future = person(admin, "MASADEPAN")
    effective = person(admin, "HARIINI")

    writer.call(BASE + "/master", status=403)
    viewer.call(BASE, status=403)
    tab(viewer, effective, expected=403)
    tab(writer, outside, expected=404)
    assert b"Pensiun OUT" not in roster(writer, "all", q="RET-DEMO-OUT")
    assert b"Pensiun KOSONG" in roster(writer, "unknown") and not badge(writer, unknown, "Purnawirawan")
    writer.post(BASE + "/save", {
        "personnel": outside, "category": "DATA DEMO", "pic_id": pic, "projection_method": "unknown",
    }, 404)

    master = "/app/pensiun/master"
    admin.post(master + "/rule", {
        "code": "DEMO-LEAP", "version_label": "test-v1", "category": "DATA DEMO Aturan sintetis",
        "source_reference": "Fixture internal pengujian", "age_months": 12, "valid_from": "2024-01-01",
    })
    body = manager.call(master)[1]
    rule = re.search(rb'name="id" value="(\d+)"', body)[1].decode()
    admin.post(master + "/verify", {"id": rule}, 409)
    guess = {"birth_on": "2024-02-29", "birth_source": "DATA DEMO Sumber lahir"}
    writer.post(BASE + "/save", {
        "personnel": forecast, "projection_method": "rule", "rule_id": rule, "pic_id": pic, **guess,
    }, 422)
    manager.post(master + "/verify", {"id": rule})
    estimate(writer, forecast, pic_id=pic, **guess)
    action(writer, "save", forecast, 422, projection_method="rule", rule_id=rule, pic_id=pic, **guess)
    action(reviewer, "birth", forecast)
    action(writer, "save", forecast, projection_method="rule", rule_id=rule, pic_id=pic, **guess)
    assert b"2025-02-28" in tab(writer, forecast)
    assert badge(writer, forecast, "Aktif")

    writer.post(BASE + "/preferences", {"enabled": 1, "months": 1})
    action(writer, "save", forecast, projection_method="manual", category="DATA DEMO", pic_id=pic,
           planned_on=SOON, projection_source="DATA DEMO Perkiraan internasional", **guess)
    assert b"Pensiun PROYEKSI" in roster(writer, "near", months=1)
    cron()
    cron()
    assert writer.call("/app/notifikasi")[1].count(NOTICE) == 1
    action(writer, "save", forecast, projection_method="manual", category="DATA DEMO", pic_id=pic,
           planned_on=SOON, projection_source="DATA DEMO Perkiraan diperiksa", **guess)
    cron()
    assert writer.call("/app/notifikasi")[1].count(NOTICE) == 1
    later = (DATE + timedelta(days=70)).isoformat()
    action(writer, "save", forecast, projection_method="manual", category="DATA DEMO", pic_id=pic,
           planned_on=later, projection_source="DATA DEMO Jadwal baru", **guess)
    assert NOTICE not in writer.call("/app/notifikasi")[1]
    cron()
    assert NOTICE not in writer.call("/app/notifikasi")[1]
    writer.post(BASE + "/preferences", {"enabled": 0, "months": 6})

    for kind, label in (("document", "Bukti Dokumen"), ("handover", "Bukti Serah Terima")):
        admin.post(master + "/template", dict(code=kind, label=label, item_type=kind, required=1, active=1))
    category = record_master(admin, "decrees", "retirement-demo", "DATA DEMO Petikan Pensiun")

    def prepare(pid, tmt, code, file=False):
        fields = dict(category_id=category, document_no=code, title="DATA DEMO SK Pensiun",
                      issuer="DATA DEMO Penerbit", decreed_on=PAST, effective_on=tmt, parent_reference="DATA DEMO Induk")
        decree = rec(writer, pid, "decrees", fields)
        if file:
            upload(writer, API+"/upload", dict(personnel=pid, tab="decrees", record=decree, version=1, purpose="document"),
                   pdf, filename="data-demo-sk-pensiun.pdf")
        review(reviewer, pid, "decrees", decree)
        assert badge(writer, pid, "Aktif")
        estimate(writer, pid, pic_id=pic, decree_id=decree)
        action(writer, "decision", pid, 403, action="submit", csrf="invalid")
        action(writer, "decision", pid, action="submit")
        action(reviewer, "decision", pid, 409, action="verify")
        set_checklist(writer, pid, b"Bukti Dokumen")
        action(reviewer, "decision", pid, action="verify")
        action(reviewer, "decision", pid, 403, action="approve")
        action(admin, "decision", pid, action="approve")
        return decree, fields

    pdf = admin.call("/app/pelatihan?format=pdf")[1]
    future_decree, _ = prepare(future, SOON, "SK-RET-FUTURE")
    assert b"SK-RET-FUTURE" not in admin.call("/app/log")[1]
    assert b"Pensiun MASADEPAN" in roster(writer, "approved")
    data, metrics = map_summary(viewer, retirement_months=3)
    assert metrics["planned"] == 1 and metrics["scheduled"] == 1, metrics
    assert metrics["remaining"] == metrics["active"] - 1 and metrics["gap"] is None
    assert [row["months"] for row in metrics["timeline"]] == [3, 6, 12, 24]
    assert not data["personnel_allowed"] and "retirement_url" not in data
    assert b"SK-RET-FUTURE" not in json.dumps(data).encode()
    _, matching = map_summary(viewer, profession_id=1, retirement_months=3)
    _, absent = map_summary(viewer, profession_id=9999, retirement_months=3)
    assert matching["planned"] == 1 and absent is None
    viewer.call("/api/maps/detail?facility=2", status=404)
    viewer.call("/api/maps/personnel?facility=1", status=403)
    action(admin, "process", future)
    cron()
    assert badge(writer, future, "Aktif")
    action(admin, "decision", future, 422, action="cancel")
    action(admin, "decision", future, action="cancel", reason="DATA DEMO Dibatalkan berdasarkan perubahan.")
    assert badge(writer, future, "Aktif") and b"Dibatalkan" in tab(writer, future)
    assert b"SK-RET-FUTURE" in detail(writer, future, "decrees", future_decree)

    decree, fields = prepare(effective, DAY, "SK-RET-EFFECTIVE", file=True)
    canonical_file = file_from(detail(writer, effective, "decrees", decree))
    assert canonical_file.encode() in tab(writer, effective)
    get_file(viewer, effective, "decrees", decree, canonical_file, 403)
    assert get_file(writer, effective, "decrees", decree, canonical_file)[1] == pdf
    base_certificate = record_master(admin, "certificates", "retirement-cert", "DATA DEMO Sertifikat Pensiun")
    cert = rec(writer, effective, "certificates", dict(
        category_id=base_certificate, title="DATA DEMO Sertifikat tetap", document_no="RET-CERT-01",
        issuer="DATA DEMO Penerbit", issued_on=PAST, valid_from=PAST, validity_kind="dated",
        expires_on=SOON, reminder_days=10,
    ))
    grant(OP, "read,write,download")
    case_category = record_master(admin, "cases", "retirement-case", "DATA DEMO Kasus Pensiun")
    case = rec(writer, effective, "cases", dict(category_id=case_category, registration_no="RET-KAS-01",
               title="DATA DEMO Kasus tetap", reported_on=DAY, occurred_on=PAST, handling_unit_id=1, handler_id=pic,
               report_source="DATA DEMO Sumber kasus", summary="DATA DEMO Isi kasus"))
    grant(TARGET, "read")
    assert b"RET-KAS-01" in detail(target, effective, "cases", case)
    admin.post(BASE + "/account", dict(personnel=effective, action="link", email=TARGET, reference="DATA DEMO Berita acara"))
    action(admin, "process", effective, 409)
    cron(blocked=1)
    assert badge(writer, effective, "Aktif")
    set_checklist(writer, effective, b"Bukti Serah Terima")
    save_edit(writer, effective, "decrees", decree, {**fields, "title": "DATA DEMO SK Pensiun direvisi"})
    action(admin, "process", effective, 409)
    _, blocked = map_summary(viewer, retirement_months=3)
    assert blocked["scheduled"] == 0 and blocked["followup"] >= 1
    assert b"Buka tindak lanjut" not in viewer.call("/app/peta")[1]
    action(admin, "decision", effective, action="return", reason="DATA DEMO Perubahan Petikan.")
    review(reviewer, effective, "decrees", decree)
    action(writer, "decision", effective, action="submit")
    action(reviewer, "decision", effective, action="verify")
    action(admin, "decision", effective, action="approve")
    repeat = current(admin, effective)
    _, before = map_summary(writer)
    assert before["scheduled"] == 1
    cron(applied=1)
    admin.post(BASE + "/process", repeat)
    cron()
    assert badge(writer, effective, "Purnawirawan")
    assert b"Pensiun HARIINI" in roster(writer, "effective") and b"Pensiun HARIINI" not in roster(writer, "approved")
    assert b"Pensiun HARIINI" in writer.call("/app/personel?state=retired")[1]
    assert b"Pensiun HARIINI" not in writer.call("/app/personel?state=active")[1]
    assert b"Pensiun HARIINI" not in writer.call("/app/personel?state=inactive")[1]
    assert b"DATA DEMO Jabatan terakhir" in tab(writer, effective)
    assert b"RET-CERT-01" in detail(writer, effective, "certificates", cert)
    assert b"RET-KAS-01" in detail(writer, effective, "cases", case)
    history = tab(writer, effective)
    assert history.count(b"<td>Diterapkan</td>") == 1
    assert b"SK-RET-EFFECTIVE" in detail(writer, effective, "decrees", decree)
    assert get_file(writer, effective, "decrees", decree, canonical_file)[1] == pdf
    _, after = map_summary(writer)
    assert (after["scheduled"], after["active"]) == (0, before["active"] - 1)
    assert b"Pensiun HARIINI" not in writer.call("/api/maps/personnel?facility=1")[1]
    assert b"Pensiun dalam 12 bulan" in writer.call("/app/dashboard")[1]
    assert json.loads(viewer.call("/api/maps/markers?retirement_months=3")[1])
    target.call("/app/dashboard", status=303)
    new = Client()
    new.call("/login")
    new.post("/login", {"email": TARGET, "password": PW}, 401)
    admin.post("/app/users/save", {"id": uid, "role": "operator", "email": TARGET, "active": 1,
                                   "name": "DATA DEMO Target", "units[]": [1]}, 409)
    admin.post(BASE + "/account", dict(personnel=effective, action="grant", unit_id=1, expires_on=SOON,
                                      reference="DATA DEMO Mandat tugas terbatas"))
    assignee = login(TARGET)
    assignee.call("/api/maps/detail?facility=2", status=404)
    assignee.call(BASE, status=403)
    profile(assignee, effective, "cases", expected=403)
    admin.post(BASE + "/account", dict(personnel=effective, action="revoke"))
    assignee.call("/app/dashboard", status=303)
    print("Pensiun: aturan, perkiraan, jadwal, audit, checklist, dokumen, scope, statistik, pengingat, cron dan pencabutan sesi lulus.")


if __name__ == "__main__":
    main()
