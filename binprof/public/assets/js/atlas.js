import {button, definitions, element, enableFullscreen, kilometers, loadScript, request, table, timeText, value} from "./atlas-ui.js";
import {enableEvents} from "./atlas-events.js";

const root = document.querySelector("[data-atlas]");
const q = selector => root.querySelector(selector);
const go = matchMedia("(prefers-reduced-motion: reduce)");
const routes = JSON.parse(root.dataset.routes);
const api = (key, options = {}) => request(routes[key],{...options,csrf:root.dataset.csrf});
const flat = q("[data-map]");
const sphere = q("[data-globe]");
const filterForm = q("[data-filters]").closest("form");
const filterKeys = [...q("[data-filters]").querySelectorAll("[name]")].map(node => node.name);
const theme = q("[data-theme]");
const detail = q("[data-detail]");
const zone = root.dataset.zone;
const retirementAllowed = root.dataset.retirement === "1";
const retirementLabels = ["Perkiraan tercatat","Keputusan dijadwalkan","Proyeksi aktif setelah keputusan"];
const retirementValues = row => [row?.planned,row?.scheduled,row?.remaining];
const categories = JSON.parse(root.dataset.categories);
const severity = JSON.parse(root.dataset.severity);
const palette = ["#175d9a","#303b91","#087163","#88461a","#6e3d81","#9b354c","#3a667b","#376335"];
const unknown = "#6d8294";
const names = ["Fasilitas","Kategori","Satuan","Komando","Personel tercatat","Dokter","Dokter gigi","Perawat","Lokasi"];
const coord = row => row.latitude === null || row.longitude === null ? null : [Number(row.latitude),Number(row.longitude)];
const byId = row => String(row.id);
const comparisons = new Map();
let rows = JSON.parse(root.dataset.rows);
let displayedFilters = readFilters();
let result = new Map(rows.map(row => [byId(row),row]));
let badges = new Map();
let events = new Map();
let activeEventPanel;
let saved = new Map();
let map;
let cluster;
let markerById = new Map();
let globe;
let mode = "map";
let sceneRequest = 0;
let paused = go.matches;
let selected;
let point;
let pointCircle;
let filterSequence = 0;
let detailSequence = 0;
let detailRevision = 0;

function showStatus(message) {
    const node = q("[data-status]");
    node.textContent = message;
    node.hidden = !message;
}

function readFilters() {
    return Object.fromEntries(filterKeys.map(key => [key,filterForm.elements[key].value.trim()]));
}

function setFilters(filters) {
    for (const key of filterKeys) filterForm.elements[key].value = filters[key] ?? (key === "retirement_months" ? "12" : "");
}

function updateLink(filters) {
    const reset = new URL(q("[data-reset]").href,location.origin);
    for (const [key,val] of Object.entries(filters)) if (val) reset.searchParams.set(key,val);
    history.replaceState(null,"",reset);
    const exportLink = q("[data-export]");
    if (exportLink) {
        reset.searchParams.set("format","csv");
        exportLink.href = reset.href;
    }
}

function keyColor(key) {
    let code = 0;
    for (const char of key) code = (code * 31 + char.codePointAt(0)) >>> 0;
    return palette[code % palette.length];
}

function markerColor(row) {
    if (theme.value === "category") return keyColor(row.kind);
    if (theme.value === "personnel") {
        if (row.total === null) return unknown;
        if (Number(row.total) >= 50) return "#123c66";
        if (Number(row.total) >= 10) return "#18728b";
        return "#4e936d";
    }
    if (theme.value === "retirement") {
        const sum = row.retirement;
        if (!sum || sum.active === null) return unknown;
        if (sum.scheduled > 0) return "#9a432c";
        return sum.planned > 0 ? "#266995" : "#567865";
    }
    return ["#4c788c","#2e715e","#ad541d","#a42e49"][Number(badges.get(byId(row))?.severity || 0)];
}

