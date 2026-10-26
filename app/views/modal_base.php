<?php
/**
 * The dialog look and the open/close machinery, shared by every popup.
 *
 * Goes in app/views/modal_base.php. Pull it in with require_once - never require -
 * so a page that includes two dialogs still only emits this once:
 *
 *     <?php require_once APP_DIR . '/views/modal_base.php'; ?>
 *
 * Then any dialog is just:
 *
 *     <div class="gt-modal" id="m-something">
 *       <div class="gt-box">...<button class="gt-close">Cancel</button></div>
 *     </div>
 *
 * and anything with data-modal="#m-something" opens it.
 *
 * ---------------------------------------------------------------------------
 * Why this is its own file
 * ---------------------------------------------------------------------------
 * These styles used to live inside renew_modal.php, which is only included for
 * administrators. The moment accounts needed a dialog of their own - the payment
 * message on certificate_view.php - they would have got the markup with none of
 * the CSS and none of the script: an invisible dialog behind a link that does
 * nothing. Shared furniture belongs somewhere both roles can reach.
 */
?>
<style>
/* Dialogs: centred, wide enough not to scroll, no backdrop - same as tracking. */
.gt-modal { display: none; position: fixed; inset: 0; z-index: 2000; }
.gt-modal.on { display: block; }
.gt-box {
  position: absolute; left: 50%; top: 50%; transform: translate(-50%, -50%);
  /* Without this the padding and border sit OUTSIDE the width and max-height, so
     a box capped at 337px actually renders 387px tall and the gap below is 50px
     smaller than the rule says. Measured, not assumed. */
  box-sizing: border-box;
  width: min(720px, calc(100vw - 32px));
  /* Room to breathe top and bottom. A tall form scrolls inside the box rather
     than running to the edges of the screen, which reads as the page having
     overflowed rather than as a dialog sitting on top of it.
     dvh is there for phones, where the visible height changes as the address bar
     hides and vh does not follow it. Whichever of the two is SMALLER wins: dvh can
     resolve larger than the visible area in some contexts, and taking it on trust
     gives a box taller than intended with the gap squeezed to nothing. */
  max-height: calc(100vh - 96px);
  max-height: min(calc(100vh - 96px), calc(100dvh - 96px));
  overflow: auto;
  background: #fff; border: 1px solid #D7DEE5; border-radius: 12px;
  box-shadow: 0 18px 50px rgba(13, 79, 114, 0.28);
  padding: 22px 24px 26px;
}
.gt-box h3 { margin: 0 0 14px; font-size: 19px; color: #0D4F72; }
.gt-f { display: block; margin: 0 0 14px; font-size: 13px; font-weight: 600; color: #44505E; }
.gt-f textarea, .gt-f input {
  display: block; width: 100%; margin-top: 5px; box-sizing: border-box;
  padding: 9px 11px; border: 1px solid #C9D3DC; border-radius: 6px;
  font-family: inherit; font-size: 14px; font-weight: 400; resize: vertical;
}
.gt-hint { margin: 0 0 14px; font-size: 13px; color: #6B7786; line-height: 1.5; }
.gt-foot { display: flex; justify-content: flex-end; gap: 10px; margin-top: 22px; }
/* A pasted M-Pesa message, shown as it arrived. */
.gt-msg {
  margin: 0; padding: 14px 16px; background: #F2F7FB;
  border-left: 4px solid #1890D0; border-radius: 6px;
  font-size: 14px; line-height: 1.6; color: #1D2530; word-break: break-word;
}
</style>

<script>
(function () {
  // Anything with data-modal="#id" opens that dialog; .gt-close, the backdrop or
  // Escape shuts whatever is open.
  function shutAll() {
    document.querySelectorAll('.gt-modal.on').forEach(function (m) { m.classList.remove('on'); });
  }

  document.addEventListener('click', function (ev) {
    var opener = ev.target.closest('[data-modal]');
    if (opener) {
      ev.preventDefault();
      var m = document.querySelector(opener.getAttribute('data-modal'));
      if (m) {
        m.classList.add('on');
        var first = m.querySelector('textarea, input:not([type=hidden]), select');
        if (first) { first.focus(); }
      }
      return;
    }
    // Clicking the backdrop itself, or any Cancel, closes.
    if (ev.target.classList.contains('gt-modal') || ev.target.classList.contains('gt-close')) {
      shutAll();
    }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') { shutAll(); }
  });
})();
</script>