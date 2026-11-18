"""Synthetic integration checks. Run on the disposable BINPROFKES database."""
from datetime import datetime, timedelta
import os
import re
import shlex
import subprocess
import urllib.parse
from zoneinfo import ZoneInfo

from smoke_admin import Client, PW, location_id

DETAIL = "/app/personel/detail"
API = "/app/personel/records"
MASTER = "/app/master/personnel-records"
OP = "operator@example.invalid"
ADMIN = "admin@example.invalid"
OUTSIDE = "profile-outside@example.invalid"
DATE = datetime.now(ZoneInfo("Asia/Jakarta")).date()
DAY = DATE.isoformat()
PAST = (DATE - timedelta(days=5)).isoformat()
SOON = (DATE + timedelta(days=5)).isoformat()
CLI = shlex.split(os.environ.get("BINPROF_PROFILE_CLI", "php tools/personnel-case-access.php"))


def login(email):
    client = Client()
    client.login(email)
    return client


def link(path, **parameters):
    return path + "?" + urllib.parse.urlencode(parameters)


def profile(client, personnel, tab="basic", *, expected=200, **extra):
    return client.call(link(DETAIL,id=personnel,tab=tab,**extra),status=expected)


def post(client, operation, personnel, tab, *, expected=303, **fields):
    return client.post(API+"/"+operation,{"personnel":personnel,"tab":tab,**fields},status=expected)


def rec(client, personnel, tab, fields):
    headers, _ = post(client,"save",personnel,tab,**fields)
    return urllib.parse.parse_qs(urllib.parse.urlsplit(headers["Location"]).query)["record"][0]


def detail(client, pid, tab, record, **extra):
    return profile(client,pid,tab,record=record,**extra)[1]


def version(body):
    result = re.search(rb'name="version" value="(\d+)"',body)
    assert result, body[-150:]
    return int(result[1])


def save_edit(client, pid, tab, identifier, fields):
    current = version(detail(client,pid,tab,identifier))
    post(client,"save",pid,tab,record=identifier,version=current,**fields)


def review(client, pid, tab, identifier, expected=303, **details):
    row = detail(client,pid,tab,identifier)
    post(client,"verify",pid,tab,expected=expected,record=identifier,version=version(row),
         decision="verified",**details)


def upload(client, path, fields, contents, expected=303, filename="data-demo.pdf", field="attachment"):
    boundary = b"BINPROF-PROFILE-TEST"
    form = b""
    for key, value in {"csrf":client.csrf,**fields}.items():
        form += b"--"+boundary+f'\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{value}\r\n'.encode()
    form += b"--"+boundary+(
        f'\r\nContent-Disposition: form-data; name="{field}"; filename="{filename}"\r\n'
        "Content-Type: application/pdf\r\n\r\n"
    ).encode()+contents+b"\r\n--"+boundary+b"--\r\n"
    client.call(path,form,status=expected,content_type="multipart/form-data; boundary="+boundary.decode())


def file_from(body):
    return re.search(rb'file=([a-f0-9]{32})',body)[1].decode()


def get_file(client, pid, tab, identifier, file, expected=200, **extra):
    return client.call(link(API+"/file",personnel=pid,tab=tab,record=identifier,file=file,**extra),status=expected)


def grant(email, permissions):
    subprocess.run([*CLI,ADMIN,email,permissions],check=True,capture_output=True,text=True)


def record_master(client, kind, code, name, rank=0):
    client.post(MASTER+"/save",dict(kind=kind,code=code,name=name,sort_order=rank,active=1))
    body = client.call(link(MASTER,kind=kind))[1]
    row = next(row for row in re.findall(rb'<tr>.*?</tr>',body,re.S) if name.encode() in row)
    return int(re.search(rb'&amp;id=(\d+)',row)[1])


def user_id(client, email):
    body = client.call("/app/users?per=100")[1]
    row = next(row for row in re.findall(rb'<tr>.*?</tr>',body,re.S) if email.encode() in row)
    return int(re.search(rb'href="/app/users\?id=(\d+)"',row)[1])


