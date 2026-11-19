import {button,definitions,element,enableFullscreen,loadScript,request,table,timeText,value} from "./atlas-ui.js";

const root = document.querySelector("[data-exec]");
const q = selector => root.querySelector(selector);
const form = q("[data-exec-filter]").closest("form");
const urls = JSON.parse(root.dataset.urls);
const perms = new Set(JSON.parse(root.dataset.permissions));
let model = JSON.parse(root.dataset.initial);
root.removeAttribute("data-initial");
const zone = root.dataset.zone;
const access = model.access;
const facets = model.facets;
const reduce = matchMedia("(prefers-reduced-motion: reduce)");
const choices = ["now","staffing","retirement","certificate","directive"];
const states = {issued:"Diterbitkan",accepted:"Diterima",working:"Dikerjakan",review:"Menunggu verifikasi",done:"Selesai"};
const levels = {green:["Memenuhi kriteria","#1b775e"],yellow:["Perlu perhatian","#b16d0f"],
    red:["Prioritas tinggi","#aa3238"],gray:["Data belum cukup","#6f7d8b"]};
const kinds = {staff:"Distribusi SDM",staffing:"Gap SDM",certificate:"Sertifikat wajib",retirement:"Pensiun terjadwal",
    event:"Gangguan fasilitas",quality:"Mutu data",directive:"Tindak lanjut"};
const compare = new Set();
let filters = model.filters;
let saved = model.presets;
let rows = new Map();
let selected;
let revision;
let poll;
let timeout;
let timer;
let rotation = 0;
let rotated = false;
let failures = 0;
let expired = false;
let inFlight;
let map;
let cluster;
let resize;
let globe;
let sceneRequest = 0;
let showGlobe = false;
let paused = reduce.matches;
const markers = new Map();
const flat = q("[data-exec-map]");
const sphere = q("[data-exec-scene]");
const layerSelect = q("select[name=layer]");
const dialog = q("[data-exec-comparison]");
const styleFor = row => filters.layer === "situation" ? row.condition : (row.assessments[filters.layer]?.level ?? "gray");
const colors = row => levels[styleFor(row)][1];
const point = row => row.latitude === null || row.longitude === null ? null : [Number(row.latitude),Number(row.longitude)];
const id = row => String(row.id);
const keys = [...q("[data-exec-filter]").querySelectorAll("[name]")].map(node => node.name);

function endpoint(path, params = {}) {
    const target = new URL(urls[path], location.origin);
    for (const [key, val] of Object.entries(params)) {
        if (val !== null && val !== "") target.searchParams.set(key,val);
    }
    return target;
}

function action(text, path, query) {
    const link = element("a",text,"button secondary");
    link.href = endpoint(path,query);
    return link;
}

function notice(text) {
    if (expired) return;
    const node = q("[data-exec-status]");
    node.textContent = text ?? "";
    node.hidden = !text;
}

function linkedOptions() {
    const cascade = [
        ["command_id", "command_id", {}],
        ["unit_id", "unit_id", {command_id:form.elements.command_id.value}],
        ["facility_id", "id", {command_id:form.elements.command_id.value,unit_id:form.elements.unit_id.value}],
    ];
    for (const [key, prop, parents] of cascade) {
        const available = new Set(facets.filter(row => Object.entries(parents).every(([name,val]) => !val || String(row[name]) === val))
            .map(row => String(row[prop])));
        const control = form.elements[key];
        for (const option of control.options) option.hidden = option.value !== "" && !available.has(option.value);
        if (control.selectedOptions[0]?.hidden) control.value = "";
    }
}

function readForm() {
    return Object.fromEntries(keys.map(key => [key,form.elements[key].value.trim()]));
}

function showPeriodFields() {
    q("[data-exec-date]").hidden = form.elements.mode.value !== "history";
    q("[data-exec-horizon]").hidden = form.elements.mode.value !== "projection";
}

function updateControls() {
    const count = ["command_id","unit_id","facility_id","type_id","profession_id","specialty_id"]
        .filter(name => filters[name] !== null && filters[name] !== "").length;
    q("[data-exec-filter-status]").textContent = count ? count + " aktif" : "";
    for (const control of root.querySelectorAll("[data-exec-preset]")) {
        const preset = control.dataset.execPreset;
        const layer = preset === "now" ? "situation" : preset;
        const periodMatches = preset === "retirement"
            ? filters.mode === "projection" && Number(filters.months) === 12
            : filters.mode === "now";
        control.setAttribute("aria-pressed",String(layer === filters.layer && periodMatches && filters.metric === "all"));
    }
}

