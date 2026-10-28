// Ürün görseli yüklenemezse alttaki üretilmiş poster kullanılmaya devam eder.
(function () {
    document.querySelectorAll('.poster').forEach(function (poster) {
        var image = poster.querySelector('.poster-img');
        function showFallback() {
            if (image) image.hidden = true;
            poster.classList.add('is-image-fallback');
        }
        if (!image || (image.complete && image.naturalWidth === 0)) {
            showFallback();
        } else {
            image.addEventListener('error', showFallback, { once: true });
        }
    });
})();

// 1. Kaydırınca menüde buzlu cam zemin
(function () {
    var nav = document.getElementById('site-nav');
    if (!nav) return;
    function onScroll() {
        nav.classList.toggle('scrolled', window.scrollY > 24);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
})();

// 2. Ana ekranda imleci takip eden ışık (yalnızca fare olan cihazlarda)
(function () {
    var hero = document.querySelector('.hero');
    if (!hero || !window.matchMedia('(hover: hover)').matches) return;
    var frame = null;
    hero.addEventListener('pointermove', function (ev) {
        if (frame) return;
        frame = requestAnimationFrame(function () {
            var r = hero.getBoundingClientRect();
            hero.style.setProperty('--mx', (ev.clientX - r.left) + 'px');
            hero.style.setProperty('--my', (ev.clientY - r.top) + 'px');
            frame = null;
        });
    });
})();

// 3. İletişim formu -> WhatsApp
(function () {
    var form = document.getElementById('whatsappForm');
    if (!form) return;
    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var name = form.elements['name'].value.trim();
        var email = form.elements['email'].value.trim();
        var message = form.elements['message'].value.trim();

        var text =
            '*Yeni iletişim talebi*\n' +
            '------------------\n' +
            '*Ad:* ' + name + '\n' +
            '*E-posta:* ' + email + '\n' +
            '*Mesaj:*\n' + message;

        var url = 'https://wa.me/' + form.dataset.wa + '?text=' + encodeURIComponent(text);
        window.open(url, '_blank', 'noopener');
        form.reset();
    });
})();

// 4. Mağaza: sıralama seçimi anında uygulanır
(function () {
    var select = document.querySelector('[data-sort-select]');
    var submit = document.querySelector('[data-sort-submit]');
    if (!select) return;
    if (submit) submit.hidden = true;
    select.addEventListener('change', function () {
        if (!select.form) return;
        if (typeof select.form.requestSubmit === 'function') {
            select.form.requestSubmit();
        } else {
            select.form.submit();
        }
    });
})();

// 5. Sepet bildirimi: kısa süre görünür
(function () {
    var flash = document.querySelector('[data-flash]');
    if (!flash) return;
    requestAnimationFrame(function () { flash.classList.add('show'); });
    setTimeout(function () { flash.classList.remove('show'); }, 3200);
})();

// 6. Ürün detayında adet artır / azalt
(function () {
    document.querySelectorAll('[data-qty]').forEach(function (qty) {
        var input = qty.querySelector('input');
        if (!input) return;
        qty.querySelectorAll('[data-qty-up], [data-qty-down]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var min = parseInt(input.min || '1', 10);
                var max = parseInt(input.max || '10', 10);
                var value = parseInt(input.value || '1', 10);
                value += btn.hasAttribute('data-qty-up') ? 1 : -1;
                input.value = Math.min(max, Math.max(min, value));
            });
        });
    });
})();

