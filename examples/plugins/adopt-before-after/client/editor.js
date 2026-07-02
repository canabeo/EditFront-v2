/* adopt-before-after — editor half (§6.4).
 *
 * previewHtml  : instant in-iframe mirror of the server render().
 * matches      : claim a .project-showcase__ba-grid (self or ancestor of a click).
 * extractProps : read the two images + captions straight from the raw DOM.
 * mountEditor  : two sides (before / after), each with a thumbnail that opens the
 *                core image picker, a caption field and an alt field. */
(function () {
  'use strict';
  if (!window.__cms || typeof window.__cms.registerKind !== 'function') return;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function item(src, alt, label) {
    return '<div class="project-showcase__ba">'
      + '<img' + (src ? ' src="' + esc(src) + '"' : '') + ' alt="' + esc(alt) + '">'
      + '<span class="project-showcase__ba-label">' + esc(label) + '</span>'
      + '</div>';
  }

  function field(host, labelText, value, onChange) {
    var row = document.createElement('label');
    row.className = 'cms-form-row';
    var lab = document.createElement('span');
    lab.className = 'cms-form-label';
    lab.textContent = labelText;
    var inp = document.createElement('input');
    inp.type = 'text';
    inp.className = 'cms-form-control';
    inp.value = value || '';
    inp.addEventListener('change', function () { onChange(inp.value); });
    row.appendChild(lab);
    row.appendChild(inp);
    host.appendChild(row);
  }

  // one side (before / after): thumbnail (click → picker) + caption + alt
  function side(host, ctx, t, prefix, heading, props) {
    var head = document.createElement('div');
    head.className = 'cms-form-array-head';
    head.textContent = heading;
    host.appendChild(head);

    var src = props[prefix + 'Src'] || '';
    var thumb = document.createElement('img');
    thumb.className = 'cms-ba-thumb';
    thumb.alt = '';
    if (src) thumb.src = src;
    thumb.title = t('plugin.adopt-before-after.change_image', null, 'Заменить картинку');
    thumb.addEventListener('click', function () {
      ctx.pickImage(function (url) { if (url) ctx.setProp(prefix + 'Src', url); });
    });
    host.appendChild(thumb);

    field(host, t('plugin.adopt-before-after.caption', null, 'Подпись'),
      props[prefix + 'Label'], function (v) { ctx.setProp(prefix + 'Label', v); });
    field(host, t('plugin.adopt-before-after.alt', null, 'Alt-текст'),
      props[prefix + 'Alt'], function (v) { ctx.setProp(prefix + 'Alt', v); });
  }

  window.__cms.registerKind('adopt-before-after', {

    previewHtml: function (props) {
      props = props || {};
      return '<div class="project-showcase__ba-grid">'
        + item(props.beforeSrc, props.beforeAlt, props.beforeLabel)
        + item(props.afterSrc, props.afterAlt, props.afterLabel)
        + '</div>';
    },

    matches: function (el) {
      return (el && typeof el.closest === 'function') ? el.closest('.project-showcase__ba-grid') : null;
    },

    extractProps: function (el) {
      var bas = el.querySelectorAll('.project-showcase__ba');
      function read(ba) {
        if (!ba) return { src: '', alt: '', label: '' };
        var img = ba.querySelector('img');
        var lab = ba.querySelector('.project-showcase__ba-label');
        return {
          src: img ? (img.getAttribute('src') || '') : '',
          alt: img ? (img.getAttribute('alt') || '') : '',
          label: lab ? (lab.textContent || '').trim() : ''
        };
      }
      var b = read(bas[0]);
      var a = read(bas[1]);
      return {
        beforeSrc: b.src, beforeAlt: b.alt, beforeLabel: b.label || 'Before',
        afterSrc: a.src, afterAlt: a.alt, afterLabel: a.label || 'After'
      };
    },

    mountEditor: function (host, props, ctx) {
      host.replaceChildren();
      var t = (ctx && ctx.t) ? ctx.t : function (k, p, f) { return f || k; };
      props = props || {};
      side(host, ctx, t, 'before', t('plugin.adopt-before-after.before', null, 'До'), props);
      side(host, ctx, t, 'after', t('plugin.adopt-before-after.after', null, 'После'), props);
    }
  });
}());
