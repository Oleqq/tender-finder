<style>
    :root { color-scheme: light; --bg: #f8f9fc; --surface: #fff; --soft: #f0f2f8; --text: #171b2c; --muted: #697184; --line: #e6e8f0; --accent: #4c53ef; --accent-soft: #eeeffc; --button: #4c53ef; --success: #19704d; --success-soft: #e7f5ee; }
    :root[data-theme="dark"] { color-scheme: dark; --bg: #0d0f17; --surface: #181c29; --soft: #1d2231; --text: #f1f3fa; --muted: #adb4c6; --line: #343b50; --accent: #a3a7ff; --accent-soft: #242744; --button: #5c62e9; --success: #8cdbb5; --success-soft: #19382c; }
    @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { color-scheme: dark; --bg: #0d0f17; --surface: #181c29; --soft: #1d2231; --text: #f1f3fa; --muted: #adb4c6; --line: #343b50; --accent: #a3a7ff; --accent-soft: #242744; --button: #5c62e9; --success: #8cdbb5; --success-soft: #19382c; } }
    * { box-sizing: border-box; }
    html { scroll-padding-top: 24px; }
    body { margin: 0; background: var(--bg); color: var(--text); font: 16px/1.75 Inter, "SF Pro Text", "Segoe UI", system-ui, -apple-system, sans-serif; -webkit-text-size-adjust: 100%; overflow-wrap: anywhere; }
    a { color: var(--accent); text-underline-offset: 4px; }
    a:hover { text-decoration-thickness: 2px; }
    :focus-visible { outline: 3px solid var(--accent); outline-offset: 4px; }
    button, summary, a { -webkit-tap-highlight-color: transparent; }
    button { font: inherit; cursor: pointer; }
    [hidden] { display: none !important; }
    svg { width: 20px; height: 20px; flex-shrink: 0; }
    .shell { width: min(1160px, 100%); margin-inline: auto; padding-inline: max(24px, env(safe-area-inset-left)) max(24px, env(safe-area-inset-right)); }
    .site-header { border-bottom: 1px solid var(--line); background: var(--surface); }
    .header-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; min-height: 84px; }
    .brand { display: inline-flex; align-items: center; gap: 10px; min-height: 44px; color: var(--text); font-size: 21px; font-weight: 800; letter-spacing: -.04em; text-decoration: none; line-height: 1.3; }
    .brand-mark { display: grid; place-items: center; width: 36px; height: 36px; border-radius: 11px; background: var(--button); color: #fff; font-size: 16px; letter-spacing: -.06em; }
    .header-actions { display: flex; gap: 8px; align-items: center; }
    .theme-toggle { display: inline-flex; align-items: center; justify-content: center; width: 44px; height: 44px; border: 1px solid var(--line); border-radius: 13px; background: var(--surface); color: var(--text); }
    .theme-toggle .sun { display: none; }
    .theme-toggle[aria-pressed="true"] .sun { display: block; }
    .theme-toggle[aria-pressed="true"] .moon { display: none; }
    .primary-nav { display: flex; flex-wrap: wrap; gap: 4px 8px; padding-bottom: 12px; }
    .primary-nav a { display: inline-flex; align-items: center; min-height: 44px; padding: 8px 14px; border-radius: 12px; color: var(--muted); text-decoration: none; font-size: 14px; font-weight: 600; }
    .primary-nav a:hover, .primary-nav a[aria-current="page"] { color: var(--accent); background: var(--accent-soft); }
    .button { display: inline-flex; align-items: center; justify-content: center; gap: 10px; min-height: 46px; padding: 10px 18px; border: 1px solid transparent; border-radius: 13px; background: var(--button); color: #fff; font-size: 14px; font-weight: 650; line-height: 1.45; text-decoration: none; text-align: center; }
    .button:hover { filter: brightness(.96); text-decoration: none; }
    .button-secondary { border-color: var(--line); background: var(--surface); color: var(--text); }
    .hero { padding-block: 40px 28px; max-width: 850px; }
    .eyebrow { display: block; margin: 0 0 12px; color: var(--accent); font-size: 13px; font-weight: 650; letter-spacing: .06em; text-transform: uppercase; }
    h1, h2, h3 { color: var(--text); overflow-wrap: anywhere; }
    h1 { margin: 0; font-size: clamp(28px, 4vw, 42px); line-height: 1.2; font-weight: 750; letter-spacing: -.035em; text-wrap: balance; }
    .hero p { margin: 16px 0 0; max-width: 760px; color: var(--muted); }
    .hero .meta { margin-top: 12px; font-size: 14px; }
    .document-layout { display: grid; grid-template-columns: 250px minmax(0, 1fr); align-items: start; gap: 24px; padding-bottom: 48px; }
    .document-sidebar { position: sticky; top: 24px; display: grid; gap: 16px; }
    .disclosure { min-width: 0; background: var(--surface); border: 1px solid var(--line); border-radius: 18px; }
    summary { display: flex; align-items: center; justify-content: space-between; gap: 16px; min-height: 52px; padding: 14px 18px; list-style: none; cursor: pointer; font-size: 14px; font-weight: 650; line-height: 1.5; }
    summary::-webkit-details-marker { display: none; }
    summary::after { content: ''; width: 7px; height: 7px; border-right: 1.5px solid var(--muted); border-bottom: 1.5px solid var(--muted); transform: rotate(45deg); flex-shrink: 0; margin-right: 3px; margin-top: -4px; }
    details[open] > summary::after { transform: rotate(225deg); margin-top: 4px; }
    .document-menu { padding: 0 8px 8px; }
    .document-menu a { display: flex; align-items: center; min-height: 44px; padding: 10px; border-radius: 10px; color: var(--muted); font-size: 14px; line-height: 1.45; text-decoration: none; }
    .document-menu a:hover, .document-menu a[aria-current="page"] { background: var(--accent-soft); color: var(--accent); }
    .contents ol { list-style: none; margin: 0; padding: 0 18px 14px; }
    .contents a { display: block; padding-block: 9px; font-size: 13px; line-height: 1.6; color: var(--muted); text-decoration: none; }
    .contents a:hover { color: var(--accent); text-decoration: underline; }
    .paper { min-width: 0; background: var(--surface); border: 1px solid var(--line); border-radius: 24px; padding: clamp(20px, 3.4vw, 40px); }
    .document-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px 16px; padding-bottom: 28px; margin-bottom: 28px; border-bottom: 1px solid var(--line); }
    .version { flex: 1 1 160px; min-width: 0; color: var(--muted); }
    .version summary { padding: 0; min-height: 44px; justify-content: flex-start; font-size: 13px; font-weight: 500; }
    .version p { margin: 8px 0 0; font-size: 12px; font-family: ui-monospace, monospace; line-height: 1.65; }
    .document-body p { white-space: pre-line; margin: 18px 0; }
    .document-body > :first-child { margin-top: 0; }
    .document-body > :last-child { margin-bottom: 0; }
    .document-body h2 { margin: 38px 0 16px; font-size: 20px; line-height: 1.5; font-weight: 700; letter-spacing: -.015em; scroll-margin-top: 24px; }
    .table-wrap { max-width: 100%; overflow-x: auto; overscroll-behavior-x: contain; border: 1px solid var(--line); border-radius: 14px; margin: 24px 0 8px; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; line-height: 1.65; }
    th, td { text-align: left; padding: 14px 16px; border-bottom: 1px solid var(--line); vertical-align: top; min-width: 120px; white-space: pre-line; overflow-wrap: normal; }
    th { background: var(--soft); font-weight: 650; }
    tbody tr:last-child td { border-bottom: 0; }
    .table-hint { display: none; color: var(--muted); font-size: 12px; margin: 8px 0 24px; }
    .reading-end { display: flex; justify-content: space-between; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 32px; padding-top: 20px; border-top: 1px solid var(--line); font-size: 14px; }
    .reading-end a { display: inline-flex; align-items: center; min-height: 44px; }
    .section { margin-bottom: 28px; }
    .section h2 { margin: 0 0 16px; font-size: 23px; line-height: 1.4; letter-spacing: -.025em; }
    .section > p { margin: 16px 0 0; }
    .muted { color: var(--muted); }
    .small { font-size: 14px; }
    .info-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 24px; align-items: start; padding-bottom: 48px; }
    .info-main { min-width: 0; }
    .service-card { border: 1px solid var(--line); background: var(--surface); border-radius: 24px; padding: 28px; }
    .service-card p { margin: 14px 0; }
    .service-card p:last-child { margin-bottom: 0; }
    .plans { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
    .plan { min-width: 0; background: var(--surface); border: 1px solid var(--line); border-radius: 20px; padding: 20px; }
    .plan-highlight { border-color: var(--accent); }
    .plan h3 { margin: 0; font-size: 22px; letter-spacing: -.02em; }
    .plan p { margin: 4px 0 20px; color: var(--muted); font-size: 13px; }
    .plan dl { margin: 0; display: grid; gap: 12px; }
    .plan dl div { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 2px 12px; font-size: 14px; line-height: 1.6; }
    .plan dt { color: var(--muted); }
    .plan dd { margin: 0; font-weight: 650; white-space: nowrap; }
    .document-cards { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
    .document-card { display: flex; gap: 16px; align-items: center; justify-content: space-between; padding: 20px; min-height: 100px; border: 1px solid var(--line); border-radius: 18px; background: var(--surface); color: var(--text); text-decoration: none; line-height: 1.5; }
    .document-card:hover { border-color: var(--accent); text-decoration: none; }
    .document-card strong { display: block; font-size: 15px; font-weight: 650; }
    .document-card span { display: block; margin-top: 4px; color: var(--muted); font-size: 13px; }
    .seller { min-width: 0; }
    .seller h2 { font-size: 20px; }
    .seller dl { margin: 20px 0; display: grid; gap: 14px; font-size: 14px; line-height: 1.7; }
    .seller dt { color: var(--muted); font-size: 12px; }
    .seller dd { margin: 2px 0 0; }
    .seller .button { width: 100%; }
    .contacts { scroll-margin-top: 24px; }
    .site-footer { border-top: 1px solid var(--line); color: var(--muted); font-size: 13px; padding-block: 24px max(28px, env(safe-area-inset-bottom)); }
    .footer-row { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px 24px; }
    .footer-links { display: flex; flex-wrap: wrap; gap: 4px 20px; }
    .footer-links a { display: inline-flex; align-items: center; min-height: 44px; }
    .footer-row p { margin: 8px 0 0; }
    .skip-link { position: absolute; top: -100px; left: 16px; padding: 12px 16px; z-index: 10; background: var(--surface); border: 2px solid var(--accent); border-radius: 10px; }
    .skip-link:focus { top: 12px; }
    .sr-only { position: absolute; width: 1px; height: 1px; margin: -1px; padding: 0; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
    .cancel-shell { max-width: 650px; margin: 48px auto; padding-bottom: 32px; }
    .cancel-shell h1 { font-size: clamp(27px, 4vw, 34px); }
    .cancel-shell p { margin: 20px 0; }
    .cancel-shell .button { width: 100%; }
    .status-note { border-radius: 14px; padding: 14px 16px; font-size: 14px; background: var(--accent-soft); }
    .status-success { color: var(--success); background: var(--success-soft); }
    @media (min-width: 860px) { .document-sidebar { max-height: calc(100dvh - 48px); overflow-y: auto; overscroll-behavior-y: contain; scrollbar-width: thin; } }
    @media (max-width: 1023px) { .info-grid { grid-template-columns: minmax(0, 1fr); } .seller { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; } .seller h2 { grid-column: 1 / -1; margin-bottom: 0; } .seller dl { margin: 0; } }
    @media (max-width: 859px) { .document-layout { grid-template-columns: minmax(0, 1fr); gap: 20px; } .document-sidebar { position: static; grid-template-columns: repeat(2, minmax(0, 1fr)); align-items: start; } .contents a { padding-block: 12px; } }
    @media (max-width: 599px) {
        .shell { padding-inline: max(16px, env(safe-area-inset-left)) max(16px, env(safe-area-inset-right)); }
        .header-row { min-height: 76px; gap: 8px; }
        .brand { font-size: 19px; gap: 8px; }
        .brand-mark { width: 32px; height: 32px; border-radius: 10px; font-size: 14px; }
        .header-actions > .button { display: none; }
        .primary-nav { gap: 4px; padding-bottom: 10px; }
        .primary-nav a { padding-inline: 12px; }
        .hero { padding-block: 28px 24px; }
        .hero p { font-size: 15px; }
        .document-sidebar { grid-template-columns: minmax(0, 1fr); gap: 10px; }
        .document-layout { padding-bottom: 28px; }
        .paper { border-radius: 20px; }
        .document-toolbar { gap: 8px; margin-bottom: 24px; padding-bottom: 20px; }
        .document-toolbar .button { width: 100%; }
        .version { flex-basis: 100%; }
        .document-body h2 { font-size: 18px; margin-top: 32px; }
        .table-hint { display: block; }
        .plans { grid-template-columns: minmax(0, 1fr); gap: 12px; }
        .plan { padding: 20px; }
        .plan p { margin-bottom: 16px; }
        .plan dl { grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
        .plan dl div { display: block; font-size: 13px; }
        .plan dd { margin-top: 4px; font-size: 15px; }
        .document-cards, .seller { grid-template-columns: minmax(0, 1fr); gap: 12px; }
        .document-card { min-height: 86px; padding: 18px; }
        .service-card { padding: 22px; border-radius: 20px; }
        .seller { gap: 20px; }
        .section { margin-bottom: 24px; }
        .section h2 { font-size: 21px; }
        .cancel-shell { margin-block: 28px; }
    }
    @media print {
        :root { color-scheme: light !important; --bg: #fff !important; --surface: #fff !important; --soft: #f5f5f5 !important; --text: #000 !important; --muted: #333 !important; --line: #ccc !important; --accent: #000 !important; }
        .site-header, .site-footer, .document-sidebar, .document-toolbar, .reading-end, .skip-link, .table-hint { display: none !important; }
        .shell { width: 100%; padding: 0; } .hero { padding-block: 0 20px; } h1 { font-size: 26px; }
        .document-layout { display: block; } .paper { border: 0; padding: 0; border-radius: 0; }
        .document-body { font-size: 11pt; } .document-body h2 { break-after: avoid; }
        .table-wrap { overflow: visible; border-radius: 0; } table { font-size: 10pt; } th, td { min-width: 0; padding: 8px; } tr { break-inside: avoid; }
        a { text-decoration: none; }
    }
</style>