// 7. Sepete ekle: sayfa yenilenmeden (AJAX), düğmede kısa onay + bildirim
(function () {
    function showToast(text) {
        if (!text) return;
        var old = document.querySelector('.flash.is-dynamic');
        if (old) old.remove();
        var toast = document.createElement('div');
        toast.className = 'flash show is-dynamic';
        toast.setAttribute('role', 'status');
        toast.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m4 12 5 5L20 6"/></svg>';
        toast.appendChild(document.createTextNode(text));
        document.body.appendChild(toast);
        setTimeout(function () {
            toast.classList.remove('show');
            setTimeout(function () { toast.remove(); }, 450);
        }, 2600);
    }

    function updateCount(count) {
        document.querySelectorAll('[data-cart-count]').forEach(function (el) {
            el.textContent = String(count);
            if (count > 0) {
                el.removeAttribute('hidden');
            } else {
                el.setAttribute('hidden', '');
            }
        });
    }

    function isCartAction(form, submitter) {
        if (!form.matches('form.add-form, form.buy-form')) return false;
        if (form.matches('form.buy-form')) {
            // Yalnızca "Sepete ekle"; "Hemen al" bilinçli olarak sepet sayfasına gider.
            if (!submitter || submitter.name !== 'action' || submitter.value !== 'add') return false;
        }
        return typeof window.fetch === 'function' && typeof window.FormData === 'function';
    }

    document.querySelectorAll('form.add-form, form.buy-form').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (!isCartAction(form, ev.submitter)) return;
            ev.preventDefault();
            var btn = form.querySelector('.add-btn, .btn');
            if (btn) btn.classList.add('is-done');

            var data = new FormData(form);
            if (ev.submitter && ev.submitter.name) {
                data.append(ev.submitter.name, ev.submitter.value);
            }

            fetch(form.getAttribute('action') || 'cart.php', {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: data
            }).then(function (res) {
                return res.json();
            }).then(function (json) {
                if (!json || json.ok !== true) {
                    showToast((json && json.flash) || 'Bir sorun oluştu, tekrar dene.');
                    return;
                }
                updateCount(json.count || 0);
                showToast(json.flash || 'Sepet güncellendi.');
            }).catch(function () {
                // AJAX başarısızsa sayfayı normal şekilde gönder.
                if (btn) btn.classList.remove('is-done');
                if (typeof form.requestSubmit === 'function') {
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'dg-fallback';
                    hidden.value = '1';
                    form.appendChild(hidden);
                    form.submit();
                }
            });
        });
    });
})();

// 8. Sepette adet kutusu değişince otomatik güncelle
(function () {
    document.querySelectorAll('.cart-qty-form').forEach(function (form) {
        var input = form.querySelector('input[type="number"]');
        if (!input) return;
        input.addEventListener('change', function () {
            var value = Math.min(10, Math.max(1, parseInt(input.value || '1', 10)));
            input.value = value;
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        });
    });
})();

// 9. Mağaza slider'ı: otomatik geçiş, oklar ve noktalar
(function () {
    document.querySelectorAll('[data-slider]').forEach(function (root) {
        var slides = Array.prototype.slice.call(root.querySelectorAll('.promo-slide'));
        if (!slides.length) return;

        var dots = Array.prototype.slice.call(root.querySelectorAll('[data-slider-dot]'));
        var prev = root.querySelector('[data-slider-prev]');
        var next = root.querySelector('[data-slider-next]');
        var current = 0;
        var timer = null;
        var paused = false;
        var reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        function show(index) {
            current = (index + slides.length) % slides.length;
            slides.forEach(function (slide, i) {
                var active = i === current;
                slide.classList.toggle('is-active', active);
                if (active) {
                    slide.removeAttribute('aria-hidden');
                } else {
                    slide.setAttribute('aria-hidden', 'true');
                }
            });
            dots.forEach(function (dot, i) {
                dot.classList.toggle('is-active', i === current);
                dot.setAttribute('aria-selected', i === current ? 'true' : 'false');
            });
        }

        function stop() {
            if (timer) { clearInterval(timer); timer = null; }
        }

        function start() {
            stop();
            if (reduceMotion || slides.length < 2 || paused) return;
            timer = setInterval(function () { show(current + 1); }, 6000);
        }

        if (prev) prev.addEventListener('click', function () { show(current - 1); start(); });
        if (next) next.addEventListener('click', function () { show(current + 1); start(); });
        dots.forEach(function (dot) {
            dot.addEventListener('click', function () {
                show(parseInt(dot.getAttribute('data-slider-dot'), 10) || 0);
                start();
            });
        });

        root.addEventListener('mouseenter', function () { paused = true; stop(); });
        root.addEventListener('mouseleave', function () { paused = false; start(); });
        root.addEventListener('focusin', stop);
        root.addEventListener('focusout', function () { start(); });
        root.addEventListener('keydown', function (ev) {
            if (ev.key === 'ArrowLeft') { show(current - 1); start(); }
            if (ev.key === 'ArrowRight') { show(current + 1); start(); }
        });

        show(0);
        start();
    });
})();

// 10. Kaydırma konumunun korunması assets/js/scroll-restore.js dosyasındadır;
// böylece aynı davranış yönetim panelinde de kullanılabilir.
