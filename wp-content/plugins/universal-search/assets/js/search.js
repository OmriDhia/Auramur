(function () {
  const $ = (s, r=document) => r.querySelector(s);
  const $$ = (s, r=document) => Array.from(r.querySelectorAll(s));

  const panel = $('#us-panel');
  if (!panel) return;
  const toggle = $('.us-toggle');
  const tabs = $$('.us-modes [role="tab"]', panel);

  toggle.addEventListener('click', () => {
    const open = panel.hasAttribute('hidden');
    panel.toggleAttribute('hidden', !open);
    toggle.setAttribute('aria-expanded', String(open));
  });

  tabs.forEach(tab => tab.addEventListener('click', () => {
    tabs.forEach(t => t.setAttribute('aria-selected', 'false'));
    tab.setAttribute('aria-selected', 'true');
    const mode = tab.dataset.mode;
    $$('.us-mode', panel).forEach(x => x.hidden = true);
    $(`.us-mode-${mode}`, panel).hidden = false;
  }));

  // TEXT
  const textForm = $('.us-form-text', panel);
  if (textForm) textForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const q = new FormData(e.target).get('q') || '';
    const url = new URL('/search-all/', window.location.origin);
    url.searchParams.set('q', q);
    url.searchParams.set('mode', 'text');
    window.location = url.toString();
  });

  // VOICE
  let mediaRecorder, chunks=[];
  const btnStart = $('.us-voice-start', panel);
  const btnStop  = $('.us-voice-stop', panel);
  const statusEl = $('.us-voice-status', panel);

  async function startRec() {
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      const mime = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : 'audio/ogg';
      mediaRecorder = new MediaRecorder(stream, { mimeType: mime });
      chunks = [];
      mediaRecorder.ondataavailable = e => chunks.push(e.data);
      mediaRecorder.onstop = async () => {
        const blob = new Blob(chunks, { type: mime });
        const fd = new FormData(); fd.append('file', blob, 'voice.webm');
        statusEl.textContent = 'Transcribing…';
        try {
          const r = await fetch(`${UnivSearch.restBase}/voice`, {
            method: 'POST',
            headers: { 'X-WP-Nonce': UnivSearch.nonce },
            body: fd
          });
          const data = await r.json();
          if (!r.ok) { statusEl.textContent = data?.message || 'Voice error'; return; }
          statusEl.textContent = 'Searching…';
          const run = await fetch(`${UnivSearch.restBase}/run`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': UnivSearch.nonce },
            body: JSON.stringify(data.query)
          });
          await run.json();
          const url = new URL('/search-all/', window.location.origin);
          url.searchParams.set('q', data.query.query || '');
          url.searchParams.set('mode', 'voice');
          url.searchParams.set('rq', btoa(JSON.stringify(data.query)));
          window.location = url.toString();
        } catch (err) {
          statusEl.textContent = 'An error occurred.';
        }
      };
      mediaRecorder.start();
      btnStart.disabled = true; btnStop.disabled = false; statusEl.textContent = 'Recording…';
    } catch (e) {
      statusEl.textContent = 'Microphone not available.';
    }
  }
  if (btnStart) btnStart.addEventListener('click', startRec);
  if (btnStop) btnStop.addEventListener('click', () => { mediaRecorder?.stop(); btnStart.disabled=false; btnStop.disabled=true; });

  // IMAGE
  const imageForm = $('.us-form-image', panel);
  if (imageForm) imageForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    try {
      const r = await fetch(`${UnivSearch.restBase}/image`, {
        method: 'POST',
        headers: { 'X-WP-Nonce': UnivSearch.nonce },
        body: fd
      });
      const data = await r.json();
      if (!r.ok) { alert(data?.message || 'Image search failed'); return; }
      const url = new URL('/search-all/', window.location.origin);
      url.searchParams.set('q', data.query.query || '');
      url.searchParams.set('mode', 'image');
      url.searchParams.set('rq', btoa(JSON.stringify(data.query)));
      window.location = url.toString();
    } catch (e) {
      alert('Image search failed.');
    }
  });
})();