function renderLegend() {
    const legend = q("[data-legend]");
    let entries;
    if (theme.value === "category") {
        entries = [...new Set(rows.map(row => row.kind))].sort().map(kind => [kind,keyColor(kind)]);
    } else if (theme.value === "personnel") {
        entries = [["Belum tersedia",unknown],["Di bawah 10","#4e936d"],["10–49","#18728b"],["50 ke atas","#123c66"]];
    } else if (theme.value === "retirement") {
        entries = [["Data belum tersedia",unknown],["Tidak ada jadwal tercatat","#567865"],["Ada perkiraan","#266995"],["Ada keputusan resmi","#9a432c"]];
        entries.unshift([(displayedFilters.retirement_months ?? "12") + " bulan","#19334a"]);
    } else {
        entries = [["Tanpa kejadian aktif","#4c788c"],["Info","#2e715e"],["Perhatian","#ad541d"],["Kritis","#a42e49"]];
    }
    legend.replaceChildren(...entries.map(([name,color]) => {
        const label = element("span");
        const swatch = element("i");
        swatch.style.backgroundColor = color;
        swatch.setAttribute("aria-hidden","true");
        label.append(swatch,document.createTextNode(name));
        return label;
    }));
}

function iconFor(row) {
    const outer = element("span");
    const dot = element("i","","atlas-pin-core");
    dot.style.backgroundColor = markerColor(row);
    outer.append(dot);
    if (theme.value === "retirement" && row.retirement?.active != null) {
        const {planned,scheduled} = row.retirement;
        const count = element("span",String(planned + scheduled),"atlas-pin-projection");
        count.title = planned + " perkiraan tercatat · " + scheduled + " keputusan resmi";
        outer.append(count);
    }
    const unread = badges.get(byId(row))?.unread;
    if (Number(unread) > 0) outer.append(element("b",String(unread),"atlas-pin-count"));
    if (row.retirement?.followup > 0) {
        const count = element("b",String(row.retirement.followup),"atlas-pin-retirement");
        count.title = "Administrasi pensiun perlu tindak lanjut";
        count.setAttribute("aria-label",count.textContent + " administrasi pensiun perlu tindak lanjut");
        outer.append(count);
    }
    return window.L.divIcon({className:"atlas-pin",html:outer,iconSize:[26,26]});
}

function renderMarkers() {
    renderLegend();
    if (!cluster) return;
    cluster.clearLayers();
    markerById = new Map();
    const markers = [];
    for (const row of rows) {
        const position = coord(row);
        if (!position) continue;
        const id = byId(row);
        const marker = window.L.marker(position,{icon:iconFor(row),title:row.name,alt:row.name,bubblingMouseEvents:false,autoPanOnFocus:false});
        marker.facilityId = id;
        marker.on("click",() => openFacility(id));
        markerById.set(id,marker);
        markers.push(marker);
    }
    cluster.addLayers(markers);
}

function refreshMarkerStyle() {
    renderLegend();
    for (const row of rows) markerById.get(byId(row))?.setIcon(iconFor(row));
    cluster?.refreshClusters();
    globe?.recolor(row => markerColor(row));
}

function fitAll() {
    if (!map) return;
    if (mode === "globe") {
        globe?.reset();
        return;
    }
    const bounds = cluster.getBounds();
    if (bounds.isValid()) map.fitBounds(bounds,{padding:[25,25],maxZoom:8,animate:false});
    else map.setView([-2.4,118],4,{animate:false});
}

function tableRows() {
    q("[data-result]").textContent = rows.length + " fasilitas · " + rows.filter(coord).length
        + " berkoordinat. Maksimal 1.000 hasil.";
    const headers = retirementAllowed ? [...names,...retirementLabels] : names;
    q("[data-table]").replaceChildren(table(headers, rows.map(row => [
        button(row.name,() => focusFacility(byId(row),false)),row.kind,row.unit,row.command_name,
        row.total,row.doctors,row.dentists,row.nurses,coord(row)?.join(", "),
        ...(retirementAllowed ? retirementValues(row.retirement) : [])
    ])));
}

