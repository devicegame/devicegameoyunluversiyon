(function () {
    'use strict';

    var configEl = document.getElementById('dg-config');
    var config = {};
    if (configEl) {
        try { config = JSON.parse(configEl.textContent || '{}'); } catch (e) { config = {}; }
    }

    var currentBalance = Number(config.balance || 0);
    var dailyLimit = Number(config.dailyLimit || 0);
    var todayCount = Number(config.todayCount || 0);
    var remaining = Number(config.remaining || 0);

    function formatNumber(value) {
        return Number(value || 0).toLocaleString('tr-TR');
    }

    function showToast(message, ms) {
        if (!message) return;
        var toast = document.getElementById('dcoinToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'dcoinToast';
            toast.className = 'dcoin-toast';
            toast.setAttribute('role', 'status');
            document.body.appendChild(toast);
        }
        toast.textContent = message;
        toast.classList.add('show');
        window.clearTimeout(showToast.timer);
        showToast.timer = window.setTimeout(function () {
            toast.classList.remove('show');
        }, ms || 3200);
    }

    // PHP flash mesajlarını eski bildirim diliyle göster.
    document.querySelectorAll('[data-dg-flash]').forEach(function (el, index) {
        window.setTimeout(function () { el.classList.add('show'); }, 80 + index * 120);
        window.setTimeout(function () { el.classList.remove('show'); }, 4200 + index * 250);
    });

    function updateBalances(balance) {
        currentBalance = Number(balance || 0);
        document.querySelectorAll('[data-live-balance]').forEach(function (el) {
            el.textContent = formatNumber(currentBalance);
        });
        refreshBuyButtons();
    }

    function updateDailyProgress() {
        remaining = Math.max(0, dailyLimit - todayCount);
        document.querySelectorAll('[data-today-count]').forEach(function (el) {
            el.textContent = String(todayCount);
        });
        document.querySelectorAll('[data-remaining-count]').forEach(function (el) {
            el.textContent = String(remaining);
        });
        document.querySelectorAll('[data-daily-fill]').forEach(function (el) {
            var pct = dailyLimit > 0 ? Math.min(100, (todayCount / dailyLimit) * 100) : 0;
            el.style.width = pct + '%';
        });
        var limitChip = document.getElementById('limitChip');
        if (limitChip) {
            limitChip.innerHTML = '<strong>' + todayCount + ' / ' + dailyLimit + '</strong> izlendi';
        }
    }

    function refreshBuyButtons() {
        document.querySelectorAll('[data-purchase][data-method="dcoin"]').forEach(function (button) {
            var price = Number(button.getAttribute('data-price') || 0);
            var card = button.closest('[data-store-card]');
            var stock = card ? Number(card.getAttribute('data-stock') || 0) : 1;
            if (stock <= 0) {
                button.disabled = true;
                button.textContent = 'Stokta yok';
                return;
            }
            if (!config.isLogged) return;
            if (price <= 0 || currentBalance < price) {
                button.disabled = true;
                button.title = 'Bakiye yetersiz';
                button.textContent = price > 0 ? 'Yetersiz bakiye' : 'D-Coin ile alınamaz';
            } else {
                button.disabled = false;
                button.title = '';
                button.textContent = button.getAttribute('data-buy-label') || 'D-Coin ile al';
            }
        });
    }

    // Ana sayfa mağaza araması.
    var searchInput = document.getElementById('storeSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            var query = searchInput.value.toLocaleLowerCase('tr-TR').trim();
            var visible = 0;
            document.querySelectorAll('[data-store-card]').forEach(function (card) {
                var haystack = (card.getAttribute('data-name') || '').toLocaleLowerCase('tr-TR');
                var show = !query || haystack.indexOf(query) !== -1;
                card.style.display = show ? '' : 'none';
                if (show) visible++;
            });
            var countEl = document.querySelector('[data-visible-count]');
            if (countEl) countEl.textContent = String(visible);
        });
    }

    // --------- Satın alma ---------
    var modal = document.getElementById('purchaseModal');
    var modalTitle = document.getElementById('modalTitle');
    var modalDesc = document.getElementById('modalDesc');
    var modalCodeLabel = document.getElementById('modalCodeLabel');
    var modalKeyText = document.getElementById('modalKeyText');
    var modalOrderCodeRow = document.getElementById('modalOrderCodeRow');
    var modalOrderCode = document.getElementById('modalOrderCode');
    var modalWhatsappNote = document.getElementById('modalWhatsappNote');
    var whatsappOrderShare = document.getElementById('whatsappOrderShare');

    function closeModal() {
        if (modal) modal.classList.remove('open');
    }

    document.querySelectorAll('[data-close-modal]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeModal();
    });

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text || '').then(function () {
                showToast('Kod kopyalandı.');
            }).catch(function () {
                showToast('Kodu elle kopyalayabilirsiniz.');
            });
        } else {
            showToast('Kodu elle kopyalayabilirsiniz.');
        }
    }

    var copyButton = document.getElementById('copyDeliveryCode');
    if (copyButton && modalKeyText) {
        copyButton.addEventListener('click', function () {
            copyText(modalKeyText.textContent || '');
        });
    }
    var copyOrderButton = document.getElementById('copyOrderCode');
    if (copyOrderButton && modalOrderCode) {
        copyOrderButton.addEventListener('click', function () {
            copyText(modalOrderCode.textContent || '');
        });
    }

    function updatePurchasedCard(itemId) {
        document.querySelectorAll('[data-store-card][data-item-id="' + itemId + '"]').forEach(function (card) {
            var stock = Math.max(0, Number(card.getAttribute('data-stock') || 0) - 1);
            card.setAttribute('data-stock', String(stock));
            var stockLabel = card.querySelector('[data-stock-label]');
            if (stockLabel) {
                stockLabel.textContent = stock > 0 ? stock + ' stok' : 'Tükendi';
                stockLabel.classList.toggle('is-low', stock > 0 && stock <= 2);
                stockLabel.classList.toggle('is-empty', stock <= 0);
            }
            if (stock <= 0) {
                card.querySelectorAll('[data-purchase]').forEach(function (button) {
                    button.disabled = true;
                    button.textContent = 'Stokta yok';
                });
            }
        });
    }

    function whatsappOrderUrl(result, orderCode) {
        var phone = String(config.whatsapp || '').replace(/\D/g, '');
        if (!phone || !orderCode) return '';

        var lines = [
            'Merhaba, Device Game üzerinden satın aldığım ürün için sipariş kodumu paylaşıyorum.',
            '',
            'Ürün: ' + (result.item_name || 'Ürün'),
            'Sipariş kodu: ' + orderCode
        ];
        if (result.payment_method === 'dcoin' && result.price_paid_dcoin != null) {
            lines.push('Ödeme: ' + formatNumber(result.price_paid_dcoin) + ' D-Coin');
        } else if (result.payment_method === 'cash' && result.price_paid_tl != null) {
            lines.push('Ödeme: ₺' + formatNumber(result.price_paid_tl));
        }
        lines.push('', 'Teslimat için yardımcı olabilir misiniz?');

        return 'https://wa.me/' + phone + '?text=' + encodeURIComponent(lines.join('\n'));
    }

    function openDeliveryModal(result) {
        if (!modal || !modalTitle || !modalDesc || !modalKeyText) return;
        var isKey = result.delivery_type !== 'order_code';
        var deliveredCode = result.delivered_key || '';
        var orderCode = result.order_code || (!isKey ? deliveredCode : '');
        var whatsappUrl = whatsappOrderUrl(result, orderCode);

        modalTitle.textContent = isKey ? 'Anahtarın hazır.' : 'Siparişin oluşturuldu.';
        modalDesc.textContent = isKey
            ? result.item_name + ' için ürün anahtarın teslim edildi. Sipariş kodunu WhatsApp’tan göndererek teslimat kaydını hızlandırabilirsin.'
            : result.item_name + ' için sipariş kodun oluşturuldu. Kodu WhatsApp’tan bize gönder, teslimatını hazırlayalım.';
        if (modalCodeLabel) modalCodeLabel.textContent = isKey ? 'Ürün anahtarı' : 'Sipariş kodu';
        modalKeyText.textContent = deliveredCode;

        if (modalOrderCodeRow && modalOrderCode) {
            var showOrderCode = !!orderCode && orderCode !== deliveredCode;
            modalOrderCode.textContent = showOrderCode ? orderCode : '';
            modalOrderCodeRow.hidden = !showOrderCode;
        }
        if (modalWhatsappNote) modalWhatsappNote.hidden = !whatsappUrl;
        if (whatsappOrderShare) {
            if (whatsappUrl) {
                whatsappOrderShare.href = whatsappUrl;
                whatsappOrderShare.removeAttribute('aria-disabled');
            } else {
                whatsappOrderShare.removeAttribute('href');
                whatsappOrderShare.setAttribute('aria-disabled', 'true');
            }
            whatsappOrderShare.hidden = !whatsappUrl;
        }

        modal.classList.add('open');
    }

    document.querySelectorAll('[data-purchase]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!config.isLogged) {
                window.location.href = 'login.php?next=' + encodeURIComponent(config.loginNext || 'index.php');
                return;
            }
            var itemId = Number(button.getAttribute('data-purchase') || 0);
            var method = button.getAttribute('data-method') || 'dcoin';
            var question = method === 'dcoin'
                ? 'D-Coin ile satın alınsın mı? Tutar bakiyenden düşülecek.'
                : 'Nakit satın alma oluşturulsun mu?';
            if (!window.confirm(question)) return;

            var originalText = button.textContent;
            button.disabled = true;
            button.textContent = 'İşleniyor...';

            fetch('api/purchase.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({
                    store_item_id: itemId,
                    payment_method: method,
                    csrf: config.csrf || ''
                })
            }).then(function (response) {
                return response.json().catch(function () {
                    return { ok: false, error: 'Sunucudan geçerli bir yanıt alınamadı.' };
                });
            }).then(function (result) {
                if (result && result.ok) {
                    if (typeof result.new_balance !== 'undefined') updateBalances(result.new_balance);
                    updatePurchasedCard(itemId);
                    openDeliveryModal(result);
                    showToast('Satın alma tamamlandı.');
                } else {
                    showToast((result && result.error) || 'Satın alma tamamlanamadı.', 4200);
                }
            }).catch(function () {
                showToast('Bağlantı hatası. Tekrar deneyin.', 4200);
            }).finally(function () {
                button.textContent = originalText;
                refreshBuyButtons();
                var card = button.closest('[data-store-card]');
                if (!card || Number(card.getAttribute('data-stock') || 0) > 0) {
                    if (method === 'cash') button.disabled = false;
                }
            });
        });
    });

    // --------- Video oyuncusu ---------
    var video = document.getElementById('dgVideo');
    var ads = Array.isArray(config.ads) ? config.ads : [];
    if (!video || !ads.length) {
        refreshBuyButtons();
        updateDailyProgress();
        return;
    }

    var playOverlay = document.getElementById('playOverlay');
    var bigPlay = document.getElementById('bigPlay');
    var pauseBtn = document.getElementById('pauseBtn');
    var muteBtn = document.getElementById('muteBtn');
    var progressFill = document.getElementById('progressFill');
    var timeLabel = document.getElementById('timeLabel');
    var rewardChip = document.getElementById('rewardChip');
    var playerStatus = document.getElementById('playerStatus');
    var statusText = document.getElementById('statusText');
    var seekGuard = document.getElementById('seekGuard');
    var watchResult = document.getElementById('watchResult');
    var tabChip = document.getElementById('tabChip');

    var currentAd = null;
    var watchToken = null;
    var watchStartTime = 0;
    var hasRewarded = false;
    var isResettingSeek = false;
    var lastTime = 0;
    var starting = false;

    function fmt(seconds) {
        seconds = Math.max(0, Math.round(Number(seconds) || 0));
        var m = String(Math.floor(seconds / 60)).padStart(2, '0');
        var s = String(seconds % 60).padStart(2, '0');
        return m + ':' + s;
    }

    function setStatus(text) {
        if (statusText) statusText.textContent = text;
        if (playerStatus) playerStatus.textContent = text;
    }

    function showResult(html, isError) {
        if (!watchResult) return;
        watchResult.innerHTML = '<div class="dcoin-result-card' + (isError ? ' is-error' : '') + '">' + html + '</div>';
        watchResult.hidden = false;
    }

    function findAd(id) {
        for (var i = 0; i < ads.length; i++) {
            if (Number(ads[i].id) === Number(id)) return ads[i];
        }
        return null;
    }

    function loadAd(ad) {
        if (!ad) return;
        currentAd = ad;
        watchToken = null;
        watchStartTime = 0;
        hasRewarded = false;
        lastTime = 0;
        if (watchResult) { watchResult.hidden = true; watchResult.innerHTML = ''; }
        if (progressFill) progressFill.style.width = '0%';
        if (bigPlay) bigPlay.setAttribute('aria-label', 'Videoyu oynat');

        document.querySelectorAll('[data-ad-id]').forEach(function (item) {
            item.classList.toggle('is-active', Number(item.getAttribute('data-ad-id')) === Number(ad.id));
        });

        video.pause();
        video.removeAttribute('src');
        video.load();
        video.src = ad.video_url;
        if (ad.thumbnail_url) video.setAttribute('poster', ad.thumbnail_url);
        else video.removeAttribute('poster');
        video.load();

        if (rewardChip) rewardChip.textContent = '+' + Number(ad.reward_dcoin || 0) + ' D-Coin';
        if (timeLabel) timeLabel.textContent = '00:00 / ' + fmt(ad.duration_sec);
        if (playOverlay) playOverlay.hidden = false;
        setStatus(ad.title || 'Hazır');
    }

    document.querySelectorAll('[data-ad-id]').forEach(function (item) {
        item.addEventListener('click', function () {
            loadAd(findAd(item.getAttribute('data-ad-id')));
        });
    });

    video.addEventListener('loadedmetadata', function () {
        if (timeLabel) {
            timeLabel.textContent = '00:00 / ' + fmt(video.duration || (currentAd && currentAd.duration_sec));
        }
        if (playOverlay && video.paused) playOverlay.hidden = false;
    });

    function startPlayback() {
        if (!currentAd || starting) return;
        if (config.isLogged && remaining <= 0) {
            showToast('Günlük izleme limitin doldu. Yarın tekrar gel.');
            return;
        }
        if (video.ended) {
            watchToken = null;
            watchStartTime = 0;
            hasRewarded = false;
            lastTime = 0;
            try { video.currentTime = 0; } catch (e) {}
        }

        function playNow() {
            if (playOverlay) playOverlay.hidden = true;
            var promise = video.play();
            if (promise && typeof promise.catch === 'function') {
                promise.catch(function () {
                    if (playOverlay) playOverlay.hidden = false;
                    setStatus('Oynatmak için tekrar dokunun');
                });
            }
        }

        if (!config.isLogged) {
            showToast('Videoyu izleyebilirsin; D-Coin için giriş yapmalısın.');
            playNow();
            return;
        }
        if (watchToken) {
            playNow();
            return;
        }

        starting = true;
        setStatus('Ödül oturumu açılıyor...');
        fetch('api/watch_start.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ ad_id: currentAd.id, csrf: config.csrf || '' })
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, error: 'Video başlatılamadı.' };
            });
        }).then(function (result) {
            if (!result || !result.ok) {
                showToast((result && result.error) || 'Video başlatılamadı.');
                setStatus('Hazır');
                return;
            }
            watchToken = result.token;
            watchStartTime = Date.now();
            playNow();
        }).catch(function () {
            showToast('Bağlantı hatası. Tekrar deneyin.');
            setStatus('Hazır');
        }).finally(function () {
            starting = false;
        });
    }

    if (bigPlay) bigPlay.addEventListener('click', startPlayback);
    video.addEventListener('click', function () {
        if (video.paused) startPlayback();
        else video.pause();
    });
    if (pauseBtn) {
        pauseBtn.addEventListener('click', function () {
            if (video.paused) startPlayback();
            else video.pause();
        });
    }
    if (muteBtn) {
        muteBtn.addEventListener('click', function () {
            video.muted = !video.muted;
            muteBtn.textContent = video.muted ? 'Sessiz' : 'Ses açık';
        });
    }

    video.addEventListener('play', function () {
        if (playOverlay) playOverlay.hidden = true;
        setStatus('Oynatılıyor. Sekmeyi değiştirmeyin.');
        if (pauseBtn) pauseBtn.textContent = 'Duraklat';
    });
    video.addEventListener('pause', function () {
        if (!video.ended) {
            setStatus('Duraklatıldı. Devam etmek için oynatın.');
            if (playOverlay) playOverlay.hidden = false;
        }
        if (pauseBtn) pauseBtn.textContent = 'Oynat';
    });
    video.addEventListener('timeupdate', function () {
        var duration = video.duration || (currentAd && currentAd.duration_sec) || 0;
        var current = video.currentTime || 0;
        if (progressFill) progressFill.style.width = (duration ? Math.min(100, (current / duration) * 100) : 0) + '%';
        if (timeLabel) timeLabel.textContent = fmt(current) + ' / ' + fmt(duration);
        if (!video.seeking && !isResettingSeek) lastTime = current;
    });

    video.addEventListener('seeking', function () {
        if (isResettingSeek) return;
        if (video.currentTime > lastTime + 1.2) {
            isResettingSeek = true;
            try { video.currentTime = lastTime; } catch (e) {}
            if (seekGuard) {
                seekGuard.classList.add('show');
                window.setTimeout(function () { seekGuard.classList.remove('show'); }, 2200);
            }
            setStatus('İleri sarma engellendi.');
            window.setTimeout(function () { isResettingSeek = false; }, 350);
        }
    });

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (!video.paused) {
                video.pause();
                showToast('Sekme değiştirildiği için video duraklatıldı.');
            }
            if (tabChip) tabChip.innerHTML = 'Sekme: <strong>Arka planda</strong>';
        } else if (tabChip) {
            tabChip.innerHTML = 'Sekme: <strong>Aktif</strong>';
        }
    });
    video.addEventListener('contextmenu', function (event) { event.preventDefault(); });

    video.addEventListener('ended', function () {
        if (progressFill) progressFill.style.width = '100%';
        if (playOverlay) playOverlay.hidden = false;
        setStatus('Tamamlandı. Ödül doğrulanıyor...');

        if (!config.isLogged) {
            showResult('<div><b>Videoyu tamamladın.</b><p>D-Coin kazanmak için <a href="login.php?next=' + encodeURIComponent(config.loginNext || 'index.php#dcoin') + '">giriş yap</a>.</p></div>', false);
            return;
        }
        if (hasRewarded || !watchToken) return;
        hasRewarded = true;

        var declaredDuration = Number(currentAd && currentAd.duration_sec) || 0;
        var mediaDuration = Number(video.duration) || declaredDuration;
        var expected = Math.min(declaredDuration || mediaDuration, mediaDuration || declaredDuration);
        var elapsed = watchStartTime ? (Date.now() - watchStartTime) / 1000 : 0;
        if (expected > 0 && elapsed < expected - 3) {
            showResult('<div><b>Süre doğrulanamadı.</b><p>Ödül için videoyu baştan sona izlemelisiniz.</p></div>', true);
            watchToken = null;
            return;
        }

        fetch('api/watch_complete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ ad_id: currentAd.id, token: watchToken, csrf: config.csrf || '' })
        }).then(function (response) {
            return response.json().catch(function () {
                return { ok: false, error: 'Ödül doğrulanamadı.' };
            });
        }).then(function (result) {
            watchToken = null;
            if (result && result.ok) {
                todayCount = Number(result.today_count != null ? result.today_count : todayCount + 1);
                updateBalances(result.new_balance);
                updateDailyProgress();
                showResult(
                    '<div><b>+' + Number(result.earned || 0) + ' D-Coin kazandın.</b>' +
                    '<p>Bakiyen: ' + formatNumber(result.new_balance) + ' D-Coin. Bugün ' + todayCount + ' / ' + dailyLimit + ' izleme tamamlandı.</p></div>',
                    false
                );
                showToast('+' + Number(result.earned || 0) + ' D-Coin hesabına eklendi.');
                setStatus(remaining > 0 ? 'Ödül eklendi. Bir video daha seçebilirsin.' : 'Günlük limit tamamlandı.');
            } else {
                showResult('<div><b>Ödül verilemedi.</b><p>' + ((result && result.error) || 'Tekrar deneyin.') + '</p></div>', true);
                setStatus('Ödül doğrulanamadı.');
            }
        }).catch(function () {
            watchToken = null;
            showResult('<div><b>Bağlantı hatası.</b><p>Ödül doğrulanırken bir sorun oluştu.</p></div>', true);
        });
    });

    loadAd(findAd(config.activeAdId) || ads[0]);
    refreshBuyButtons();
    updateDailyProgress();
})();
