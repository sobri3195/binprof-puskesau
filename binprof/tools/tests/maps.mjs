import assert from "node:assert/strict";
import { createRequire } from "node:module";

const require = createRequire(import.meta.url);
const {chromium} = require("playwright");
const base = process.env.BINPROF_TEST_URL ?? "http://127.0.0.1:18484";
if (!["localhost","127.0.0.1"].includes(new URL(base).hostname)) throw new Error("Gunakan data uji lokal.");
const password = process.env.BINPROF_TEST_PASSWORD ?? "Synthetic-local-governance-2026";
const browser = await chromium.launch({
    executablePath: process.env.BINPROF_CHROMIUM ?? "/usr/bin/chromium",
    headless: true, args: ["--no-sandbox","--disable-dev-shm-usage","--enable-unsafe-swiftshader","--use-gl=angle","--use-angle=swiftshader"],
});
const errors = [];
const external = [];

async function account(email, options) {
    const context = await browser.newContext(options);
    await context.route("**/*", route => {
        if (new URL(route.request().url()).origin === base) return route.continue();
        external.push(route.request().url());
        return route.abort();
    });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    page.on("pageerror",error => errors.push(String(error)));
    await page.goto(base + "/login", {waitUntil:"domcontentloaded"});
    await page.getByLabel("Email",{exact:true}).fill(email);
    await page.getByLabel("Kata sandi",{exact:true}).fill(password);
    await page.getByRole("button",{name:"Masuk",exact:true}).click();
    await page.waitForURL("**/app/dashboard");
    await page.goto(base + "/app/peta-sebaran");
    await page.waitForFunction(() => document.querySelector("[data-connect]").textContent.includes("Terhubung"));
    return page;
}

async function json(page,path,expected=200,body,csrf=true) {
    let response;
    if (body) {
        const token = await page.locator("[data-atlas]").getAttribute("data-csrf");
        response = await page.request.post(base + path,{
            headers:{Origin:base},form:csrf ? {...body,csrf:token} : body,
        });
    } else response = await page.request.get(base+path);
    assert.equal(response.status(),expected,path + " " + await response.text());
    return response.json();
}

async function eventually(check, message) {
    for (let index=0; index<50; index++) {
        if (await check()) return;
        await new Promise(resolve => setTimeout(resolve,100));
    }
    throw new Error(message);
}