async function applyFilters(filters, {announce = true} = {}) {
    const requestId = ++filterSequence;
    if (announce) showStatus("Memuat fasilitas…");
    try {
        const next = await api("markers",{query:filters});
        if (requestId !== filterSequence) return false;
        displayedFilters = filters;
        rows = next;
        result = new Map(rows.map(row => [byId(row),row]));
        if (selected && !result.has(selected)) {
            ++detailSequence;
            selected = null;
            activeEventPanel = null;
            detail.replaceChildren(element("h3","Detail fasilitas"), element("p","Pilih fasilitas dari hasil filter."));
        }
        for (const id of comparisons.keys()) if (!result.has(id)) comparisons.delete(id);
        tableRows();
        renderMarkers();
        if (mode === "globe") await renderGlobe();
        if (requestId !== filterSequence) return false;
        fitAll();
        nearResults();
        compare();
        if (selected) openFacility(selected);
        updateLink(filters);
        if (announce) showStatus(rows.length ? "" : "Tidak ada fasilitas yang sesuai dengan filter.");
        return true;
    } catch (error) {
        if (requestId === filterSequence) showStatus(error.message);
        return false;
    }
}

async function openFacility(id, {reveal = false} = {}) {
    selected = id;
    const revision = ++detailSequence;
    detailRevision = revision;
    activeEventPanel = null;
    detail.replaceChildren(element("h3","Detail fasilitas"),element("p","Memuat detail…","subtle"));
    try {
        const data = await api("detail",{query:{...displayedFilters,facility:id}});
        if (revision !== detailSequence) return;
        const facility = data.facility;
        const title = element("h3",facility.name);
        title.tabIndex = -1;
        detail.replaceChildren(title,element("p",[facility.kind,facility.unit,facility.command_name].filter(Boolean).join(" · "),"subtle"),
            definitions([["Personel aktif tercatat",data.total],["Pembaruan master",timeText(facility.updated_at,zone)],
                ["Alamat",facility.address],["Koordinat",coord(facility)?.join(", ")]]));
        detail.append(element("h3","Distribusi profesi"));
        detail.append(data.professions ? definitions(data.professions.map(row => [row.name,row.total])) : element("p","Belum tersedia","subtle"));
        detail.append(element("h3","Pelatihan"));
        detail.append(data.training ? definitions([["Catatan diklat",data.training.total],["Sudah",data.training.completed],
            ["Belum",data.training.pending],["Akan berakhir ≤90 hari",data.training.expiring]]) : element("p","Belum tersedia","subtle"));
        if (retirementAllowed) renderRetirementDetail(data);
        activeEventPanel = element("div");
        detail.append(activeEventPanel);
        renderDetailEvents(data.events);
        const actions = element("div","","actions");
        const compareButton = button(comparisons.has(id) ? "Hapus dari perbandingan" : "Bandingkan",() => {
            if (comparisons.has(id)) {
                comparisons.delete(id);
            } else {
                if (comparisons.size >= 4) {
                    showStatus("Perbandingan dibatasi empat fasilitas.");
                    return;
                }
                comparisons.set(id,data);
            }
            compareButton.textContent = comparisons.has(id) ? "Hapus dari perbandingan" : "Bandingkan";
            compare();
        });
        actions.append(compareButton);
        detail.append(actions);
        if (data.personnel_allowed) {
            const personnel = element("div");
            detail.append(button("Muat daftar personel",() => loadPersonnel(id,1,personnel,revision)),personnel);
        }
        if (root.dataset.report === "1") detail.append(newEventForm(id));
        if (reveal) {
            detail.scrollIntoView({block:"nearest"});
            title.focus({preventScroll:true});
        }
    } catch (error) {
        if (revision === detailSequence) detail.append(element("p",error.message));
    }
}

function renderRetirementDetail(data) {
    const row = data.retirement;
    const panel = element("section","","atlas-retirement");
    panel.append(element("h3","Pensiun/Purnawirawan"));
    if (row) {
        panel.append(definitions([
            ["Periode",row.months + " bulan hingga " + row.through],
            ["Personel aktif dalam filter",row.active],
            ["Perkiraan tercatat",row.planned],
            ["Keputusan resmi menunggu penerapan",row.scheduled],
            ["Administrasi perlu tindak lanjut",row.followup],
            ["Pembaruan data pensiun",timeText(row.updated_at,zone)],
            ["Waktu penghitungan",timeText(row.as_of,zone)],
        ]));
        panel.append(element("h4","Proyeksi tenaga hingga 24 bulan"));
        panel.append(table(["Periode","Sampai tanggal",...retirementLabels],
            row.timeline.map(period => [period.months + " bulan",period.through,...retirementValues(period)])));
        panel.append(element("p","Proyeksi aktif mengurangi jumlah aktif dengan keputusan resmi yang masih menunggu penerapan dalam periode. Selisih kebutuhan belum tersedia karena target terverifikasi belum tersedia.","subtle"));
    } else {
        panel.append(element("p","Data tenaga dalam filter ini belum tersedia.","subtle"));
    }
    if (data.retirement_url && row?.followup > 0) {
        const link = element("a","Buka tindak lanjut pensiun","button secondary");
        link.href = data.retirement_url;
        panel.append(link);
    }
    detail.append(panel);
}

