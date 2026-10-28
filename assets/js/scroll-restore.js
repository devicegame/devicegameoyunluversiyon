(() => {
  const KEY = 'dg-scroll-pos:v3';
  const MAX_AGE_MS = 120000;

  const keyForUrl = (value) => {
    const url = new URL(value, window.location.href);
    const entries = Array.from(url.searchParams.entries()).sort(([aKey, aValue], [bKey, bValue]) => {
      if (aKey === bKey) return aValue.localeCompare(bValue);
      return aKey.localeCompare(bKey);
    });
    const query = entries
      .map(([name, entryValue]) => `${encodeURIComponent(name)}=${encodeURIComponent(entryValue)}`)
      .join('&');
    return url.pathname + (query ? `?${query}` : '');
  };

  const clearSaved = () => {
    try { window.sessionStorage.removeItem(KEY); } catch (_) {}
  };

  const peekSaved = () => {
    try {
      const raw = window.sessionStorage.getItem(KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (_) {
      return null;
    }
  };

  const isUsable = (data) => {
    if (!data || typeof data.y !== 'number' || !Number.isFinite(data.y)) return false;
    if (Date.now() - Number(data.t || 0) > MAX_AGE_MS) return false;
    if (data.key === keyForUrl(window.location.href)) return true;
    return Boolean(data.fallbackPath && data.path === window.location.pathname);
  };

  if (!window.location.hash && isUsable(peekSaved()) && 'scrollRestoration' in window.history) {
    window.history.scrollRestoration = 'manual';
  }

  const anchorForElement = (element) => {
    if (!(element instanceof Element)) return null;
    const anchored = element.closest('[id]');
    if (anchored && anchored.id) {
      return {
        id: anchored.id,
        offset: anchored.getBoundingClientRect().top,
      };
    }
    return null;
  };

  const visibleAnchor = () => {
    const candidates = document.querySelectorAll('main [id], section [id], article[id], [data-scroll-anchor][id]');
    let best = null;
    candidates.forEach((element) => {
      const rect = element.getBoundingClientRect();
      if (rect.bottom < 0 || rect.top > window.innerHeight) return;
      const distance = Math.abs(rect.top);
      if (!best || distance < best.distance) {
        best = { id: element.id, offset: rect.top, distance };
      }
    });
    return best ? { id: best.id, offset: best.offset } : null;
  };

  const saveFor = (target, fallbackPath = false, originElement = null) => {
    try {
      const url = new URL(target, window.location.href);
      const anchor = anchorForElement(originElement) || visibleAnchor();
      window.sessionStorage.setItem(KEY, JSON.stringify({
        key: keyForUrl(url.href),
        path: url.pathname,
        fallbackPath: Boolean(fallbackPath),
        y: Math.max(0, window.scrollY || window.pageYOffset || 0),
        anchorId: anchor ? anchor.id : '',
        anchorOffset: anchor ? Number(anchor.offset) || 0 : 0,
        t: Date.now(),
      }));
    } catch (_) {}
  };

  const formDestination = (form, submitter) => {
    const actionUrl = new URL(form.getAttribute('action') || window.location.href, window.location.href);
    const method = (form.method || 'get').toLowerCase();

    if (method === 'get') {
      const destination = new URL(actionUrl.href);
      destination.search = '';
      let formData;
      try {
        formData = submitter ? new FormData(form, submitter) : new FormData(form);
      } catch (_) {
        formData = new FormData(form);
        if (submitter && submitter.name) formData.append(submitter.name, submitter.value || '');
      }
      const params = new URLSearchParams();
      formData.forEach((value, name) => {
        if (typeof value === 'string') params.append(name, value);
      });
      destination.search = params.toString();
      return destination;
    }

    const returnValue = form.querySelector('input[name="return"]')?.value || '';
    if (returnValue) {
      // return değeri formun action adresine görelidir (örn. sosyal/aksiyon.php).
      try { return new URL(returnValue, actionUrl.href); } catch (_) {}
    }
    return null;
  };

  document.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return;
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.hasAttribute('data-no-scroll-restore')) return;
    if (form.target && form.target.toLowerCase() === '_blank') return;

    let destination = null;
    try {
      destination = formDestination(form, event.submitter || null);
    } catch (_) {}

    // Bilinçli bir bölüm çapasına dönen işlemlerde tarayıcı hedefi göstersin;
    // ancak genel akış çapaları () artık kullanılmadığı için üst sıçraması olmaz.
    if (destination?.hash) {
      clearSaved();
      return;
    }

    if (destination && destination.origin === window.location.origin) {
      saveFor(destination.href, destination.pathname === window.location.pathname, form);
      return;
    }

    // POST + yönlendirme genellikle aynı sayfaya döner; hedef farklıysa pathname eşleşmez.
    saveFor(window.location.href, true, form);
  }, true);

  document.addEventListener('click', (event) => {
    if (event.button !== 0) return;
    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!link) return;
    if (link.target && link.target.toLowerCase() === '_blank') return;
    if (link.hasAttribute('download')) return;

    let url;
    try { url = new URL(link.href, window.location.href); } catch (_) { return; }
    if (url.origin !== window.location.origin) return;

    const samePath = url.pathname === window.location.pathname;
    const explicit = link.hasAttribute('data-preserve-scroll')
      || link.closest('.social-topic-list, .social-tabs, .social-pagination, .filters, .admin-filters, .blog-categories, .social-comment-sort');
    const socialFilter = samePath && Boolean(link.closest('.social-shell'));

    if (!samePath) {
      // Akıştan konu/etiket bağlantısıyla yine akışa dönülüyorsa normal sayfa
      // geçişidir; sosyal akışın kendi AJAX katmanı bunu yerinde halleder.
      return;
    }

    if (url.hash) {
      // Aynı sayfa içi gerçek çapa (yorum, kural başlığı vb.) bilinçlidir.
      clearSaved();
      return;
    }

    // Aynı sayfadaki filtre/sekme/sayfalama bağlantıları konumu korur.
    // Normal aynı-sayfa yenileme bağlantılarında da eski konuma dönmek,
    // kullanıcının "bir şeye tıkladım, en üste attı" şikayetini önler.
    if (explicit || socialFilter) {
      saveFor(url.href, false, link);
    }
  }, true);

  let pending = null;
  let userMoved = false;
  ['wheel', 'touchmove', 'keydown'].forEach((name) => {
    window.addEventListener(name, () => { userMoved = true; }, { passive: true });
  });

  const jumpToPending = () => {
    if (!pending || userMoved) return;
    let top = Math.max(0, Number(pending.y) || 0);
    const anchorId = String(pending.anchorId || '');
    if (anchorId) {
      const target = document.getElementById(anchorId);
      if (target) {
        const rect = target.getBoundingClientRect();
        top = Math.max(0, rect.top + (window.scrollY || 0) - (Number(pending.anchorOffset) || 0));
      }
    }
    try {
      window.scrollTo({ top, left: 0, behavior: 'instant' });
    } catch (_) {
      window.scrollTo(0, top);
    }
  };

  const restore = () => {
    const data = peekSaved();
    clearSaved();
    if (window.location.hash || !isUsable(data)) return;
    pending = data;
    jumpToPending();
    // Görsel/font yüklemeleri sayfa yüksekliğini değiştirirse çapa konumunu tazele.
    window.setTimeout(jumpToPending, 120);
    window.setTimeout(jumpToPending, 450);
    window.setTimeout(jumpToPending, 1000);
  };

  const restoreHashTarget = () => {
    if (!window.location.hash || userMoved) return;
    const id = decodeURIComponent(window.location.hash.slice(1));
    const target = id ? document.getElementById(id) : null;
    if (!target) return;
    // Tarayıcı ilk atlamayı görseller yüklenmeden yapmış olabilir; hedefi tazele.
    try {
      target.scrollIntoView({ block: 'start', behavior: 'instant' });
    } catch (_) {
      target.scrollIntoView();
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', restore, { once: true });
  } else {
    restore();
  }

  window.addEventListener('load', () => {
    jumpToPending();
    restoreHashTarget();
  });
  window.addEventListener('pageshow', (event) => {
    // Geri/ileri önbelleğindeki konumu tarayıcı zaten geri yükledi.
    if (event.persisted) {
      pending = null;
      clearSaved();
    }
  });
})();
