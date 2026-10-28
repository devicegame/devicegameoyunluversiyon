        <style>/* Device Game Studio — iletişim alanı ve footer */
:root {
    --dg-text: #f5f6ff;
    --dg-muted: #aab4c8;
    --dg-accent: #a995ff;
    --dg-border: rgba(255, 255, 255, 0.1);
}

.wrap {
    width: min(1200px, calc(100% - 32px));
    margin-inline: auto;
}

.cta,
.cta *,
.site-footer,
.site-footer * {
    box-sizing: border-box;
}

/* İletişim */
.cta {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(220px, 260px);
    align-items: center;
    gap: clamp(2rem, 5vw, 5rem);
    margin-block: clamp(3rem, 6vw, 5rem);
    padding: clamp(2rem, 5vw, 4rem);
    overflow: hidden;
    border: 1px solid rgba(169, 149, 255, 0.25);
    border-radius: 28px;
    color: var(--dg-text);
    background:
        radial-gradient(circle at 0% 0%, rgba(129, 94, 255, 0.22), transparent 48%),
        radial-gradient(circle at 100% 100%, rgba(48, 196, 218, 0.1), transparent 42%),
        #111522;
    box-shadow: 0 24px 70px rgba(0, 0, 0, 0.2);
    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
}

.cta .eyebrow {
    margin: 0 0 1rem;
    color: #b8a8ff;
    font-size: 0.75rem;
    font-weight: 800;
    letter-spacing: 0.15em;
    text-transform: uppercase;
}

.cta h2 {
    max-width: 16ch;
    margin: 0 0 1rem;
    font-size: clamp(2rem, 4.5vw, 3.5rem);
    line-height: 1.08;
    letter-spacing: -0.04em;
}

.cta p:not(.eyebrow) {
    max-width: 58ch;
    margin: 0;
    color: #c0c9da;
    font-size: 1rem;
    line-height: 1.75;
}

