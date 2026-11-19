"""Exercises the five position roles on a disposable BINPROFKES database."""
import json
import re

from executive import create_account
from personnel_records import ADMIN, DAY, OP, PAST, link, login, profile, rec, record_master, review, user_id, version
from retirement import current, estimate
from smoke_admin import Client, PW, location_id

SECURITY = "/app/users"
PDF = "/app/peta-kendali/pdf"
RETIREMENT = "/app/pensiun"
EMAILS = {role: role + "@example.invalid"
          for role in ("kapuskesau","dirbinprof","kasipa","kasibata","staf_binprof")}


def decision(client, fields, name, status=303, **extras):
    client.post(RETIREMENT+"/decision", {**fields, "action":name, **extras}, status)


def dashboard_location(role):
    client = Client()
    client.call("/login")
    return client.post("/login", {"email":EMAILS[role],"password":PW})[0]["Location"]


def main():
    admin = login(ADMIN)
    index = admin.call(SECURITY)[1]
    for code in EMAILS:
        assert f'value="{code}"'.encode() in index
    admin.post(SECURITY+"/save", dict(name="DATA DEMO tanpa cakupan", email="no-assignment@example.invalid", role="kapuskesau",
                                    active=1,password=PW), 422)
    chief = create_account(admin, EMAILS["kapuskesau"], "kapuskesau", facilities=[1])
    director = create_account(admin, EMAILS["dirbinprof"], "dirbinprof", units=[1])
    head = create_account(admin, EMAILS["kasipa"], "kasipa", units=[1])
    other_head = create_account(admin, EMAILS["kasibata"], "kasibata", facilities=[2])
    staff = create_account(admin, EMAILS["staf_binprof"], "staf_binprof", units=[1])
    for role in EMAILS:
        expected = "/app/peta-kendali" if role in ("kapuskesau","dirbinprof") else "/app/dashboard"
        assert dashboard_location(role) == expected

    personal = dict(name="DATA DEMO Jabatan A",nrp="JABATAN-DEMO-A",unit_id=1,facility_id=1,
                    profession_id=1,active=1,bp="unknown")
    alpha = location_id(admin.post("/app/personel/save", {**personal, "contact":"0000000001","nik":"0000000000009991"})[0])
    beta = location_id(admin.post("/app/personel/save", {**personal, "name":"DATA DEMO Jabatan B",
                       "nrp":"JABATAN-DEMO-B","unit_id":2,"facility_id":2})[0])
    chief.call(SECURITY,status=403)
    director.call("/app/master",status=403)
    for account in (chief,director,head,staff):
        profile(account,alpha)
        profile(account,beta,expected=404)
        profile(account,alpha,"contact",expected=403)
        account.call("/app/erm",status=403)
        profile(account,alpha,"cases",expected=404)
    profile(other_head,beta)
    profile(other_head,alpha,expected=404)
    create_form = other_head.call("/app/personel/edit")[1]
    choices = re.search(rb'<select name="unit_id"[^>]*>(.*?)</select>',create_form,re.S)[1]
    assert re.findall(rb'<option value="(\d+)"',choices) == [b"2"]
    other_personal = {**personal, "name":"DATA DEMO Lingkup fasilitas","nrp":"JABATAN-DEMO-FACILITY","unit_id":2}
    other_head.post("/app/personel/save",{**other_personal,"facility_id":""},403)
    location_id(other_head.post("/app/personel/save",{**other_personal,"facility_id":2})[0])
    assert b"0000000000009991" in profile(login(OP),alpha,"contact")[1]
    assert b"0000000000009991" in profile(admin,alpha,"contact")[1]
    assert b">Kontak<" not in profile(staff,alpha)[1]
    new_contact = staff.call(link("/app/personel/edit",id=alpha))[1]
    assert not re.search(rb'name="(nik|contact)"', new_contact)
    profile_form = {**personal,"id":alpha,"version":version(new_contact),"position_title":"DATA DEMO Posisi"}
    staff.post("/app/personel/save",{**profile_form,"nik":"1111111111111111"},403)
    staff.post("/app/personel/save",profile_form)
    assert b"0000000000009991" in profile(admin,alpha,"contact")[1]
    assert b"0000000001" in profile(admin,alpha,"contact")[1]
    later = location_id(staff.post("/app/personel/save", {**personal, "nrp":"JABATAN-DEMO-NEW"})[0])

    # Verify both permissions and the facility scope of aggregate and privileged routes.
    kpis = json.loads(chief.call("/api/executive/data")[1])
    assert [row["id"] for row in kpis["rows"]] == [1]
    assert json.loads(chief.call("/api/executive/data?facility_id=2")[1])["rows"] == []
    assert chief.call(PDF)[1].startswith(b"%PDF")
    assert director.call(PDF)[1].startswith(b"%PDF")
    chief.call("/app/personel?format=csv",status=403)
    for account in (head,other_head,staff):
        account.call(PDF,status=403)
        account.call("/api/executive/data",status=403)
    assert b'href="/app/peta-kendali/sumber"' in head.call("/app/dashboard")[1]
    head.call("/app/peta-kendali/sumber?facility=1")
    other_head.call("/app/peta-kendali/sumber?facility=2")
    other_head.call("/app/peta-kendali/sumber?facility=1",status=404)
    staff.call("/app/peta-kendali/sumber?facility=1",status=403)
    staff.post("/app/mutasi/process",{},403)

    # Staff input, section review of the source, Dirbinprof verification, then Kapuskesau decision.
    category = record_master(admin,"decrees","position-roles-ret","DATA DEMO Keputusan Jabatan")
    attrs = dict(category_id=category,document_no="SK-JABATAN-DEMO",title="DATA DEMO Pensiun",
                 issuer="DATA DEMO Instansi",decreed_on=PAST,effective_on=DAY)
    record = rec(staff,alpha,"decrees",attrs)
    review(staff,alpha,"decrees",record,expected=403)
    profile(other_head,alpha,"decrees",expected=404)
    review(head,alpha,"decrees",record)
    pic = user_id(admin,EMAILS["staf_binprof"])
    estimate(staff,alpha,pic_id=pic,decree_id=record)
    draft = current(staff,alpha)
    decision(chief,draft,"submit",403)
    decision(staff,draft,"submit")
    submitted = current(director,alpha)
    decision(staff,submitted,"verify",403)
    decision(head,submitted,"verify",403)
    decision(other_head,submitted,"verify",404)
    decision(chief,submitted,"verify",403)
    decision(director,submitted,"verify")
    verified = current(director,alpha)
    decision(director,verified,"approve",403)
    assert b"Kembalikan untuk perbaikan" in profile(chief,alpha,"retirement")[1]
    decision(chief,verified,"return",422)
    decision(chief,verified,"return",reason="DATA DEMO Tinjau ulang lampiran.")
    decision(staff,current(staff,alpha),"submit")
    decision(director,current(director,alpha),"verify")
    decision(chief,current(director,alpha),"approve")
    approved = current(director,alpha)
    decision(director,approved,"cancel",403,reason="Uji pembatasan.")
    chief.post(RETIREMENT+"/process",approved,403)
    director.post(RETIREMENT+"/process",approved)
    assert b"Purnawirawan" in profile(chief,alpha,"retirement")[1]
    estimate(staff,later,pic_id=pic)
    decision(staff,current(staff,later),"submit")
    pending = current(director,later)
    decision(staff,pending,"cancel",403,reason="Uji pembatasan.")
    decision(chief,pending,"cancel",reason="DATA DEMO Pengajuan dibatalkan.")

    # Normal account administration revokes the old session on a change of both role and scope.
    edit = admin.call(link(SECURITY,id=pic))[1]
    admin.post(SECURITY+"/save",{"id":pic,"version":version(edit),"name":"DATA DEMO Kepala seksi uji",
               "email":EMAILS["staf_binprof"],"role":"kasibata","active":1,"facilities[]":[2]})
    staff.call("/app/dashboard",status=303)
    new_role = login(EMAILS["staf_binprof"])
    profile(new_role,beta)
    profile(new_role,alpha,expected=404)
    print("Jabatan BINPROFKES: migrasi, lima peran, landing, scope, NIK/kontak, pemisahan workflow, ekspor dan pencabutan sesi lulus.")


if __name__ == "__main__":
    main()