function applyForm(current) {
    for (const key of keys) form.elements[key].value = current[key] ?? "";
    layerSelect.value = current.layer;
    linkedOptions();
    showPeriodFields();
}

function activeCard(metric) {
    load({...filters,metric:filters.metric === metric ? "all" : metric},{activity:true,fit:true});
}

function renderCards() {
    for (const node of root.querySelectorAll("[data-kpi]")) {
        const key = node.dataset.kpi;
        node.setAttribute("aria-pressed",String(filters.metric === key));
        node.classList.toggle("is-unavailable",model.kpi[key] === null);
        node.querySelector("span").textContent = model.labels[key];
        node.querySelector("strong").textContent = model.kpi[key] ?? (key === "shortage" ? "Belum dapat dinilai" : "Belum tersedia");
        node.querySelector("small").textContent = model.covered[key] + " dari " + model.rows.length + " fasilitas dengan data";
    }
}

function renderLists() {
    const list = q("[data-exec-priorities]");
    const items = model.priorities.map(item => {
        const li = element("li","","exec-priority exec-level-" + item.level);
        const heading = button(item.facility + " · " + kinds[item.kind],() => focusFacility(String(item.facility_id),true));
        li.append(heading,element("p",item.reason),element("p",item.action ?? ""),
            element("small",[levels[item.level][0],item.state,"PIC " + (item.pic ?? "belum tercatat"),
                item.due ? "Tenggat " + item.due : null,item.date ? "Acuan " + String(item.date).slice(0,10) : null,
                "read" in item && (item.kind === "directive" || item.kind === "event") ? (item.read ? "Dibaca" : "Belum dibaca") : null].filter(Boolean).join(" · ")));
        return li;
    });
    list.replaceChildren(...(items.length ? items : [element("li","Belum ada prioritas dalam filter ini.")]));
    q("[data-exec-table]").replaceChildren(table(["Fasilitas","Komando / satuan","Kondisi","Personel","Dokter","Perawat","Gap SDM","Pensiun 12 bulan"],
        model.rows.map(row => [button(row.name,() => focusFacility(id(row),true)),[row.command_name,row.unit].filter(Boolean).join(" / "),
            levels[styleFor(row)][0],row.totals?.active,row.totals?.doctors,row.totals?.nurses,row.gap ?? "Belum dapat dinilai",row.near])));
    q("[data-exec-progress]").replaceChildren(definitions(Object.entries(model.progress).map(([key,count]) => [states[key],count])));
    const timeline = q("[data-exec-timeline]");
    timeline.replaceChildren(model.timeline.length ?
        table(["Periode","Sampai","Perkiraan","Keputusan resmi","Proyeksi tenaga aktif","Fasilitas dengan catatan"],
            model.timeline.map(p => [p.months + " bulan",p.through,p.planned,p.scheduled,p.remaining,p.covered])) :
        element("p","Belum ada data periode dalam cakupan ini."));
    timeline.append(element("p","Personel aktif tercatat dikurangi keputusan resmi yang menunggu penerapan. Proyeksi memakai roster, Petikan dan data pensiun; perkiraan belum mengurangi tenaga aktif.","subtle"));
}

