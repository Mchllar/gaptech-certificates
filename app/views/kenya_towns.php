<?php
/**
 * Towns offered in the client Address box. It is a suggestion list, not a
 * locked dropdown: an address can still be typed in full ("WESTLANDS, NAIROBI",
 * a P.O. Box) or be a town that is not here.
 */
const KENYA_TOWNS = [
    'NAIROBI', 'MOMBASA', 'KISUMU', 'NAKURU', 'ELDORET', 'THIKA', 'RUIRU', 'KIKUYU',
    'JUJA', 'KIAMBU', 'LIMURU', 'KITENGELA', 'ATHI RIVER', 'MLOLONGO', 'SYOKIMAU',
    'NGONG', 'KAJIADO', 'ONGATA RONGAI', 'MACHAKOS', 'KITUI', 'MAKUENI', 'WOTE',
    'EMALI', 'MTITO ANDEI', 'VOI', 'WUNDANYI', 'TAVETA', 'NAMANGA', 'NAIVASHA',
    'GILGIL', 'NYAHURURU', 'NYERI', 'KARATINA', 'OTHAYA', "MURANG'A", 'KENOL',
    'MARAGUA', 'KERUGOYA', 'KUTUS', 'SAGANA', 'EMBU', 'RUNYENJES', 'CHUKA', 'MERU',
    'MAUA', 'NANYUKI', 'TIMAU', 'ISIOLO', 'MARSABIT', 'MOYALE', 'NAROK', 'BOMET',
    'KERICHO', 'LITEIN', 'SOTIK', 'KISII', 'NYAMIRA', 'KEROKA', 'MIGORI', 'RONGO',
    'AWENDO', 'ISEBANIA', 'HOMA BAY', 'MBITA', 'OYUGIS', 'KENDU BAY', 'SIAYA',
    'BONDO', 'UGUNJA', 'KAKAMEGA', 'MUMIAS', 'BUTERE', 'VIHIGA', 'MBALE', 'BUNGOMA',
    'WEBUYE', 'KIMILILI', 'BUSIA', 'MALABA', 'KITALE', 'KAPENGURIA', 'LODWAR',
    'LOKICHOGIO', 'ITEN', 'KABARNET', 'ELDAMA RAVINE', 'KAPSABET', 'NANDI HILLS',
    'MOLO', 'NJORO', 'RUMURUTI', 'MARALAL', 'MALINDI', 'WATAMU', 'KILIFI', 'MTWAPA',
    'MARIAKANI', 'KWALE', 'UKUNDA', 'DIANI', 'MSAMBWENI', 'LUNGA LUNGA', 'LAMU',
    'MPEKETONI', 'HOLA', 'GARISSA', 'WAJIR', 'MANDERA', 'MWINGI', 'MATUU', 'KANGUNDO',
    'TALA', 'OL KALOU',
];

/**
 * The town list plus a small script that shows it as a white, scrollable list
 * right under each input with list="kenya-towns". The browser's own datalist
 * popup cannot be styled (Chrome draws it dark, full height), so the script
 * takes the list attribute off and draws its own. Print once per page.
 */
function kenya_towns_datalist(): void
{
    $towns = KENYA_TOWNS;
    sort($towns);
    echo '<datalist id="kenya-towns">';
    foreach ($towns as $t) {
        echo '<option value="' . e($t) . '">';
    }
    echo '</datalist>';
    ?>
<style>
  .town-pick { position:absolute; left:0; right:0; top:100%; z-index:50; margin-top:4px;
    background:#fff; color:#1C2430; border:1px solid #D5DCE4; border-radius:10px;
    box-shadow:0 8px 24px rgba(16,24,40,.14); max-height:220px; overflow-y:auto; display:none; }
  .town-pick.open { display:block; }
  .town-pick div { padding:9px 14px; font-size:14px; cursor:pointer; }
  .town-pick div:hover, .town-pick div.on { background:#EAF4FB; color:#0D4F72; }
  .town-pick .none { color:#7A8696; cursor:default; }
  .town-pick .none:hover { background:#fff; color:#7A8696; }
</style>
<script>
(function () {
  var list = document.getElementById("kenya-towns");
  if (!list) return;
  var towns = Array.prototype.map.call(list.options, function (o) { return o.value; });

  function attach(input) {
    input.removeAttribute("list");
    input.setAttribute("autocomplete", "off");
    var holder = input.parentNode;
    if (getComputedStyle(holder).position === "static") holder.style.position = "relative";
    var box = document.createElement("div");
    box.className = "town-pick";
    holder.appendChild(box);
    var active = -1;

    function show() {
      // Filter on the last part typed, so "WESTLANDS, NA" still offers NAIROBI.
      var parts = input.value.split(",");
      var q = parts[parts.length - 1].trim().toUpperCase();
      var hits = towns.filter(function (t) { return q === "" || t.indexOf(q) !== -1; });
      hits.sort(function (a, b) { return (a.indexOf(q) === 0 ? 0 : 1) - (b.indexOf(q) === 0 ? 0 : 1); });
      box.innerHTML = "";
      active = -1;
      if (!hits.length) { box.innerHTML = "<div class=\"none\">No town matches - keep typing your own</div>"; }
      hits.forEach(function (t) {
        var d = document.createElement("div");
        d.textContent = t;
        d.addEventListener("mousedown", function (ev) { ev.preventDefault(); pick(t); });
        box.appendChild(d);
      });
      box.classList.add("open");
      box.scrollTop = 0;
    }
    function pick(t) {
      var parts = input.value.split(",");
      parts[parts.length - 1] = (parts.length > 1 ? " " : "") + t;
      input.value = parts.join(",");
      hide();
      input.dispatchEvent(new Event("change", { bubbles: true }));
    }
    function hide() { box.classList.remove("open"); active = -1; }
    function move(step) {
      var items = box.querySelectorAll("div:not(.none)");
      if (!items.length) return;
      if (active >= 0) items[active].classList.remove("on");
      active = (active + step + items.length) % items.length;
      items[active].classList.add("on");
      items[active].scrollIntoView({ block: "nearest" });
    }

    input.addEventListener("focus", show);
    input.addEventListener("input", show);
    input.addEventListener("blur", hide);
    input.addEventListener("keydown", function (ev) {
      if (!box.classList.contains("open")) { if (ev.key === "ArrowDown") show(); return; }
      if (ev.key === "ArrowDown") { ev.preventDefault(); move(1); }
      else if (ev.key === "ArrowUp") { ev.preventDefault(); move(-1); }
      else if (ev.key === "Enter" && active >= 0) {
        ev.preventDefault();
        pick(box.querySelectorAll("div:not(.none)")[active].textContent);
      }
      else if (ev.key === "Escape" || ev.key === "Tab") hide();
    });
  }

  function init() {
    document.querySelectorAll("input[list=kenya-towns]").forEach(attach);
  }
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
  else init();
})();
</script>
    <?php
}
