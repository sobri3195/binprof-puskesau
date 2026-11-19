import assert from "node:assert/strict";
import {createRequire} from "node:module";

const {chromium} = createRequire(import.meta.url)("playwright");
const base = process.env.BINPROF_TEST_URL ?? "http://127.0.0.1:18484";
if (!["localhost","127.0.0.1"].includes(new URL(base).hostname)) throw new Error("Gunakan data uji lokal.");
const password = process.env.BINPROF_TEST_PASSWORD ?? "Synthetic-local-governance-2026";
const browser = await chromium.launch({
    executablePath:process.env.BINPROF_CHROMIUM ?? "/usr/bin/chromium",
    args:["--no-sandbox","--disable-dev-shm-usage"],
});
const errors = [];
const external = [];

async function open(options) {
    const context = await browser.newContext(options);
    await context.route("**/*",route => {
        if (new URL(route.request().url()).origin === base) return route.continue();
        external.push(route.request().url());
        return route.abort();
    });
    const page = await context.newPage();
    page.setDefaultTimeout(10000);
    page.on("pageerror",error => errors.push(String(error)));
    await page.goto(base + "/login");
    return page;
}

async function login(page,email) {
    await page.getByLabel("Email",{exact:true}).fill(email);
    await page.getByLabel("Kata sandi",{exact:true}).fill(password);
    await page.getByRole("button",{name:"Masuk",exact:true}).click();
    await page.waitForURL("**/app/dashboard");
}

async function noOverflow(page) {
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
}

try {
    const admin = await open({viewport:{width:1440,height:900}});
    const field = admin.getByLabel("Kata sandi",{exact:true});
    const toggle = admin.getByRole("button",{name:"Tampilkan kata sandi",exact:true});
    await toggle.click();
    assert.equal(await field.getAttribute("type"),"text");
    assert.equal(await toggle.getAttribute("aria-pressed"),"true");
    await toggle.click();
    assert.equal(await field.getAttribute("type"),"password");
    await admin.getByLabel("Email",{exact:true}).fill("admin@example.invalid");
    await field.fill("wrong-synthetic-password");
    const denied = admin.waitForResponse(response => response.url() === base + "/login" && response.status() === 401);
    await admin.getByRole("button",{name:"Masuk",exact:true}).click();
    await denied;
    await admin.getByRole("alert").waitFor();
    assert.equal(await admin.getByLabel("Email",{exact:true}).inputValue(),"admin@example.invalid");
    assert.equal(await field.inputValue(),"");
    await login(admin,"admin@example.invalid");
    assert.ok(await admin.locator(".session-panel").evaluate(element => element.getBoundingClientRect().bottom <= innerHeight));
    const other = await admin.goto(base + "/app/personel?q=DEMO-FKTP-99");
    assert.equal(other.status(),200);
    const foreignRow = admin.getByRole("row").filter({hasText:"DEMO-FKTP-99"});
    const foreignLink = await foreignRow.getByRole("link",{name:"Detail",exact:true}).getAttribute("href");
    const foreign = new URL(foreignLink,base).searchParams.get("id");

    await admin.getByRole("link",{name:"Peta Kendali Pimpinan",exact:true}).click();
    await admin.locator("[data-exec-map].leaflet-container").waitFor();
    await admin.locator("[data-exec-sync]").getByText("Terhubung",{exact:false}).waitFor();
    assert.equal(await admin.locator("h1").count(),1);
    assert.equal(await admin.locator(".exec-filters").evaluate(element => element.open),false);
    assert.ok(await admin.locator("[data-exec-map]").evaluate(element => element.getBoundingClientRect().top < innerHeight));
    await admin.selectOption("select[name=mode]","history");
    await admin.locator("input[name=date]").waitFor();
    assert.equal(await admin.locator("select[name=months]").isVisible(),false);
    await admin.selectOption("select[name=mode]","projection");
    await admin.locator("select[name=months]").waitFor();
    assert.equal(await admin.locator("input[name=date]").isVisible(),false);
    await admin.getByRole("button",{name:"Kebutuhan Tenaga",exact:true}).click();
    await admin.waitForURL(url => url.searchParams.get("layer") === "staffing");
    assert.equal(await admin.getByRole("button",{name:"Kebutuhan Tenaga",exact:true}).getAttribute("aria-pressed"),"true");
    await noOverflow(admin);

    const operator = await open({viewport:{width:390,height:844},isMobile:true,hasTouch:true,reducedMotion:"reduce"});
    await login(operator,"rsau-operator@example.invalid");
    const nav = operator.locator(".navigation");
    assert.equal(await nav.evaluate(element => element.open),false);
    await nav.locator("summary").click();
    assert.ok(await nav.getByRole("link",{name:"Input Fasilitas",exact:true}).isVisible());
    assert.equal(await nav.getByRole("link",{name:"Pengguna",exact:true}).count(),0);
    await nav.getByRole("link",{name:"Input Fasilitas",exact:true}).focus();
    await operator.keyboard.press("Escape");
    assert.equal(await nav.evaluate(element => element.open),false);
    assert.equal(await operator.evaluate(() => document.activeElement?.tagName),"SUMMARY");
    await noOverflow(operator);
    assert.equal((await operator.request.get(base + "/app/pelatihan/edit?personnel_id=" + foreign)).status(),404);
    await operator.getByRole("link",{name:"Dokumen personel",exact:true}).click();
    await operator.getByRole("row").filter({hasText:"DEMO-RSAU-99"}).getByRole("link",{name:"Detail",exact:true}).click();
    await operator.getByRole("link",{name:"Catat diklat",exact:true}).click();
    await operator.getByText("DATA DEMO Petugas RSAU · DEMO-RSAU-99",{exact:true}).waitFor();
    assert.equal(await operator.locator('input[type=hidden][name=personnel_id]').count(),1);
    assert.equal(await operator.locator('input[name=q]').count(),0);
    await noOverflow(operator);
    assert.deepEqual(external,[]);
    assert.deepEqual(errors,[]);
    console.log("UX Chromium lulus untuk login, navigasi responsif, indikator dan filter, akses berbasis scope, serta diklat dari profil.");
} finally {
    await browser.close();
}