try {
    const admin = await account("admin@example.invalid",{viewport:{width:1366,height:900}});
    const mobile = await account("viewer@example.invalid",{viewport:{width:390,height:844},isMobile:true,hasTouch:true,reducedMotion:"reduce"});
    const scoped = await account("operator@example.invalid",{viewport:{width:1050,height:800}});

    const all = await json(admin,"/api/maps/markers");
    assert.ok(all.length >= 5,"Fixture minimal lima fasilitas");
    assert.equal((await json(mobile,"/api/maps/markers")).some(row => row.id === 2),false);
    await json(scoped,"/api/maps/detail?facility=2",404);
    await json(scoped,"/api/maps/personnel?facility=2",404);
    await json(mobile,"/api/maps/personnel?facility=1",403);
    assert.equal((await mobile.request.get(base + "/app/peta?format=csv")).status(),403);
    const ownAggregate = await json(mobile,"/api/maps/detail?facility=1");
    assert.equal(ownAggregate.personnel_allowed,false);
    assert.equal(JSON.stringify(ownAggregate).includes("Personel A"),false);
    assert.ok((await json(scoped,"/api/maps/personnel?facility=1")).rows.length > 0);
    assert.equal((await json(admin,"/api/maps/detail?facility=3")).total,null);
    assert.equal((await json(admin,"/api/maps/detail?facility=3")).training,null);
    assert.equal((await json(admin,"/api/maps/markers?unit_id=1&command_id=1&type_id=1&profession_id=1&specialty_id=1")).length,1);
    assert.equal((await json(admin,"/api/maps/markers?unit_id=1&profession_id=3&specialty_id=1")).length,0);
    const exportResponse = await admin.request.get(base + "/app/peta?format=csv&unit_id=1&profession_id=1&specialty_id=1");
    assert.equal(exportResponse.status(),200);
    assert.ok((await exportResponse.text()).includes("DATA DEMO Fasilitas 0"));

    await admin.locator("[data-map] .marker-cluster").first().waitFor();
    await admin.locator("[data-fullscreen]").click();
    await admin.waitForFunction(() => document.fullscreenElement?.hasAttribute("data-atlas"));
    await admin.locator("[data-atlas]").getByLabel("Nama atau kode fasilitas").fill("Fasilitas 1");
    await admin.locator("[data-atlas]").getByRole("button",{name:"Terapkan"}).click();
    await admin.waitForFunction(() => document.querySelector("[data-result]").textContent.startsWith("1 fasilitas"));
    assert.equal(await admin.locator("[data-table] tbody tr").count(),1);
    assert.equal(await admin.locator("[data-map] .atlas-pin").count(),1);
    await admin.locator("[data-map] .atlas-pin").click();
    await admin.locator("[data-detail]").getByRole("heading",{name:"DATA DEMO Fasilitas 1",exact:true}).waitFor();
    await admin.locator("[data-detail]").getByRole("button",{name:"Muat daftar personel"}).click();
    await admin.locator("[data-detail]").getByText("DATA DEMO Personel B",{exact:true}).waitFor();
    await admin.locator("[data-map]").click({position:{x:65,y:85}});
    await admin.locator("[data-near]").getByText("Jarak garis lurus, bukan rute perjalanan.").waitFor();
    await admin.locator("[data-radius]").selectOption("1000");
    assert.match(await admin.locator("[data-coordinate]").textContent(),/Koordinat: -?\d+\.\d+/);
    assert.match(await admin.locator("[data-near-results]").textContent(),/km · garis lurus/);
    await admin.locator("[data-fullscreen]").click();
    await admin.waitForFunction(() => !document.fullscreenElement);

    await mobile.evaluate(() => {
        HTMLElement.prototype.requestFullscreen = () => Promise.reject(new Error("Diblokir oleh pengujian"));
    });
    await mobile.locator("[data-fullscreen]").click();
    await mobile.locator("[data-atlas].is-expanded").waitFor();
    await mobile.locator("[data-show-events]").click();
    await mobile.waitForFunction(() => document.querySelector("[data-events-panel]").open);
    assert.equal(await mobile.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true);
    await mobile.keyboard.press("Escape");
    await mobile.locator("[data-atlas].is-expanded").waitFor({state:"detached"});
    await mobile.setViewportSize({width:360,height:780});
    assert.equal(await mobile.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true);

    await admin.locator("[data-theme]").selectOption("personnel");
    await admin.getByText("Tampilan tersimpan",{exact:true}).click();
    await admin.locator("[data-save-view]").getByLabel("Nama tampilan").fill("DATA DEMO Tampilan");
    await admin.locator("[data-save-view]").getByRole("button").click();
    await admin.locator("[data-saved]").getByRole("button",{name:"DATA DEMO Tampilan"}).waitFor();
    const views = await json(admin,"/api/maps/views");
    assert.equal(views[0].view.theme,"personnel");
    assert.equal((await json(mobile,"/api/maps/views")).length,0);
    await json(mobile,"/api/maps/views",404,{action:"delete",id:views[0].id});
    await admin.locator("[data-reset]").click();
    await admin.waitForFunction(() => document.querySelectorAll("[data-table] tbody tr").length >= 5);
    for (const row of all.slice(0,5)) {
        await admin.locator("[data-table]").getByRole("button",{name:row.name,exact:true}).click();
        await admin.locator("[data-detail]").getByRole("button",{name:"Bandingkan",exact:true}).click();
    }
    assert.equal(await admin.locator("[data-compare] tbody tr").count(),4);
    await admin.locator("[data-status]").getByText("Perbandingan dibatasi empat fasilitas.").waitFor();

    await admin.locator("[data-view=globe]").click();
    await admin.locator("[data-globe] canvas").waitFor();
    await admin.locator("[data-view=map]").click();
    assert.equal(await admin.locator("[data-globe] canvas").count(),0);
    await mobile.evaluate(() => {
        const original = HTMLCanvasElement.prototype.getContext;
        HTMLCanvasElement.prototype.getContext = function(type,...args) {
            return type === "webgl2" ? null : original.call(this,type,...args);
        };
    });
    await mobile.locator("[data-view=globe]").click();
    await mobile.locator("[data-status]").getByText("WebGL2 tidak tersedia.",{exact:false}).waitFor();

    if (await mobile.locator("[data-atlas]").getAttribute("data-retirement") === "1") {
        await mobile.locator("[data-theme]").selectOption("retirement");
        await mobile.locator("[data-filters] select[name=retirement_months]").selectOption("3");
        await mobile.getByRole("button",{name:"Terapkan",exact:true}).click();
        await mobile.waitForURL(url => url.searchParams.get("retirement_months") === "3");
        await mobile.locator("[data-legend]").getByText("3 bulan",{exact:true}).waitFor();
        await mobile.locator("[data-table]").getByRole("button",{name:"DATA DEMO Fasilitas 0",exact:true}).click();
        const retirement = mobile.locator(".atlas-retirement");
        await retirement.getByRole("heading",{name:"Proyeksi tenaga hingga 24 bulan"}).waitFor();
        assert.equal(await retirement.locator("tbody tr").count(),4);
        assert.match(await retirement.textContent(),/target terverifikasi belum tersedia/);
        assert.equal(await retirement.getByRole("link",{name:"Buka tindak lanjut pensiun"}).count(),0);
        assert.equal(await mobile.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true);
        await mobile.locator("[data-detail]").getByRole("button",{name:"Bandingkan",exact:true}).click();
        await mobile.locator("[data-compare]").getByRole("columnheader",{name:"Keputusan dijadwalkan"}).waitFor();
        await mobile.locator("[data-reset]").click();
        await mobile.waitForURL(url => url.searchParams.get("retirement_months") === "12");
        assert.equal(await mobile.locator("[data-theme]").inputValue(),"category");

        await scoped.goto(base + "/app/pensiun");
        await scoped.getByRole("heading",{name:"Daftar pensiun"}).waitFor();
        assert.ok(await scoped.locator('select[name=months]').locator('option[value="24"]').count());
        if (process.env.BINPROF_RETIREMENT_FIXTURE === "1") {
            await scoped.setViewportSize({width:360,height:780});
            await scoped.locator('input[name=q]').fill("RET-DEMO-HARIINI");
            await scoped.locator('select[name=group]').selectOption("effective");
            await scoped.getByRole("button",{name:"Terapkan",exact:true}).click();
            await scoped.getByRole("link",{name:"Buka",exact:true}).click();
            await scoped.getByRole("heading",{name:"Pensiun/Purnawirawan",exact:true}).waitFor();
            await scoped.getByText("Purnawirawan",{exact:true}).waitFor();
            assert.equal(await scoped.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true);
            await scoped.setViewportSize({width:1050,height:800});
        }
        await scoped.goto(base + "/app/peta-sebaran");
        console.log("Pensiun Chromium: layer, periode, timeline, perbandingan, scope agregat dan roster mobile lulus.");
    }

    const baseline = await json(mobile,"/api/maps/events");
    const create = {action:"create",facility:1,category:"operations",severity:"warning"};
    await json(admin,"/api/maps/events",403,create,false);
    await json(mobile,"/api/maps/events",403,create);
    const simultaneous = await Promise.all([json(admin,"/api/maps/events",200,create),json(admin,"/api/maps/events",200,create)]);
    assert.equal(simultaneous.filter(outcome => outcome.changed).length,1);
    const id = simultaneous[0].id;
    assert.equal(simultaneous[1].id,id);
    const deltaUrl = "/api/maps/events?after=" + baseline.revision + "&personal=" + baseline.personal;
    const delta = await json(mobile,deltaUrl);
    assert.equal(delta.reset,false);
    assert.deepEqual(delta.events.map(row => row.id),[id]);
    assert.equal(delta.events[0].read_at,null);
    const hidden = await json(admin,"/api/maps/events",200,{...create,facility:2});
    await json(mobile,"/api/maps/events",404,{action:"read",facility:2,event:hidden.id});
    assert.ok(!(await json(mobile,"/api/maps/events")).events.some(row => row.id === hidden.id));
    await mobile.evaluate(() => window.dispatchEvent(new Event("online")));
    await mobile.locator("[data-unread]").getByText("1",{exact:true}).waitFor();
    await mobile.locator("[data-events-panel]").evaluate(panel => { panel.open = true; });
    await mobile.locator("[data-events]").getByRole("button",{name:"Fokus fasilitas"}).click();
    await mobile.locator("[data-detail]").getByRole("heading",{name:"DATA DEMO Fasilitas 0",exact:true}).waitFor();
    await eventually(async () => Boolean((await json(mobile,"/api/maps/events")).events.find(row => row.id===id)?.read_at),"Status baca belum tersimpan");
    await json(scoped,"/api/maps/events",200,{action:"acknowledge",facility:1,event:id});
    await json(scoped,"/api/maps/events",403,{action:"resolve",facility:1,event:id});
    let statuses = (await json(mobile,"/api/maps/events")).events.find(row => row.id===id);
    assert.ok(statuses.read_at && statuses.acknowledged_at && !statuses.resolved_at);
    await json(admin,"/api/maps/events",200,{action:"resolve",facility:1,event:id});
    statuses = (await json(mobile,"/api/maps/events")).events.find(row => row.id===id);
    assert.ok(statuses.read_at && statuses.acknowledged_at && statuses.resolved_at);
    const fresh = await json(mobile,"/api/maps/events");
    const noDelta = await json(mobile,"/api/maps/events?after=" + fresh.revision + "&personal=" + fresh.personal);
    assert.equal(noDelta.changed,false);
    assert.deepEqual(noDelta.events,[]);
    const pref = await json(mobile,"/api/maps/preferences",200,{
        operations:1,data:0,training:1,muted:1,quiet_start:"22:00",quiet_end:"06:00",
    });
    assert.deepEqual([pref.operations,pref.data,pref.muted,pref.quiet_start,pref.quiet_end],[true,false,true,"22:00","06:00"]);
    await json(mobile,"/api/maps/preferences",422,{quiet_start:"20:00"});
    await mobile.context().grantPermissions(["notifications"],{origin:base});
    await mobile.evaluate(() => {
        const permission = Notification.permission;
        window.notices = [];
        window.Notification = class {
            static permission = permission;
            static requestPermission = async () => permission;
            constructor(title,options) { window.notices.push({title,...options}); }
        };
        window.dispatchEvent(new Event("online"));
    });
    const prefs = mobile.locator(".atlas-preferences");
    await prefs.locator("summary").click();
    await prefs.getByRole("button",{name:"Aktifkan notifikasi browser"}).click();
    await prefs.getByRole("button",{name:"Matikan notifikasi browser"}).waitFor();
    const mutedEvent = await json(admin,"/api/maps/events",200,{...create,category:"training"});
    await mobile.evaluate(() => window.dispatchEvent(new Event("online")));
    await mobile.locator("[data-unread]").getByText("1",{exact:true}).waitFor();
    assert.deepEqual(await mobile.evaluate(() => window.notices),[]);
    await json(admin,"/api/maps/events",200,{action:"resolve",facility:1,event:mutedEvent.id});
    await json(mobile,"/api/maps/preferences",200,{operations:1,data:1,training:1,muted:0});
    await mobile.evaluate(() => window.dispatchEvent(new Event("online")));
    await mobile.waitForFunction(() => document.querySelector("[data-unread]").textContent === "");
    await json(admin,"/api/maps/events",200,{...create,category:"data"});
    await mobile.evaluate(() => window.dispatchEvent(new Event("online")));
    await mobile.waitForFunction(() => window.notices.length === 1);
    assert.deepEqual(await mobile.evaluate(() => window.notices[0].body),"Ada kejadian fasilitas baru.");
    await prefs.getByRole("button",{name:"Matikan notifikasi browser"}).click();
    await prefs.getByRole("button",{name:"Aktifkan notifikasi browser"}).waitFor();
    await mobile.evaluate(() => window.dispatchEvent(new Event("offline")));
    await mobile.locator("[data-connect]").getByText("Luring",{exact:false}).waitFor();

    assert.deepEqual(external,[]);
    assert.deepEqual(errors,[]);
    console.log("Maps Chromium: fullscreen, fallback dan mobile, cluster/filter, fokus/radius, 3D/fallback, simpan/bandingkan, delta/kejadian/preferensi dan scope lulus.");
} finally {
    await browser.close();
}
