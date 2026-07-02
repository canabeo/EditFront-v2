/* adopt-gallery — editor half (§6.4).
 *
 * previewHtml  : instant in-iframe mirror of the server render() (no jump on save).
 * matches      : claim an existing .project-card so it can be adopted into a block.
 * extractProps : read props straight from that raw DOM card (data-images + cover + title).
 * mountEditor  : a custom panel — the image set is edited with the core image
 *                picker (upload / gallery / URL), not raw text inputs. */
(function () {
  'use strict';
  if (!window.__cms || typeof window.__cms.registerKind !== 'function') return;

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function srcList(props) {
    var imgs = (props && Array.isArray(props.images)) ? props.images : [];
    return imgs
      .map(function (i) { return (i && typeof i.src === 'string') ? i.src : ''; })
      .filter(function (s) { return s !== ''; });
  }

  function miniBtn(txt, fn) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'cms-form-mini';
    b.textContent = txt;
    b.addEventListener('click', function (e) { e.stopPropagation(); fn(); });
    return b;
  }

  window.__cms.registerKind('adopt-gallery', {

    previewHtml: function (props) {
      props = props || {};
      var srcs = srcList(props);
      var title = props.title || '';
      var cover = props.cover || (srcs.length ? srcs[0] : '');
      var dots = srcs.map(function () { return '<span class="project-card__dot"></span>'; }).join('');
      var img = '<img'
        + (cover ? ' src="' + esc(cover) + '"' : '')
        + ' alt="' + esc(title) + '" class="project-card__img">';
      return '<div class="project-card" data-images="' + esc(JSON.stringify(srcs)) + '">'
        + img
        + '<div class="project-card__title">' + esc(title) + '</div>'
        + '<div class="project-card__dots">' + dots + '</div>'
        + '</div>';
    },

    // self or the nearest .project-card ancestor (a click usually lands on the
    // cover image / title bar, which are children of the card)
    matches: function (el) {
      return (el && typeof el.closest === 'function') ? el.closest('.project-card') : null;
    },

    extractProps: function (el) {
      var srcs = [];
      var raw = el.getAttribute('data-images') || '';
      if (raw) {
        try {
          var d = JSON.parse(raw);
          if (Array.isArray(d)) srcs = d.filter(function (s) { return typeof s === 'string' && s !== ''; });
        } catch (e) { /* malformed attribute → fall back to the cover image below */ }
      }
      var imgEl = el.querySelector('.project-card__img') || el.querySelector('img');
      var cover = imgEl ? (imgEl.getAttribute('src') || '') : '';
      if (srcs.length === 0 && cover) srcs = [cover];
      var titleEl = el.querySelector('.project-card__title');
      var title = titleEl ? (titleEl.textContent || '').trim() : '';
      return {
        title: title,
        cover: cover,
        images: srcs.map(function (s) { return { src: s }; })
      };
    },

    mountEditor: function (host, props, ctx) {
      host.replaceChildren();
      var t = (ctx && ctx.t) ? ctx.t : function (k, p, f) { return f || k; };
      props = props || {};
      var imgs = Array.isArray(props.images) ? props.images : [];
      var cover = props.cover || (imgs[0] && imgs[0].src) || '';

      // title
      var row = document.createElement('label');
      row.className = 'cms-form-row';
      var lab = document.createElement('span');
      lab.className = 'cms-form-label';
      lab.textContent = t('plugin.adopt-gallery.title', null, 'Заголовок');
      var inp = document.createElement('input');
      inp.type = 'text';
      inp.className = 'cms-form-control';
      inp.value = props.title || '';
      inp.addEventListener('change', function () { ctx.setProp('title', inp.value); });
      row.appendChild(lab);
      row.appendChild(inp);
      host.appendChild(row);

      // images
      var head = document.createElement('div');
      head.className = 'cms-form-array-head';
      head.textContent = t('plugin.adopt-gallery.images', null, 'Картинки') + ' (' + imgs.length + ')';
      host.appendChild(head);

      var hint = document.createElement('div');
      hint.className = 'cms-gal-hint';
      hint.textContent = t('plugin.adopt-gallery.cover_hint', null, 'Клик по картинке — сделать обложкой');
      host.appendChild(hint);

      var grid = document.createElement('div');
      grid.className = 'cms-gal-grid';
      imgs.forEach(function (im, i) {
        var src = (im && im.src) || '';
        var cell = document.createElement('div');
        cell.className = 'cms-gal-cell' + (src && src === cover ? ' is-cover' : '');

        var th = document.createElement('img');
        th.className = 'cms-gal-thumb';
        th.src = src;
        th.alt = '';
        th.title = t('plugin.adopt-gallery.set_cover', null, 'Сделать обложкой');
        th.addEventListener('click', function () { ctx.setProp('cover', src); });
        cell.appendChild(th);

        var bar = document.createElement('div');
        bar.className = 'cms-gal-bar';
        bar.appendChild(miniBtn('↑', function () { if (i > 0) ctx.arrayMove('images', i, i - 1); }));
        bar.appendChild(miniBtn('↓', function () { if (i < imgs.length - 1) ctx.arrayMove('images', i, i + 1); }));
        bar.appendChild(miniBtn('✕', function () { ctx.arrayRemove('images', i); }));
        cell.appendChild(bar);

        grid.appendChild(cell);
      });
      host.appendChild(grid);

      var add = document.createElement('button');
      add.type = 'button';
      add.className = 'cms-form-add';
      add.textContent = t('plugin.adopt-gallery.add_image', null, '+ Добавить картинку');
      add.addEventListener('click', function (e) {
        e.stopPropagation();
        ctx.pickImage(function (url) {
          if (url) ctx.arrayInsert('images', imgs.length, { src: url });
        });
      });
      host.appendChild(add);
    }
  });
}());
