const records = document.querySelector("[data-profile-records]");
if (records) {
    const status = records.querySelector("[data-profile-busy]");
    for (const form of records.querySelectorAll("form[method=post]")) {
        form.addEventListener("submit", (event) => {
            if (form.getAttribute("aria-busy") === "true") {
                event.preventDefault();
                return;
            }
            form.setAttribute("aria-busy", "true");
            status.hidden = false;
        });
    }
    window.addEventListener("pageshow", () => {
        for (const form of records.querySelectorAll("form[aria-busy]")) {
            form.removeAttribute("aria-busy");
        }
        status.hidden = true;
    });
}
