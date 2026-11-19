<?php
/**
 * Shared pieces for the entry pages (welcome, login, client_login, verify):
 * the stylesheet, the logo, messages, the way back, and the form behaviour.
 * Styles live in public/assets/auth.css and are scoped to <div id="wl">.
 */

if (!function_exists('entry_icon')) {
    /** A small line icon as inline SVG. */
    function entry_icon(string $name): string
    {
        $paths = [
            'staff'  => '<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
            'client' => '<circle cx="9" cy="7.5" r="3.6"/><path d="M2.8 20.5c0-3.4 2.8-6.2 6.2-6.2s6.2 2.8 6.2 6.2"/>'
                      . '<path d="M16.3 4.3a3.5 3.5 0 0 1 0 6.4"/><path d="M21.2 20.5c0-2.6-1.6-4.8-3.9-5.7"/>',
            'shield' => '<path d="M12 2.8l7.5 3v5.6c0 4.6-3.2 8.3-7.5 9.8-4.3-1.5-7.5-5.2-7.5-9.8V5.8z"/>'
                      . '<path d="M8.7 12l2.3 2.3 4.3-4.6"/>',
            'next'   => '<path d="M9 6l6 6-6 6"/>',
            'back'   => '<path d="M19 12H5M11 18l-6-6 6-6"/>',
            'error'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5v.01"/>',
            'info'   => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5v.01"/>',
        ];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"'
             . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
             . ($paths[$name] ?? '') . '</svg>';
    }

    /** The stylesheet. Call once, right after layout_top(). */
    function entry_styles(): string
    {
        $v = @filemtime(dirname(__DIR__, 2) . '/public/assets/auth.css') ?: 1;
        return '<link rel="stylesheet" href="assets/auth.css?v=' . $v . '">';
    }

    /** The company logo, linking back to the start page. */
    function entry_logo(): string
    {
        return '<a class="wl-logo-link" href="welcome.php" aria-label="GAPTECH Solutions Ltd, start page">'
             . '<img class="wl-logo" src="assets/logo_wide.png" width="800" height="179"'
             . ' alt="GAPTECH Solutions Ltd - Fleet Management Systems &amp; IT Solutions"></a>';
    }

    /** A message box: $kind is 'error' or 'info'. */
    function entry_alert(string $kind, string $text): string
    {
        $role = $kind === 'error' ? ' role="alert"' : ' role="status"';
        return '<div class="wl-alert is-' . e($kind) . '"' . $role . '>'
             . entry_icon($kind === 'error' ? 'error' : 'info') . '<span>' . e($text) . '</span></div>';
    }

    /** The quiet link under the card. */
    function entry_back(string $href, string $label): string
    {
        return '<a class="wl-back" href="' . e($href) . '">' . entry_icon('back') . e($label) . '</a>';
    }

    /**
     * Form behaviour: a Caps Lock warning on password fields that have a
     * .wl-caps line after them, and a busy state on submit so a slow server
     * is not met with a second click.
     */
    function entry_script(): string
    {
        return <<<'JS'
<script>
(function () {
  document.querySelectorAll('#wl input[type="password"]').forEach(function (input) {
    var field = input.closest('.wl-field');
    var warn = field && field.querySelector('.wl-caps');
    if (!warn) { return; }
    function check(ev) {
      if (ev.getModifierState) { warn.classList.toggle('is-on', ev.getModifierState('CapsLock')); }
    }
    input.addEventListener('keydown', check);
    input.addEventListener('keyup', check);
    input.addEventListener('blur', function () { warn.classList.remove('is-on'); });
  });

  document.querySelectorAll('#wl form.wl-form').forEach(function (form) {
    form.addEventListener('submit', function () {
      var btn = form.querySelector('.wl-btn[type="submit"]');
      if (!btn) { return; }
      // After the browser has taken the click, so the button's value is still sent.
      setTimeout(function () {
        btn.disabled = true;
        btn.classList.add('is-busy');
        var label = btn.querySelector('.wl-btn-label');
        if (label && btn.dataset.busy) { label.textContent = btn.dataset.busy; }
      }, 0);
    });
  });

  // Coming back with the browser's Back button: the page is restored from cache
  // with the button still spinning. Put it back.
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) { return; }
    document.querySelectorAll('#wl .wl-btn.is-busy').forEach(function (btn) {
      btn.disabled = false;
      btn.classList.remove('is-busy');
      var label = btn.querySelector('.wl-btn-label');
      if (label && btn.dataset.idle) { label.textContent = btn.dataset.idle; }
    });
  });
})();
</script>
JS;
    }
}
