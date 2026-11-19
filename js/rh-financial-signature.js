(function () {
  'use strict';
  document.querySelectorAll('form[data-rh-manual-signature]').forEach(function (form) {
    const canvas = form.querySelector('[data-rh-manual-canvas]');
    const input = form.querySelector('[data-rh-manual-value]') || form.querySelector('[name="rh_manual_signature"]');
    const status = form.querySelector('[data-rh-manual-status]');
    const context = canvas.getContext('2d');
    let strokes = [], current = null, pointer = null, count = 0;
    context.lineWidth = 2.8;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    context.strokeStyle = '#172033';
    function point(event) {
      const rect = canvas.getBoundingClientRect();
      return [Math.round(Math.max(0, Math.min(600, (event.clientX - rect.left) * 600 / rect.width)) * 100) / 100,
        Math.round(Math.max(0, Math.min(180, (event.clientY - rect.top) * 180 / rect.height)) * 100) / 100];
    }
    canvas.addEventListener('pointerdown', function (event) {
      if (pointer !== null || (event.pointerType === 'mouse' && event.button !== 0)) return;
      if (strokes.length >= 100 || count >= 2500) { status.textContent = 'Effacez le cadre pour recommencer la signature.'; return; }
      event.preventDefault();
      pointer = event.pointerId;
      canvas.setPointerCapture(pointer);
      current = [point(event)]; strokes.push(current); count++;
      context.beginPath(); context.moveTo(...current[0]);
    });
    canvas.addEventListener('pointermove', function (event) {
      if (event.pointerId !== pointer || !current || count >= 2500) return;
      const next = point(event);
      current.push(next); count++;
      context.lineTo(...next); context.stroke();
      input.value = ''; status.textContent = 'Signature en cours.';
    });
    function finish(event) {
      if (event.pointerId !== pointer) return;
      current = null; pointer = null;
      status.textContent = 'Signature prête à être enregistrée.';
    }
    canvas.addEventListener('pointerup', finish);
    canvas.addEventListener('pointercancel', finish);
    canvas.addEventListener('lostpointercapture', finish);
    form.querySelector('[data-rh-manual-clear]').addEventListener('click', function () {
      context.clearRect(0, 0, 600, 180); strokes = []; current = null; pointer = null; count = 0; input.value = '';
      status.textContent = 'Dessinez votre signature dans le cadre.';
    });
    form.addEventListener('submit', function (event) {
      const method = form.querySelector('[name="signature_method"]:checked') || form.querySelector('[name="signature_method"]');
      if (method && method.value !== 'manual') return;
      let distance = 0;
      strokes.forEach(stroke => stroke.forEach((p, index) => {
        if (index) distance += Math.hypot(p[0] - stroke[index - 1][0], p[1] - stroke[index - 1][1]);
      }));
      if (distance < 50) {
        event.preventDefault(); status.textContent = 'Dessinez une signature lisible avant de signer.'; return;
      }
      input.value = JSON.stringify(strokes);
    });
  });
})();
