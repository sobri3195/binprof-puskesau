import * as THREE from "../vendor/three/three.module.min.js";

export function createGlobe(container, facilities, select, paused, color = () => "#d2fcea") {
    const canvas = document.createElement("canvas");
    const context = canvas.getContext("webgl2", { alpha: true, antialias: true });
    if (!context) throw new Error("WebGL2 unavailable");
    const renderer = new THREE.WebGLRenderer({ canvas, context, alpha: true, antialias: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.5));
    container.append(canvas);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(37, 1, 0.1, 30);
    camera.position.set(0, 0, 4.2);
    const globe = new THREE.Group();
    scene.add(globe);
    // Longitude 118° faces the camera initially.
    globe.rotation.y = 118 * Math.PI / 180 - Math.PI / 2;
    const body = new THREE.Mesh(
        new THREE.SphereGeometry(1, 40, 24),
        new THREE.MeshBasicMaterial({ color: 0x1e5372, transparent: true, opacity: 0.65 })
    );
    globe.add(body);
    const wire = new THREE.Mesh(
        new THREE.SphereGeometry(1.008, 20, 12),
        new THREE.MeshBasicMaterial({ color: 0x70c7cf, wireframe: true, transparent: true, opacity: 0.36 })
    );
    globe.add(wire);
    const markerGeometry = new THREE.SphereGeometry(0.027, 8, 6);
    const markerMaterials = new Map();
    function material(code) {
        if (!markerMaterials.has(code)) markerMaterials.set(code,new THREE.MeshBasicMaterial({color:code}));
        return markerMaterials.get(code);
    }
    const markers = [];
    for (const facility of facilities) {
        const latitude = Number(facility.latitude) * Math.PI / 180;
        const longitude = Number(facility.longitude) * Math.PI / 180;
        const marker = new THREE.Mesh(markerGeometry, material(color(facility)));
        marker.position.set(1.04 * Math.cos(latitude) * Math.cos(longitude), 1.04 * Math.sin(latitude),
            1.04 * Math.cos(latitude) * Math.sin(longitude));
        marker.userData.facility = facility;
        globe.add(marker);
        markers.push(marker);
    }

    const reduced = matchMedia("(prefers-reduced-motion: reduce)");
    const pointer = new THREE.Vector2();
    const ray = new THREE.Raycaster();
    let frame = 0;
    let visible = false;
    let contextLost = false;
    let previous = 0;

    function redraw() {
        if (!contextLost) renderer.render(scene, camera);
    }
    function rotate(now) {
        if (previous) globe.rotation.y -= Math.min(35, now - previous) * 0.00025;
        previous = now;
        redraw();
        frame = requestAnimationFrame(rotate);
    }
    function sync() {
        cancelAnimationFrame(frame);
        previous = 0;
        if (visible && !document.hidden && !paused && !reduced.matches && !contextLost) {
            frame = requestAnimationFrame(rotate);
        } else {
            redraw();
        }
    }
    function resize() {
        const width = Math.max(1, container.clientWidth);
        const height = Math.max(1, container.clientHeight);
        camera.aspect = width / height;
        camera.position.z = width / height < 0.9 ? 5.25 : 4.2;
        camera.updateProjectionMatrix();
        renderer.setSize(width, height);
        redraw();
    }
    function pointed(event) {
        const box = canvas.getBoundingClientRect();
        pointer.set((event.clientX - box.left) / box.width * 2 - 1, -(event.clientY - box.top) / box.height * 2 + 1);
        ray.setFromCamera(pointer, camera);
        const target = ray.intersectObjects([body, ...markers], false)[0]?.object;
        if (target?.userData.facility) return target.userData.facility;
        return null;
    }
    function hover(event) {
        canvas.style.cursor = pointed(event) ? "pointer" : "";
    }
    function clicked(event) {
        select?.(pointed(event));
    }
    function navigate(event) {
        const turns = { ArrowLeft: 0.15, ArrowRight: -0.15, ArrowUp: 0.15, ArrowDown: -0.15 };
        if (!(event.key in turns)) return;
        event.preventDefault();
        if (event.key === "ArrowLeft" || event.key === "ArrowRight") globe.rotation.y += turns[event.key];
        else globe.rotation.x = THREE.MathUtils.clamp(globe.rotation.x + turns[event.key], -1.4, 1.4);
        redraw();
    }
    function lost(event) {
        event.preventDefault();
        contextLost = true;
        sync();
    }
    function restored() {
        contextLost = false;
        sync();
    }

    const size = new ResizeObserver(resize);
    size.observe(container);
    const intersection = new IntersectionObserver(([entry]) => {
        visible = entry.isIntersecting;
        sync();
    });
    intersection.observe(container);
    document.addEventListener("visibilitychange", sync);
    reduced.addEventListener("change", sync);
    canvas.addEventListener("webglcontextlost", lost);
    canvas.addEventListener("webglcontextrestored", restored);
    if (select) {
        canvas.tabIndex = 0;
        canvas.setAttribute("aria-label","Globe. Putar dengan tombol arah.");
        canvas.addEventListener("keydown", navigate);
        canvas.addEventListener("pointermove", hover);
        canvas.addEventListener("click", clicked);
    }
    resize();

    return {
        pause(value) {
            paused = value;
            sync();
        },
        focus(row) {
            globe.rotation.order = "XYZ";
            globe.rotation.y = Number(row.longitude) * Math.PI / 180 - Math.PI / 2;
            globe.rotation.x = Number(row.latitude) * Math.PI / 180;
            redraw();
        },
        reset() {
            this.focus({longitude:118,latitude:0});
        },
        recolor(nextColor) {
            for (const marker of markers) marker.material = material(nextColor(marker.userData.facility));
            redraw();
        },
        dispose() {
            cancelAnimationFrame(frame);
            size.disconnect();
            intersection.disconnect();
            document.removeEventListener("visibilitychange", sync);
            reduced.removeEventListener("change", sync);
            canvas.removeEventListener("webglcontextlost", lost);
            canvas.removeEventListener("webglcontextrestored", restored);
            canvas.removeEventListener("pointermove", hover);
            canvas.removeEventListener("click", clicked);
            body.geometry.dispose();
            body.material.dispose();
            wire.geometry.dispose();
            wire.material.dispose();
            markerGeometry.dispose();
            for (const item of markerMaterials.values()) item.dispose();
            canvas.removeEventListener("keydown", navigate);
            renderer.dispose();
            renderer.forceContextLoss();
            canvas.remove();
        }
    };
}
