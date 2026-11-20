import {button, element, timeText} from "./atlas-ui.js";

export function enableEvents(root, api, {focus, onBadges, onRefresh, showStatus}) {
    const categories = JSON.parse(root.dataset.categories);
    const severities = JSON.parse(root.dataset.severity);
    const zone = root.dataset.zone;
    const form = root.querySelector("[data-preferences]");
    const list = root.querySelector("[data-events]");
    const panel = root.querySelector("[data-events-panel]");
    const conn = root.querySelector("[data-connect]");
    const toast = root.querySelector(".atlas-toast");
    const browser = root.querySelector("[data-browser]");
    const events = new Map();
    let cursor;
    let personal;
    let lastSync;
    let pending = false;
    let queued = false;
    let stopped = false;
    let timer;
    let dismiss;
    let retry = 30000;
    let preferenceRevision;
    let browserOptIn = false;
    let browserNote;

    function stop() {
        stopped = true;
        clearTimeout(timer);
        clearTimeout(dismiss);
        browserNote?.close?.();
        events.clear();
        list.replaceChildren();
        toast.textContent = "";
    }

    function browserLabel() {
        browser.textContent = browserOptIn ? "Matikan notifikasi browser" : "Aktifkan notifikasi browser";
        browser.hidden = !("Notification" in window) || !window.isSecureContext;
    }
    browserLabel();
    browser.addEventListener("click", async () => {
        const granted = browserOptIn ? false : await Notification.requestPermission() === "granted";
        if (stopped) return;
        if (!browserOptIn && !granted) showStatus("Izin browser belum diberikan.");
        browserOptIn = granted;
        browserLabel();
    });

    function notify(count) {
        const text = count === 1 ? "Ada kejadian fasilitas baru." : count + " kejadian fasilitas baru.";
        if (!document.hidden) {
            toast.textContent = text;
            toast.hidden = false;
            clearTimeout(dismiss);
            dismiss = setTimeout(() => { toast.hidden = true; }, 6000);
        }
        if (browserOptIn && "Notification" in window && Notification.permission === "granted") {
            try {
                const note = new Notification("BINPROFKES", {body:text,tag:"binprof-maps"});
                browserNote = note;
                note.onclick = () => { window.focus(); panel.open = true; note.close(); };
            } catch {}
        }
    }

    function render() {
        const severity = root.querySelector("[name=event_severity]").value;
        const category = root.querySelector("[name=event_category]").value;
        const state = root.querySelector("[name=event_state]").value;
        const rows = [...events.values()].filter(row =>
            (!severity || row.severity === severity) && (!category || row.category === category)
            && (state === "all" || (state === "active" && !row.resolved_at) || (state === "unread" && !row.read_at)
                || (state === "ack" && row.acknowledged_at) || (state === "resolved" && row.resolved_at)));
        rows.sort((a,b) => Number(Boolean(a.resolved_at)) - Number(Boolean(b.resolved_at)) || b.created_at.localeCompare(a.created_at));
        const items = rows.map(row => {
            const item = element("li");
            item.append(element("strong", severities[row.severity] + " · " + categories[row.category]));
            item.append(element("small", row.facility + " · " + timeText(row.created_at, zone), "subtle"));
            const status = [row.read_at ? "Dibaca" : "Belum dibaca",
                row.acknowledged_at ? "Diakui" : "Belum diakui", row.resolved_at ? "Selesai" : "Aktif"];
            item.append(element("small", status.join(" · ")));
            const actions = element("div","","actions");
            actions.append(button("Fokus fasilitas", async () => {
                await focus(String(row.facility_id));
                if (!row.read_at) await update(row,"read");
            }));
            if (!row.read_at) actions.append(button("Tandai dibaca", () => update(row,"read")));
            if (root.dataset.respond === "1" && !row.acknowledged_at && !row.resolved_at) {
                actions.append(button("Akui", () => update(row,"acknowledge")));
            }
            if (root.dataset.manage === "1" && !row.resolved_at) {
                actions.append(button("Selesaikan", () => update(row,"resolve")));
            }
            item.append(actions);
            return item;
        });
        list.replaceChildren(...(items.length ? items : [element("li","Tidak ada kejadian yang sesuai.","subtle")]));
    }

    async function update(row, action) {
        try {
            await api("events",{body:{action,facility:row.facility_id,event:row.id}});
            await poll();
        } catch (error) {
            showStatus(error.message);
        }
    }

    async function poll() {
        clearTimeout(timer);
        if (stopped || (document.hidden && !browserOptIn)) return;
        if (pending) {
            queued = true;
            return;
        }
        pending = true;
        try {
            const data = await api("events",{query:{after:cursor ?? null,personal:personal ?? null}});
            if (stopped) return;
            const incoming = data.events.filter(row => cursor !== undefined && !data.reset && !events.has(row.id)
                && !row.read_at && !row.resolved_at && data.preferences[row.category]);
            if (data.reset) events.clear();
            for (const row of data.events) events.set(row.id,row);
            if (events.size > 200) {
                const latest = [...events.values()].sort((a,b) =>
                    Number(Boolean(a.resolved_at)) - Number(Boolean(b.resolved_at)) || b.created_at.localeCompare(a.created_at)).slice(0,200);
                events.clear();
                for (const row of latest) events.set(row.id,row);
            }
            cursor = data.revision;
            personal = data.personal;
            lastSync = data.synced_at;
            if (preferenceRevision !== personal && !form.contains(document.activeElement)) {
                fillPreferences(data.preferences);
                preferenceRevision = personal;
            }
            if (data.changed) {
                const active = data.badges.reduce((total,row) => total + Number(row.unread),0);
                root.querySelector("[data-unread]").textContent = active ? String(active) : "";
                onBadges(data.badges,events);
                render();
            }
            if (incoming.length && !data.preferences.muted && !data.preferences.quiet) notify(incoming.length);
            conn.textContent = "Terhubung · Sinkron terakhir " + timeText(lastSync,zone);
            retry = 30000;
            onRefresh(data.refresh_revision);
        } catch {
            if (stopped) return;
            conn.textContent = lastSync ? "Sinkronisasi terputus · Terakhir " + timeText(lastSync,zone) : "Sinkronisasi belum tersedia";
            retry = Math.min(retry * 2,120000);
        } finally {
            pending = false;
            if (!(stopped || (document.hidden && !browserOptIn))) {
                timer = setTimeout(poll,queued ? 25 : retry);
                queued = false;
            }
        }
    }

    function fillPreferences(preferences) {
        for (const key of [...Object.keys(categories),"muted"]) {
            form.elements[key].checked = preferences[key];
        }
        form.elements.quiet_start.value = preferences.quiet_start;
        form.elements.quiet_end.value = preferences.quiet_end;
    }

    form.addEventListener("submit", async event => {
        event.preventDefault();
        try {
            const prefs = await api("preferences",{body:Object.fromEntries(new FormData(form))});
            fillPreferences(prefs);
            showStatus("Preferensi disimpan.");
            await poll();
        } catch (error) {
            showStatus(error.message);
        }
    });
    panel.querySelectorAll("[name^=event_]").forEach(control => control.addEventListener("change",render));
    root.querySelector("[data-show-events]").addEventListener("click", () => {
        panel.open = !panel.open;
        if (panel.open) panel.scrollIntoView({block:"nearest"});
    });
    document.addEventListener("visibilitychange", () => {
        if (!document.hidden || browserOptIn) poll();
        else clearTimeout(timer);
    });
    window.addEventListener("offline", () => {
        if (stopped) return;
        conn.textContent = lastSync ? "Luring · Sinkron terakhir " + timeText(lastSync,zone) : "Luring";
    });
    window.addEventListener("online", () => poll());
    window.addEventListener("focus", () => poll());
    poll();
    return {
        stop,
        async create(facility, fields) {
            const outcome = await api("events",{body:{action:"create",facility,...fields}});
            await poll();
            return outcome;
        }
    };
}
