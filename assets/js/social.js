(() => {
  const showToast = (message, isError = false) => {
    if (!message) return;
    let toast = document.querySelector('[data-social-toast]');
    if (!toast) {
      toast = document.createElement('div');
      toast.className = 'social-toast';
      toast.dataset.socialToast = '';
      toast.setAttribute('role', 'status');
      toast.setAttribute('aria-live', 'polite');
      document.body.appendChild(toast);
    }
    toast.textContent = message;
    toast.classList.toggle('is-error', isError);
    toast.classList.add('is-visible');
    window.clearTimeout(showToast._timer);
    showToast._timer = window.setTimeout(() => toast.classList.remove('is-visible'), 3200);
  };
  window.DGSocialToast = showToast;

  const clearScrollCapture = () => {
    try { window.sessionStorage.removeItem('dg-scroll-pos:v3'); } catch (_) {}
  };

  const setBadge = (selector, count) => {
    document.querySelectorAll(selector).forEach((badge) => {
      badge.textContent = String(Math.max(0, Number(count) || 0));
      badge.hidden = !(Number(count) > 0);
    });
  };

  const updateCharacterCount = (field) => {
    if (!field || !field.id) return;
    const output = document.querySelector(`[data-count-for="${CSS.escape(field.id)}"]`);
    if (!output) return;
    const max = field.getAttribute('maxlength') || '';
    output.textContent = `${field.value.length}${max ? ` / ${max}` : ''}`;
  };

  const refreshCharacterCounts = (root = document) => {
    root.querySelectorAll('[data-character-count]').forEach(updateCharacterCount);
  };

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) return;
    if (!window.confirm(form.dataset.confirm || 'Emin misin?')) {
      event.preventDefault();
      event.stopImmediatePropagation();
    }
  });

  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('form[data-social-ajax]')) return;
    if (event.defaultPrevented) return;
    event.preventDefault();

    const submitter = event.submitter instanceof HTMLButtonElement ? event.submitter : null;
    const button = submitter || form.querySelector('button[type="submit"], button:not([type])');
    const originalHtml = button ? button.innerHTML : '';
    if (button) button.disabled = true;

    const data = new FormData(form, submitter || undefined);
    data.set('ajax', '1');
    try {
      const response = await fetch(form.action, {
        method: 'POST',
        body: data,
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        throw new Error(payload.message || 'İşlem tamamlanamadı.');
      }

      const mode = form.dataset.socialAjax || '';
      const hasActive = Object.prototype.hasOwnProperty.call(payload, 'active');
      if (button && hasActive && mode !== 'poll') {
        button.classList.toggle('is-active', Boolean(payload.active));
        button.setAttribute('aria-pressed', payload.active ? 'true' : 'false');
      }
      if (button && (button.dataset.labelActive || button.dataset.labelInactive)) {
        const active = mode === 'topic-mute' ? Boolean(payload.muted) : Boolean(payload.active);
        button.textContent = active ? (button.dataset.labelActive || button.textContent) : (button.dataset.labelInactive || button.textContent);
        if (mode === 'topic-mute') {
          button.classList.toggle('is-active', active);
          button.setAttribute('aria-pressed', active ? 'true' : 'false');
        }
      }

      if (mode === 'poll' && payload.poll) {
        const panel = form.closest('[data-poll]');
        const options = new Map((payload.poll.options || []).map((option) => [String(option.id), option]));
        if (panel) {
          panel.querySelectorAll('[data-poll-option]').forEach((optionElement) => {
            const option = options.get(optionElement.dataset.pollOption || '');
            if (!option) return;
            const percent = Math.max(0, Math.min(100, Number(option.percent || 0)));
            const fill = optionElement.querySelector('[data-poll-fill]');
            const countElement = optionElement.querySelector('[data-poll-count]');
            const percentElement = optionElement.querySelector('[data-poll-percent]');
            const selected = String(payload.poll.selected || payload.selected || '') === String(option.id);
            if (fill) fill.style.width = `${percent}%`;
            if (countElement) countElement.textContent = String(option.votes || 0);
            if (percentElement) percentElement.textContent = `%${percent}`;
            optionElement.classList.toggle('is-selected', selected);
            if (optionElement.tagName === 'BUTTON') optionElement.setAttribute('aria-pressed', selected ? 'true' : 'false');
          });
          const meta = panel.querySelector('[data-poll-meta]');
          if (meta) {
            let suffix = ' · süresiz';
            if (payload.poll.closed) suffix = ' · kapandı';
            else if (payload.poll.closes_at) {
              const date = new Date(Number(payload.poll.closes_at) * 1000);
              suffix = ` · ${date.toLocaleString('tr-TR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' })} tarihine kadar`;
            }
            meta.textContent = `${payload.poll.total_votes || 0} oy${suffix}`;
          }
        }
      }

      if (mode === 'rsvp' && button) {
        const label = button.querySelector('[data-rsvp-label]');
        if (label) label.textContent = payload.active ? 'Katılıyorsun ✓' : 'Katılıyorum';
        const section = button.closest('[data-event]');
        section?.querySelectorAll('[data-rsvp-count]').forEach((countElement) => {
          countElement.textContent = String(payload.count || 0);
        });
      }
      if (mode === 'gg' && button) {
        const count = button.querySelector('[data-gg-count]');
        if (count && typeof payload.count !== 'undefined') count.textContent = String(payload.count);
      }
      if (mode === 'comment-gg' && button) {
        const count = button.querySelector('[data-comment-gg-count]');
        if (count && typeof payload.count !== 'undefined') count.textContent = String(payload.count);
      }
      if (mode === 'save' && button && !button.dataset.labelActive) {
        button.textContent = payload.active ? 'Kaydedildi' : 'Kaydet';
      }
      if (mode === 'follow' && button && !button.dataset.labelActive) {
        button.textContent = payload.active ? 'Takip ediliyor' : 'Takip et';
        button.classList.toggle('btn-primary', !payload.active);
        button.classList.toggle('btn-ghost', Boolean(payload.active));
      }
      if (mode === 'watch' && button) {
        const count = form.closest('[data-watch-wrap]')?.querySelector('[data-watch-count]')
          || document.querySelector('[data-watch-count]');
        if (count && typeof payload.count !== 'undefined') count.textContent = String(payload.count);
      }
      if (mode === 'solution') {
        const comment = form.closest('.social-comment');
        document.querySelectorAll('.social-comment.is-solution').forEach((item) => {
          if (item !== comment) {
            item.classList.remove('is-solution');
            item.querySelector('[data-solution-badge]')?.remove();
            const otherButton = item.querySelector('form[data-social-ajax="solution"] button');
            if (otherButton) {
              otherButton.textContent = 'Çözüm seç';
              otherButton.classList.remove('is-active');
              otherButton.setAttribute('aria-pressed', 'false');
            }
          }
        });
        if (comment) {
          comment.classList.toggle('is-solution', Boolean(payload.active));
          let badge = comment.querySelector('[data-solution-badge]');
          if (payload.active && !badge) {
            badge = document.createElement('span');
            badge.className = 'social-mini-badge is-solution';
            badge.dataset.solutionBadge = '';
            badge.textContent = '✓ Kabul edilen çözüm';
            comment.querySelector('.social-comment-head')?.appendChild(badge);
          }
          if (!payload.active) badge?.remove();
        }
        if (button && !button.dataset.labelActive) button.textContent = payload.active ? 'Çözümü kaldır' : 'Çözüm seç';
      }
      if (mode === 'report') {
        form.reset();
        const details = form.closest('details');
        if (details) details.open = false;
      }
      if (mode === 'notifications-read') {
        document.querySelectorAll('.social-notification.is-unread').forEach((item) => item.classList.remove('is-unread'));
        setBadge('[data-notification-count]', 0);
        form.hidden = true;
      }
      if (mode === 'mark-read') {
        const feed = document.querySelector('[data-social-feed-root]');
        feed?.querySelectorAll('.social-post-card.is-unread').forEach((card) => {
          card.classList.remove('is-unread');
          card.querySelector('[data-unread-badge]')?.remove();
        });
      }

      window.DGChat?.handleAction?.(mode, payload, form);
      window.DGMessages?.handleAction?.(mode, payload, form);
      showToast(payload.message || 'İşlem tamamlandı.');
    } catch (error) {
      if (button) button.innerHTML = originalHtml;
      showToast(error.message || 'İşlem tamamlanamadı.', true);
    } finally {
      if (button) button.disabled = false;
    }
  });

  document.addEventListener('input', (event) => {
    if (event.target instanceof HTMLElement && event.target.matches('[data-character-count]')) {
      updateCharacterCount(event.target);
    }
    const form = event.target instanceof Element ? event.target.closest('form[data-social-post-form]') : null;
    if (form) schedulePostDraftSave(form);
    if (event.target instanceof HTMLTextAreaElement && event.target.matches('[data-comment-draft]')) {
      scheduleCommentDraftSave(event.target);
    }
  });

  document.addEventListener('change', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    if (target.matches('[data-social-topic-select]')) syncTopicFields();
    if (target.matches('[data-poll-toggle]')) syncPollFields();
    if (target.id === 'social-sort' && target instanceof HTMLSelectElement && target.form) {
      target.form.requestSubmit();
    }
    const form = target.closest('form[data-social-post-form]');
    if (form) schedulePostDraftSave(form);
  });

  const syncTopicFields = () => {
    const topicSelect = document.querySelector('[data-social-topic-select]');
    if (!topicSelect) return;
    document.querySelectorAll('[data-topic-fields]').forEach((section) => {
      const visible = topicSelect.value === section.dataset.topicFields;
      section.hidden = !visible;
      section.querySelectorAll('input, select, textarea').forEach((input) => {
        input.disabled = !visible || input.hasAttribute('data-locked-input');
      });
    });
    const prefixSelect = document.querySelector('[data-social-prefix-select]');
    if (prefixSelect && prefixSelect.dataset.touched !== '1') {
      const defaults = {
        'yardim': 'soru',
        'rehberler': 'rehber',
        'oyun-arkadasi': 'ekip',
        'etkinlikler': 'etkinlik',
        'duyurular': 'duyuru',
        'oyun-onerileri': 'oneriler',
      };
      if (defaults[topicSelect.value]) prefixSelect.value = defaults[topicSelect.value];
    }
  };

  const syncPollFields = () => {
    const pollToggle = document.querySelector('[data-poll-toggle]');
    const pollFields = document.querySelector('[data-poll-fields]');
    if (!pollToggle || !pollFields) return;
    const visible = pollToggle.checked;
    const locked = pollFields.dataset.pollLocked === '1';
    pollFields.hidden = !visible;
    pollFields.querySelectorAll('input, select, textarea').forEach((input) => {
      input.disabled = !visible || (locked && input.tagName === 'SELECT');
    });
  };

  const initOnboarding = () => {
    const onboarding = document.querySelector('[data-onboarding]');
    if (!onboarding) return;
    const key = `dg-social-onboarding-dismissed-${onboarding.dataset.onboarding || 'guest'}`;
    const forceShow = new URLSearchParams(window.location.search).get('hosgeldin') === '1';
    try {
      const dismissedAt = Number(window.localStorage.getItem(key) || 0);
      if (!forceShow && dismissedAt && Date.now() - dismissedAt < 7 * 24 * 60 * 60 * 1000) {
        onboarding.hidden = true;
      }
    } catch (_) {}
  };

  const initAnnouncement = () => {
    const announcement = document.querySelector('[data-announcement]');
    if (!announcement) return;
    try {
      if (window.localStorage.getItem(`dg-social-announcement-${announcement.dataset.announcement}`) === '1') {
        announcement.hidden = true;
      }
    } catch (_) {}
  };

  document.addEventListener('click', async (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const onboardingDismiss = target.closest('[data-onboarding-dismiss]');
    if (onboardingDismiss) {
      const onboarding = onboardingDismiss.closest('[data-onboarding]');
      if (onboarding) {
        const key = `dg-social-onboarding-dismissed-${onboarding.dataset.onboarding || 'guest'}`;
        try { window.localStorage.setItem(key, String(Date.now())); } catch (_) {}
        onboarding.hidden = true;
        showToast('Başlangıç adımları bir hafta sonra tekrar gösterilecek.');
      }
      return;
    }

    const announcementDismiss = target.closest('[data-announcement-dismiss]');
    if (announcementDismiss) {
      const announcement = announcementDismiss.closest('[data-announcement]');
      if (announcement) {
        try { window.localStorage.setItem(`dg-social-announcement-${announcement.dataset.announcement}`, '1'); } catch (_) {}
        announcement.hidden = true;
      }
      return;
    }

    const replyButton = target.closest('[data-reply-button]');
    if (replyButton) {
      const form = document.getElementById('comment-form');
      const parentInput = form?.querySelector('[data-comment-parent]');
      const banner = form?.querySelector('[data-reply-banner]');
      const author = form?.querySelector('[data-reply-author]');
      const textarea = form?.querySelector('textarea[name="body"]');
      if (parentInput) parentInput.value = replyButton.dataset.parentId || '0';
      if (author) author.textContent = replyButton.dataset.author || '';
      if (banner) banner.hidden = false;
      if (textarea) {
        textarea.focus({ preventScroll: true });
        textarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      return;
    }

    const quoteButton = target.closest('[data-quote-button]');
    if (quoteButton) {
      const form = document.getElementById('comment-form');
      const textarea = form?.querySelector('textarea[name="body"]');
      const parentInput = form?.querySelector('[data-comment-parent]');
      if (textarea) {
        const authorName = quoteButton.dataset.author || 'oyuncu';
        const quoteText = (quoteButton.dataset.quote || '').trim().replace(/\s+/g, ' ').slice(0, 320);
        const quote = `> ${authorName}: ${quoteText}\n\n`;
        if (!textarea.value.includes(quoteText) || textarea.value.trim() === '') {
          textarea.value = quote + textarea.value;
        }
        if (parentInput && quoteButton.dataset.parentId) parentInput.value = quoteButton.dataset.parentId;
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
        textarea.focus({ preventScroll: true });
        textarea.setSelectionRange(textarea.value.length, textarea.value.length);
        textarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      return;
    }

    if (target.closest('[data-reply-cancel]')) {
      const form = document.getElementById('comment-form');
      const parentInput = form?.querySelector('[data-comment-parent]');
      const banner = form?.querySelector('[data-reply-banner]');
      if (parentInput) parentInput.value = '0';
      if (banner) banner.hidden = true;
      return;
    }

    const copyButton = target.closest('[data-copy-link]');
    if (copyButton) {
      const url = copyButton.dataset.copyUrl
        ? new URL(copyButton.dataset.copyUrl, window.location.href).href
        : window.location.href.split('#')[0];
      try {
        await navigator.clipboard.writeText(url);
      } catch (_) {
        const area = document.createElement('textarea');
        area.value = url;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        document.execCommand('copy');
        area.remove();
      }
      showToast('Bağlantı kopyalandı.');
      return;
    }

    const formatButton = target.closest('[data-format-command]');
    if (formatButton) {
      applyFormat(formatButton);
      return;
    }

    const previewButton = target.closest('[data-preview-toggle]');
    if (previewButton) {
      togglePreview(previewButton);
      return;
    }

    const discardDraft = target.closest('[data-draft-discard]');
    if (discardDraft) {
      const form = discardDraft.closest('form[data-social-post-form]');
      if (form) {
        try { window.localStorage.removeItem(postDraftKey(form)); } catch (_) {}
        const status = form.querySelector('[data-draft-status]');
        if (status) status.textContent = 'Tarayıcı taslağı silindi.';
        showToast('Taslak silindi.');
      }
    }
  });

  const applyFormat = (button) => {
    const field = document.getElementById(button.dataset.formatTarget || '');
    if (!(field instanceof HTMLTextAreaElement)) return;
    const start = field.selectionStart || 0;
    const end = field.selectionEnd || start;
    const selected = field.value.slice(start, end) || 'metin';
    let replacement = selected;
    let cursorOffset = null;
    switch (button.dataset.formatCommand) {
      case 'bold': replacement = `**${selected}**`; cursorOffset = 2; break;
      case 'italic': replacement = `*${selected}*`; cursorOffset = 1; break;
      case 'code': replacement = `\`${selected}\``; cursorOffset = 1; break;
      case 'spoiler': replacement = `||${selected}||`; cursorOffset = 2; break;
      case 'quote':
        replacement = selected.split('\n').map((line) => `> ${line}`).join('\n');
        break;
      case 'list':
        replacement = selected.split('\n').map((line) => line.trim() ? `- ${line}` : line).join('\n');
        break;
      case 'link': {
        const url = window.prompt('Bağlantı adresi (https://...)', 'https://');
        if (!url || !/^https?:\/\//i.test(url.trim())) return;
        replacement = selected === 'metin' ? url.trim() : `${selected}: ${url.trim()}`;
        break;
      }
      default: return;
    }
    field.setRangeText(replacement, start, end, 'end');
    if (cursorOffset !== null && start === end) {
      const pos = start + cursorOffset + selected.length;
      field.setSelectionRange(pos, pos);
    }
    field.focus({ preventScroll: true });
    field.dispatchEvent(new Event('input', { bubbles: true }));
  };

  const togglePreview = async (button) => {
    const field = document.getElementById(button.dataset.formatTarget || '');
    const scope = button.closest('.field, form') || document;
    const preview = scope.querySelector('[data-editor-preview]');
    if (!(field instanceof HTMLTextAreaElement) || !preview) return;
    if (!preview.hidden) {
      preview.hidden = true;
      button.textContent = 'Önizleme';
      return;
    }
    const form = field.closest('form');
    const csrf = form?.querySelector('input[name="csrf"]')?.value || document.querySelector('input[name="csrf"]')?.value || '';
    const data = new FormData();
    data.set('ajax', '1');
    data.set('csrf', csrf);
    data.set('islem', 'onizleme');
    data.set('body', field.value);
    button.disabled = true;
    try {
      const response = await fetch('aksiyon.php', {
        method: 'POST',
        body: data,
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      const payload = await response.json();
      if (!response.ok || !payload.ok) throw new Error(payload.message || 'Önizleme oluşturulamadı.');
      preview.innerHTML = payload.html || '<p class="muted">Önizleme boş.</p>';
      preview.hidden = false;
      button.textContent = 'Önizlemeyi kapat';
    } catch (error) {
      showToast(error.message || 'Önizleme oluşturulamadı.', true);
    } finally {
      button.disabled = false;
    }
  };

  const postDraftKey = (form) => {
    const editId = form.querySelector('input[name="edit_id"]')?.value || '0';
    return `dg-social-draft:post:${editId}`;
  };

  let postDraftTimer = 0;
  const schedulePostDraftSave = (form) => {
    window.clearTimeout(postDraftTimer);
    postDraftTimer = window.setTimeout(() => {
      const values = {};
      form.querySelectorAll('input[name], select[name], textarea[name]').forEach((field) => {
        if (!field.name || field.name === 'csrf' || field.type === 'file' || field.type === 'password') return;
        if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) return;
        if (field.name.endsWith('[]')) {
          const key = field.name.slice(0, -2);
          if (!Array.isArray(values[key])) values[key] = [];
          values[key].push(field.value);
        } else {
          values[field.name] = field.value;
        }
      });
      try {
        window.localStorage.setItem(postDraftKey(form), JSON.stringify({ values, saved_at: Date.now() }));
        const status = form.querySelector('[data-draft-status]');
        if (status) status.textContent = `Taslak bu tarayıcıya ${new Date().toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' })} itibarıyla kaydedildi.`;
      } catch (_) {}
    }, 450);
  };

  const restorePostDraft = () => {
    const form = document.querySelector('form[data-social-post-form]');
    if (!form) return;
    const title = form.querySelector('#social-title');
    const body = form.querySelector('#social-body');
    if (!title || !body || title.value.trim() || body.value.trim()) return;
    try {
      const parsed = JSON.parse(window.localStorage.getItem(postDraftKey(form)) || 'null');
      const values = parsed?.values || {};
      if (!Object.keys(values).length) return;
      Object.entries(values).forEach(([name, value]) => {
        const fields = form.querySelectorAll(`[name="${CSS.escape(name)}"], [name="${CSS.escape(name)}[]"]`);
        fields.forEach((field, index) => {
          if (field.type === 'file' || field.name === 'csrf') return;
          if (field.type === 'checkbox') field.checked = Boolean(value);
          else if (Array.isArray(value)) field.value = value[index] || '';
          else field.value = value;
        });
      });
      const status = form.querySelector('[data-draft-status]');
      if (status && parsed.saved_at) {
        status.textContent = `Kayıtlı taslağın geri yüklendi (${new Date(parsed.saved_at).toLocaleString('tr-TR')}).`;
      }
      syncTopicFields();
      syncPollFields();
      refreshCharacterCounts(form);
    } catch (_) {}
  };

  let commentDraftTimer = 0;
  const scheduleCommentDraftSave = (field) => {
    window.clearTimeout(commentDraftTimer);
    commentDraftTimer = window.setTimeout(() => {
      try {
        const key = `dg-social-draft:comment:${field.dataset.commentDraft || 'post'}`;
        if (field.value.trim()) window.localStorage.setItem(key, field.value);
        else window.localStorage.removeItem(key);
      } catch (_) {}
    }, 350);
  };

  const restoreCommentDrafts = () => {
    document.querySelectorAll('[data-comment-draft]').forEach((field) => {
      if (!(field instanceof HTMLTextAreaElement) || field.value.trim()) return;
      try {
        const saved = window.localStorage.getItem(`dg-social-draft:comment:${field.dataset.commentDraft || 'post'}`);
        if (saved) {
          field.value = saved;
          updateCharacterCount(field);
        }
      } catch (_) {}
    });
  };

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.matches('form[data-social-post-form]')) {
      window.clearTimeout(postDraftTimer);
      try { window.localStorage.removeItem(postDraftKey(form)); } catch (_) {}
    }
    const draftField = form.querySelector('[data-comment-draft]');
    if (draftField) {
      try { window.localStorage.removeItem(`dg-social-draft:comment:${draftField.dataset.commentDraft || 'post'}`); } catch (_) {}
    }
  });

  const initSimilarPosts = () => {
    const title = document.getElementById('social-title');
    const box = document.querySelector('[data-similar-posts]');
    if (!title || !box) return;
    let timer = 0;
    let controller = null;
    title.addEventListener('input', () => {
      window.clearTimeout(timer);
      const query = title.value.trim();
      if (query.length < 8) {
        box.hidden = true;
        box.innerHTML = '';
        return;
      }
      timer = window.setTimeout(async () => {
        controller?.abort();
        controller = new AbortController();
        try {
          const response = await fetch(`aksiyon.php?islem=benzer&q=${encodeURIComponent(query)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
          });
          const payload = await response.json();
          const items = Array.isArray(payload.items) ? payload.items : [];
          box.innerHTML = '';
          if (!items.length) {
            box.hidden = true;
            return;
          }
          const heading = document.createElement('strong');
          heading.textContent = 'Benzer konular daha önce açılmış olabilir:';
          box.appendChild(heading);
          items.forEach((item) => {
            const link = document.createElement('a');
            link.href = item.url;
            link.target = '_blank';
            link.rel = 'noopener';
            link.textContent = `${item.title} (${item.comment_count} yorum)`;
            box.appendChild(link);
          });
          box.hidden = false;
        } catch (_) {}
      }, 550);
    });
  };

  const buildFeedUrl = (form) => {
    const url = new URL(form.getAttribute('action') || 'index.php', window.location.href);
    url.hash = '';
    url.search = '';
    const data = new FormData(form);
    data.forEach((value, name) => {
      if (typeof value !== 'string') return;
      if (value === '' && name !== 'q') return;
      url.searchParams.append(name, value);
    });
    if (!url.searchParams.get('q')) url.searchParams.delete('q');
    return url;
  };

  let feedRequest = 0;
  const updateFeed = async (urlValue, push = true) => {
    const root = document.querySelector('[data-social-feed-root]');
    const main = root?.querySelector('.social-main');
    if (!root || !main) return false;
    const url = new URL(urlValue, window.location.href);
    url.hash = '';
    const requestId = ++feedRequest;
    const scrollY = window.scrollY || window.pageYOffset || 0;
    const oldHeight = main.offsetHeight;
    main.style.minHeight = `${oldHeight}px`;
    root.classList.add('is-feed-loading');
    main.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url.href, {
        headers: { Accept: 'text/html', 'X-DG-Feed': '1' },
        credentials: 'same-origin',
      });
      if (!response.ok) throw new Error('Akış güncellenemedi.');
      const html = await response.text();
      if (requestId !== feedRequest) return true;
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      const nextMain = parsed.querySelector('[data-social-feed-root] .social-main');
      if (!nextMain) throw new Error('Akış içeriği bulunamadı.');
      main.innerHTML = nextMain.innerHTML;

      const currentTopics = root.querySelector('[data-feed-topic-panel]');
      const nextTopics = parsed.querySelector('[data-feed-topic-panel]');
      if (currentTopics && nextTopics) currentTopics.innerHTML = nextTopics.innerHTML;

      const currentHeroTitle = root.querySelector('[data-feed-hero-title]');
      const nextHeroTitle = parsed.querySelector('[data-feed-hero-title]');
      if (currentHeroTitle && nextHeroTitle) currentHeroTitle.innerHTML = nextHeroTitle.innerHTML;
      const currentHeroText = root.querySelector('[data-feed-hero-text]');
      const nextHeroText = parsed.querySelector('[data-feed-hero-text]');
      if (currentHeroText && nextHeroText) currentHeroText.textContent = nextHeroText.textContent;

      document.title = parsed.title || document.title;
      if (push) window.history.pushState({ dgFeed: true }, '', url.href);
      clearScrollCapture();
      refreshCharacterCounts(main);
      const maxY = Math.max(0, document.documentElement.scrollHeight - window.innerHeight);
      window.scrollTo(0, Math.min(scrollY, maxY));
      window.setTimeout(() => {
        main.style.minHeight = '';
        const nextMaxY = Math.max(0, document.documentElement.scrollHeight - window.innerHeight);
        window.scrollTo(0, Math.min(scrollY, nextMaxY));
      }, 60);
      return true;
    } catch (_) {
      main.style.minHeight = '';
      return false;
    } finally {
      if (requestId === feedRequest) {
        root.classList.remove('is-feed-loading');
        main.removeAttribute('aria-busy');
      }
    }
  };

  const initFeedAjax = () => {
    const root = document.querySelector('[data-social-feed-root]');
    if (!root) return;

    root.addEventListener('click', async (event) => {
      if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
      const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
      if (!link || link.target || link.hasAttribute('download')) return;
      const url = new URL(link.href, window.location.href);
      if (url.origin !== window.location.origin || url.pathname !== window.location.pathname || url.hash) return;
      const isFeedNavigation = link.closest('.social-topic-list, .social-tabs, .social-pagination, .social-tags, .social-topic-badge, .social-panel-head')
        || link.hasAttribute('data-feed-link');
      if (!isFeedNavigation) return;
      event.preventDefault();
      const ok = await updateFeed(url, true);
      if (!ok) window.location.assign(url.href);
    });

    root.addEventListener('submit', async (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) return;
      if (!form.matches('[data-feed-form], .social-search, .social-advanced-search')) return;
      event.preventDefault();
      const url = buildFeedUrl(form);
      const ok = await updateFeed(url, true);
      if (!ok) window.location.assign(url.href);
    });

    window.addEventListener('popstate', () => {
      if (document.querySelector('[data-social-feed-root]')) {
        updateFeed(window.location.href, false);
      }
    });
  };

  const refreshBadges = async () => {
    if (!document.querySelector('[data-notification-count], [data-message-count]')) return;
    try {
      const response = await fetch('aksiyon.php?islem=bildirim_sayisi', {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
      });
      if (!response.ok) return;
      const payload = await response.json();
      setBadge('[data-notification-count]', payload.count || 0);
      setBadge('[data-message-count]', payload.messages || 0);
    } catch (_) {
      // Sayaçlar kritik değildir; sonraki denemede güncellenir.
    }
  };

  refreshCharacterCounts();
  syncTopicFields();
  syncPollFields();
  initOnboarding();
  initAnnouncement();
  restorePostDraft();
  restoreCommentDrafts();
  initSimilarPosts();
  initFeedAjax();

  const commentForm = document.getElementById('comment-form');
  const parentInput = commentForm?.querySelector('[data-comment-parent]');
  const banner = commentForm?.querySelector('[data-reply-banner]');
  if (parentInput && banner && parentInput.value !== '0') banner.hidden = false;

  document.querySelector('[data-social-prefix-select]')?.addEventListener('change', (event) => {
    event.currentTarget.dataset.touched = '1';
  });

  if (document.querySelector('[data-notification-count], [data-message-count]')) {
    window.setInterval(() => {
      if (!document.hidden) refreshBadges();
    }, 45000);
  }
})();