def main():
    admin, writer = login(ADMIN), login(OP)
    admin.post("/app/users/save",{
        "name":"DATA DEMO Petugas Unit B","email":OUTSIDE,"role":"operator","active":1,"password":PW,"units[]":[2],
    })
    outsider = login(OUTSIDE)
    pid = location_id(admin.post("/app/personel/save",{
        "name":"DATA DEMO Profil Enam Tab","nrp":"PROFILE-DEMO-001","unit_id":1,"facility_id":1,
        "profession_id":1,"active":1,"bp":"unknown","position_title":"DATA DEMO Jabatan Asal",
    })[0])
    other_pid = location_id(admin.post("/app/personel/save",{
        "name":"DATA DEMO Profil Pembanding","nrp":"PROFILE-DEMO-002","unit_id":1,"facility_id":1,
        "profession_id":1,"active":1,"bp":"unknown",
    })[0])
    profile(admin,pid,"cases",expected=404)
    profile(writer,pid,"cases",expected=404,q="DATA DEMO",format="csv")
    assert b">Kasus<" not in profile(writer,pid)[1]
    for old in ("basic","contact","history","awards","works"):
        profile(writer,pid,old)
    master = {
        "cases":record_master(admin,"cases","case-demo","DATA DEMO Jenis Kasus"),
        "certificates":record_master(admin,"certificates","cert-demo","DATA DEMO Jenis Sertifikat"),
        "decrees":record_master(admin,"decrees","decree-demo","DATA DEMO Jenis Petikan"),
        "military":record_master(admin,"military","mil-demo","DATA DEMO Jenjang Militer",5),
        "general":record_master(admin,"general","gen-demo","DATA DEMO Jenjang Dasar",10),
        "higher":record_master(admin,"general","gen-higher","DATA DEMO Jenjang Tinggi",20),
    }
    master["diplomas"] = master["general"]
    grant(OP,"read,write,download")
    grant(ADMIN,"read,write,verify,download,export")
    grant(OUTSIDE,"read,write,download")
    assert b"Kasus" in profile(writer,pid,"cases")[1]
    profile(outsider,pid,"cases",expected=404,format="csv")
    pdf = admin.call("/app/pelatihan?format=pdf")[1]

    case = dict(category_id=master["cases"], registration_no="DEMO-KAS-1", title="DATA DEMO <Laporan>",
                reported_on=DAY,occurred_on=PAST,handling_unit_id=1,handler_id=user_id(admin,OP),
                report_source="DATA DEMO Pelapor",summary="DATA DEMO Ringkasan faktual",follow_up_on=SOON)
    post(writer,"save",pid,"cases",expected=422,**{**case,"occurred_on":SOON})
    post(writer,"save",pid,"cases",expected=403,csrf="invalid",**case)
    case_id = rec(writer,pid,"cases",case)
    case_body = detail(writer,pid,"cases",case_id)
    assert b"DATA DEMO &lt;Laporan&gt;" in case_body and b"<Laporan>" not in case_body
    upload(writer,API+"/upload",{"personnel":pid,"tab":"cases","record":case_id,"version":1},b"not a PDF",422)
    upload(writer,API+"/upload",{"personnel":pid,"tab":"cases","record":case_id,"version":1},pdf)
    case_file = file_from(detail(writer,pid,"cases",case_id))
    headers, data = get_file(writer,pid,"cases",case_id,case_file)
    assert data==pdf and "no-store" in headers["Cache-Control"]
    get_file(writer,pid,"cases",case_id,case_file,409,mode="preview")
    get_file(outsider,pid,"cases",case_id,case_file,404)
    get_file(writer,pid,"certificates",case_id,case_file,404)
    get_file(writer,other_pid,"cases",case_id,case_file,404)
    post(writer,"progress",pid,"cases",record=case_id,version=2,state="review",occurred_on=DAY,progress_note="DATA DEMO Nota verifikasi")
    post(writer,"progress",pid,"cases",expected=422,record=case_id,version=3,state="handling",occurred_on=DAY,progress_note="DATA DEMO")
    review(admin,pid,"cases",case_id)
    post(writer,"progress",pid,"cases",record=case_id,version=4,state="handling",occurred_on=DAY,progress_note="DATA DEMO Penanganan")
    case.update(decision="DATA DEMO Putusan administratif",decision_no="DEMO-SK-0",decision_on=DAY)
    save_edit(writer,pid,"cases",case_id,case)
    review(admin,pid,"cases",case_id)
    post(admin,"progress",pid,"cases",record=case_id,version=7,state="closed",occurred_on=DAY,progress_note="DATA DEMO Penutupan")
    closed=detail(admin,pid,"cases",case_id)
    assert b"DATA DEMO Penutupan" in closed and b"akhir penanganan" in closed
    profile(writer,pid,"cases",expected=403,format="csv")
    assert b"DEMO-KAS-1" in profile(admin,pid,"cases",format="csv",q="DEMO-KAS-1")[1]
    assert b"DEMO-KAS-1" not in admin.call('/app/log')[1]

    base_cert = dict(category_id=master["certificates"],title="DATA DEMO Sertifikat Independen",document_no="CERT-DEMO-1",
                     issuer="DATA DEMO Lembaga",issued_on=PAST,valid_from=PAST,validity_kind="dated",expires_on=SOON,reminder_days=10)
    post(writer,"save",pid,"certificates",expected=422,**{**base_cert,"expires_on":""})
    certificate = rec(writer,pid,"certificates",base_cert)
    save_edit(writer,pid,"certificates",certificate,{**base_cert,"activity_name":"DATA DEMO Kegiatan"})
    post(writer,"save",pid,"certificates",expected=409,record=certificate,version=1,**base_cert)
    review(admin,pid,"certificates",certificate)
    assert b"Independen" in profile(writer,pid,"certificates",state="soon")[1]
    unknown = rec(writer,pid,"certificates",{**base_cert,"title":"DATA DEMO Tanggal Hilang","validity_kind":"unknown","expires_on":""})
    assert b"Tanggal Hilang" in profile(writer,pid,"certificates",state="unknown")[1]
    assert b"Tanggal Hilang" not in profile(writer,pid,"certificates",state="soon")[1]

    training_fields = dict(type_id=1,organizer="DATA DEMO Sumber Diklat",completed=1,started_on=PAST,ended_on=PAST,expires_on=SOON)
    training = location_id(writer.post("/app/pelatihan/save",{"personnel_id":pid,**training_fields})[0])
    upload(writer,"/app/pelatihan/upload",{"id":training},pdf,field="certificate")
    raw_file = file_from(writer.call(link("/app/pelatihan/detail",id=training))[1])
    virtual = detail(writer,pid,"certificates",training,source="training")
    assert file_from(virtual) == raw_file
    linked = rec(writer,pid,"certificates",{**base_cert,"title":"DATA DEMO Metadata Diklat","expires_on":"",
               "training_id":training,"training_version":2})
    assert file_from(detail(writer,pid,"certificates",linked)) == raw_file
    assert get_file(writer,pid,"certificates",linked,raw_file)[1] == pdf
    profile(writer,pid,"certificates",record=training,source="training",expected=404)
    post(writer,"save",other_pid,"certificates",expected=404,**{**base_cert,"expires_on":"","training_id":training})
    review(admin,pid,"certificates",linked,training_version=2)
    writer.post("/app/pelatihan/save",{"id":training,"version":2,**training_fields,"expires_on":""})
    assert b"Menunggu verifikasi" in detail(writer,pid,"certificates",linked)
    assert b"Metadata Diklat" in profile(writer,pid,"certificates",state="unknown")[1]

    decree_data = dict(category_id=master["decrees"],document_no="SK-DEMO-1",title="DATA DEMO Petikan Asal",
                       issuer="DATA DEMO Instansi",decreed_on=PAST,effective_on=DAY,parent_reference="DATA DEMO Induk")
    decree = rec(writer,pid,"decrees",decree_data)
    save_edit(writer,pid,"decrees",decree,{**decree_data,"title":"DATA DEMO Petikan Diperbarui"})
    review(admin,pid,"decrees",decree)
    replacement = rec(writer,pid,"decrees",{**decree_data,"document_no":"SK-DEMO-2","title":"DATA DEMO Pengganti"})
    review(admin,pid,"decrees",replacement)
    post(admin,"decree",pid,"decrees",record=decree,version=3,document_status="replaced",
         replacement=replacement,reason="DATA DEMO Penggantian resmi")
    assert b"petikan pengganti" in detail(writer,pid,"decrees",decree)
    post(admin,"decree",pid,"decrees",record=replacement,version=2,document_status="revoked",reason="DATA DEMO Cabut")
    assert b"Dicabut" in detail(writer,pid,"decrees",replacement)
    assert b"DATA DEMO Jabatan Asal" in profile(writer,pid)[1]
    assert b"SK-DEMO-1" in profile(admin,pid,"decrees",q="SK-DEMO-1",year=DATE.year,category=master["decrees"],format="csv")[1]

    diploma_data = dict(category_id=master["diplomas"],document_no="DIPL-DEMO-1",institution="DATA DEMO Kampus A",
                        study_program="DATA DEMO Program",degree_title="DATA DEMO Gelar",graduation_year=DATE.year,
                        graduated_on=DAY,issued_on=DAY)
    diploma = rec(writer,pid,"diplomas",diploma_data)
    save_edit(writer,pid,"diplomas",diploma,{**diploma_data,"legalization_note":"DATA DEMO Bukti"})
    upload(writer,API+"/upload",{"personnel":pid,"tab":"diplomas","record":diploma,"version":2,"purpose":"transcript"},pdf)
    review(admin,pid,"diplomas",diploma,expected=422)
    upload(writer,API+"/upload",{"personnel":pid,"tab":"diplomas","record":diploma,"version":3,"purpose":"legalization"},pdf,
           filename="data-demo-legalisasi.pdf")
    review(admin,pid,"diplomas",diploma)
    diploma_file = file_from(detail(writer,pid,"diplomas",diploma))

    general_data = dict(category_id=master["general"],institution="DATA DEMO Universitas Terverifikasi",
                        study_program="DATA DEMO Jurusan",start_year=DATE.year-2,end_year=DATE.year,
                        graduated_on=DAY,education_status="graduated",diploma_id=diploma)
    general = rec(writer,pid,"general",general_data)
    save_edit(writer,pid,"general",general,{**general_data,"faculty":"DATA DEMO Fakultas"})
    review(admin,pid,"general",general)
    assert file_from(detail(writer,pid,"general",general)) == diploma_file
    assert b"Dikum" in detail(writer,pid,"diplomas",diploma)
    get_file(writer,pid,"general",general,diploma_file,404)
    higher = rec(writer,pid,"general",{**general_data,"category_id":master["higher"],"institution":"DATA DEMO Studi Berjalan",
                  "education_status":"ongoing","graduated_on":"","diploma_id":"","end_year":""})
    review(admin,pid,"general",higher)
    summary = profile(writer,pid,"general")[1].split(b'<form class="filters"')[0]
    assert b"DATA DEMO Universitas Terverifikasi" in summary and b"DATA DEMO Studi Berjalan" not in summary

    mil_data = dict(category_id=master["military"],title="DATA DEMO Pendidikan Militer",institution="DATA DEMO Pusat",
                    cohort="DEMO-A",start_year=DATE.year,end_year=DATE.year,started_on=DAY,ended_on=DAY,
                    education_status="graduated",graduated_on=DAY,certificate_id=certificate)
    military = rec(writer,pid,"military",mil_data)
    save_edit(writer,pid,"military",military,{**mil_data,"qualification":"DATA DEMO Kualifikasi"})
    review(admin,pid,"military",military)
    profile(writer,pid,"general",record=military,expected=404)

    for tab, ident in [("cases",case_id),("certificates",certificate),("decrees",decree),("diplomas",diploma),
                       ("military",military),("general",general)]:
        current = version(detail(admin,pid,tab,ident))
        post(admin,"archive",pid,tab,record=ident,version=current,action="archive")
        assert ident.encode() in profile(admin,pid,tab,archive="archived")[1]
        assert ident.encode() not in profile(admin,pid,tab)[1]
        post(admin,"archive",pid,tab,record=ident,version=current+1,action="restore")
        assert b"Riwayat perubahan" in detail(admin,pid,tab,ident)
        profile(outsider,pid,tab,record=ident,expected=404)

    for index in range(26):
        rec(writer,pid,"certificates",{**base_cert,"title":f"DATA DEMO Halaman {index:02d}","reminder_days":""})
    assert b"Halaman 1 dari 2" in profile(writer,pid,"certificates",q="DATA DEMO Halaman",per=25)[1]
    assert b"Halaman 2 dari 2" in profile(writer,pid,"certificates",q="DATA DEMO Halaman",per=25,page=2)[1]
    admin.post("/app/mutasi/process",{})
    admin.post("/app/mutasi/process",{})
    inbox = writer.call("/app/notifikasi")[1]
    assert inbox.count(b"Ada sertifikat personel yang mendekati tanggal akhir.") == 1

    grant(OP,"-")
    profile(writer,pid,"cases",record=case_id,expected=404)
    get_file(writer,pid,"cases",case_id,case_file,404)
    assert b">Kasus<" not in profile(writer,pid)[1]
    print(f"Personel: enam tab, CRUD, verifikasi, arsip, relasi, dokumen, ekspor, pengingat dan scope lulus. Profil uji {pid}")


if __name__ == "__main__":
    main()