function renderDetailEvents(initial) {
    if (!activeEventPanel || detailRevision !== detailSequence) return;
    const rows = initial ?? [...events.values()].filter(row => String(row.facility_id) === selected && !row.resolved_at);
    const list = element("ul","","atlas-list");
    for (const row of rows) list.append(element("li",severity[row.severity] + " · " + categories[row.category]
        + " · " + (row.acknowledged_at ? "Diakui" : "Belum diakui")));
    activeEventPanel.replaceChildren(element("h3","Kejadian aktif"),
        rows.length ? list : element("p","Tidak ada kejadian aktif.","subtle"));
}

async function loadPersonnel(id,page,container,revision) {
    container.textContent = "Memuat personel…";
    try {
        const data = await api("personnel",{query:{facility:id,page}});
        if (revision !== detailSequence) return;
        const list = element("ul","","atlas-list");
        for (const row of data.rows) {
            const link = element("a",row.name);
            link.href = row.url;
            const item = element("li");
            item.append(link,element("small",row.profession,"subtle"));
            list.append(item);
        }
        container.replaceChildren(data.rows.length ? list : element("p","Tidak ada personel tercatat.","subtle"));
        if (page > 1) container.append(button("Halaman sebelumnya",() => loadPersonnel(id,page-1,container,revision)));
        if (data.next) container.append(button("Halaman berikutnya",() => loadPersonnel(id,page+1,container,revision)));
    } catch (error) {
        if (revision === detailSequence) container.textContent = error.message;
    }
}

function newEventForm(id) {
    const box = element("details","","atlas-box");
    box.append(element("summary","Laporkan kejadian"));
    const form = element("form","","atlas-inline");
    for (const [key,label,choices] of [["category","Kategori",categories],["severity","Severity",severity]]) {
        const field = element("label",label);
        const select = element("select");
        select.name = key;
        for (const [code,text] of Object.entries(choices)) {
            const option = element("option",text);
            option.value = code;
            select.append(option);
        }
        form.append(field);
        field.append(select);
    }
    form.append(element("button","Catat kejadian"));
    form.addEventListener("submit",async event => {
        event.preventDefault();
        try {
            const response = await notifications.create(id,Object.fromEntries(new FormData(form)));
            showStatus(response.changed ? "Kejadian dicatat." : "Kejadian kategori ini masih aktif.");
        } catch (error) {
            showStatus(error.message);
        }
    });
    box.append(form);
    return box;
}

async function focusFacility(id,resetFilters) {
    if (!result.has(id) && resetFilters) {
        setFilters({});
        await applyFilters(readFilters(),{announce:false});
        showStatus("Filter direset untuk menampilkan fasilitas kejadian.");
    }
    const row = result.get(id);
    if (row && coord(row)) {
        if (mode === "globe") globe?.focus(row);
        else if (map) {
            const marker = markerById.get(id);
            if (marker) cluster.zoomToShowLayer(marker,() => {
                map.panTo(marker.getLatLng(),{animate:false});
                marker.getElement()?.focus({preventScroll:true});
            });
        }
    }
    return openFacility(id,{reveal:true});
}

function compare() {
    const panel = q("[data-compare]");
    panel.hidden = comparisons.size === 0;
    const body = q("[data-compare-body]");
    const headers = ["Fasilitas","Kategori","Personel aktif tercatat","Catatan diklat","Kejadian aktif",
        ...(retirementAllowed ? retirementLabels : []),"Aksi"];
    body.replaceChildren(table(headers,
        [...comparisons].map(([id,{facility,training}]) => {
            const row = result.get(id);
            return [facility.name,facility.kind,row?.total,training?.total,badges.get(id)?.active ?? 0,
                ...(retirementAllowed ? retirementValues(row?.retirement) : []),
                button("Hapus",() => { comparisons.delete(id); compare(); })];
        })));
}

