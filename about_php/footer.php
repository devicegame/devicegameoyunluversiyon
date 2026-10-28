</main>

<footer class="site-footer">
    <div class="footer-grid">
        <div class="footer-brand">
            <a href="index.php" class="footer-logo"><?php echo e($company_name); ?><span class="dot">.</span></a>
            <p class="footer-slogan"><?php echo e($slogan); ?></p>
        </div>

        <nav class="footer-col" aria-label="Site">
            <h2 class="footer-head">Site</h2>
            <a href="index.php">Ana sayfa</a>
            <a href="about.php">Vizyonumuz</a>
            <a href="contact.php">İletişim</a>
        </nav>

        <nav class="footer-col" aria-label="Device Game dünyası">
            <h2 class="footer-head">Device Game</h2>
            <a href="<?php echo e($shop_url); ?>" target="_blank" rel="noopener">Mağaza</a>
            <a href="<?php echo e($studio_url); ?>" target="_blank" rel="noopener">Stüdyo</a>
            <a href="contact.php">Kariyer</a>
        </nav>

        <div class="footer-col">
            <h2 class="footer-head">Bize ulaşın</h2>
            <a href="mailto:<?php echo e($email); ?>"><?php echo e($email); ?></a>
            <span class="footer-address"><?php echo e($city); ?></span>
        </div>
    </div>
    <div class="footer-bottom">
        <p>&copy; <?php echo e($year); ?> <?php echo e($company_name); ?>. Tüm hakları saklıdır.</p>
    </div>
</footer>

<script src="script.js"></script>
</body>
</html>