.cta-actions {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.cta-actions .btn {
    display: inline-flex;
    min-height: 52px;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    padding: 0.85rem 1.25rem;
    border: 1px solid transparent;
    border-radius: 12px;
    font-size: 0.95rem;
    font-weight: 700;
    line-height: 1.3;
    text-align: center;
    text-decoration: none;
    transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease;
}

.cta-actions .btn svg {
    flex-shrink: 0;
}

.cta-actions .btn:hover {
    transform: translateY(-2px);
}

.cta-actions .btn-primary {
    color: #151126;
    background: linear-gradient(135deg, #b7a5ff, #81dcf5);
}

.cta-actions .btn-primary:hover {
    background: linear-gradient(135deg, #c9bcff, #a5eafd);
}

.cta-actions .btn-ghost {
    border-color: rgba(255, 255, 255, 0.16);
    color: var(--dg-text);
    background: rgba(255, 255, 255, 0.04);
}

.cta-actions .btn-ghost:hover {
    border-color: rgba(169, 149, 255, 0.7);
    background: rgba(169, 149, 255, 0.1);
}

/* Footer */
.site-footer {
    padding: clamp(3rem, 6vw, 5rem) 0 1.75rem;
    border-top: 1px solid var(--dg-border);
    color: var(--dg-muted);
    background: #090c15;
    font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
}

.footer-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.6fr) repeat(3, minmax(0, 1fr));
    gap: 2.5rem;
}

.footer-brand .brand {
    display: inline-flex;
    align-items: baseline;
    color: var(--dg-text);
    font-size: 1.4rem;
    font-weight: 850;
    letter-spacing: -0.05em;
    text-decoration: none;
}

.footer-brand .dot {
    color: var(--dg-accent);
}

.footer-brand .studio-tag {
    margin-left: 0.5rem;
    color: var(--dg-muted);
    font-size: 0.65rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.footer-brand p {
    max-width: 32ch;
    margin: 1rem 0 0;
    font-size: 0.9rem;
    line-height: 1.7;
}

.footer-col {
    display: flex;
    min-width: 0;
    flex-direction: column;
    align-items: flex-start;
    gap: 0.75rem;
}

.footer-col h2 {
    margin: 0 0 0.4rem;
    color: var(--dg-text);
    font-size: 0.8rem;
    font-weight: 750;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.footer-col a,
.footer-col span {
    color: var(--dg-muted);
    font-size: 0.9rem;
    line-height: 1.5;
    overflow-wrap: anywhere;
    text-decoration: none;
}

.footer-col a {
    transition: color 0.2s ease;
}

.footer-col a:hover {
    color: #d3caff;
}

.footer-bottom {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.5rem 2rem;
    margin-top: 3.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--dg-border);
    font-size: 0.8rem;
    line-height: 1.6;
}

.footer-bottom p {
    margin: 0;
}

.cta a:focus-visible,
.site-footer a:focus-visible {
    outline: 2px solid #c3b6ff;
    outline-offset: 4px;
}

/* JavaScript .in sınıfını eklediğinde animasyon oynar.
   JavaScript kapalıysa içerik yine görünür kalır. */
@media (prefers-reduced-motion: no-preference) {
    .cta[data-reveal].in {
        animation: dg-reveal 0.65s cubic-bezier(0.2, 0.7, 0.2, 1) both;
    }
}

@keyframes dg-reveal {
    from {
        opacity: 0;
        transform: translateY(20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@media (max-width: 900px) {
    .footer-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 700px) {
    .cta {
        grid-template-columns: 1fr;
        border-radius: 22px;
    }

    .cta h2 {
        max-width: none;
    }
}

@media (max-width: 520px) {
    .footer-brand,
    .footer-col:last-child {
        grid-column: 1 / -1;
    }

    .footer-bottom {
        flex-direction: column;
    }
}

@media (prefers-reduced-motion: reduce) {
    .cta-actions .btn,
    .footer-col a {
        transition: none;
    }

    .cta-actions .btn:hover {
        transform: none;
    }
}</style>
        <div class="wrap">
            <div class="cta" data-reveal>
                <div>
                    <p class="eyebrow">İletişim</p>
                    <h2>Birlikte bir evren kuralım.</h2>
                    <p>
                        Yayıncı, sanatçı, geliştirici ya da sadece iyi bir fikri olan biri — kapımız açık.
                        Projeni anlat; 48 saat içinde dönelim.
                    </p>
                </div>
                <div class="cta-actions">
                    <a class="btn btn-primary"
                       href="https://wa.me/905519578350?text=Merhaba%2C%20Device%20Game%20Studio%20ile%20ileti%C5%9Fime%20ge%C3%A7mek%20istiyorum."
                       target="_blank" rel="noopener">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.3-1.39a9.86 9.86 0 0 0 4.73 1.2h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm4.52 13.86c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.17.25-.64.81-.79.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-2-1.23-.74-.66-1.24-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.47-.07 1.47-.6 1.68-1.18.21-.58.21-1.08.14-1.18-.06-.11-.22-.17-.47-.29Z"/></svg>
                        WhatsApp'tan yaz
                    </a>
                    <a class="btn btn-ghost" href="mailto:info@devicegame.com">E-posta gönder</a>
                    <a class="btn btn-ghost" href="https://devicegame.com/shop.php" target="_blank" rel="noopener">Mağazayı ziyaret et</a>
                </div>
            </div>
        </div>
    </section>

</main>

<footer class="site-footer">
    <div class="wrap">
        <div class="footer-grid">
            <div class="footer-brand">
                <a href="#" class="brand">device game<span class="dot">.</span><span class="studio-tag">studio</span></a>
                <p>Oyun dünyasının anahtarı. Sınırları zorlayan hikayeler, yeni nesil evrenler.</p>
            </div>

            <nav class="footer-col" aria-label="Site">
                <h2>Studio</h2>
                <a href="#studio">Stüdyo</a>
                <a href="#projeler">Projeler</a>
                <a href="#surec">Süreç</a>
                <a href="#iletisim">İletişim</a>
            </nav>

            <nav class="footer-col" aria-label="Device Game dünyası">
                <h2>Device Game</h2>
                <a href="https://devicegame.com" target="_blank" rel="noopener">Ana site</a>
                <a href="https://devicegame.com/shop.php" target="_blank" rel="noopener">Mağaza</a>
                <a href="https://devicegame.com/about.php" target="_blank" rel="noopener">Vizyonumuz</a>
                <a href="https://devicegame.com/contact.php" target="_blank" rel="noopener">İletişim</a>
            </nav>

            <div class="footer-col">
                <h2>Bize ulaşın</h2>
                <a href="mailto:info@devicegame.com">info@devicegame.com</a>
                <a href="https://wa.me/905519578350" target="_blank" rel="noopener">WhatsApp ile yaz</a>
                <span>Torbalı, İzmir</span>
            </div>
        </div>

        <div class="footer-bottom">
            <p>© <span id="year">2026</span> Device Game Studio. Tüm hakları saklıdır.</p>
            <p>Stüdyo İzmir'de · Oyunlar her yerde</p>
        </div>
    </div>
</footer>

<script>
    // Menü: kaydırınca buzlu cam
    (function () {
        var nav = document.getElementById('site-nav');
        if (!nav) return;
        function onScroll() {
            nav.classList.toggle('scrolled', window.scrollY > 24);
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    })();

    // Hero: imleci takip eden ışık (yalnızca fare)
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

    // Kaydırınca beliren içerik
    (function () {
        var items = document.querySelectorAll('[data-reveal]');
        if (!items.length) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches ||
            !('IntersectionObserver' in window)) {
            items.forEach(function (el) { el.classList.add('in'); });
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('in');
                    io.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
        items.forEach(function (el) { io.observe(el); });
    })();

    // Yıl
    (function () {
        var year = document.getElementById('year');
        if (year) year.textContent = String(new Date().getFullYear());
    })();
</script>
</body>
</html>