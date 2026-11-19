<?php
/**
 * The look of the form dialogs (New staff account, Client portal login, ...).
 *
 *     <?= nu_dialog_css('#m-something') ?>
 *
 * Every rule is scoped to the dialog's id on purpose: content.css and ui.css style
 * inputs and labels inside <main> with long selectors, and an id outranks them all
 * without !important. The markup uses the nu-* classes; see user_admin.php.
 * Needs modal_base.php for opening and closing.
 */
if (!function_exists('nu_dialog_css')) {
    function nu_dialog_css(string $id): string
    {
        return '<style>' . str_replace('#NU', $id, <<<'CSS'
    #NU {
      --nu-navy: #0D4F72; --nu-blue: #1890D0; --nu-green: #8CC63F; --nu-ok: #2E7D32;
      --nu-ink: #1C2430; --nu-body: #3D4756; --nu-muted: #5C6775;
      --nu-line: #E4EBF2; --nu-soft: #F6F9FC; --nu-bad: #C3121A;
    }
    #NU .gt-box {
      width: min(560px, calc(100vw - 32px)); padding: 0;
      display: flex; flex-direction: column; overflow: hidden;
      border: 1px solid var(--nu-line); border-radius: 16px;
      box-shadow: 0 24px 60px rgba(13,79,114,.28), 0 2px 8px rgba(13,79,114,.08);
      color: var(--nu-body); text-align: left;
    }

    #NU .nu-head {
      display: flex; align-items: flex-start; gap: 14px;
      padding: 20px 22px 16px; border-bottom: 1px solid var(--nu-line);
    }
    #NU .nu-badge {
      flex: none; width: 42px; height: 42px; border-radius: 12px;
      display: flex; align-items: center; justify-content: center;
      background: linear-gradient(135deg, #E8F4FB, #EEF7E3); color: var(--nu-navy);
    }
    #NU .nu-head h3 { margin: 1px 0 3px; font-size: 18px; font-weight: 700; color: var(--nu-ink); letter-spacing: -.2px; }
    #NU .nu-head p { margin: 0; font-size: 13px; color: var(--nu-muted); line-height: 1.45; }
    #NU .nu-x {
      flex: none; margin: -4px -6px 0 auto; width: 34px; height: 34px; padding: 0;
      display: flex; align-items: center; justify-content: center;
      background: none; border: 0; border-radius: 8px; color: var(--nu-muted); cursor: pointer;
      box-shadow: none;
    }
    #NU .nu-x:hover { background: var(--nu-soft); color: var(--nu-ink); }
    #NU .nu-x svg { pointer-events: none; }   /* so the click lands on .gt-close itself */

    #NU .nu-body { flex: 1 1 auto; overflow: auto; padding: 18px 22px 6px; }

    #NU .nu-alert {
      display: flex; gap: 10px; align-items: flex-start;
      margin: 0 0 16px; padding: 10px 13px; border-radius: 10px;
      background: #FDF1F1; border: 1px solid #F5D0D2; color: #93131A;
      font-size: 13.5px; line-height: 1.45;
    }
    #NU .nu-alert svg { flex: none; margin-top: 1px; }

    #NU .nu-section {
      display: flex; align-items: center; gap: 10px; margin: 4px 0 12px;
      font-size: 11.5px; font-weight: 700; letter-spacing: .6px;
      text-transform: uppercase; color: var(--nu-muted);
    }
    #NU .nu-section::after { content: ""; flex: 1; height: 1px; background: var(--nu-line); }
    #NU .nu-section + * { margin-top: 0; }
    #NU .nu-gap { height: 10px; }

    #NU .nu-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 14px; }
    @media (max-width: 520px) { #NU .nu-grid { grid-template-columns: 1fr; } }

    #NU .nu-field { margin: 0 0 14px; }
    #NU label.nu-label, #NU .nu-label {
      display: block; margin: 0 0 6px; padding: 0;
      font-size: 13px; font-weight: 600; letter-spacing: 0; text-transform: none; color: var(--nu-ink);
    }
    #NU .nu-label .req { color: var(--nu-bad); font-weight: 600; }
    #NU .nu-label .opt { color: var(--nu-muted); font-weight: 400; }
    #NU input.nu-input {
      display: block; width: 100%; box-sizing: border-box; margin: 0;
      padding: 10px 12px; font: inherit; font-size: 14.5px; line-height: 1.4; font-weight: 400;
      color: var(--nu-ink); background: var(--nu-soft);
      border: 1px solid var(--nu-line); border-radius: 10px; box-shadow: none;
      text-transform: none; letter-spacing: 0;
      transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
    }
    #NU input.nu-input:hover { border-color: #CBDCEA; }
    #NU input.nu-input:focus {
      outline: none; background: #fff; border-color: var(--nu-blue);
      box-shadow: 0 0 0 3px rgba(24,144,208,.15);
    }
    #NU input.nu-input.is-good { border-color: #A9D18E; }
    #NU input.nu-input.is-bad  { border-color: #E5A3A7; }
    #NU .nu-mono { font-family: var(--gt-font-mono, Consolas, monospace); letter-spacing: .3px; }
    #NU .nu-help { margin: 6px 0 0; font-size: 12.5px; color: var(--nu-muted); line-height: 1.45; }

    /* role cards */
    #NU .nu-roles { display: grid; gap: 8px; margin: 0 0 16px; padding: 0; border: 0; }
    #NU .nu-role {
      position: relative; display: flex; gap: 12px; align-items: flex-start;
      margin: 0; padding: 11px 13px; cursor: pointer;
      border: 1px solid var(--nu-line); border-radius: 11px; background: #fff;
      font-size: 13px; font-weight: 400; letter-spacing: 0; text-transform: none; color: var(--nu-body);
      transition: border-color .15s ease, background .15s ease, box-shadow .15s ease;
    }
    #NU .nu-role:hover { border-color: #CBDCEA; background: var(--nu-soft); }
    #NU .nu-role input { position: absolute; opacity: 0; pointer-events: none; }
    #NU .nu-dot {
      flex: none; width: 18px; height: 18px; margin-top: 1px; box-sizing: border-box;
      border: 1.5px solid #B6C2CF; border-radius: 50%; background: #fff;
      transition: border .15s ease;
    }
    #NU .nu-role b { display: block; margin: 0 0 2px; font-size: 14px; font-weight: 600; color: var(--nu-ink); }
    #NU .nu-role span.nu-desc { display: block; line-height: 1.45; color: var(--nu-muted); }
    #NU .nu-role:has(input:checked) {
      border-color: var(--nu-blue); background: #F2F9FD; box-shadow: 0 0 0 1px var(--nu-blue);
    }
    #NU .nu-role input:checked + .nu-dot { border: 5px solid var(--nu-blue); }
    #NU .nu-role input:focus-visible + .nu-dot { box-shadow: 0 0 0 3px rgba(24,144,208,.25); }

    /* password with inline tools */
    #NU .nu-pw { position: relative; }
    #NU .nu-pw input.nu-input { padding-right: 150px; }
    #NU .nu-tools { position: absolute; top: 50%; right: 6px; transform: translateY(-50%); display: flex; gap: 4px; }
    #NU .nu-tool {
      display: inline-flex; align-items: center; gap: 5px; margin: 0;
      padding: 6px 9px; border: 1px solid var(--nu-line); border-radius: 7px;
      background: #fff; color: var(--nu-navy); box-shadow: none;
      font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; white-space: nowrap;
    }
    #NU .nu-tool:hover { background: var(--nu-soft); border-color: #CBDCEA; }
    #NU .nu-tool svg { pointer-events: none; }
    #NU .nu-tool.done { color: var(--nu-ok); border-color: #A9D18E; }

    #NU .nu-rules {
      list-style: none; margin: 10px 0 0; padding: 0;
      display: flex; flex-wrap: wrap; gap: 6px;
    }
    #NU .nu-rules li {
      display: inline-flex; align-items: center; gap: 6px; margin: 0;
      padding: 4px 10px 4px 7px; border-radius: 999px;
      background: var(--nu-soft); border: 1px solid var(--nu-line);
      font-size: 12px; color: var(--nu-muted); transition: all .15s ease;
    }
    #NU .nu-rules li::before {
      content: ""; width: 12px; height: 12px; box-sizing: border-box;
      border: 1.5px solid #B6C2CF; border-radius: 50%;
    }
    #NU .nu-rules li.met { color: var(--nu-ok); background: #EFF8EC; border-color: #CFE7C2; }
    #NU .nu-rules li.met::before {
      border-color: var(--nu-ok);
      background: var(--nu-ok) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'%3E%3Cpath d='M4 8.2l2.6 2.5L12 5.4' fill='none' stroke='%23fff' stroke-width='2.2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E") center/9px no-repeat;
    }
    #NU .nu-match { margin: 6px 0 0; font-size: 12.5px; min-height: 1.2em; }
    #NU .nu-match.ok { color: var(--nu-ok); }
    #NU .nu-match.bad { color: var(--nu-bad); }

    #NU .nu-note {
      display: flex; gap: 10px; align-items: flex-start;
      margin: 4px 0 16px; padding: 10px 12px; border-radius: 10px;
      background: #FFF8EC; border: 1px solid #F3E2BF; color: #6B4A00;
      font-size: 12.5px; line-height: 1.5;
    }
    #NU .nu-note svg { flex: none; margin-top: 1px; }

    #NU .nu-foot {
      display: flex; justify-content: flex-end; gap: 10px; margin: 0;
      padding: 14px 22px; border-top: 1px solid var(--nu-line); background: var(--nu-soft);
    }
    #NU .nu-foot .button { padding: 10px 18px; font-size: 14px; border-radius: 9px; }
    #NU .nu-foot button[type="submit"][disabled] { opacity: .75; cursor: progress; box-shadow: none; }

    @media (max-width: 520px) {
      #NU .nu-head { padding: 16px 16px 14px; }
      #NU .nu-body { padding: 16px 16px 4px; }
      #NU .nu-foot { padding: 12px 16px; }
      #NU .nu-foot .button { flex: 1; }
      #NU .nu-tool .nu-tool-text { display: none; }
      #NU .nu-pw input.nu-input { padding-right: 84px; }
    }
    @media (prefers-reduced-motion: reduce) {
      #NU *, #NU *::before { transition: none !important; }
    }
    #NU select.nu-input {
      appearance: none; -webkit-appearance: none; padding-right: 36px; cursor: pointer;
      background: var(--nu-soft) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%235C6775' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E") right 12px center/16px no-repeat;
    }
    #NU select.nu-input:focus { background-color: #fff; }
CSS) . '</style>';
    }
}
