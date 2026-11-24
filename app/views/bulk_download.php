<?php
/**
 * app/views/bulk_download.php - the "Download selected" behaviour shared by the
 * governor register (index.php) and the tracking register (tracking_list.php).
 *
 * Expects the page to have drawn #bulk-dl, #bulk-dl-btn, #bulk-dl-count,
 * #bulk-all and .bulk-pick boxes. Does nothing when they are not there.
 */
?>
<style>
  .bulk-bar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin: 0 0 10px; }
  .bulk-bar button[disabled] { opacity: .5; cursor: not-allowed; }
</style>
<script>
(function () {
  var form = document.getElementById('bulk-dl');
  if (!form) return;
  var btn   = document.getElementById('bulk-dl-btn');
  var count = document.getElementById('bulk-dl-count');
  var all   = document.getElementById('bulk-all');
  var picks = Array.prototype.slice.call(document.querySelectorAll('.bulk-pick'));
  var idle  = count.textContent;

  function refresh() {
    var n = picks.filter(function (b) { return b.checked; }).length;
    btn.disabled = n === 0;
    btn.textContent = n ? 'Download ' + n + ' selected' : 'Download selected';
    count.textContent = n ? 'They come as one .zip file.' : idle;
    if (all) {
      all.checked = n > 0 && n === picks.length;
      all.indeterminate = n > 0 && n < picks.length;
    }
  }

  picks.forEach(function (b) { b.addEventListener('change', refresh); });
  if (all) {
    all.addEventListener('change', function () {
      picks.forEach(function (b) { b.checked = all.checked; });
      refresh();
    });
  }

  // A new PDF takes a few seconds to draw, so a big selection can take a
  // while. The spinning ring and the "sent once" guard come from layout.php;
  // this only changes the words, and puts them back when the zip arrives.
  form.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return;
    btn.textContent = 'Preparing the download...';
    count.textContent = 'This can take a minute for many certificates. Stay on this page.';
  });
  form.addEventListener('busy:done', refresh);

  refresh();
})();
</script>
