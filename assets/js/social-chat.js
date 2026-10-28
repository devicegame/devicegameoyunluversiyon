(() => {
  const root = document.querySelector('[data-chat-root]');
  if (!root) return;

  const list = root.querySelector('[data-chat-messages]');
  const form = root.querySelector('[data-chat-form]');
  const textarea = form?.querySelector('textarea[name="body"]');
  const pinnedBox = root.querySelector('[data-chat-pinned]');
  const onlineBox = root.querySelector('[data-chat-online]');
  const typingBox = root.querySelector('[data-chat-typing]');
  const statusBox = root.querySelector('[data-chat-status]');
  const newButton = root.querySelector('[data-chat-new]');
  const titleBox = root.querySelector('[data-chat-title]');
  const descBox = root.querySelector('[data-chat-description]');
  const seen = new Set();
  let channel = root.dataset.channel || 'genel';
  let lastId = Number(root.dataset.lastId || 0) || 0;
  let pollTimer = 0;
  let typingTimer = 0;
  let typingSentAt = 0;
  let failures = 0;
  let requestId = 0;

  const toast = (message, isError = false) => {
    if (window.DGSocialToast) window.DGSocialToast(message, isError);
  };

  const initials = (name) => {
    const parts = String(name || 'DG').trim().split(/\s+/).slice(0, 2);
    return parts.map((part) => Array.from(part)[0] || '').join('').toLocaleUpperCase('tr-TR') || 'DG';
  };

  const isNearBottom = () => {
    if (!list) return true;
    return list.scrollHeight - list.scrollTop - list.clientHeight < 150;
  };

  const scrollBottom = (smooth = false) => {
    if (!list) return;
    list.scrollTo({ top: list.scrollHeight, behavior: smooth ? 'smooth' : 'instant' });
    if (newButton) newButton.hidden = true;
  };

  const makeAvatar = (message) => {
    const wrap = document.createElement('span');
    wrap.className = 'social-avatar';
    wrap.setAttribute('aria-hidden', 'true');
    if (message.author_avatar) {
      const img = document.createElement('img');
      img.src = message.author_avatar.startsWith('../') || /^https?:/i.test(message.author_avatar)
        ? message.author_avatar
        : `../${message.author_avatar}`;
      img.alt = '';
      img.loading = 'lazy';
      img.addEventListener('error', () => img.remove());
      wrap.appendChild(img);
    }
    const span = document.createElement('span');
    span.className = 'social-avatar-initials';
    span.textContent = initials(message.author_display || message.author_username);
    wrap.appendChild(span);
    return wrap;
  };

  const hiddenInput = (name, value) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = String(value ?? '');
    return input;
  };

  const buildActions = (message) => {
    const currentUserId = Number(root.dataset.userId || 0);
    const isAdmin = root.dataset.isAdmin === '1';
    if (!currentUserId || message.status !== 'published') return null;
    const mine = Number(message.user_id) === currentUserId;
    if (!mine && !isAdmin) return null;
    const csrf = root.dataset.csrf || form?.querySelector('input[name="csrf"]')?.value || '';
    const details = document.createElement('details');
    details.className = 'social-chat-actions';
    const summary = document.createElement('summary');
    summary.textContent = 'İşlemler';
    details.appendChild(summary);

    const makeStateForm = (status, label, confirmText = '') => {
      const stateForm = document.createElement('form');
      stateForm.method = 'post';
      stateForm.action = 'aksiyon.php';
      stateForm.dataset.socialAjax = 'chat-state';
      if (confirmText) stateForm.dataset.confirm = confirmText;
      stateForm.append(
        hiddenInput('csrf', csrf),
        hiddenInput('islem', 'sohbet_durum'),
        hiddenInput('channel', channel),
        hiddenInput('message_id', message.id),
        hiddenInput('status', status),
        hiddenInput('return', `sohbet.php?kanal=${encodeURIComponent(channel)}#sohbet-son`),
      );
      const button = document.createElement('button');
      button.type = 'submit';
      button.className = 'social-text-button';
      button.textContent = label;
      stateForm.appendChild(button);
      return stateForm;
    };

    if (mine || isAdmin) details.appendChild(makeStateForm('deleted', 'Sil', 'Bu sohbet mesajı silinsin mi?'));
    if (isAdmin) {
      details.appendChild(makeStateForm(message.pinned ? 'unpin' : 'pin', message.pinned ? 'Sabitten kaldır' : 'Sabitle'));
      details.appendChild(makeStateForm('hidden', 'Gizle'));
    }
    return details.childNodes.length > 1 ? details : null;
  };

  const createMessage = (message) => {
    const article = document.createElement('article');
    article.className = `social-chat-message${message.mine ? ' is-mine' : ''}${message.pinned ? ' is-pinned' : ''}`;
    article.dataset.messageId = String(message.id);

    const profileLink = document.createElement('a');
    profileLink.href = `profil.php?kullanici=${encodeURIComponent(message.author_username || '')}`;
    profileLink.appendChild(makeAvatar(message));
    article.appendChild(profileLink);

    const bubble = document.createElement('div');
    bubble.className = 'social-chat-bubble';
    const meta = document.createElement('div');
    meta.className = 'social-dm-meta';
    const strong = document.createElement('strong');
    strong.textContent = message.author_display || message.author_username || 'Oyuncu';
    const small = document.createElement('small');
    small.textContent = `@${message.author_username || ''}`;
    const time = document.createElement('time');
    time.textContent = message.time_label || message.date_label || '';
    time.title = message.date_label || '';
    meta.append(strong, small, time);
    if (message.pinned) {
      const badge = document.createElement('span');
      badge.className = 'social-mini-badge';
      badge.textContent = 'Sabit';
      meta.appendChild(badge);
    }
    const body = document.createElement('div');
    body.className = 'social-rich-text';
    body.innerHTML = message.html || '';
    bubble.append(meta, body);
    const actions = buildActions(message);
    if (actions) bubble.appendChild(actions);
    article.appendChild(bubble);
    return article;
  };

  const appendMessages = (messages, forceBottom = false) => {
    if (!list || !Array.isArray(messages)) return 0;
    const wasNearBottom = isNearBottom();
    let added = 0;
    messages.forEach((message) => {
      const id = Number(message.id || 0);
      if (!id || seen.has(id)) return;
      seen.add(id);
      lastId = Math.max(lastId, id);
      root.dataset.lastId = String(lastId);
      list.querySelector('[data-chat-empty]')?.remove();
      list.appendChild(createMessage(message));
      added += 1;
    });
    if (!added) return 0;
    if (forceBottom || wasNearBottom) scrollBottom(true);
    else if (newButton) newButton.hidden = false;
    return added;
  };

  const renderPinned = (message) => {
    if (!pinnedBox) return;
    if (!message) {
      pinnedBox.hidden = true;
      pinnedBox.innerHTML = '';
      return;
    }
    pinnedBox.innerHTML = '';
    const label = document.createElement('strong');
    label.textContent = '📌 Sabit mesaj';
    const body = document.createElement('div');
    body.className = 'social-rich-text';
    body.innerHTML = message.html || '';
    const author = document.createElement('small');
    author.textContent = `${message.author_display || ''} · ${message.date_label || ''}`;
    pinnedBox.append(label, body, author);
    pinnedBox.hidden = false;
  };

  const renderOnline = (users) => {
    if (!onlineBox || !Array.isArray(users)) return;
    onlineBox.innerHTML = '';
    if (!users.length) {
      const empty = document.createElement('p');
      empty.className = 'muted';
      empty.textContent = 'Şu an görünür çevrimiçi üye yok.';
      onlineBox.appendChild(empty);
      return;
    }
    users.forEach((user) => {
      const link = document.createElement('a');
      link.href = user.profile_url || `profil.php?kullanici=${encodeURIComponent(user.username || '')}`;
      const avatarMessage = {
        author_avatar: user.avatar || '',
        author_display: user.display_name || '',
        author_username: user.username || '',
      };
      link.appendChild(makeAvatar(avatarMessage));
      const span = document.createElement('span');
      const strong = document.createElement('strong');
      strong.textContent = user.display_name || user.username || 'Oyuncu';
      const small = document.createElement('small');
      small.textContent = `@${user.username || ''} · çevrimiçi`;
      span.append(strong, small);
      link.appendChild(span);
      onlineBox.appendChild(link);
    });
  };

  const renderTypers = (names) => {
    if (!typingBox) return;
    const safeNames = Array.isArray(names) ? names.filter(Boolean) : [];
    typingBox.hidden = !safeNames.length;
    typingBox.textContent = safeNames.length ? `${safeNames.join(', ')} yazıyor…` : '';
  };

  const fetchChat = async (afterId) => {
    const response = await fetch(`aksiyon.php?islem=sohbet_getir&kanal=${encodeURIComponent(channel)}&son=${afterId}`, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
    });
    const payload = await response.json();
    if (!response.ok || !payload.ok) throw new Error(payload.message || 'Sohbet güncellenemedi.');
    return payload;
  };

  const poll = async () => {
    if (document.hidden) {
      pollTimer = window.setTimeout(poll, 5000);
      return;
    }
    const currentRequest = ++requestId;
    try {
      const payload = await fetchChat(lastId);
      if (currentRequest !== requestId) return;
      failures = 0;
      appendMessages(payload.messages || []);
      renderPinned(payload.pinned || null);
      renderOnline(payload.online || []);
      renderTypers(payload.typers || []);
      if (statusBox) statusBox.textContent = 'Canlı bağlantı açık';
    } catch (_) {
      failures += 1;
      if (statusBox) statusBox.textContent = 'Bağlantı yenileniyor…';
    }
    pollTimer = window.setTimeout(poll, Math.min(15000, 2500 + failures * 2500));
  };

  const switchChannel = async (link) => {
    const nextChannel = link.dataset.channel || new URL(link.href, window.location.href).searchParams.get('kanal') || 'genel';
    if (!nextChannel || nextChannel === channel) return true;
    const previousChannel = channel;
    const scrollY = window.scrollY || 0;
    channel = nextChannel;
    root.dataset.channel = channel;
    seen.clear();
    lastId = 0;
    root.dataset.lastId = '0';
    if (list) list.innerHTML = '';
    renderPinned(null);
    try {
      const payload = await fetchChat(0);
      appendMessages(payload.messages || [], true);
      renderPinned(payload.pinned || null);
      renderOnline(payload.online || []);
      renderTypers(payload.typers || []);
      if (payload.channel) {
        if (titleBox) titleBox.textContent = `${payload.channel.icon || ''} ${payload.channel.name || ''}`.trim();
        if (descBox) descBox.textContent = payload.channel.description || '';
        document.title = `${payload.channel.name} — Canlı Sohbet | Device Game`;
        if (form) {
          const hidden = form.querySelector('input[name="channel"]');
          if (hidden) hidden.value = payload.channel.slug;
          const returnField = form.querySelector('input[name="return"]');
          if (returnField) returnField.value = `sohbet.php?kanal=${encodeURIComponent(payload.channel.slug)}#sohbet-son`;
          const officialOnly = Boolean(payload.channel.official_only);
          const canWrite = !officialOnly || root.dataset.isAdmin === '1';
          if (textarea) {
            textarea.disabled = !canWrite;
            textarea.placeholder = canWrite ? 'Mesajını yaz…' : 'Bu kanala yalnızca DeviceGame ekibi yazabilir.';
          }
          const submit = form.querySelector('button[type="submit"]');
          if (submit) submit.disabled = !canWrite;
        }
      }
      root.querySelectorAll('[data-chat-channel-link]').forEach((item) => {
        const active = item.dataset.channel === channel;
        item.classList.toggle('is-active', active);
        if (active) item.setAttribute('aria-current', 'page');
        else item.removeAttribute('aria-current');
      });
      const url = new URL(link.href, window.location.href);
      window.history.pushState({ dgChatChannel: channel }, '', url.href);
      try { window.sessionStorage.removeItem('dg-scroll-pos:v3'); } catch (_) {}
      window.scrollTo(0, scrollY);
      window.clearTimeout(pollTimer);
      pollTimer = window.setTimeout(poll, 2500);
      return true;
    } catch (_) {
      channel = previousChannel;
      root.dataset.channel = channel;
      return false;
    }
  };

  const sendTyping = async (typing) => {
    if (!form) return;
    const csrf = form.querySelector('input[name="csrf"]')?.value || root.dataset.csrf || '';
    const data = new FormData();
    data.set('ajax', '1');
    data.set('csrf', csrf);
    data.set('islem', 'yaziyor');
    data.set('type', 'chat');
    data.set('channel', channel);
    data.set('typing', typing ? '1' : '0');
    try {
      await fetch('aksiyon.php', {
        method: 'POST',
        body: data,
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
    } catch (_) {}
  };

  list?.querySelectorAll('[data-message-id]').forEach((item) => {
    const id = Number(item.dataset.messageId || 0);
    if (id) seen.add(id);
  });
  scrollBottom(false);

  list?.addEventListener('scroll', () => {
    if (isNearBottom() && newButton) newButton.hidden = true;
  }, { passive: true });
  newButton?.addEventListener('click', () => scrollBottom(true));

  root.addEventListener('click', async (event) => {
    const link = event.target instanceof Element ? event.target.closest('[data-chat-channel-link]') : null;
    if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    const ok = await switchChannel(link);
    if (!ok) window.location.assign(link.href);
  });

  window.addEventListener('popstate', () => {
    const next = new URL(window.location.href).searchParams.get('kanal') || 'genel';
    const link = root.querySelector(`[data-chat-channel-link][data-channel="${CSS.escape(next)}"]`);
    if (link && next !== channel) switchChannel(link);
  });

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!textarea || !textarea.value.trim()) return;
    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    const data = new FormData(form);
    data.set('ajax', '1');
    data.set('channel', channel);
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: data,
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Mesaj gönderilemedi.');
      if (payload.message_item) appendMessages([payload.message_item], true);
      textarea.value = '';
      textarea.style.height = 'auto';
      textarea.dispatchEvent(new Event('input', { bubbles: true }));
      sendTyping(false);
      textarea.focus({ preventScroll: true });
    } catch (error) {
      toast(error.message || 'Mesaj gönderilemedi.', true);
    } finally {
      if (button) button.disabled = false;
    }
  });

  textarea?.addEventListener('input', () => {
    textarea.style.height = 'auto';
    textarea.style.height = `${Math.min(150, textarea.scrollHeight)}px`;
    const typing = textarea.value.trim().length > 0;
    if (typing && Date.now() - typingSentAt > 3500) {
      typingSentAt = Date.now();
      sendTyping(true);
    }
    window.clearTimeout(typingTimer);
    typingTimer = window.setTimeout(() => sendTyping(false), 4200);
    if (!typing) sendTyping(false);
  });

  textarea?.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
      event.preventDefault();
      form?.requestSubmit();
    }
  });

  window.DGChat = {
    handleAction(mode, payload, actionForm) {
      if (mode !== 'chat-state') return;
      const article = actionForm.closest('[data-message-id]');
      if ((payload.status === 'deleted' || payload.status === 'hidden') && article) {
        article.remove();
      }
      window.clearTimeout(pollTimer);
      pollTimer = window.setTimeout(poll, 350);
    },
  };

  pollTimer = window.setTimeout(poll, 2500);
})();
