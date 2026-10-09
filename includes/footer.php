  </div><!-- /.content-wrapper -->
</div><!-- /.wrapper -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
<script src="<?= APP_URL ?>/assets/js/offline.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.ts-select').forEach(function(el) {
    new TomSelect(el, {
      allowEmptyOption: true,
      create: false,
      sortField: { field: 'text', direction: 'asc' }
    });
  });
});
</script>
</body>
</html>