function nearResults() {
    if (!point) return;
    const radius = Number(q("[data-radius]").value);
    if (map) {
        pointCircle?.remove();
        pointCircle = window.L.circle(point,{radius:radius*1000,interactive:false,color:"#247a9a",weight:2,fillOpacity:0.10}).addTo(map);
    }
    const close = rows.filter(coord).map(row => [row,kilometers(point,coord(row))])
        .filter(([,km]) => km <= radius).sort((a,b) => a[1]-b[1]).slice(0,25);
    const list = q("[data-near-results]");
    list.replaceChildren(...(close.length ? close.map(([row,km]) => {
        const item = element("li");
        item.append(button(row.name,() => focusFacility(byId(row),false)),
            element("small",km.toLocaleString("id-ID",{maximumFractionDigits:1}) + " km · garis lurus"));
        return item;
    }) : [element("li","Tidak ada hasil berkoordinat dalam radius ini.","subtle")]));
}

function setPoint(position) {
    point = position;
    q("[data-near]").hidden = false;
    q("[data-coordinate]").textContent = "Koordinat: " + position.map(n => n.toFixed(5)).join(", ");
    nearResults();
}

function updateMode(next) {
    mode = next;
    flat.hidden = next !== "map" || !map;
    sphere.hidden = next !== "globe";
    q("[data-motion]").hidden = next !== "globe";
    for (const control of root.querySelectorAll("[data-view]")) {
        control.setAttribute("aria-pressed",String(control.dataset.view === next));
    }
    requestAnimationFrame(() => { if (next === "map") map?.invalidateSize(); });
}

async function renderGlobe() {
    const ticket = ++sceneRequest;
    try {
        const {createGlobe} = await import("./globe.js");
        if (ticket !== sceneRequest) return;
        globe?.dispose();
        globe = undefined;
        updateMode("globe");
        globe = createGlobe(sphere,rows.filter(coord),row => { if (row) openFacility(byId(row)); },paused,markerColor);
        if (selected && result.has(selected) && coord(result.get(selected))) globe.focus(result.get(selected));
        showStatus("");
    } catch {
        if (ticket !== sceneRequest) return;
        updateMode("map");
        showStatus("WebGL2 tidak tersedia. Gunakan peta 2D atau tabel.");
    }
}

async function initMap() {
    for (const name of ["MarkerCluster.css","MarkerCluster.Default.css"]) {
        const stylesheet = element("link");
        stylesheet.rel = "stylesheet";
        stylesheet.href = "/assets/vendor/markercluster/" + name;
        document.head.append(stylesheet);
    }
    const [land] = await Promise.all([
        request("/assets/data/land-110m.geojson"),
        loadScript("/assets/vendor/leaflet/leaflet.js").then(() => loadScript("/assets/vendor/markercluster/leaflet.markercluster.js"))
    ]);
    flat.hidden = false;
    map = window.L.map(flat,{scrollWheelZoom:false,maxZoom:16});
    // Leaflet's default focus handling restores window scroll, while fullscreen
    // scrolls the atlas container. Focus without scrolling before its handler.
    flat.addEventListener("mousedown", event => {
        if (!event.target.closest(".leaflet-control")) flat.focus({preventScroll:true});
    }, {capture:true});
    map.attributionControl.addAttribution("Natural Earth 1:110m");
    window.L.geoJSON(land,{interactive:false,style:{color:"#7395a6",weight:1,fillColor:"#bed9ce",fillOpacity:1}}).addTo(map);
    cluster = window.L.markerClusterGroup({
        animate:!go.matches,removeOutsideVisibleBounds:true,showCoverageOnHover:false,
        iconCreateFunction(group) {
            const count = group.getChildCount();
            const unread = group.getAllChildMarkers().reduce((sum,marker) => sum + Number(badges.get(marker.facilityId)?.unread || 0),0);
            const label = element("div");
            label.append(element("span",String(count)));
            if (unread) label.append(element("b",String(unread),"atlas-pin-count"));
            label.setAttribute("aria-label",count + " fasilitas, " + unread + " kejadian belum dibaca");
            return window.L.divIcon({html:label,className:"marker-cluster marker-cluster-small",iconSize:[40,40]});
        }
    });
    cluster.addTo(map);
    renderMarkers();
    fitAll();
    map.on("click",event => setPoint([event.latlng.lat,event.latlng.lng]));
    const resize = new ResizeObserver(() => { if (mode === "map") map?.invalidateSize(); });
    resize.observe(flat);
    window.addEventListener("pagehide",event => {
        if (!event.persisted) {
            resize.disconnect();
            map.remove();
        }
    });
}

