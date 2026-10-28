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
