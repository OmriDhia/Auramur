(function () {
  const $ = (s, r=document) => r.querySelector(s);
  const $$ = (s, r=document) => Array.from(r.querySelectorAll(s));

  const panel = $('#us-panel');
  if (!panel) return;
  const toggle = $('.us-toggle');
  const tabs = $$('.us-modes [role="tab"]', panel);
  const escapeHtml = (str = '') => str.replace(/[&<>"']/g, (m) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  const debounce = (fn, wait=180) => {
    let t;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn.apply(null, args), wait);
    };
  };
  const currencyCode = (typeof UnivSearch.currency === 'string' && UnivSearch.currency)
    ? UnivSearch.currency
    : 'USD';

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
  const instant = $('.us-instant', panel);
  const instantList = $('.us-instant-list', panel);
  const instantMore = $('.us-instant-more', panel);
  const textInput = textForm ? $('input[name="q"]', textForm) : null;
  let abortController = null;
  let hideTimeout = null;

  function buildResultsUrl(query, payload) {
    const base = UnivSearch.resultsBase || '/search-all/';
    const url = new URL(base, window.location.origin);
    url.searchParams.set('q', query);
    url.searchParams.set('mode', 'text');
    if (payload) {
      try {
        url.searchParams.set('rq', btoa(JSON.stringify(payload)));
      } catch (err) {
        // ignore encoding errors
      }
    }
    return url.toString();
  }

  function hideInstant(force=false) {
    if (!instant) return;
    if (force) {
      instant.hidden = true;
      instantList.innerHTML = '';
      instant.dataset.state = 'idle';
      return;
    }
    hideTimeout = setTimeout(() => {
      if (!panel.contains(document.activeElement)) {
        instant.hidden = true;
        instantList.innerHTML = '';
        instant.dataset.state = 'idle';
      }
    }, 120);
  }

  function showInstant() {
    if (!instant) return;
    clearTimeout(hideTimeout);
    instant.hidden = false;
  }

  async function runInstantSearch(value) {
    if (!instant || !instantList) return;
    const query = value.trim();
    if (!query) {
      hideInstant(true);
      return;
    }

    showInstant();
    instant.dataset.state = 'loading';
    instantList.innerHTML = '<li class="us-instant-status">Searching…</li>';
    if (instantMore) {
      instantMore.hidden = true;
    }

    if (abortController) {
      abortController.abort();
    }
    abortController = new AbortController();

    const filters = Array.isArray(UnivSearch.indexedPostTypes) && UnivSearch.indexedPostTypes.length
      ? { post_type: UnivSearch.indexedPostTypes.filter(Boolean) }
      : null;
    const payload = { query, limit: 5, page: 1 };
    if (filters && filters.post_type.length) {
      payload.filters = filters;
    }

    try {
      const response = await fetch(`${UnivSearch.restBase}/run`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': UnivSearch.nonce,
        },
        body: JSON.stringify(payload),
        signal: abortController.signal,
      });
      const data = await response.json();
      if (!response.ok) {
        throw new Error(data?.message || 'Search failed');
      }
      const hits = data?.results?.hits || [];
      if (!hits.length) {
        instant.dataset.state = 'empty';
        instantList.innerHTML = '<li class="us-instant-empty">No matches yet.</li>';
        if (instantMore) {
          instantMore.hidden = false;
          instantMore.href = buildResultsUrl(query, { query, limit: 24, page: 1, filters });
        }
        return;
      }
      instant.dataset.state = 'results';
      instantList.innerHTML = hits.map((hit) => {
        const doc = hit.document || {};
        const title = escapeHtml(doc.title || 'Untitled');
        const meta = [];
        if (doc.post_type) {
          meta.push(escapeHtml(String(doc.post_type).replace(/_/g, ' ')));
        }
        const price = typeof doc.price !== 'undefined' && doc.price !== null && doc.price !== ''
          ? parseFloat(doc.price)
          : null;
        if (price && !Number.isNaN(price) && price > 0) {
          try {
            meta.push(new Intl.NumberFormat(undefined, { style: 'currency', currency: currencyCode }).format(price));
          } catch (formatErr) {
            meta.push(`${price.toFixed(2)} ${currencyCode}`);
          }
        }
        const excerpt = doc.excerpt ? escapeHtml(String(doc.excerpt).substring(0, 140)) : '';
        return `<li><a href="${doc.permalink ? doc.permalink : '#'}"><span class="us-instant-title">${title}</span>${meta.length ? `<span class="us-instant-meta">${meta.join(' · ')}</span>` : ''}${excerpt ? `<span class="us-instant-excerpt">${excerpt}</span>` : ''}</a></li>`;
      }).join('');
      if (instantMore) {
        const fullPayload = { query, limit: 24, page: 1 };
        if (filters && filters.post_type.length) {
          fullPayload.filters = filters;
        }
        instantMore.hidden = false;
        instantMore.href = buildResultsUrl(query, fullPayload);
      }
    } catch (err) {
      if (err.name === 'AbortError') return;
      instant.dataset.state = 'error';
      instantList.innerHTML = `<li class="us-instant-error">${escapeHtml(err.message || 'Search error')}</li>`;
      if (instantMore) {
        instantMore.hidden = true;
      }
    }
  }

  if (textInput && instant) {
    textInput.addEventListener('input', debounce(() => runInstantSearch(textInput.value)));
    textInput.addEventListener('focus', () => {
      if (textInput.value.trim()) {
        runInstantSearch(textInput.value);
      }
    });
    textInput.addEventListener('blur', () => hideInstant());
    instant.addEventListener('mouseenter', () => clearTimeout(hideTimeout));
    instant.addEventListener('mouseleave', () => hideInstant(true));
  }

  if (textForm) textForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const q = new FormData(e.target).get('q') || '';
    window.location = buildResultsUrl(q, { query: q, limit: 24, page: 1 });
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