async function loadViews() {
    const rows = await api("views");
    saved = new Map(rows.map(row => [row.id,row]));
    const list = q("[data-saved]");
    list.replaceChildren(...(rows.length ? rows.map(row => {
        const item = element("li");
        item.append(button(row.name,() => {
            const view = saved.get(row.id).view;
            setFilters(view.filters);
            theme.value = Array.from(theme.options).some(option => option.value === view.theme) ? view.theme : "category";
            applyFilters(readFilters());
        }),button("Hapus",async () => {
            try {
                await api("views",{body:{action:"delete",id:row.id}});
                await loadViews();
                showStatus("Tampilan dihapus.");
            } catch (error) {
                showStatus(error.message);
            }
        }));
        return item;
    }) : [element("li","Belum ada tampilan tersimpan.","subtle")]));
}

for (const node of root.querySelectorAll("[data-enhanced]")) node.hidden = false;
enableFullscreen(root,() => { if (mode === "map") map?.invalidateSize(); });
const notifications = enableEvents(root,api,{focus:id => focusFacility(id,true),showStatus,onBadges(next,current,refreshFacilities) {
    badges = new Map(next.map(row => [String(row.facility_id),row]));
    events = current;
    refreshMarkerStyle();
    renderDetailEvents();
    compare();
    if (refreshFacilities) applyFilters(displayedFilters,{announce:false});
}});
tableRows();
initMap().catch(() => showStatus("Peta 2D tidak dapat dimuat. Gunakan tabel fasilitas."));
loadViews().catch(error => showStatus(error.message));
q("[data-fit]").addEventListener("click",fitAll);
q("[data-view=map]").addEventListener("click",() => {
    ++sceneRequest;
    globe?.dispose();
    globe = undefined;
    updateMode("map");
    showStatus("");
});
q("[data-view=globe]").addEventListener("click",() => { if (mode !== "globe") renderGlobe(); });
q("[data-motion]").addEventListener("click",event => {
    paused = !paused;
    globe?.pause(paused);
    event.currentTarget.textContent = paused ? "Mulai animasi" : "Jeda animasi";
    event.currentTarget.setAttribute("aria-pressed",String(paused));
});
if (paused) {
    q("[data-motion]").textContent = "Mulai animasi";
    q("[data-motion]").setAttribute("aria-pressed","true");
}
theme.addEventListener("change",refreshMarkerStyle);
q("[data-radius]").addEventListener("change",nearResults);
q("[data-clear-compare]").addEventListener("click",() => {
    comparisons.clear();
    compare();
    if (selected) openFacility(selected);
});
q("[data-reset]").addEventListener("click",event => {
    event.preventDefault();
    setFilters({});
    theme.value = "category";
    point = null;
    pointCircle?.remove();
    q("[data-near]").hidden = true;
    applyFilters(readFilters());
});
filterForm.addEventListener("submit",event => {
    event.preventDefault();
    applyFilters(readFilters());
});
q("[data-save-view]").addEventListener("submit",async event => {
    event.preventDefault();
    const form = event.currentTarget;
    try {
        await api("views",{body:{...readFilters(),name:form.elements.name.value,theme:theme.value,action:"save"}});
        form.reset();
        await loadViews();
        showStatus("Tampilan disimpan.");
    } catch (error) {
        showStatus(error.message);
    }
});
window.addEventListener("pagehide",event => { if (!event.persisted) globe?.dispose(); });
