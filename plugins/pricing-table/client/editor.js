/* pricing-table — editor half (§6.4). No mountEditor: the core renders the
 * edit form from props_schema (§6.4.1). We only provide previewHtml — the
 * instant in-iframe mirror of the server render(), so editing feels live and
 * a save reload produces no visual jump (the server HTML is byte-identical in
 * shape). */
(function () {
  'use strict';
  if (!window.__cms || typeof window.__cms.registerKind !== 'function') return;

  var CURRENCY = { USD: '$', EUR: '€', UAH: '₴' };
  var PERIOD = { mo: '/mo', yr: '/yr' };

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function priceStr(p) {
    var n = Number(p) || 0;
    return (n % 1 === 0) ? String(n | 0) : n.toFixed(2);
  }

  window.__cms.registerKind('pricing-table', {
    previewHtml: function (props) {
      props = props || {};
      var symbol = CURRENCY[props.currency] || '€';
      var per = PERIOD[props.period] || '/mo';
      var tiers = Array.isArray(props.tiers) ? props.tiers : [];

      var cards = tiers.map(function (t) {
        t = t || {};
        var featured = !!t.featured;
        var cardStyle = 'flex:1 1 180px;min-width:160px;padding:20px;border-radius:12px;text-align:center;'
          + (featured
            ? 'border:2px solid #2563eb;box-shadow:0 6px 24px rgba(37,99,235,0.15)'
            : 'border:1px solid #e5e7eb');
        var ps = priceStr(t.price);
        return '<div class="ef-pt-tier"'
          + ' data-pt-title="' + esc(t.title) + '"'
          + ' data-pt-price="' + esc(ps) + '"'
          + ' data-pt-featured="' + (featured ? '1' : '0') + '"'
          + ' style="' + cardStyle + '">'
          + '<h3 class="ef-pt-title" style="margin:0 0 8px;font-size:1.1em">' + esc(t.title) + '</h3>'
          + '<div class="ef-pt-price" style="font-size:1.8em;font-weight:700">'
          + esc(symbol) + esc(ps)
          + '<span class="ef-pt-per" style="font-size:0.5em;font-weight:400;color:#6b7280">' + esc(per) + '</span>'
          + '</div>'
          + '</div>';
      }).join('');

      return '<div class="ef-pt"'
        + ' data-pt-currency="' + esc(props.currency || 'EUR') + '"'
        + ' data-pt-period="' + esc(props.period || 'mo') + '"'
        + ' style="display:flex;gap:16px;flex-wrap:wrap;align-items:stretch">'
        + cards
        + '</div>';
    }
  });
}());
