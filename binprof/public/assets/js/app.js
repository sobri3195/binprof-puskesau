const reduced = matchMedia("(prefers-reduced-motion: reduce)");
const navigation = document.querySelector(".navigation");
if (navigation) {
    const compact = matchMedia("(max-width: 900px)");
    const adaptMenu = () => { navigation.open = !compact.matches; };
    adaptMenu();
    compact.addEventListener("change",adaptMenu);
    navigation.addEventListener("keydown",event => {
        if (event.key === "Escape" && compact.matches && navigation.open) {
            event.preventDefault();
            navigation.open = false;
            navigation.querySelector("summary").focus();
        }
    });
    const active = navigation.querySelector("[aria-current=page]");
    if (!compact.matches) active?.scrollIntoView({block:"nearest"});
}
for (const control of document.querySelectorAll("[data-password-toggle]")) {
    const password = document.getElementById(control.getAttribute("aria-controls"));
    control.hidden = false;
    control.addEventListener("click",() => {
        const reveal = password.type === "password";
        password.type = reveal ? "text" : "password";
        control.setAttribute("aria-pressed",String(reveal));
        control.textContent = reveal ? "Sembunyikan" : "Tampilkan";
    });
}
document.querySelector("[data-login-feedback]")?.focus();

function loadScript(path) {
    return new Promise((resolve, reject) => {
        const script = document.createElement("script");
        script.src = path;
        script.onload = resolve;
        script.onerror = reject;
        document.head.append(script);
    });
}

async function readJson(path) {
    const response = await fetch(path, { credentials: "same-origin", cache: "no-store" });
    if (!response.ok) throw new Error("Data tidak tersedia");
    return response.json();
}

function facilityDescription(row) {
    const count = (value,label) => value == null ? "" : value + " " + label;
    return [row.name,row.kind,row.unit,row.command_name,count(row.total,"personel aktif") || "Data personel belum tersedia",
        count(row.doctors,"dokter"),count(row.dentists,"dokter gigi"),count(row.nurses,"perawat")].filter(Boolean).join(" · ");
}

async function enableMap(panel) {
    const flat = panel.querySelector("[data-map]");
    const sphere = panel.querySelector("[data-globe]");
    const controls = panel.querySelector(".map-controls");
    const motion = panel.querySelector("[data-motion]");
    const status = panel.querySelector("[data-map-status]");
    const fit = panel.querySelector("[data-fit]");
    let globe;
    let current = "map";
    let viewRequest = 0;
    let paused = reduced.matches;
    status.textContent = "Memuat peta…";
    const rows = await readJson(panel.dataset.endpoint);
    const located = rows.filter(row => row.latitude !== null && row.longitude !== null);
    const [land] = await Promise.all([
        readJson("/assets/data/land-110m.geojson"),
        loadScript("/assets/vendor/leaflet/leaflet.js")
    ]);
    flat.hidden = false;
    const map = window.L.map(flat, { scrollWheelZoom: false, attributionControl: true }).setView([-2.4,118],4);
    map.attributionControl.addAttribution("Natural Earth 1:110m");
    window.L.geoJSON(land, {
        interactive: false, style: { color: "#7799a9", weight: 1, fillColor: "#c0d8cd", fillOpacity: 1 }
    }).addTo(map);
    const markers = new Map();
    for (const row of located) {
        const point = [Number(row.latitude), Number(row.longitude)];
        const marker = window.L.circleMarker(point, {
            radius: 7, color: "#fff", fillColor: "#175d9a", fillOpacity: 1, weight: 2
        }).addTo(map);
        const description = document.createElement("p");
        description.textContent = facilityDescription(row);
        marker.bindPopup(description);
        markers.set(String(row.id), marker);
    }
    function fitAll() {
        if (located.length) {
            map.fitBounds(located.map(row => [Number(row.latitude), Number(row.longitude)]), { padding: [22, 22], maxZoom: 7 });
        } else {
            map.setView([-2.4, 118], 4);
        }
    }
    function showMap() {
        globe?.dispose();
        globe = undefined;
        current = "map";
        sphere.hidden = true;
        flat.hidden = false;
        fit.hidden = false;
        if (motion) motion.hidden = true;
        map.invalidateSize();
        fitAll();
        status.textContent = located.length
            ? located.length + " fasilitas berkoordinat. Lingkaran biru menandai lokasi."
            : "Belum ada fasilitas berkoordinat pada hasil ini.";
    }
    showMap();
    controls.hidden = false;
    fit.addEventListener("click", fitAll);
    panel.querySelector("[data-view=map]").addEventListener("click", () => {
        viewRequest++;
        showMap();
    });
    panel.querySelector("[data-view=globe]")?.addEventListener("click", async () => {
        if (current === "globe") return;
        const request = ++viewRequest;
        status.textContent = "Memuat globe 3D…";
        try {
            const { createGlobe } = await import("./globe.js");
            if (request !== viewRequest) return;
            sphere.hidden = false;
            globe = createGlobe(sphere, located, row => {
                status.textContent = row ? facilityDescription(row) : "Pilih marker untuk membuka ringkasan fasilitas.";
            }, paused);
            current = "globe";
            flat.hidden = true;
            fit.hidden = true;
            motion.hidden = false;
            renderMotion();
            status.textContent = "Globe untuk melihat sebaran. Gunakan peta 2D untuk fokus lokasi.";
        } catch {
            if (request !== viewRequest) return;
            showMap();
            status.textContent = "WebGL2 tidak tersedia. Peta 2D tetap dapat digunakan.";
        }
    });
    function renderMotion() {
        motion.textContent = paused ? "Mulai animasi" : "Jeda animasi";
        motion.setAttribute("aria-pressed", String(paused));
    }
    motion?.addEventListener("click", () => {
        paused = !paused;
        globe?.pause(paused);
        renderMotion();
    });
    const table = panel.parentElement;
    for (const button of table.querySelectorAll("[data-focus]")) {
        if (!markers.has(button.dataset.focus)) continue;
        button.hidden = false;
        button.parentElement.querySelector(".coordinates").hidden = true;
        button.addEventListener("click", () => {
            viewRequest++;
            if (current !== "map") showMap();
            const marker = markers.get(button.dataset.focus);
            map.setView(marker.getLatLng(), 8, { animate: !reduced.matches });
            marker.openPopup();
            panel.scrollIntoView({ behavior: reduced.matches ? "instant" : "smooth", block: "center" });
        });
    }
    window.addEventListener("pagehide", event => {
        if (!event.persisted) {
            globe?.dispose();
            map.remove();
        }
    }, { once: true });
}

for (const panel of document.querySelectorAll("[data-map-panel]")) {
    const observer = new IntersectionObserver(entries => {
        if (!entries.some(entry => entry.isIntersecting)) return;
        observer.disconnect();
        enableMap(panel).catch(() => {
            panel.querySelector("[data-map-status]").textContent = "Peta tidak tersedia. Gunakan daftar fasilitas.";
        });
    }, { rootMargin: "120px" });
    observer.observe(panel);
}
