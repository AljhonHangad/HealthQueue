<?php if (!empty($hqIsAdminLayout)): ?>
  </div><!-- /.admin-main -->
</div><!-- /.admin-layout -->
<?php else: ?>
<footer class="site-footer">
  <div class="container">
    <div class="footer-row">
      <span class="footer-note">&copy; <?= date('Y') ?> HealthQueue. All rights reserved.</span>
      <ul class="footer-links">
        <li><a href="#">Privacy Policy</a></li>
        <li><a href="#">Terms &amp; Conditions</a></li>
        <li><a href="<?= HQ_BASE_URL ?>/index.php#contact">Contact Us</a></li>
      </ul>
    </div>
  </div>
</footer>
<?php endif; ?>

<script src="<?= HQ_BASE_URL ?>/assets/js/main.js?v=<?= @filemtime(__DIR__ . '/../assets/js/main.js') ?: 1 ?>"></script>
</body>
</html>
