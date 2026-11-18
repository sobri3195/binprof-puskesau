export function element(tag, content = "", className = "") {
    const result = document.createElement(tag);
    result.textContent = content;
    if (className) result.className = className;
    return result;
}

export function button(label, action) {
    const node = element("button", label, "secondary");
    node.type = "button";
    node.addEventListener("click", action);
    return node;
}

export const value = n => n === null || n === undefined || n === "" ? "Belum tersedia" : String(n);

export function definitions(values) {
    const list = element("dl", "", "definitions");
    for (const [label, data] of values) {
        const item = element("div");
        item.append(element("dt", label), element("dd", value(data)));
        list.append(item);
    }
    return list;
}

export function table(headers, rows) {
    if (!rows.length) return element("p", "Tidak ada fasilitas yang sesuai.", "empty");
    const wrap = element("div", "", "table-wrap");
    wrap.tabIndex = 0;
    wrap.setAttribute("role", "region");
    wrap.setAttribute("aria-label", "Tabel fasilitas");
    const output = element("table");
    const head = output.createTHead().insertRow();
    for (const label of headers) {
        const header = element("th", label);
        header.scope = "col";
        head.append(header);
    }
    const body = output.createTBody();
    for (const cells of rows) {
        const row = body.insertRow();
        for (const datum of cells) {
            const td = row.insertCell();
            td.append(datum instanceof Node ? datum : document.createTextNode(value(datum)));
        }
    }
    wrap.append(output);
    return wrap;
}

export function loadScript(path) {
    return new Promise((resolve, reject) => {
        const script = document.createElement("script");
        script.src = path;
        script.onload = resolve;
        script.onerror = reject;
        document.head.append(script);
    });
}

export async function request(path, {query, body, csrf} = {}) {
    const target = new URL(path, location.origin);
    if (query) {
        for (const [key, val] of Object.entries(query)) {
            if (val !== null && val !== "") target.searchParams.set(key, val);
        }
    }
    const options = {cache: "no-store", credentials: "same-origin"};
    if (body) {
        const fields = new URLSearchParams();
        for (const [key, val] of Object.entries({...body, csrf})) {
            if (val !== null) fields.set(key, val);
        }
        options.method = "POST";
        options.body = fields;
    }
    const response = await fetch(target, options);
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.error || "Permintaan gagal. Coba lagi.");
    return result;
}

export const timeText = (utc, zone) => utc
    ? new Intl.DateTimeFormat("id-ID",{dateStyle:"short",timeStyle:"short",timeZone:zone})
        .format(new Date(utc.includes("T") ? utc : utc.replace(" ","T") + "Z"))
    : "Belum tersedia";

export function enableFullscreen(root, resize) {
    const control = root.querySelector("[data-fullscreen]");
    let restoreFocus;
    const background = [...document.querySelectorAll(".navigation, .page-header, .workspace > .skip")];

    function expanded(value) {
        root.classList.toggle("is-expanded", value);
        document.body.classList.toggle("atlas-locked", value);
        for (const node of background) node.inert = value;
        if (!value && restoreFocus?.isConnected) restoreFocus.focus({preventScroll:true});
    }
    function sync() {
        const full = document.fullscreenElement === root;
        const fallback = root.classList.contains("is-expanded");
        control.textContent = full ? "Keluar layar penuh" : fallback ? "Tutup mode perluas" : "Layar penuh";
        control.setAttribute("aria-pressed", String(full || fallback));
        requestAnimationFrame(resize);
    }
    control.addEventListener("click", async () => {
        if (document.fullscreenElement === root) {
            await document.exitFullscreen();
        } else if (root.classList.contains("is-expanded")) {
            expanded(false);
        } else {
            restoreFocus = document.activeElement;
            try {
                if (!root.requestFullscreen) throw new Error("Fullscreen tidak tersedia");
                await root.requestFullscreen();
            } catch {
                expanded(true);
            }
        }
        sync();
    });
    document.addEventListener("fullscreenchange", sync);
    document.addEventListener("keydown", event => {
        if (event.key === "Escape" && root.classList.contains("is-expanded")) {
            expanded(false);
            sync();
        }
    });
}

export function kilometers(origin, point) {
    const rad = Math.PI / 180;
    const dlat = (point[0] - origin[0]) * rad;
    const dlon = (point[1] - origin[1]) * rad;
    const h = Math.sin(dlat/2)**2 + Math.cos(origin[0]*rad)*Math.cos(point[0]*rad)*Math.sin(dlon/2)**2;
    return 12742 * Math.asin(Math.sqrt(Math.min(1,h)));
}
