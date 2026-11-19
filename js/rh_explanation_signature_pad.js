document.addEventListener("DOMContentLoaded", function () {
  document.querySelectorAll(".js-signature-pad").forEach(function (container) {
    const canvas = container.querySelector(".js-signature-canvas");
    const hiddenInput = container.querySelector(".js-signature-value");
    const statusEl = container.querySelector(".js-signature-status");
    const hintEl = container.querySelector(".js-signature-hint");
    const previewEl = container.querySelector(".js-signature-preview");
    const clearButton = container.querySelector(".js-signature-clear");
    const useButton = container.querySelector(".js-signature-use");

    if (!canvas || !hiddenInput) return;

    const form = container.closest("form");
    if (form) {
      const action = form.querySelector('[name="action"]')?.value || "";
      const requestId = form.querySelector('[name="request_id"]')?.value || "";
      const role = form.querySelector('[name="signature_role"]')?.value || "";
      if (action === "save_signature" && requestId && role) {
        form.dataset.passkeyContext = `rh_request:${requestId}:${role}`;
      }
    }

    const context = canvas.getContext("2d");
    let drawing = false;
    let lastPoint = null;
    let hasSignature = false;
    let strokeCount = 0;
    let totalDistance = 0;
    const MIN_STROKES = 1;
    const MIN_DISTANCE = 50;

    function updateStatus(message) { if (statusEl) statusEl.textContent = message; }
    function resizeCanvas() {
      const ratio = window.devicePixelRatio || 1;
      const rect = canvas.getBoundingClientRect();
      canvas.width = Math.max(1, rect.width * ratio);
      canvas.height = Math.max(1, rect.height * ratio);
      context.setTransform(1, 0, 0, 1, 0, 0);
      context.scale(ratio, ratio);
      context.lineCap = "round";
      context.lineJoin = "round";
      context.lineWidth = 2.8;
      context.strokeStyle = "#172033";
      context.fillStyle = "#ffffff";
      context.clearRect(0, 0, rect.width, rect.height);
      context.fillRect(0, 0, rect.width, rect.height);
    }
    function clearSignature() {
      resizeCanvas(); drawing = false; lastPoint = null; hasSignature = false; strokeCount = 0; totalDistance = 0; hiddenInput.value = "";
      if (hintEl) hintEl.style.display = "block";
      if (previewEl) previewEl.innerHTML = previewEl.dataset.emptyLabel || "Aucune signature enregistrée";
      updateStatus("Aucune signature capturée");
    }
    function renderPreview(dataUrl) { if (previewEl) previewEl.innerHTML = '<img src="' + dataUrl + '" alt="Signature" style="max-width:100%; max-height:92px; object-fit:contain;" />'; }
    function captureSignature() {
      if (!hasSignature || strokeCount < MIN_STROKES || totalDistance < MIN_DISTANCE) { updateStatus("Signature trop courte ou absente"); return false; }
      const dataUrl = canvas.toDataURL("image/png"); hiddenInput.value = dataUrl; if (hintEl) hintEl.style.display = "none"; renderPreview(dataUrl); updateStatus("Signature capturée et prête à être enregistrée"); return true;
    }
    function pointFromEvent(event) { const rect = canvas.getBoundingClientRect(); return { x: event.clientX - rect.left, y: event.clientY - rect.top }; }
    function startDraw(x, y) { drawing = true; hasSignature = true; strokeCount += 1; context.beginPath(); context.moveTo(x, y); lastPoint = { x, y }; if (hintEl) hintEl.style.display = "none"; updateStatus("Signature en cours..."); }
    function drawLine(x, y) { if (!drawing) return; context.lineTo(x, y); context.stroke(); if (lastPoint) { const dx=x-lastPoint.x, dy=y-lastPoint.y; totalDistance += Math.sqrt(dx*dx+dy*dy); } lastPoint={x,y}; }
    function endDraw() { if (!drawing) return; drawing=false; captureSignature(); }

    resizeCanvas(); window.addEventListener("resize", resizeCanvas);
    if (window.ResizeObserver && canvas.parentElement) new ResizeObserver(resizeCanvas).observe(canvas.parentElement);
    canvas.addEventListener("mousedown", event => { const p=pointFromEvent(event); startDraw(p.x,p.y); });
    canvas.addEventListener("mousemove", event => { const p=pointFromEvent(event); drawLine(p.x,p.y); });
    window.addEventListener("mouseup", endDraw);
    canvas.addEventListener("touchstart", event => { event.preventDefault(); if (!event.touches.length) return; const p=pointFromEvent(event.touches[0]); startDraw(p.x,p.y); }, {passive:false});
    canvas.addEventListener("touchmove", event => { event.preventDefault(); if (!event.touches.length) return; const p=pointFromEvent(event.touches[0]); drawLine(p.x,p.y); }, {passive:false});
    canvas.addEventListener("touchend", event => { event.preventDefault(); endDraw(); }, {passive:false});
    clearButton?.addEventListener("click", clearSignature);
    useButton?.addEventListener("click", () => { if (!captureSignature()) window.alert("Merci de dessiner une signature lisible avant validation."); });
    if (form) form.addEventListener("submit", event => { if (!hiddenInput.value && !captureSignature()) { event.preventDefault(); window.alert("La signature manuscrite est requise pour cet enregistrement."); } });
    if (previewEl && previewEl.querySelector("img")) { updateStatus("Signature déjà enregistrée"); if (hintEl) hintEl.style.display="none"; } else updateStatus("Aucune signature capturée");
  });
});
