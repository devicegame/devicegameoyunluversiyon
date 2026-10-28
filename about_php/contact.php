<?php
$page_title = 'İletişim';
include 'header.php';
?>

<section class="page-head">
    <h1 class="page-title seq" style="--d:.05s">İletişim</h1>
    <p class="page-sub seq" style="--d:.2s">Bir sorunuz ya da projeniz mi var? Mesajınızı yazın, WhatsApp üzerinden bize ulaşsın.</p>
</section>

<section class="contact">
    <div class="contact-inner">
        <dl class="contact-info">
            <div class="info-row">
                <dt>Adres</dt>
                <dd><?php echo e($hq_address); ?></dd>
            </div>
            <div class="info-row">
                <dt>E-posta</dt>
                <dd><a class="text-link" href="mailto:<?php echo e($email); ?>"><?php echo e($email); ?></a></dd>
            </div>
            <div class="info-row">
                <dt>Yanıt kanalı</dt>
                <dd>WhatsApp</dd>
            </div>
        </dl>

        <form id="whatsappForm" class="contact-form" data-wa="<?php echo e($whatsapp); ?>">
            <div class="field">
                <label for="userName">Adınız</label>
                <input type="text" id="userName" name="name" required autocomplete="name">
            </div>
            <div class="field">
                <label for="userEmail">E-posta adresiniz</label>
                <input type="email" id="userEmail" name="email" required autocomplete="email">
            </div>
            <div class="field">
                <label for="userMessage">Mesajınız</label>
                <textarea id="userMessage" name="message" required rows="5"></textarea>
            </div>
            <button type="submit" class="btn btn-primary btn-full">WhatsApp'ta gönder</button>
            <p class="form-note">Gönder'e bastığınızda WhatsApp açılır. Mesajı orada onaylayarak iletirsiniz.</p>
        </form>
    </div>
</section>

<?php include 'footer.php'; ?>