function detail(row) {
    const node = q("[data-exec-detail]");
    if (!row) {
        node.replaceChildren(element("h3","Detail fasilitas"),element("p","Pilih fasilitas di peta, prioritas, atau tabel."));
        return;
    }
    node.replaceChildren(element("h3",row.name),element("p",[row.command_name,row.unit,row.kind].filter(Boolean).join(" · "),"subtle"),
        definitions([["Kondisi",levels[styleFor(row)][0]],["Personel tercatat",row.totals?.active],["PIC fasilitas",row.pic],
            ["Kebutuhan tenaga (gap)",row.gap ?? "Belum dapat dinilai"],["Diklat tercatat",row.training?.total],["Diklat selesai",row.training?.completed],
            ["Perkiraan pensiun",row.retirement?.planned],["Keputusan pensiun",row.retirement?.scheduled],
            ["Rencana pengganti",row.replacement ? "Referensi tercatat" : "Belum tercatat"],["Pembaruan sumber",timeText(row.source_at,zone)],...(row.captured_at ? [["Direkam",timeText(row.captured_at,zone)]] : [])]));
    const summaries = element("details","","exec-assessments");
    summaries.append(element("summary","Dasar penilaian, sumber, dan versi"));
    for (const [key, verdict] of Object.entries(row.assessments)) {
        summaries.append(element("h4",kinds[key] + " · " + levels[verdict.level][0]),
            element("p",verdict.reason),
            definitions([["Aktual",verdict.actual],["Target / ambang",verdict.target],["Tanggal sumber",verdict.date],
                ["Sumber",verdict.source],["Versi aturan",verdict.version]]));
    }
    node.append(summaries);
    if (row.target_details.length) {
        node.append(element("h4","Target terverifikasi"),
            table(["Profesi","Aktual","Target","Gap","Sumber","Versi"],row.target_details.map(item =>
                [item.profession,item.actual,item.target,item.gap,item.source,item.version])));
    }
    if (Object.keys(row.distribution).length) {
        node.append(element("h4","Distribusi profesi"),definitions(Object.entries(row.distribution)));
    }
    if (row.events?.length) {
        const events = element("ul","","atlas-list");
        for (const event of row.events) {
            const li = element("li",[({operations:"Operasional",data:"Data",training:"Diklat"})[event.category],
                event.verified_at ? "Terverifikasi" : "Belum diverifikasi",event.acknowledged_at ? "Diakui" : "Belum diakui",
                event.read_at ? "Dibaca" : "Belum dibaca"].join(" · "));
            if (!event.read_at) {
                const read = button("Tandai dibaca",async () => {
                    try {
                        await post("mapEvents",{action:"read",facility:row.id,event:event.id});
                        await load(filters,{activity:true});
                    } catch (error) {
                        notice(error.message);
                    }
                });
                read.classList.add("exec-private");
                li.append(read);
            }
            events.append(li);
        }
        node.append(element("h4","Kejadian aktif"),events);
    }
    node.append(element("h4","Progres arahan"),definitions(Object.entries(row.progress).map(([key,n]) => [states[key],n])));
    const controls = element("div","","actions");
    controls.append(button(compare.has(id(row)) ? "Hapus perbandingan" : "Tambahkan ke perbandingan",() => {
        if (compare.has(id(row))) compare.delete(id(row));
        else if (compare.size === 4) return notice("Pilih maksimal empat fasilitas.");
        else compare.add(id(row));
        renderCompare();
        detail(row);
    }));
    if (filters.mode !== "history") {
        const restricted = element("div","","actions exec-private");
        const scope = {facility:row.id,profession_id:filters.profession_id,specialty_id:filters.specialty_id};
        if (perms.has("personnel.read")) restricted.append(action("Personel","personnel",{...scope,facility_id:row.id,state:"active"}));
        if (perms.has("training.read")) restricted.append(action("Diklat","training",{...scope,facility_id:row.id,active_personnel:1}));
        if (perms.has("retirement.read")) restricted.append(action("Pensiun","retirement",{...scope,months:filters.months}));
        if (perms.has("executive.work")) restricted.append(action(perms.has("executive.issue") ? "Buka / buat arahan" : "Arahan",
            "directives",{facility:row.id}));
        controls.append(restricted);
    }
    node.append(controls);
}

function renderCompare() {
    const rowsForComparison = [...compare].map(key => rows.get(key)).filter(Boolean);
    q("[data-exec-compare]").textContent = "Bandingkan (" + rowsForComparison.length + "/4)";
    q("[data-exec-compare-table]").replaceChildren(table(["Fasilitas","Kondisi","Personel","Gap SDM","Keputusan pensiun","Tindak lanjut"],
        rowsForComparison.map(row => [row.name,levels[styleFor(row)][0],row.totals?.active,row.gap,
            row.retirement?.scheduled, row.progress.working + row.progress.review])));
}

