(() => {
    'use strict';

    const nativeFetch = window.fetch.bind(window);
    const target = 'check_feb_document_fidest_ia.php';
    const pollDelay = 3000;
    let lastState = '';

    const sleep = ms => new Promise(resolve => window.setTimeout(resolve, ms));

    function isTarget(input) {
        const url = typeof input === 'string' ? input : (input && input.url) || '';
        return url.includes(target);
    }

    function statusUrl(input) {
        const raw = typeof input === 'string' ? input : (input && input.url) || '../request/check_feb_document_fidest_ia.php';
        const url = new URL(raw, window.location.href);
        url.search = '';
        url.searchParams.set('action', 'ocr_status');
        return url.toString();
    }

    function ensureNotice() {
        let notice = document.getElementById('febOcrAvailabilityNotice');
        if (notice) return notice;
        notice = document.createElement('div');
        notice.id = 'febOcrAvailabilityNotice';
        notice.setAttribute('role', 'status');
        notice.setAttribute('aria-live', 'polite');
        notice.style.cssText = 'position:fixed;right:20px;bottom:20px;z-index:99999;max-width:390px;padding:14px 16px;border-radius:16px;background:#fff;border:1px solid #e2e8f0;box-shadow:0 18px 50px rgba(15,23,42,.16);font:600 14px/1.45 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#0f172a;display:none';
        document.body.appendChild(notice);
        return notice;
    }

    function notify(state, message, elapsedSeconds = 0) {
        const notice = ensureNotice();
        const icons = { available: '✓', busy: '⏳', unavailable: '!', checking: '…' };
        const titles = { available: 'IA disponible', busy: 'IA occupée', unavailable: 'OCR indisponible', checking: 'Vérification de l’IA' };
        notice.innerHTML = '<div style="display:flex;gap:11px;align-items:flex-start"><span style="display:flex;width:28px;height:28px;align-items:center;justify-content:center;border-radius:999px;background:#f1f5f9">' + (icons[state] || 'i') + '</span><span><strong style="display:block;margin-bottom:2px">' + (titles[state] || 'FIDEST IA') + '</strong><span style="font-weight:500;color:#475569">' + escapeHtml(message) + (state === 'busy' && elapsedSeconds > 0 ? ' · attente ' + elapsedSeconds + ' s' : '') + '</span></span></div>';
        notice.style.display = 'block';
        if (state !== 'busy' || lastState !== state) {
            notice.animate([{opacity:.4,transform:'translateY(6px)'},{opacity:1,transform:'translateY(0)'}], {duration:180});
        }
        lastState = state;
        if (state === 'available') window.setTimeout(() => { if (lastState === 'available') notice.style.display = 'none'; }, 2200);
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
    }

    async function readStatus(url) {
        const response = await nativeFetch(url, {
            method: 'GET',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            cache: 'no-store',
            credentials: 'same-origin'
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'Impossible de vérifier la disponibilité de FIDEST IA.');
        }
        return data.ocr || { status: 'unavailable', available: false, busy: false, message: 'Service OCR temporairement indisponible.' };
    }

    async function waitUntilAvailable(url) {
        const started = Date.now();
        notify('checking', 'Contrôle de la disponibilité du moteur OCR…');
        for (;;) {
            let ocr;
            try {
                ocr = await readStatus(url);
            } catch (error) {
                notify('unavailable', error.message || 'Service OCR temporairement indisponible.');
                throw error;
            }

            if (ocr.status === 'available' || ocr.available === true) {
                notify('available', ocr.message || 'FIDEST IA est disponible.');
                return;
            }

            if (ocr.status === 'busy' || ocr.busy === true) {
                notify('busy', ocr.message || 'Je suis occupée en ce moment. Merci de patienter.', Math.floor((Date.now() - started) / 1000));
                await sleep(pollDelay);
                continue;
            }

            const message = ocr.message || 'Service OCR temporairement indisponible.';
            notify('unavailable', message);
            throw new Error(message);
        }
    }

    window.fetch = async function(input, init = {}) {
        const method = String(init.method || (input && input.method) || 'GET').toUpperCase();
        if (!isTarget(input) || method !== 'POST') return nativeFetch(input, init);

        const availabilityUrl = statusUrl(input);
        await waitUntilAvailable(availabilityUrl);

        for (;;) {
            const response = await nativeFetch(input, init);
            if (response.status !== 409) return response;

            let payload = {};
            try { payload = await response.clone().json(); } catch (_) {}
            if (payload.code !== 'OCR_BUSY' && payload.ocr_busy !== true) return response;

            notify('busy', payload.error || 'Une autre analyse OCR est en cours. Votre document reste prêt et sera analysé dès que possible.');
            await sleep(pollDelay);
            await waitUntilAvailable(availabilityUrl);
        }
    };
})();