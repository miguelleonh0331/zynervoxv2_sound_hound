// ==== Panel de propiedades redimensionable (arrastrar el divisor) ====
(function () {
  const root = document.documentElement;
  const saved = Number(localStorage.getItem("ivrbuilder_right_w"));
  if (saved && saved >= 260 && saved <= 1100) root.style.setProperty("--right-w", saved + "px");

  const resizer = document.getElementById("resizer");
  let dragging = false;

  resizer.addEventListener("mousedown", (e) => {
    dragging = true;
    resizer.classList.add("active");
    e.preventDefault();
  });
  document.addEventListener("mousemove", (e) => {
    if (!dragging) return;
    let w = Math.max(260, Math.min(1100, window.innerWidth - e.clientX));
    root.style.setProperty("--right-w", w + "px");
  });
  document.addEventListener("mouseup", () => {
    if (!dragging) return;
    dragging = false;
    resizer.classList.remove("active");
    let w = getComputedStyle(root).getPropertyValue("--right-w").trim();
    localStorage.setItem("ivrbuilder_right_w", parseInt(w, 10) || 460);
  });
})();