function fitAll() {
    if (showGlobe) return globe?.reset();
    if (!map) return;
    const bounds = cluster.getBounds();
    if (bounds.isValid()) map.fitBounds(bounds,{padding:[24,24],maxZoom:8,animate:false});
    else map.setView([-2.4,118],4,{animate:false});
}

function focusFacility(key,reveal=false) {
    if (!rows.has(key)) return;
    selected = key;
    detail(rows.get(key));
    if (map && markers.has(key) && !showGlobe) {
        const marker = markers.get(key);
        cluster.zoomToShowLayer(marker,() => marker.getElement()?.focus({preventScroll:true}));
    }
    if (reveal) q("[data-exec-detail]").scrollIntoView({block:"nearest",behavior:"instant"});
}

function renderMarkers() {
    if (!cluster) return;
    markers.clear();
    cluster.clearLayers();
    for (const row of rows.values()) {
        if (!point(row)) continue;
        const dot = element("span","","exec-marker");
        dot.style.backgroundColor = colors(row);
        const marker = window.L.marker(point(row),{icon:window.L.divIcon({className:"atlas-pin",html:dot,iconSize:[24,24]}),
            title:row.name + " · " + levels[styleFor(row)][0],alt:row.name,autoPanOnFocus:false});
        marker.on("click",() => focusFacility(id(row)));
        markers.set(id(row),marker);
    }
    cluster.addLayers([...markers.values()]);
}

function switchMap() {
    ++sceneRequest;
    globe?.dispose();
    globe = undefined;
    showGlobe = false;
    sphere.hidden = true;
    flat.hidden = !map;
    q("[data-exec-motion]").hidden = true;
    q("[data-exec-globe]").setAttribute("aria-pressed","false");
    q("[data-exec-globe]").textContent = "Globe 3D";
    requestAnimationFrame(() => map?.invalidateSize());
}

async function switchGlobe() {
    const sequence = ++sceneRequest;
    try {
        const {createGlobe} = await import("./globe.js");
        if (expired || sequence !== sceneRequest) return;
        globe?.dispose();
        sphere.hidden = false;
        globe = createGlobe(sphere,[...rows.values()].filter(point),row => { if (row) focusFacility(id(row)); },paused,colors);
        showGlobe = true;
        flat.hidden = true;
        q("[data-exec-motion]").hidden = false;
        q("[data-exec-motion]").textContent = paused ? "Mulai animasi" : "Jeda animasi";
        q("[data-exec-globe]").setAttribute("aria-pressed","true");
        q("[data-exec-globe]").textContent = "Kembali ke peta 2D";
    } catch {
        switchMap();
        notice("Globe 3D tidak tersedia. Gunakan peta 2D atau tabel.");
    }
}

async function initMap() {
    for (const name of ["MarkerCluster.css","MarkerCluster.Default.css"]) {
        const css = element("link");
        css.rel = "stylesheet";
        css.href = "/assets/vendor/markercluster/" + name;
        document.head.append(css);
    }
    const [land] = await Promise.all([
        request("/assets/data/land-110m.geojson"),
        loadScript("/assets/vendor/leaflet/leaflet.js").then(() => loadScript("/assets/vendor/markercluster/leaflet.markercluster.js")),
    ]);
    if (expired) return;
    flat.hidden = showGlobe;
    map = window.L.map(flat,{scrollWheelZoom:false,maxZoom:16}).setView([-2.4,118],4);
    map.attributionControl.addAttribution("Natural Earth 1:110m");
    window.L.geoJSON(land,{interactive:false,style:{color:"#789b9a",weight:1,fillColor:"#c0dbd2",fillOpacity:1}}).addTo(map);
    cluster = window.L.markerClusterGroup({animate:!reduce.matches,showCoverageOnHover:false}).addTo(map);
    resize = new ResizeObserver(() => { if (!showGlobe) map?.invalidateSize(); });
    resize.observe(flat);
    renderMarkers();
    fitAll();
}

