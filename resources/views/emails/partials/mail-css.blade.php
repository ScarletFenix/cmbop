<style type="text/css">
:root { color-scheme: light dark; supported-color-schemes: light dark; }
body, table, td, th, p, a, span, div, h1, h2, h3, h4, li, strong, em, b {
  font-family: Arial, Helvetica, sans-serif !important;
}
@media only screen and (max-width: 600px) {
  .inner-body, .footer, .mail-header-bar, .mail-footer-bar, .mail-shell { width: 100% !important; max-width: 100% !important; }
  .content-cell, .mail-pad { padding-left: 16px !important; padding-right: 16px !important; }
  .table td:first-child, .table th:first-child, .mail-summary-label { white-space: normal !important; width: 34% !important; }
}
@media only screen and (max-width: 479px) {
  .mail-btn-row .mail-btn-cell { display: block !important; width: 100% !important; padding: 0 0 8px 0 !important; }
  .mail-btn-row .mail-btn, .mail-btn-row .mail-btn-outline { display: block !important; width: 100% !important; text-align: center !important; box-sizing: border-box !important; }
}
.mail-progress-done, .mail-progress-future, .mail-progress-current {
  display: inline-block;
  width: 28px;
  height: 28px;
  min-width: 28px;
  min-height: 28px;
  max-width: 28px;
  max-height: 28px;
  border-radius: 50%;
  text-align: center;
  box-sizing: border-box;
}
.mail-anchor-url, .mail-url {
  word-break: normal;
  overflow-wrap: anywhere;
}
@media (prefers-color-scheme: dark) {
  body, .wrapper, .mail-wrapper { background-color: #0b1f21 !important; color: #d4f1f0 !important; }
  .inner-body, .mail-body-card, .body { background-color: #123f42 !important; }
  .mail-header-bar { background-color: #123f42 !important; }
  .mail-footer-bar, .footer { background-color: #0b1f21 !important; }
  .mail-logo-pill { background-color: #ffffff !important; }
  h1, h2, h3, .mail-heading { color: #d4f1f0 !important; }
  p, .mail-body-copy, .mail-body-copy p { color: #d4f1f0 !important; }
  .mail-subline, .mail-footer-copy, .subcopy p, .footer p { color: #9ec9cb !important; }
  .mail-card, .mail-details, .mail-protection, .mail-item-card, .mail-anchor, .panel-content {
    background-color: #0b2a2d !important;
    border-color: #1a585e !important;
    color: #d4f1f0 !important;
  }
  .mail-card td, .mail-details td, .mail-item-card td, .mail-anchor td,
  .mail-card p, .mail-details p, .mail-item-card p, .mail-anchor p,
  .mail-anchor span, .mail-anchor strong, .mail-protection td {
    background-color: transparent !important;
    color: #d4f1f0 !important;
  }
  .mail-summary-label { color: #9ec9cb !important; font-weight: 400 !important; }
  .mail-summary-value { color: #d4f1f0 !important; }
  .mail-deadline, .mail-details td.mail-deadline, .mail-details .mail-deadline {
    color: #fbbf24 !important;
  }
  a, .mail-url { color: #3faeb2 !important; }
  .mail-btn, a.mail-btn, a.button, a.button-primary, a.button-blue {
    background-color: #0b6266 !important;
    background: #0b6266 !important;
    color: #ffffff !important;
    border-color: #0b6266 !important;
    font-weight: 700 !important;
  }
  .mail-btn-outline, a.mail-btn-outline, a.button-outline {
    background-color: #123f42 !important;
    color: #ffffff !important;
    border-color: #3faeb2 !important;
  }
  .mail-tick { background-color: #3faeb2 !important; color: #ffffff !important; }
  .mail-anchor-label { color: #9ec9cb !important; }
  .mail-anchor-value, .mail-anchor strong, .mail-anchor-line, .mail-anchor p {
    color: #d4f1f0 !important;
  }
  .mail-item-domain { color: #d4f1f0 !important; }
  .mail-progress-done, .mail-progress-future, .mail-progress-current {
    width: 28px !important;
    height: 28px !important;
    min-width: 28px !important;
    min-height: 28px !important;
    border-radius: 50% !important;
    text-align: center !important;
  }
  .mail-progress-future { background-color: #4b5563 !important; color: #e5e7eb !important; }
  .mail-progress-current {
    background-color: #123f42 !important;
    border-color: #3faeb2 !important;
    color: #d4f1f0 !important;
    box-sizing: border-box !important;
  }
  .mail-progress-done { background-color: #3faeb2 !important; color: #ffffff !important; }
  .mail-progress-label-future { color: #94a3b8 !important; }
  .mail-progress-label-current { color: #d4f1f0 !important; }
  .mail-progress-label-done { color: #9ec9cb !important; }
  .mail-progress-line-on { border-color: #3faeb2 !important; }
  .mail-progress-line-off { border-color: #64748b !important; }
  .mail-progress-line-edge { border-color: #123f42 !important; }
  .mail-pill { background-color: #0b2a2d !important; color: #d4f1f0 !important; }
}
[data-ogsc] .mail-logo-pill, [data-ogsb] .mail-logo-pill { background-color: #ffffff !important; }
[data-ogsc] .mail-header-bar, [data-ogsb] .mail-header-bar { background-color: #123f42 !important; }
[data-ogsc] .mail-card, [data-ogsb] .mail-card,
[data-ogsc] .mail-details, [data-ogsb] .mail-details,
[data-ogsc] .mail-item-card, [data-ogsb] .mail-item-card,
[data-ogsc] .mail-anchor, [data-ogsb] .mail-anchor,
[data-ogsc] .mail-protection, [data-ogsb] .mail-protection {
  background-color: #0b2a2d !important;
  color: #d4f1f0 !important;
}
[data-ogsc] .mail-card td, [data-ogsb] .mail-card td,
[data-ogsc] .mail-details td, [data-ogsb] .mail-details td,
[data-ogsc] .mail-item-card td, [data-ogsb] .mail-item-card td,
[data-ogsc] .mail-anchor td, [data-ogsb] .mail-anchor td,
[data-ogsc] .mail-card p, [data-ogsb] .mail-card p,
[data-ogsc] .mail-anchor p, [data-ogsb] .mail-anchor p,
[data-ogsc] .mail-anchor-value, [data-ogsb] .mail-anchor-value,
[data-ogsc] .mail-anchor strong, [data-ogsb] .mail-anchor strong,
[data-ogsc] .mail-item-domain, [data-ogsb] .mail-item-domain {
  color: #d4f1f0 !important;
}
[data-ogsc] .mail-anchor-label, [data-ogsb] .mail-anchor-label { color: #9ec9cb !important; }
[data-ogsc] .mail-btn, [data-ogsb] .mail-btn,
[data-ogsc] a.button-primary, [data-ogsb] a.button-primary {
  background-color: #0b6266 !important;
  color: #ffffff !important;
}
[data-ogsc] .mail-btn-outline, [data-ogsb] .mail-btn-outline {
  background-color: #123f42 !important;
  color: #ffffff !important;
  border-color: #3faeb2 !important;
}
[data-ogsc] .mail-progress-future, [data-ogsb] .mail-progress-future {
  background-color: #4b5563 !important;
  color: #e5e7eb !important;
}
[data-ogsc] .mail-progress-current, [data-ogsb] .mail-progress-current {
  background-color: #123f42 !important;
  border-color: #3faeb2 !important;
  color: #d4f1f0 !important;
}
[data-ogsc] .mail-progress-done, [data-ogsb] .mail-progress-done {
  background-color: #3faeb2 !important;
  color: #ffffff !important;
}
[data-ogsc] .mail-deadline, [data-ogsb] .mail-deadline,
[data-ogsc] .mail-details td.mail-deadline, [data-ogsb] .mail-details td.mail-deadline {
  color: #fbbf24 !important;
}
[data-ogsc] .mail-progress-line-edge, [data-ogsb] .mail-progress-line-edge { border-color: #123f42 !important; }
[data-ogsc] .mail-progress-line-on, [data-ogsb] .mail-progress-line-on { border-color: #3faeb2 !important; }
[data-ogsc] .mail-progress-line-off, [data-ogsb] .mail-progress-line-off { border-color: #64748b !important; }
</style>
