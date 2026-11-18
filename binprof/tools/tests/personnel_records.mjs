import assert from "node:assert/strict";
import { createRequire } from "node:module";

const {chromium} = createRequire(import.meta.url)("playwright");
const origin = process.env.BINPROF_TEST_URL ?? "http://127.0.0.1:18484";
if (!["127.0.0.1","localhost"].includes(new URL(origin).hostname)) throw new Error("Gunakan server uji lokal.");
const browser = await chromium.launch({
    executablePath: process.env.BINPROF_CHROMIUM ?? "/usr/bin/chromium",
    args: ["--no-sandbox","--disable-dev-shm-usage"],
});
const password = process.env.BINPROF_TEST_PASSWORD ?? "Synthetic-local-governance-2026";

async function openProfile(page) {
    await page.goto(origin + "/login");
    await page.getByLabel("Email",{exact:true}).fill("admin@example.invalid");
    await page.getByLabel("Kata sandi",{exact:true}).fill(password);
    await page.getByRole("button",{name:"Masuk",exact:true}).click();
    await page.waitForURL("**/app/dashboard");
    await page.goto(origin + "/app/personel?q=PROFILE-DEMO-001");
    await page.getByRole("link",{name:"Detail",exact:true}).click();
    await page.getByRole("heading",{name:"DATA DEMO Profil Enam Tab"}).waitFor();
}

try {
    const labels = ["Info dasar","Kontak","Riwayat kedinasan","Penghargaan","Karya","Kasus","Sertifikat","Petikan","Ijazah","Dikmil","Dikum"];
    for (const width of [1366,390]) {
        const mobile = width < 780;
        const context = await browser.newContext({viewport:{width,height:850},isMobile:mobile,hasTouch:mobile});
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        const errors = [];
        page.on("pageerror",error => errors.push(String(error)));
        await openProfile(page);
        const navigation = page.getByRole("navigation",{name:"Bagian profil"});
        assert.deepEqual((await navigation.getByRole("link").allTextContents()).map(text => text.trim()),labels);
        if (mobile) {
            assert.equal(await navigation.evaluate(node => node.scrollWidth > node.clientWidth),true);
        }
        await navigation.getByRole("link",{name:"Dikum",exact:true}).click();
        await page.getByRole("heading",{name:"Pendidikan umum terakhir yang terverifikasi"}).waitFor();
        await page.getByText("DATA DEMO Universitas Terverifikasi",{exact:true}).first().waitFor();
        await navigation.getByRole("link",{name:"Sertifikat",exact:true}).click();
        await page.getByRole("link",{name:"Tambah catatan"}).click();
        await page.getByRole("heading",{name:"Tambah Sertifikat"}).waitFor();
        await page.locator("[data-profile-records] select[name=category_id]").selectOption({label:"DATA DEMO Jenis Sertifikat"});
        await page.getByLabel("Nama sertifikat",{exact:true}).fill("DATA DEMO Browser " + width);
        await page.locator("[data-profile-records] select[name=validity_kind]").selectOption("unknown");
        await page.getByRole("button",{name:"Simpan",exact:true}).click();
        await page.getByRole("heading",{name:"Detail Sertifikat"}).waitFor();
        assert.match(await page.locator("[data-profile-records]").textContent(),new RegExp("DATA DEMO Browser " + width));
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),true);
        assert.deepEqual(errors,[]);
        await context.close();
    }
    console.log("Personel Chromium: 11 tab, scroll mobile, ringkasan Dikum, formulir Sertifikat dan status simpan lulus pada 1366 px dan 390 px.");
} finally {
    await browser.close();
}
