import assert from "node:assert/strict";
import {createRequire} from "node:module";

const require = createRequire(import.meta.url);
const {chromium} = require("playwright");
const base = process.env.BINPROF_TEST_URL ?? "http://127.0.0.1:18484";
if (!["localhost","127.0.0.1"].includes(new URL(base).hostname)) throw new Error("Gunakan fixture uji lokal.");
const password = process.env.BINPROF_TEST_PASSWORD ?? "Synthetic-local-governance-2026";
const browser = await chromium.launch({
    executablePath:process.env.BINPROF_CHROMIUM ?? "/usr/bin/chromium",
    args:["--no-sandbox","--disable-dev-shm-usage","--enable-unsafe-swiftshader","--use-gl=angle","--use-angle=swiftshader"],
});
const errors = [], outgoing = [];

async function leader(options) {
    const context = await browser.newContext(options);
    await context.route("**/*",route => {
        if (new URL(route.request().url()).origin === base) return route.continue();
        outgoing.push(route.request().url());
        return route.abort();
    });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    page.on("pageerror",error => errors.push(error.message));
    await page.goto(base + "/login");
    await page.getByLabel("Email",{exact:true}).fill("executive-leader@example.invalid");
    await page.getByLabel("Kata sandi",{exact:true}).fill(password);
    await page.getByRole("button",{name:"Masuk",exact:true}).click();
    await page.waitForURL("**/app/peta-kendali");
    await page.locator("[data-exec-map].leaflet-container").waitFor();
    await page.locator("[data-exec-sync]").getByText("Terhubung",{exact:false}).waitFor();
    return page;
}

try {
    const desktop = await leader({viewport:{width:1440,height:900}});
    const mobile = await leader({viewport:{width:360,height:780},hasTouch:true,isMobile:true,reducedMotion:"reduce"});
    assert.equal(await desktop.locator("[data-kpi]").count(),6);
    const candidate = "DATA DEMO Fasilitas 0";
    await desktop.locator("[data-exec-table]").getByRole("button",{name:candidate}).click();
    await desktop.locator("[data-exec-detail]").getByRole("heading",{name:candidate}).waitFor();
    await desktop.locator("[data-exec-detail]").getByText("Dasar penilaian, sumber, dan versi").click();
    await desktop.locator("[data-exec-detail]").getByText("DEMO-V1",{exact:true}).first().waitFor();
    await desktop.locator("[data-exec-detail]").getByRole("button",{name:"Tambahkan ke perbandingan"}).click();
    await desktop.locator("[data-fullscreen]").click();
    await desktop.waitForFunction(() => document.fullscreenElement?.matches("[data-exec]"));
    await desktop.getByRole("button",{name:"Bandingkan (1/4)",exact:true}).click();
    await desktop.locator("[data-exec-comparison]").getByText(candidate).waitFor();
    await desktop.locator("[data-exec-comparison]").getByRole("button",{name:"Tutup",exact:true}).click();
    await desktop.locator("[data-exec-meeting]").click();
    assert.equal(await desktop.locator("[data-exec-detail]").getByRole("link",{name:"Personel",exact:true}).isVisible(),false);
    assert.equal(await desktop.locator("[data-exec-priorities] li").count() > 0,true);
    await desktop.locator("[data-exec-meeting]").click();
    await desktop.locator("[data-fullscreen]").click();
    await desktop.waitForFunction(() => !document.fullscreenElement);

    await desktop.getByRole("button",{name:"Kebutuhan Tenaga",exact:true}).click();
    await desktop.waitForURL(url => url.searchParams.get("layer") === "staffing");
    await desktop.getByRole("button",{name:"Pensiun 12 Bulan",exact:true}).click();
    await desktop.waitForURL(url => url.searchParams.get("mode") === "projection");
    await desktop.locator("[data-kpi=near]").click();
    await desktop.waitForURL(url => url.searchParams.get("metric") === "near");
    assert.equal(await desktop.locator("[data-exec-table] tbody tr").count(),1);
    await desktop.locator("[data-exec-reset]").click();
    await desktop.waitForURL(url => url.searchParams.get("mode") === "now" && url.searchParams.get("layer") === "situation");
    await desktop.getByRole("button",{name:"Putar preset",exact:true}).click();
    assert.equal(await desktop.locator("[data-exec-rotate]").getAttribute("aria-pressed"),"true");
    await desktop.locator("input[name=q]").click();
    assert.equal(await desktop.locator("[data-exec-rotate]").getAttribute("aria-pressed"),"false");
    await desktop.locator("[data-exec-globe]").click();
    await desktop.locator("[data-exec-scene] canvas").waitFor();
    await desktop.locator("[data-exec-globe]").click();
    assert.equal(await desktop.locator("[data-exec-scene] canvas").count(),0);

    assert.equal(await mobile.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true);
    await mobile.evaluate(() => { HTMLElement.prototype.requestFullscreen = () => Promise.reject(new Error("uji fallback")); });
    await mobile.locator("[data-fullscreen]").click();
    await mobile.locator("[data-exec].is-expanded").waitFor();
    assert.equal(await mobile.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true);
    await mobile.keyboard.press("Escape");
    await mobile.locator("[data-exec].is-expanded").waitFor({state:"detached"});
    await mobile.evaluate(() => {
        const native = HTMLCanvasElement.prototype.getContext;
        HTMLCanvasElement.prototype.getContext = function(type,...args) {
            return type === "webgl2" ? null : native.call(this,type,...args);
        };
    });
    await mobile.locator("[data-exec-globe]").click();
    await mobile.locator("[data-exec-status]").getByText("Globe 3D tidak tersedia",{exact:false}).waitFor();
    const delta = mobile.waitForResponse(response => new URL(response.url()).searchParams.has("after") && response.status()===200);
    await mobile.evaluate(() => window.dispatchEvent(new Event("online")));
    assert.equal((await (await delta).json()).changed,false);
    await mobile.evaluate(() => window.dispatchEvent(new Event("offline")));
    await mobile.locator("[data-exec-sync]").getByText("Luring",{exact:false}).waitFor();

    const pdf = await desktop.request.get(base + "/app/peta-kendali/pdf?layer=retirement");
    assert.equal(pdf.headers()["content-type"],"application/pdf");
    assert.match(pdf.headers()["cache-control"],/no-store/);
    assert.equal((await pdf.body()).subarray(0,5).toString(),"%PDF-");
    assert.match((await pdf.body()).toString("latin1"),/\/Count 1\b/);

    await mobile.locator("[data-fullscreen]").click();
    const csrf = await mobile.locator("[data-exec]").getAttribute("data-csrf");
    await mobile.request.post(base + "/logout",{form:{csrf},headers:{Origin:base},maxRedirects:0});
    await mobile.evaluate(() => window.dispatchEvent(new Event("online")));
    await mobile.locator("[data-exec-expired]").waitFor();
    assert.equal(await mobile.locator("[data-exec-content] *").count(),0);
    assert.equal(await mobile.locator("[data-initial]").count(),0);
    assert.deepEqual(outgoing,[]);
    assert.deepEqual(errors,[]);
    console.log("Peta Kendali Chromium: desktop/mobile, fullscreen/fallback, dialog, mode rapat, preset/KPI, Three.js/fallback, delta, PDF dan pembersihan sesi lulus.");
} finally {
    await browser.close();
}