function render({fit=false}={}) {
    rows = new Map(model.rows.map(row => [id(row),row]));
    for (const key of compare) if (!rows.has(key)) compare.delete(key);
    if (!rows.has(selected)) selected = undefined;
    layerSelect.value = filters.layer;
    q("[data-exec-scope]").textContent = model.scope;
    q("[data-exec-period]").textContent = "Tanggal acuan " + (model.reference ?? "belum dipilih") + " · " +
        ({now:"Saat ini",history:"Historis",projection:"Proyeksi " + filters.months + " bulan"})[filters.mode];
    const exp = q("[data-exec-export]");
    if (exp) exp.href = endpoint("pdf",filters);
    notice(model.status);
    renderCards();
    updateControls();
    renderLists();
    renderMarkers();
    renderCompare();
    detail(rows.get(selected));
    if (showGlobe) switchGlobe();
    if (fit) fitAll();
}

function sync(text) {
    q("[data-exec-sync]").textContent = "Pembaruan sumber " + timeText(model.source_at,zone) + " · " + text;
}

function nextPoll(retry=0) {
    clearTimeout(poll);
    if (!expired && !document.hidden) poll = setTimeout(() => load(filters,{delta:true}),retry || 25000);
}

function armExpiry(seconds) {
    clearTimeout(timeout);
    if (seconds <= 0) return expire();
    timeout = setTimeout(expire,seconds*1000);
}

function expire() {
    if (expired) return;
    expired = true;
    clearTimeout(poll);
    clearTimeout(timeout);
    pauseRotation();
    inFlight?.abort();
    switchMap();
    resize?.disconnect();
    map?.remove();
    map = undefined;
    rows.clear();
    markers.clear();
    compare.clear();
    saved = [];
    facets.length = 0;
    filters = null;
    model = null;
    q("[data-exec-content]").replaceChildren();
    q("[data-exec-content]").hidden = true;
    q("[data-exec-scope]").textContent = "";
    const header = q(".exec-header p:not(.exec-brand)");
    if (header) header.textContent = "";
    q("[data-exec-expired]").hidden = false;
    if (document.fullscreenElement === root) document.exitFullscreen().catch(() => {});
    if (root.classList.contains("is-expanded")) q("[data-fullscreen]").click();
}

async function post(path, body) {
    const response = await fetch(endpoint(path),{method:"POST",body:new URLSearchParams({...body,csrf:root.dataset.csrf}),
        cache:"no-store",credentials:"same-origin"});
    if (response.status === 401) {
        expire();
        throw new Error("Sesi berakhir.");
    }
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || "Permintaan gagal.");
    return data;
}

async function load(next,{delta=false,activity=false,fit=false}={}) {
    if (expired) return;
    if (delta && (document.hidden || inFlight)) return nextPoll();
    inFlight?.abort();
    const controller = new AbortController();
    inFlight = controller;
    const query = {...next,after:delta ? revision ?? "" : "",activity:activity ? "1" : ""};
    try {
        const response = await fetch(endpoint("data",query),{signal:controller.signal,cache:"no-store",credentials:"same-origin"});
        if (controller.signal.aborted || expired) return;
        if (response.status === 401 || response.status === 403) return expire();
        if (!response.ok) throw new Error("Pembaruan gagal.");
        const fresh = await response.json();
        if (controller.signal.aborted || expired) return;
        if (fresh.access !== access) return expire();
        armExpiry(fresh.session_seconds);
        if (expired) return;
        failures = 0;
        revision = fresh.revision;
        if (fresh.changed) {
            model = fresh;
            filters = fresh.filters;
            if (!delta) {
                applyForm(filters);
                history.replaceState(null,"",endpoint("home",filters));
            }
            render({fit});
        }
        sync("Terhubung · diperiksa " + timeText(fresh.checked_at,zone));
    } catch (error) {
        if (controller.signal.aborted || expired) return;
        ++failures;
        sync("Koneksi terganggu · menampilkan data lama");
    } finally {
        if (inFlight === controller) {
            inFlight = undefined;
            nextPoll(failures ? Math.min(25000*2**failures,180000) : 0);
        }
    }
}

function preset(kind, activity) {
    const next = {...filters,metric:"all",layer:kind === "now" ? "situation" : kind,mode:"now",date:null};
    if (kind === "retirement") {
        next.mode = "projection";
        next.months = 12;
    }
    load(next,{activity,fit:true});
}

function pauseRotation() {
    clearInterval(timer);
    timer = undefined;
    const control = q("[data-exec-rotate]");
    if (!control) return;
    control.textContent = rotated ? "Lanjutkan rotasi" : "Putar preset";
    control.setAttribute("aria-pressed","false");
}

