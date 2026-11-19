"""RSAU/FKTP operator integration checks against the local disposable database."""
import json
import re

from executive import create_account, outcome
from personnel_records import ADMIN, API, DAY, PAST, SOON, detail, file_from, get_file, link, login, profile, rec, record_master, review, upload, user_id, version
from retirement import current, estimate
from smoke_admin import Client, PW, location_id

ROLE = "operator_fasilitas"
RSAU_EMAIL = "rsau-operator@example.invalid"
FKTP_EMAIL = "fktp-operator@example.invalid"
PROBE_EMAIL = "facility-probe@example.invalid"
EVENTS = "/api/maps/events"
WORK = "/app/peta-kendali/arahan"


def master_id(admin, kind, code):
    body = admin.call(link("/app/master",kind=kind,per=100))[1]
    row = next(row for row in re.findall(rb'<tr>.*?</tr>',body,re.S) if f"<td>{code}</td>".encode() in row)
    return int(re.search(rb'[?&]amp;id=(\d+)',row)[1])


def edit_facility(admin, id=None, **fields):
    admin.post("/app/master/save", {"kind":"facilities","unit_id":1,"active":1,"latitude":-2.25,"longitude":118.25,
                                   **({"id":id} if id else {}),**fields})
    return master_id(admin,"facilities",fields["code"])


def marker_ids(client):
    return [row["id"] for row in json.loads(client.call("/api/maps/markers")[1])]


