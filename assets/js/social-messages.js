(() => {
  const root = document.querySelector('[data-dm-root]');
  if (!root) return;

  const list = root.querySelector('[data-dm-messages]');
  const form = root.querySelector('[data-dm-form]');
  const textarea = form?.querySelector('textarea[name="body"]');
  const typingBox = root.querySelector('[data-dm-typing]');
  const peerStatus = root.querySelector('[data-dm-peer-status]');
  const newButton = root.querySelector('[data-dm-new]');
  const statusBox = root.querySelector('[data-dm-status]');
  const seen = new Set();
  let lastId = Number(root.dataset.lastId || 0) || 0;
  let conversationId = Number(root.dataset.conversationId || 0) || 0;
  let pollTimer = 0;
  let typingTimer = 0;
  let typingSentAt = 0;
  let failures = 0;

  const toast = (message, isError = false) => {
    if (window.DGSocialToast) window.DGSocialToast(message, isError);
  };

  const initials = (name) => {
    const parts = String(name || 'DG').trim().split(/\s+/).slice(0, 2);
    return parts.map((part) => Array.from(part)[0] || '').join('').toLocaleUpperCase('tr-TR') || 'DG';
  };

  const isNearBottom = () => {
    if (!list) return true;
    return list.scrollHeight - list.scrollTop - list.clientHeight < 140;
  };

  const scrollBottom = (smooth = false) => {
    if (!list) return;
    list.scrollTo({ top: list.scrollHeight, behavior: smooth ? 'smooth' : 'instant' });
    if (newButton) newButton.hidden = true;
  };

  const avatarHtml = (message) => {
    const wrap = document.createElement('span');
    wrap.className = 'social-avatar';
    wrap.setAttribute('aria-hidden', 'true');
    if (message.sender_avatar) {
      const img = document.createElement('img');
      img.src = message.sender_avatar.startsWith('../') || /^https?:/i.test(message.sender_avatar)
        ? message.sender_avatar
        : `../${message.sender_avatar}`;
      img.alt = '';
      img.loading = 'lazy';
      img.addEventListener('error', () => img.remove());
      wrap.appendChild(img);
    }
    const span = document.createElement('span');
    span.className = 'social-avatar-initials';
    span.textContent = initials(message.sender_display || message.sender_username);
    wrap.appendChild(span);
    return wrap;
  };

  const createMessage = (message) => {
    const article = document.createElement('article');
    article.className = `social-dm-message${message.mine ? ' is-mine' : ''}${message.status !== 'published' ? ' is-muted-message' : ''}`;
    article.dataset.messageId = String(message.id);

    const link = document.createElement('a');
    link.href = `profil.php?kullanici=${encodeURIComponent(message.sender_username || '')}`;
    link.appendChild(avatarHtml(message));
    article.appendChild(link);

    const bubble = document.createElement('div');
    bubble.className = 'social-dm-bubble';
    const meta = document.createElement('div');
    meta.className = 'social-dm-meta';
    const strong = document.createElement('strong');
    strong.textContent = message.sender_display || message.sender_username || 'Oyuncu';
    const time = document.createElement('time');
    time.textContent = message.time_label || message.date_label || '';
    time.title = message.date_label || '';
    meta.append(strong, time);
    const body = document.createElement('div');
    body.className = 'social-rich-text';
    body.innerHTML = message.html || '';
    bubble.append(meta, body);
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
      list.querySelector('[data-dm-empty]')?.remove();
      list.appendChild(createMessage(message));
      added += 1;
    });
    if (!added) return 0;
    if (forceBottom || wasNearBottom) scrollBottom(true);
    else if (newButton) newButton.hidden = false;
    return added;
  };

  const updateBadges = (count) => {
    document.querySelectorAll('[data-message-count]').forEach((badge) => {
      badge.textContent = String(Math.max(0, Number(count) || 0));
      badge.hidden = !(Number(count) > 0);
    });
  };

  const setStatus = (text) => {
    if (statusBox) statusBox.textContent = text || '';
  };

  const sendTyping = async (typing) => {
    if (!form || conversationId <= 0) return;
    const csrf = form.querySelector('input[name="csrf"]')?.value || '';
    const data = new FormData();
    data.set('ajax', '1');
    data.set('csrf', csrf);
    data.set('islem', 'yaziyor');
    data.set('type', 'dm');
    data.set('conversation_id', String(conversationId));
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

  const poll = async () => {
    if (conversationId <= 0) return;
    if (document.hidden) {
      pollTimer = window.setTimeout(poll, 5000);
      return;
    }
    try {
      const response = await fetch(`aksiyon.php?islem=mesajlari_getir&sohbet=${conversationId}&son=${lastId}`, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Mesajlar güncellenemedi.');
      failures = 0;
      appendMessages(payload.messages || []);
      if (typingBox) {
        typingBox.hidden = !payload.peer_typing;
        typingBox.textContent = payload.peer_typing ? 'Yazıyor…' : '';
      }
      if (peerStatus) {
        peerStatus.textContent = payload.peer_online ? 'Şu an çevrimiçi' : peerStatus.dataset.fallback || '';
        peerStatus.classList.toggle('is-online', Boolean(payload.peer_online));
      }
      if (typeof payload.unread_messages !== 'undefined') updateBadges(payload.unread_messages);
      setStatus('Canlı bağlantı açık');
    } catch (_) {
      failures += 1;
      setStatus('Bağlantı yenileniyor…');
    }
    pollTimer = window.setTimeout(poll, Math.min(15000, 3000 + failures * 2500));
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

  form?.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!textarea || !textarea.value.trim()) return;
    const button = form.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    const data = new FormData(form);
    data.set('ajax', '1');
    data.set('conversation_id', String(conversationId));
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: data,
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Mesaj gönderilemedi.');
      if (payload.conversation_id && Number(payload.conversation_id) !== conversationId) {
        conversationId = Number(payload.conversation_id);
        root.dataset.conversationId = String(conversationId);
        const hidden = form.querySelector('input[name="conversation_id"]');
        if (hidden) hidden.value = String(conversationId);
        const url = new URL(window.location.href);
        url.searchParams.set('sohbet', String(conversationId));
        url.searchParams.delete('kullanici');
        window.history.replaceState({}, '', `${url.pathname}${url.search}`);
        window.clearTimeout(pollTimer);
        pollTimer = window.setTimeout(poll, 3000);
      }
      if (payload.message_item) appendMessages([payload.message_item], true);
      if (payload.status === 'pending') setStatus('Mesaj isteği gönderildi; kabul edilmeden en fazla 3 mesaj yazabilirsin.');
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
    textarea.style.height = `${Math.min(170, textarea.scrollHeight)}px`;
    if (conversationId <= 0) return;
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

  window.DGMessages = {
    handleAction(mode, payload, actionForm) {
      if (mode === 'dm-delete') {
        const article = actionForm.closest('[data-message-id]');
        if (article) {
          article.classList.add('is-muted-message');
          const body = article.querySelector('.social-rich-text');
          if (body) body.innerHTML = '<p>Bu mesaj silindi.</p>';
          actionForm.closest('details')?.remove();
          actionForm.remove();
        }
      }
      if (mode === 'dm-archive' && payload.archived) {
        window.location.assign('mesajlar.php');
      }
    },
  };

  if (conversationId > 0) {
    pollTimer = window.setTimeout(poll, 3000);
  }
})();