function renderSaved() {
    q("[data-exec-saved]").replaceChildren(...saved.map(item => {
        const li = element("li");
        li.append(button(item.name,async () => {
            await load(item.settings,{activity:true,fit:true});
            if (!expired) item.settings.display === "globe" ? switchGlobe() : switchMap();
        }),button("Hapus",async () => {
            try {
                await post("preset",{action:"delete",id:item.id});
                saved = saved.filter(other => item.id !== other.id);
                renderSaved();
            } catch (error) { notice(error.message); }
        }));
        return li;
    }));
}

enableFullscreen(root,() => map?.invalidateSize());
form.elements.mode.addEventListener("change",() => {
    showPeriodFields();
    if (form.elements.mode.value !== "now") q(".exec-filters").open = true;
});
form.addEventListener("submit",event => {
    event.preventDefault();
    load({...filters,...readForm(),metric:"all"},{activity:true,fit:true});
});
for (const key of ["command_id","unit_id"]) form.elements[key].addEventListener("change",linkedOptions);
q("[data-exec-reset]").addEventListener("click",event => {
    event.preventDefault();
    const reset = Object.fromEntries(keys.map(key => [key,""]));
    load({...reset,mode:"now",months:12,layer:"situation",metric:"all"},{activity:true,fit:true});
});
layerSelect.addEventListener("change",() => load({...filters,layer:layerSelect.value},{activity:true}));
for (const card of root.querySelectorAll("[data-kpi]")) card.addEventListener("click",() => activeCard(card.dataset.kpi));
for (const control of root.querySelectorAll("[data-exec-preset]")) control.addEventListener("click",() => preset(control.dataset.execPreset,true));
q("[data-exec-fit]").addEventListener("click",fitAll);
q("[data-exec-compare]").addEventListener("click",() => dialog.showModal());
q("[data-exec-globe]").addEventListener("click",() => showGlobe ? switchMap() : switchGlobe());
q("[data-exec-motion]").addEventListener("click",event => {
    paused = !paused;
    globe?.pause(paused);
    event.currentTarget.textContent = paused ? "Mulai animasi" : "Jeda animasi";
});
q("[data-exec-meeting]").addEventListener("click",event => {
    const on = root.classList.toggle("exec-meeting");
    event.currentTarget.setAttribute("aria-pressed",String(on));
    event.currentTarget.textContent = on ? "Keluar mode rapat" : "Mode rapat";
});
q("[data-exec-rotate]").addEventListener("click",event => {
    if (timer) return pauseRotation();
    rotated = true;
    event.currentTarget.textContent = "Jeda rotasi";
    event.currentTarget.setAttribute("aria-pressed","true");
    timer = setInterval(() => {
        if (!document.hidden) {
            preset(choices[rotation++ % choices.length],false);
        }
    },20000);
});
for (const type of ["pointerdown","keydown"]) root.addEventListener(type,event => {
    if (!event.target.closest("[data-exec-rotate]")) pauseRotation();
});
q("[data-exec-save]").addEventListener("submit",async event => {
    event.preventDefault();
    try {
        const stored = await post("preset",{...filters,name:event.currentTarget.elements.name.value,
            display:showGlobe ? "globe" : "map",action:"save"});
        saved = [...saved.filter(item => item.id !== stored.id),stored];
        renderSaved();
        notice("Preset disimpan.");
    } catch (error) { notice(error.message); }
});
document.addEventListener("visibilitychange",() => {
    if (document.hidden) {
        clearTimeout(poll);
        pauseRotation();
    } else if (!expired) load(filters,{delta:true});
});
window.addEventListener("offline",() => { if (!expired) sync("Luring · menampilkan data lama"); });
window.addEventListener("online",() => { if (!expired) load(filters,{delta:true}); });
window.addEventListener("pagehide",expire);
window.addEventListener("pageshow",event => { if (event.persisted) location.reload(); });
applyForm(filters);
renderSaved();
render();
armExpiry(model.session_seconds);
if (!expired) {
    initMap().catch(() => { if (!expired) notice("Peta 2D tidak dapat dimuat. Gunakan tabel fasilitas."); });
    load(filters,{delta:true});
}