def main():
    admin = login(ADMIN)
    assert b'Operator Fasilitas RSAU/FKTP' in admin.call("/app/users")[1]
    rsau_type = master_id(admin,"facility_types","rsau")
    fktp_type = master_id(admin,"facility_types","fktp")
    lanud_type = master_id(admin,"facility_types","lanud")
    rsau_fields = {"code":"demo-op-rsau","name":"DATA DEMO RSAU Operator","type_id":rsau_type}
    fktp_fields = {"code":"demo-op-fktp","name":"DATA DEMO FKTP Operator","type_id":fktp_type}
    rsau_id = edit_facility(admin,**rsau_fields)
    fktp_id = edit_facility(admin,**fktp_fields)
    invalid = {"name":"DATA DEMO Cakupan ditolak","email":"invalid-facility@example.invalid",
               "password":PW,"role":ROLE,"active":1}
    for fields in ({}, {"units[]":[1]}, {"units[]":[1],"facilities[]":[rsau_id]},
                   {"facilities[]":[rsau_id,fktp_id]}, {"facilities[]":[1]}):
        admin.post("/app/users/save",{**invalid,**fields},422)

    hospital = create_account(admin,RSAU_EMAIL,ROLE,facilities=[rsau_id])
    primary = create_account(admin,FKTP_EMAIL,ROLE,facilities=[fktp_id])
    probe = create_account(admin,PROBE_EMAIL,ROLE,facilities=[rsau_id])
    for client, facility, opposite, fields in ((hospital,rsau_id,fktp_id,rsau_fields),(primary,fktp_id,rsau_id,fktp_fields)):
        body = client.call("/app/dashboard")[1]
        assert b"Input Fasilitas" in body and fields["name"].encode() in body
        assert b"Tambah personel" in body and b"Catat diklat" in body
        assert marker_ids(client) == [facility]
        client.call(link("/api/maps/detail",facility=opposite),status=404)
        client.call(link("/api/maps/personnel",facility=opposite),status=404)
        client.call("/app/peta?format=csv",status=403)
        client.call("/app/peta-kendali",status=403)
        client.call("/app/users",status=403)
        client.call("/app/master",status=403)
        client.call("/app/erm",status=403)
        client.call("/app/mutasi",status=403)

    form = hospital.call("/app/personel/edit")[1]
    assert f'type="hidden" name="facility_id" value="{rsau_id}"'.encode() in form
    assert not re.search(rb'name="(nik|contact)"',form)
    staff = dict(name="DATA DEMO Petugas RSAU",nrp="DEMO-RSAU-99",unit_id=1,facility_id=rsau_id,profession_id=1,active=1,bp="unknown")
    person = location_id(hospital.post("/app/personel/save",staff)[0])
    foreign = location_id(primary.post("/app/personel/save",{**staff,"name":"DATA DEMO Petugas FKTP",
                          "nrp":"DEMO-FKTP-99","facility_id":fktp_id})[0])
    profile(hospital,foreign,expected=404)
    profile(primary,person,expected=404)
    profile(hospital,person,"contact",expected=403)
    profile(hospital,person,"cases",expected=404)
    editable = hospital.call(link("/app/personel/edit",id=person))[1]
    edit = {**staff,"id":person,"version":version(editable),"position_title":"DATA DEMO Posisi lokal"}
    hospital.post("/app/personel/save",{**edit,"facility_id":fktp_id},403)
    hospital.post("/app/personel/save",{**edit,"facility_id":""},403)
    hospital.post("/app/personel/save",edit)
    assert b"DATA DEMO Posisi lokal" in profile(hospital,person)[1]
    assert b"Petugas FKTP" not in hospital.call("/app/personel")[1]

    course = dict(personnel_id=person,type_id=1,organizer="DATA DEMO Diklat lokal",started_on=PAST,ended_on=DAY,completed=1)
    hospital.post("/app/pelatihan/save",{**course,"personnel_id":foreign},404)
    training = location_id(hospital.post("/app/pelatihan/save",course)[0])
    primary.call(link("/app/pelatihan/detail",id=training),status=404)
    pdf = admin.call("/app/peta-kendali/pdf")[1]
    upload(hospital,"/app/pelatihan/upload",{"id":training},pdf,filename="data-demo-rsau.pdf",field="certificate")
    body = hospital.call(link("/app/pelatihan/detail",id=training))[1]
    file = file_from(body)
    file_url = link("/app/pelatihan/file",id=training,file=file)
    primary.call(file_url,status=404)
    assert hospital.call(file_url)[1] == pdf

    category = record_master(admin,"certificates","facility-op-cert","DATA DEMO Dokumen operator fasilitas")
    cert = rec(hospital,person,"certificates",dict(category_id=category,title="DATA DEMO Sertifikat lokal",
               document_no="OP-RSAU-CERT",issued_on=DAY,valid_from=DAY,validity_kind="dated",expires_on=SOON))
    assert b"OP-RSAU-CERT" in detail(hospital,person,"certificates",cert)
    review(hospital,person,"certificates",cert,expected=403)
    upload(hospital,API+"/upload",dict(personnel=person,tab="certificates",record=cert,version=1,purpose="document"),pdf)
    file = file_from(detail(hospital,person,"certificates",cert))
    get_file(primary,person,"certificates",cert,file,404)
    assert get_file(hospital,person,"certificates",cert,file)[1] == pdf

    pic = user_id(admin,RSAU_EMAIL)
    estimate(hospital,person,pic_id=pic)
    draft = current(hospital,person)
    hospital.post("/app/pensiun/decision",{**draft,"action":"submit"})
    hospital.post("/app/pensiun/decision",{**draft,"action":"approve"},403)

    assert b'data-report="1"' in hospital.call("/app/peta")[1]
    event = dict(action="create", facility=rsau_id, category="operations", severity="warning")
    first = json.loads(hospital.post(EVENTS,event,200)[1])
    assert first["changed"]
    assert not json.loads(hospital.post(EVENTS,event,200)[1])["changed"]
    hospital.post(EVENTS,{**event,"facility":fktp_id},404)
    for stage in ("acknowledge","resolve"):
        hospital.post(EVENTS,{**event,"action":stage,"event":first["id"]},403)

    directive_fields = dict(title="DATA DEMO Tindak lanjut operator",instruction="DATA DEMO Lengkapi data.",
                            pic=pic,kind="general",severity="info",due_on=SOON)
    admin.post(WORK+"/save",{**directive_fields,"facility":fktp_id},422)
    created = admin.post(WORK+"/save",{**directive_fields,"facility":rsau_id})[0]
    directive = re.search(r'id=([a-f0-9]{32})',created["Location"])[1]
    outcome(admin,directive,"issue")
    primary.call(link(WORK,id=directive),status=404)
    outcome(hospital,directive,"accept")
    outcome(hospital,directive,"work")
    outcome(hospital,directive,"submit")
    current_directive = version(admin.call(link(WORK,id=directive))[1])
    hospital.post(WORK+"/action",dict(id=directive,version=current_directive,action="finish"),403)

    # If a facility is reclassified, even an unchanged account loses its old session access.
    edit_facility(admin,id=fktp_id,**{**fktp_fields,"type_id":lanud_type})
    primary.call("/api/maps/markers",status=401)
    rejected = Client()
    rejected.call("/login")
    rejected.post("/login",{"email":FKTP_EMAIL,"password":PW},401)
    assert marker_ids(hospital) == [rsau_id]
    edit_facility(admin,id=fktp_id,**fktp_fields)
    assert marker_ids(login(FKTP_EMAIL)) == [fktp_id]

    probe_id = user_id(admin,PROBE_EMAIL)
    edit_user = admin.call(link("/app/users",id=probe_id))[1]
    admin.post("/app/users/save",dict(id=probe_id,version=version(edit_user),name="DATA DEMO FKTP probe",
               email=PROBE_EMAIL,role=ROLE,active=1,**{"facilities[]":[fktp_id]}))
    probe.call("/api/maps/markers",status=401)
    assert marker_ids(login(PROBE_EMAIL)) == [fktp_id]
    print("Operator fasilitas: RSAU/FKTP, cakupan tunggal, input personel/diklat/dokumen, pengajuan, kejadian, arahan dan pencabutan sesi lulus.")


if __name__ == "__main__":
    main()
