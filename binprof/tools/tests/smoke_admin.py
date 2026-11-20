import urllib.error
import urllib.parse
import urllib.request
import http.cookiejar
import json
import os
import re
from datetime import datetime
from zoneinfo import ZoneInfo

BASE = os.environ.get("BINPROF_TEST_URL", "http://127.0.0.1:18484")
if urllib.parse.urlsplit(BASE).hostname not in {"127.0.0.1", "localhost"}:
    raise RuntimeError("Gunakan database dan server uji lokal.")
PW = os.environ.get("BINPROF_TEST_PASSWORD", "Synthetic-local-governance-2026")

class SkipRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(SkipRedirect(), urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = None
    def call(self, path, data=None, status=200, origin=BASE, refresh=True, content_type=None):
        if isinstance(data, dict):
            data = urllib.parse.urlencode(data, doseq=True).encode()
        headers = {"Origin": origin}
        if content_type:
            headers["Content-Type"] = content_type
        req = urllib.request.Request(BASE+path, data, headers)
        try:
            response = self.opener.open(req, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        raw = response.read()
        assert response.code == status, (path, status, response.code, re.findall(rb'<p>(.*?)</p>',raw)[-4:])
        if refresh:
            m = re.search(rb'name="csrf" value="([a-f0-9]{64})"', raw)
            if m:
                self.csrf = m[1].decode()
        return response.headers, raw
    def post(self, path, data, status=303, **kwargs):
        return self.call(path, {"csrf": self.csrf or "", **data}, status, **kwargs)
    def login(self, email, password=PW):
        self.call('/login')
        self.post('/login', {'email':email, 'password':password})
        self.call('/app/dashboard')

def location_id(headers):
    return urllib.parse.parse_qs(urllib.parse.urlsplit(headers['Location']).query)['id'][0]

if __name__ == "__main__":
    a = Client()
    assert json.loads(a.call('/ready')[1]) == {'status':'ready'}
    a.call('/setup', status=404)
    a.call('/api/facilities', status=401)
    a.call('/login')
    a.post('/login', {'csrf':'invalid', 'email':'admin@example.invalid','password':PW}, 403)
    a.post('/login', {'email':'admin@example.invalid','password':PW}, 403, origin='http://other.example.invalid')
    a.post('/login', {'email':'admin@example.invalid','password':'incorrect-value'}, 401)
    a.login('admin@example.invalid')
    for path in ['/app/personel','/app/pelatihan','/app/peta','/app/peta-sebaran','/app/users','/app/master','/app/log','/app/mutasi','/app/notifikasi']:
        a.call(path)
    a.call('/app/personel?sort=bad', status=422)
    a.call('/private/database/binprofkes.sql', status=403)
    a.call('/assets/data/land-110m.geojson')
    a.call('/assets/vendor/three/three.module.min.js')
    a.call('/index.php?r=/app/personel')
    a.call('/index.php?r%5B%5D=/app/personel', status=404)

    for code in ('demo-a','demo-b'):
        a.post('/app/master/save',dict(kind='units', code=code, name='DATA DEMO '+code,active=1))
    a.post('/app/master/save',dict(kind='training_types',code='demo-course', name='DATA DEMO Pelatihan',active=1))
    ranks, rank_ids = {}, {}
    for suffix in ('a','b'):
        ranks[suffix] = dict(kind='ranks',code='demo-rank-'+suffix,name='DATA DEMO Pangkat '+suffix,group_name='DATA DEMO')
        a.post('/app/master/save', {**ranks[suffix], 'active':1})
    master_ranks = a.call('/app/master?kind=ranks')[1]
    for suffix, rank in ranks.items():
        # Use the id inserted in the edit link for this exact master row.
        row = next(row for row in re.findall(rb'<tr>.*?</tr>',master_ranks,re.S) if rank['code'].encode() in row)
        rank_ids[suffix] = int(re.search(rb'id=(\d+)',row)[1])
    unit_types = {name:int(key) for key,name in re.findall(r'<option value="(\d+)">([^<]+)</option>', a.call('/app/master?kind=facilities')[1].decode())}
    for key, label in enumerate(('DATA DEMO demo-a','DATA DEMO demo-b')):
        a.post('/app/master/save', dict(kind='facilities',code='demo-facility-'+str(key),name='DATA DEMO Fasilitas '+str(key),active=1,
            unit_id=unit_types[label],type_id=unit_types['Lanud'],latitude=-2-key,longitude=118+key))
    a.call('/app/users')
    a.post('/app/users/save',dict(name='DATA DEMO Operator',email='operator@example.invalid',role='operator',active=1,password=PW,**{'units[]':[1]}))
    a.post('/app/users/save',dict(name='DATA DEMO Viewer',email='viewer@example.invalid',role='viewer',active=1,password=PW,**{'units[]':[1]}))
    today=datetime.now(ZoneInfo('Asia/Jakarta')).date().isoformat()
    people=[]
    for index,name in enumerate(('DATA DEMO <em>Personel A</em>', 'DATA DEMO Personel B'),1):
        people.append(location_id(a.post('/app/personel/save', dict(name=name,nrp='DEMO-'+str(index),unit_id=index,facility_id=index,
            profession_id=1,rank_id=rank_ids['a'],active=1,bp='unknown',contact='555010'+str(index),
            nik='000000000000000'+str(index),promotion_date=today))[0]))
    personnel = a.call('/app/personel')[1]
    assert b'&lt;em&gt;Personel A&lt;/em&gt;' in personnel and b'<em>Personel A</em>' not in personnel
    assert b'5550101' in a.call('/app/personel/detail?id='+people[0]+'&tab=contact')[1]
    for suffix, rank in ranks.items():
        a.post('/app/master/save', {**rank, 'id':rank_ids[suffix], 'active':0})
    edit = a.call('/app/personel/edit?id='+people[0])[1]
    assert re.search(rb'<option value="' + str(rank_ids['a']).encode() + rb'" selected>DATA DEMO Pangkat a \(nonaktif\)',edit)
    assert b'DATA DEMO Pangkat b' not in edit
    version = int(re.search(rb'name="version" value="(\d+)"',edit)[1])
    update = dict(id=people[0],version=version,name='DATA DEMO <em>Personel A</em>',nrp='DEMO-1',facility_id=1,
        profession_id=1,active=1,bp='unknown',contact='5550101',nik='0000000000000001',promotion_date=today,position_title='DATA DEMO Jabatan')
    a.post('/app/personel/save', {**update, 'rank_id':rank_ids['b']}, 422)
    a.post('/app/personel/save', {**update, 'rank_id':rank_ids['a']})
    assert b'DATA DEMO Pangkat a' in a.call('/app/personel/detail?id='+people[0])[1]
    a.post('/app/personel/status',dict(id=people[0],version=999,action='archive'),409)
    a.post('/app/pelatihan/save', dict(personnel_id=people[0],type_id=1,organizer='test',completed=1,started_on='2022-02-28',ended_on='2022-02-20'),422)
    training=location_id(a.post('/app/pelatihan/save', dict(personnel_id=people[0],type_id=1,organizer='=SYNTHETIC',completed=1,expires_on=today))[0])
    assert b"'=SYNTHETIC" in a.call('/app/pelatihan?format=csv')[1]
    assert a.call('/app/pelatihan?format=xlsx')[1].startswith(b'PK')
    pdf = a.call('/app/pelatihan?format=pdf')[1]
    assert pdf.startswith(b'%PDF')
    def upload(c, filename, content, expected):
        boundary = b"BINPROF-SYNTHETIC-BOUNDARY"
        form = b""
        for key, val in (("csrf", c.csrf), ("id",training)):
            form += b"--" + boundary + (f'\r\nContent-Disposition: form-data; name="{key}"\r\n\r\n{val}\r\n').encode()
        form += b"--" + boundary + (f'\r\nContent-Disposition: form-data; name="certificate"; filename="{filename}"\r\nContent-Type: application/pdf\r\n\r\n').encode()
        form += content + b"\r\n--" + boundary + b"--\r\n"
        c.call('/app/pelatihan/upload',form,expected,content_type="multipart/form-data; boundary=" + boundary.decode())
    upload(a,"surat.pdf",b"<?php synthetic fixture ?>",422)
    upload(a,"demo.php.pdf",pdf,422)
    upload(a,"surat.pdf",pdf,303)
    attachment = re.search(rb'file=([a-f0-9]{32})', a.call('/app/pelatihan/detail?id='+training)[1])[1].decode()
    file_url = '/app/pelatihan/file?id='+training+'&file='+attachment
    assert a.call(file_url)[1] == pdf
    a.call('/app/pelatihan/detail?id='+training)
    b = Client()
    b.login('operator@example.invalid')
    assert b'Personel B' not in b.call('/app/personel')[1]
    b.call('/app/personel/detail?id='+people[1], status=404)
    b.post('/app/personel/status',dict(id=people[1],version=1,action='archive'),404)
    b.call('/app/personel?format=csv',status=403)
    assert len(json.loads(b.call('/api/facilities')[1])) == 1
    v = Client()
    v.login('viewer@example.invalid')
    v.call('/app/personel',status=403)
    assert b'NRP' not in v.call('/api/facilities')[1]
    b.post('/app/mutasi/request',dict(id=people[0],unit_id=2,facility_id=2,effective_on=today))
    got=a.call('/app/mutasi')[1]
    ids=re.findall(rb'name="id" value="([a-f0-9]{32})"',got)
    assert ids
    a.post('/app/mutasi/decide',dict(id=ids[0].decode(),decision='approved'))
    b.call('/app/personel/detail?id='+people[0],status=404)
    b.call('/app/pelatihan/detail?id='+training,status=404)
    b.call(file_url,status=404)
    a.post('/app/mutasi/process',{})
    a.post('/app/mutasi/process',{})
    a.post('/logout',{})
    a.call('/app/personel',status=303)
    print('Smoke admin: lulus')
