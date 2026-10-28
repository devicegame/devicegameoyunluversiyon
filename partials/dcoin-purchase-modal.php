<div class="dcoin-modal" id="purchaseModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="dcoin-modal-bg" data-close-modal></div>
    <div class="dcoin-modal-card">
        <p class="eyebrow">Teslimat</p>
        <h3 id="modalTitle">Teslimat hazır.</h3>
        <p id="modalDesc"></p>

        <span class="dcoin-code-label" id="modalCodeLabel">Sipariş kodu</span>
        <div class="dcoin-key-box">
            <code id="modalKeyText"></code>
            <button type="button" class="btn btn-ghost" id="copyDeliveryCode">Kopyala</button>
        </div>

        <div class="dcoin-order-code-row" id="modalOrderCodeRow" hidden>
            <div class="dcoin-order-code-meta">
                <span>Sipariş kodu</span>
                <code id="modalOrderCode"></code>
            </div>
            <button type="button" class="btn btn-ghost" id="copyOrderCode">Kopyala</button>
        </div>

        <p class="dcoin-modal-note" id="modalWhatsappNote" hidden>
            Teslimat ekibi siparişinizi bu kodla bulur. WhatsApp mesajına ürün anahtarınız eklenmez.
        </p>

        <div class="dcoin-modal-actions">
            <a class="btn dcoin-whatsapp-btn" id="whatsappOrderShare" target="_blank" rel="noopener" hidden aria-disabled="true">
                <svg viewBox="0 0 24 24" width="19" height="19" aria-hidden="true" focusable="false">
                    <path fill="currentColor" d="M12.04 2c-5.46 0-9.9 4.44-9.9 9.9 0 1.75.46 3.45 1.32 4.95L2 22l5.3-1.39a9.86 9.86 0 0 0 4.73 1.2h.01c5.46 0 9.9-4.44 9.9-9.9 0-2.65-1.03-5.14-2.9-7.01A9.82 9.82 0 0 0 12.04 2Zm0 18.02h-.01a8.2 8.2 0 0 1-4.18-1.15l-.3-.18-3.11.82.83-3.04-.2-.31a8.19 8.19 0 0 1-1.26-4.36c0-4.54 3.7-8.24 8.24-8.24 2.2 0 4.27.86 5.83 2.42a8.19 8.19 0 0 1 2.41 5.83c0 4.54-3.7 8.23-8.25 8.23Zm4.52-6.16c-.25-.12-1.47-.72-1.69-.81-.23-.08-.39-.12-.56.13-.17.25-.64.81-.79.97-.14.17-.29.19-.54.06-.25-.12-1.05-.39-2-1.23-.74-.66-1.24-1.47-1.38-1.72-.14-.25-.02-.38.11-.51.11-.11.25-.29.37-.43.12-.14.17-.25.25-.41.08-.17.04-.31-.02-.43-.06-.12-.56-1.34-.76-1.84-.2-.48-.4-.42-.56-.43h-.48c-.17 0-.43.06-.66.31-.22.25-.86.85-.86 2.07 0 1.22.89 2.4 1.01 2.56.12.17 1.75 2.67 4.23 3.74.59.26 1.05.41 1.41.52.59.19 1.13.16 1.56.1.47-.07 1.47-.6 1.68-1.18.21-.58.21-1.08.14-1.18-.06-.11-.22-.17-.47-.29Z"/>
                </svg>
                <span>Sipariş kodunu WhatsApp’tan gönder</span>
            </a>
            <button type="button" class="btn btn-primary" data-close-modal>Kapat</button>
        </div>
    </div>
</div>
