/* EditFront v2 — preview runtime (C2).
 * Every gesture builds ONE Command with forward AND undo data captured
 * synchronously BEFORE the mutation (§7.4 — the inverse never arrives as an
 * async echo) and ships it to the shell's CommandLog. cms:revert/cms:reapply
 * apply the inverse/forward locally. HTML arriving via postMessage (undo of
 * a delete, redo of a duplicate) goes through a client-side structural
 * sanitizer — the message channel is never trusted with raw HTML (v1 lesson). */
(function () {
    'use strict';

    var CFG = window.__cmsPreview || {};
    var ORIGIN = window.location.origin;
    var ID_ATTR = 'data-cms-id';

    // i18n (§8.3): full active-language dict injected by EditorController into __cmsPreview
    var I18N = CFG.i18n || {};
    function t(key, params, fallback) {
        var s = (key in I18N) ? I18N[key] : (fallback != null ? fallback : key);
        if (params) for (var k in params) s = s.split(':' + k).join(String(params[k]));
        return s;
    }

    // Remix Icon (local sprite) built via DOM — an icon name is "ri-…"; anything
    // else is treated as plain text. Names come only from our own catalogs.
    var ICONS_URL = CFG.iconsUrl || '';
    var SVG_NS = 'http://www.w3.org/2000/svg';
    function makeIconEl(name) {
        var svg = document.createElementNS(SVG_NS, 'svg');
        svg.setAttribute('class', 'cms-ico');
        svg.setAttribute('aria-hidden', 'true');
        var use = document.createElementNS(SVG_NS, 'use');
        use.setAttribute('href', ICONS_URL + '#' + name);
        use.setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', ICONS_URL + '#' + name);
        svg.appendChild(use);
        return svg;
    }
    function setIcon(btn, name) {
        btn.textContent = '';
        if (typeof name === 'string' && name.indexOf('ri-') === 0) btn.appendChild(makeIconEl(name));
        else btn.textContent = name || '';
    }

    var TEXT_FLUSH_MS = 350; // typing pause that closes one undo step (word-ish granularity)

    var TYPES = null;
    var selected = null;
    var editing = null;
    var textSnapshot = '';
    var textFlushTimer = null;
    var ui = null;
    var panel = null;
    var popover = null;
    var advancedRendered = false;

    /* --- plugin layer (§6) ---------------------------------------------- */
    var PLUGINS = CFG.plugins || {};   // slug → manifest (kinds, props_schema, asset urls)
    var ELEMENTS = CFG.elements || {}; // data-cms-id → {editable, status, kind, slug, label, props}
    var KINDBYNAME = {};               // kind name → {slug, schema, label, version, editor_js/css}
    var nodeProps = {};                // data-cms-id → live props of a plugin block

    // the registry a kind's editor_js registers its previewHtml/mountEditor into.
    // Defined synchronously so plugin scripts (loaded later) can call it (§6.4).
    window.__cms = window.__cms || {
        kinds: {},
        registerKind: function (kind, impl) { this.kinds[kind] = impl || {}; }
    };

    /* Client mirror of server NodeTemplates (visual only — the preview
     * reloads after save, the server-rendered version is canonical). */
    var TEMPLATES = {
        paragraph: { label: t('template.paragraph'), html: '<p>Новый абзац — двойной клик, чтобы изменить текст.</p>' },
        heading: { label: t('template.heading'), html: '<h2>Новый заголовок</h2>' },
        button: { label: t('template.button'), html: '<a href="#">Кнопка</a>' },
        image: { label: t('template.image'), html: '<img src="data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%22320%22%20height%3D%22180%22%3E%3Crect%20width%3D%22100%25%22%20height%3D%22100%25%22%20fill%3D%22%23e5e7eb%22%2F%3E%3C%2Fsvg%3E" alt="Изображение" width="320" height="180">' },
        divider: { label: t('template.divider'), html: '<hr>' },
        section: { label: t('template.section'), html: '<section><h2>Раздел</h2><p>Текст раздела…</p></section>' }
    };

    /* ------------------------------------------------------------------ */
    /* Action catalog: panel buttons come from the TYPE REGISTRY, handlers */
    var ACTIONS = {
        editText: { icon: 'ri-pencil-line', label: t('panel.text'), run: function (el) { startEditing(el); } },
        textSize: {
            icon: 'ri-font-size', label: t('panel.size'), run: function (el, btn) { showFontSize(el, btn); }
        },
        fontFamily: {
            icon: 'ri-text', label: t('panel.font'), run: function (el, btn) { showFontFamily(el, btn); }
        },
        align: {
            icon: 'ri-align-center', label: t('panel.align'), run: function (el, btn) {
                showChoices(btn, [
                    { label: t('panel.align_left'), run: function () { setStyleProp(el, 'text-align', ''); } },
                    { label: t('panel.align_center'), run: function () { setStyleProp(el, 'text-align', 'center'); } },
                    { label: t('panel.align_right'), run: function () { setStyleProp(el, 'text-align', 'right'); } }
                ]);
            }
        },
        link: {
            icon: 'ri-link', label: t('panel.link'), run: function (el, btn) {
                showInput(btn, t('panel.link_prompt'), el.getAttribute('href') || '', function (value) {
                    setAttrValue(el, 'href', value);
                });
            }
        },
        imageSrc: {
            icon: 'ri-image-line', label: t('panel.replace'), run: function (el) {
                showImagePicker(el);
            }
        },
        imageAlt: {
            icon: 'ri-text', label: t('panel.alt'), run: function (el, btn) {
                showInput(btn, t('panel.alt_prompt'), el.getAttribute('alt') || '', function (value) {
                    setAttrValue(el, 'alt', value);
                });
            }
        },
        insertAfter: {
            icon: 'ri-add-line', label: t('panel.insert'), run: function (el, btn) {
                var items = Object.keys(TEMPLATES).map(function (key) {
                    return { label: TEMPLATES[key].label, run: function () { insertTemplate(el, key); } };
                });
                // plugin kinds join the same insert palette (§6.2 contributions)
                Object.keys(KINDBYNAME).forEach(function (kind) {
                    var k = KINDBYNAME[kind];
                    var label = t('plugin.' + k.slug + '.label', null, k.label || kind);
                    items.push({ label: '⊞ ' + label, run: function () { insertPluginBlock(el, kind); } });
                });
                showChoices(btn, items);
            }
        },
        background: { icon: 'ri-palette-line', label: t('panel.background'), run: function (el, btn) { showBackground(el, btn); } },
        duplicate: { icon: 'ri-file-copy-line', label: t('panel.duplicate'), run: function (el) { duplicateNode(el); } },
        moveUp: { icon: 'ri-arrow-up-line', label: t('panel.move_up'), run: function (el) { moveNode(el, -1); } },
        moveDown: { icon: 'ri-arrow-down-line', label: t('panel.move_down'), run: function (el) { moveNode(el, 1); } },
        delete: { icon: 'ri-delete-bin-line', label: t('panel.delete'), run: function (el) { deleteNode(el); } },
        advanced: { icon: 'ri-settings-3-line', label: t('panel.css'), run: function (el, btn) { toggleAdvanced(el, btn); } }
    };

    /* ---------------------------- helpers ------------------------------ */

    function idOf(el) { return el.getAttribute(ID_ATTR); }

    function byId(id) {
        if (!id) return null;
        var el = document.querySelector('[' + ID_ATTR + '="' + id + '"]');
        return el && !(ui && ui.contains(el)) ? el : null;
    }

    function randomHex(bytes) {
        var arr = new Uint8Array(bytes);
        crypto.getRandomValues(arr);
        return Array.prototype.map.call(arr, function (b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');
    }

    function genId() { return 'cms-' + randomHex(6); }
    function genCmdId() { return 'cmd-' + randomHex(8); }

    /** One Command: forward exactly as the server op expects, undo captured NOW. */
    function emitCommand(kind, nodeId, forward, undoData, coalesceKey) {
        parent.postMessage({
            type: 'cms:op',
            command: {
                id: genCmdId(),
                kind: kind,
                nodeId: nodeId,
                forward: forward,
                undo: undoData || {},
                ts: Date.now(),
                coalesceKey: coalesceKey || null
            }
        }, ORIGIN);
    }

    /* Structural client-side sanitizer for HTML that arrives over
     * postMessage (undo-restore, redo-duplicate). Strips active content and
     * event handlers; the server applies its own sanitizer again on save. */
    function sanitizeStructuralFragment(html) {
        var doc = new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html');
        var rootHolder = doc.body.firstElementChild;
        var bad = rootHolder.querySelectorAll('script, style, iframe, object, embed, link, meta, base, template, svg, math, noscript, form');
        bad.forEach(function (n) { n.remove(); });
        var walker = doc.createTreeWalker(rootHolder, NodeFilter.SHOW_ELEMENT);
        var node;
        while ((node = walker.nextNode())) {
            for (var i = node.attributes.length - 1; i >= 0; i--) {
                var attr = node.attributes[i];
                var name = attr.name.toLowerCase();
                if (name.indexOf('on') === 0) {
                    node.removeAttribute(attr.name);
                    continue;
                }
                if (name === 'href' || name === 'src' || name === 'action' || name === 'formaction') {
                    var probe = attr.value.replace(/[\x00-\x20]+/g, '').toLowerCase();
                    if (probe.indexOf('javascript:') === 0 || probe.indexOf('vbscript:') === 0
                        || (probe.indexOf('data:') === 0 && probe.indexOf('data:image/') !== 0)) {
                        node.removeAttribute(attr.name);
                    }
                }
            }
        }
        var first = rootHolder.firstElementChild;
        return first ? document.importNode(first, true) : null;
    }

    function buildTemplateNode(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var node = doc.body.firstElementChild;
        return node ? document.importNode(node, true) : null;
    }

    function editableTarget(node) {
        if (!node || node.nodeType !== 1) return null;
        if (ui && ui.contains(node)) return null;
        var el = node.closest('[' + ID_ATTR + ']');
        if (!el || (ui && ui.contains(el))) return null;
        return el;
    }

    function typeFor(el) {
        var fallback = null;
        for (var i = 0; i < TYPES.types.length; i++) {
            var t = TYPES.types[i];
            if (t.match === '*') { fallback = t; continue; }
            try {
                if (el.matches(t.match)) return t;
            } catch (err) { /* bad selector — registry test guards this */ }
        }
        return fallback;
    }

    function parentInfo(el) {
        var parentEl = el.parentElement;
        if (!parentEl) return null;
        var parentId = parentEl === document.body ? null : idOf(parentEl);
        if (parentEl !== document.body && !parentId) return null;
        var index = Array.prototype.indexOf.call(parentEl.children, el);
        return { parentEl: parentEl, parentId: parentId, index: index };
    }

    /* ------------------------------ UI --------------------------------- */

    function buildUi() {
        ui = document.createElement('div');
        ui.id = 'cms-ui-root';
        ui.setAttribute('data-cms-protected', 'true');
        document.body.appendChild(ui);

        panel = document.createElement('div');
        panel.className = 'cms-panel';
        panel.hidden = true;
        ui.appendChild(panel);
    }

    function showPanel(el) {
        var type = typeFor(el);
        panel.replaceChildren();
        advancedRendered = false;

        var title = document.createElement('span');
        title.className = 'cms-panel-type';
        title.textContent = type ? t('panel.type_' + type.key.toLowerCase(), null, type.label) : t('panel.block');
        panel.appendChild(title);

        (type.quickActions || []).forEach(function (key) { addActionButton(el, key, panel); });

        var more = type.more || [];
        if (more.length) {
            var moreBtn = document.createElement('button');
            moreBtn.type = 'button';
            moreBtn.className = 'cms-panel-btn';
            setIcon(moreBtn, 'ri-more-line');
            moreBtn.title = t('panel.more');
            moreBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var items = more.map(function (key) {
                    var a = ACTIONS[key];
                    return a ? { label: a.label, run: function () { a.run(el, moreBtn); } } : null;
                }).filter(Boolean);
                showChoices(moreBtn, items);
            });
            panel.appendChild(moreBtn);
        }

        // a non-block element a plugin kind recognizes → offer "manage as …" (§6 adopt)
        var adopt = findAdoptKind(el);
        if (adopt) addAdoptButton(adopt.target, adopt.kind, panel);

        panel.hidden = false;
        positionPanel(el);
    }

    // a registered kind may expose matches(el) → the element to adopt (self or an
    // ancestor) or null. Returns the first kind that claims this node (§6 adopt).
    function findAdoptKind(el) {
        if (!el || el.hasAttribute('data-cms-block') || typeof el.closest !== 'function') return null;
        var names = Object.keys(KINDBYNAME);
        for (var i = 0; i < names.length; i++) {
            var impl = window.__cms.kinds[names[i]];
            if (!impl || typeof impl.matches !== 'function') continue;
            var target = null;
            try { target = impl.matches(el); } catch (e) { target = null; }
            if (target && target.nodeType === 1
                && target.hasAttribute(ID_ATTR)
                && !target.hasAttribute('data-cms-block')) {
                return { kind: names[i], target: target };
            }
        }
        return null;
    }

    function addAdoptButton(target, kind, container) {
        var k = KINDBYNAME[kind];
        var label = t('plugin.' + k.slug + '.label', null, k.label || kind);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cms-panel-btn cms-panel-adopt';
        btn.title = t('panel.manage_as', { name: label });
        setIcon(btn, 'ri-image-line');
        var span = document.createElement('span');
        span.className = 'cms-panel-adopt-label';
        span.textContent = label;
        btn.appendChild(span);
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            closePopover();
            adoptAsKind(target, kind);
        });
        container.appendChild(btn);
    }

    function addActionButton(el, actionKey, container) {
        var action = ACTIONS[actionKey];
        if (!action) return;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cms-panel-btn';
        setIcon(btn, action.icon);
        btn.title = action.label;
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            closePopover();
            action.run(el, btn);
        });
        container.appendChild(btn);
    }

    function positionPanel(el) {
        var rect = el.getBoundingClientRect();
        // a display:contents plugin wrapper (§6 adopt) has no box — anchor to its
        // first rendered child so the panel lands on the visible gallery instead of 0,0
        if (rect.width === 0 && rect.height === 0 && el.firstElementChild) {
            rect = el.firstElementChild.getBoundingClientRect();
        }
        var top = rect.top + window.scrollY - panel.offsetHeight - 8;
        if (top < window.scrollY + 4) top = rect.bottom + window.scrollY + 8;
        var left = Math.max(4, Math.min(
            rect.left + window.scrollX,
            window.scrollX + document.documentElement.clientWidth - panel.offsetWidth - 8
        ));
        panel.style.top = top + 'px';
        panel.style.left = left + 'px';
    }

    function closePopover() {
        if (popover) { popover.remove(); popover = null; advancedRendered = false; }
    }

    function openPopover(anchor) {
        closePopover();
        popover = document.createElement('div');
        popover.className = 'cms-popover';
        // close button — every popover is dismissable (also: click-outside / Esc)
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'cms-popover-close';
        close.title = t('common.close', null, 'Закрыть');
        setIcon(close, 'ri-close-line');
        close.addEventListener('click', function (e) { e.stopPropagation(); closePopover(); });
        popover.appendChild(close);
        ui.appendChild(popover);
        var rect = anchor.getBoundingClientRect();
        popover.style.top = (rect.bottom + window.scrollY + 6) + 'px';
        popover.style.left = Math.max(4, rect.left + window.scrollX - 8) + 'px';
        return popover;
    }

    function showChoices(anchor, items) {
        var pop = openPopover(anchor);
        items.forEach(function (item) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cms-popover-item' + (item.active ? ' is-active' : '');
            btn.textContent = item.label;
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                closePopover();
                item.run();
            });
            pop.appendChild(btn);
        });
    }

    function showInput(anchor, placeholder, value, apply) {
        var pop = openPopover(anchor);
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'cms-popover-input';
        input.placeholder = placeholder;
        input.value = value;
        var ok = document.createElement('button');
        ok.type = 'button';
        ok.className = 'cms-popover-apply';
        ok.textContent = t('common.ok');
        function commit() {
            closePopover();
            apply(input.value.trim());
        }
        ok.addEventListener('click', function (e) { e.stopPropagation(); commit(); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); commit(); }
            if (e.key === 'Escape') { e.stopPropagation(); closePopover(); }
        });
        pop.appendChild(input);
        pop.appendChild(ok);
        input.focus();
        input.select();
    }

    /* --- image picker (§4.9): upload / gallery / URL ------------------- */

    var imgPicker = null;

    // one handler instance, added on open / removed on close (review H1: the old
    // inline handler only removed itself on Escape → leaked on ✕/backdrop/pick).
    // Capture phase + stopPropagation so the global element-shortcuts (Delete,
    // Ctrl+D, …) never fire while the picker is open (review M2).
    function imgEscHandler(e) {
        if (e.key === 'Escape') { e.stopPropagation(); closeImagePicker(); }
    }

    function closeImagePicker() {
        if (imgPicker) { imgPicker.remove(); imgPicker = null; }
        document.removeEventListener('keydown', imgEscHandler, true);
    }

    function showImagePicker(el, onPick) {
        closePopover();
        closeImagePicker();

        imgPicker = document.createElement('div');
        imgPicker.className = 'cms-imgpick';
        imgPicker.addEventListener('click', function (e) { if (e.target === imgPicker) closeImagePicker(); });

        var modal = document.createElement('div');
        modal.className = 'cms-imgpick-modal';
        imgPicker.appendChild(modal);

        var head = document.createElement('div');
        head.className = 'cms-imgpick-head';
        var title = document.createElement('strong');
        title.textContent = t('panel.replace');
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'cms-imgpick-close';
        setIcon(close, 'ri-close-line');
        close.addEventListener('click', closeImagePicker);
        head.appendChild(title);
        head.appendChild(close);
        modal.appendChild(head);

        var tabs = document.createElement('div');
        tabs.className = 'cms-imgpick-tabs';
        modal.appendChild(tabs);

        var bodyWrap = document.createElement('div');
        bodyWrap.className = 'cms-imgpick-body';
        modal.appendChild(bodyWrap);

        var status = document.createElement('div');
        status.className = 'cms-imgpick-status';
        modal.appendChild(status);

        function setStatus(text, kind) {
            status.textContent = text || '';
            status.className = 'cms-imgpick-status' + (kind ? ' is-' + kind : '');
        }
        function pick(url) {
            closeImagePicker();
            if (typeof onPick === 'function') onPick(url);
            else setAttrValue(el, 'src', url);
        }

        var panes = {};
        function makeTab(key, label, render) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cms-imgpick-tab';
            btn.textContent = label;
            btn.addEventListener('click', function () { activate(key); });
            tabs.appendChild(btn);
            panes[key] = { btn: btn, render: render, built: false };
        }
        function activate(key) {
            Object.keys(panes).forEach(function (k) {
                panes[k].btn.classList.toggle('is-active', k === key);
            });
            bodyWrap.replaceChildren();
            setStatus('');
            panes[key].render(bodyWrap, pick, setStatus);
        }

        makeTab('upload', t('image.tab_upload'), renderUploadPane);
        makeTab('gallery', t('image.tab_gallery'), renderGalleryPane);
        makeTab('url', t('image.tab_url'), function (host) {
            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'cms-imgpick-url';
            input.placeholder = t('panel.img_prompt');
            input.value = el.getAttribute('src') || '';
            var ok = document.createElement('button');
            ok.type = 'button';
            ok.className = 'cms-imgpick-apply';
            ok.textContent = t('common.ok');
            function commit() { var v = input.value.trim(); if (v) pick(v); }
            ok.addEventListener('click', commit);
            input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); commit(); } });
            host.appendChild(input);
            host.appendChild(ok);
            input.focus();
        });

        ui.appendChild(imgPicker);
        activate('upload');
        document.addEventListener('keydown', imgEscHandler, true);
    }

    function renderUploadPane(host, pick, setStatus) {
        var drop = document.createElement('label');
        drop.className = 'cms-imgpick-drop';
        drop.textContent = t('image.drop_hint');
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.className = 'cms-imgpick-file';
        drop.appendChild(input);
        host.appendChild(drop);

        function handle(file) {
            if (!file) return;
            setStatus(t('image.uploading'), '');
            var fd = new FormData();
            fd.append('file', file);
            fetch(CFG.uploadUrl, {
                method: 'POST',
                headers: { 'X-CSRF-Token': CFG.csrf || '' },
                credentials: 'same-origin',
                body: fd
            }).then(function (r) {
                return r.json().catch(function () { return {}; }).then(function (d) { return { status: r.status, data: d }; });
            }).then(function (res) {
                if (res.status === 200 && res.data.ok) {
                    pick(res.data.url);
                } else {
                    setStatus(res.data.error || ('HTTP ' + res.status), 'error');
                }
            }).catch(function (err) { setStatus(String(err && err.message || err), 'error'); });
        }

        input.addEventListener('change', function () { handle(input.files && input.files[0]); });
        ['dragover', 'dragenter'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
        });
        drop.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) handle(e.dataTransfer.files[0]);
        });
    }

    function renderGalleryPane(host, pick, setStatus) {
        setStatus(t('common.loading'), '');
        var grid = document.createElement('div');
        grid.className = 'cms-imgpick-grid';
        host.appendChild(grid);
        fetch(CFG.imagesUrl, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                setStatus('');
                var images = (d && d.images) || [];
                if (!images.length) { setStatus(t('image.gallery_empty'), ''); return; }
                images.forEach(function (img) {
                    var cell = document.createElement('button');
                    cell.type = 'button';
                    cell.className = 'cms-imgpick-cell';
                    cell.title = img.name;
                    var thumb = document.createElement('img');
                    thumb.src = img.url;
                    thumb.alt = img.name;
                    thumb.loading = 'lazy';
                    cell.appendChild(thumb);
                    cell.addEventListener('click', function () { pick(img.url); });
                    grid.appendChild(cell);
                });
            })
            .catch(function (err) { setStatus(String(err && err.message || err), 'error'); });
    }

    /* Advanced drawer (§5.3): raw CSS rows render ONLY on open — the
     * not-Breakdance DOM invariant: zero input[data-css-prop] until then. */
    function toggleAdvanced(el, anchor) {
        if (advancedRendered) { closePopover(); return; }
        var pop = openPopover(anchor);
        advancedRendered = true;
        pop.classList.add('cms-popover--advanced');
        var state = 'normal'; // 'normal' = inline style, 'hover' = data-cms-hover

        // state switcher (normal / hover) — edit/add hover styles to any element
        var sw = document.createElement('div');
        sw.className = 'cms-state-switch';
        function stateBtn(key, st) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'cms-state-btn';
            b.textContent = t(key);
            b.addEventListener('click', function (e) { e.stopPropagation(); state = st; refresh(); });
            return b;
        }
        var bNormal = stateBtn('panel.state_normal', 'normal');
        var bHover = stateBtn('panel.state_hover', 'hover');
        sw.appendChild(bNormal);
        sw.appendChild(bHover);
        pop.appendChild(sw);

        var note = document.createElement('div');
        note.className = 'cms-popover-note';
        pop.appendChild(note);

        var rowsHost = document.createElement('div');
        pop.appendChild(rowsHost);

        var plus = document.createElement('button');
        plus.type = 'button';
        plus.className = 'cms-popover-item';
        plus.addEventListener('click', function (e) { e.stopPropagation(); addRow('', ''); });
        pop.appendChild(plus);

        function commitRow(prop, value) {
            if (state === 'hover') setStateProp(el, prop, value);
            else setStyleProp(el, prop, value);
        }

        function addRow(prop, value) {
            var row = document.createElement('div');
            row.className = 'cms-css-row';
            var p = document.createElement('input');
            p.type = 'text';
            p.setAttribute('data-css-prop', '');
            p.placeholder = t('panel.prop_name');
            p.value = prop;
            var v = document.createElement('input');
            v.type = 'text';
            v.setAttribute('data-css-value', '');
            v.placeholder = t('panel.prop_value');
            v.value = value;
            function commit() {
                var propName = p.value.trim().toLowerCase();
                if (!propName) return;
                commitRow(propName, v.value.trim());
            }
            v.addEventListener('change', commit);
            v.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); commit(); } });
            row.appendChild(p);
            row.appendChild(v);
            rowsHost.appendChild(row);
        }

        function refresh() {
            bNormal.classList.toggle('is-active', state === 'normal');
            bHover.classList.toggle('is-active', state === 'hover');
            note.textContent = state === 'hover' ? t('panel.advanced_hover_note') : t('panel.advanced_note');
            plus.textContent = t('panel.add_prop');
            rowsHost.replaceChildren();
            var decls = state === 'hover'
                ? parseDecls(el.getAttribute(HOVER_ATTR))
                : parseDecls(el.getAttribute('style'));
            Object.keys(decls).forEach(function (k) { addRow(k, decls[k]); });
            addRow('', '');
        }
        refresh();
    }

    /* --- font size: numeric value + unit + live multi-unit readout ------ */

    function showFontSize(el, anchor) {
        var pop = openPopover(anchor);
        var rootPx = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
        var parentPx = el.parentElement
            ? (parseFloat(getComputedStyle(el.parentElement).fontSize) || rootPx)
            : rootPx;

        var info = document.createElement('div');
        info.className = 'cms-popover-note';
        function refreshInfo() {
            var px = parseFloat(getComputedStyle(el).fontSize) || rootPx;
            info.textContent = t('panel.size_now') + ': '
                + Math.round(px) + 'px · '
                + (px / rootPx).toFixed(2) + 'rem · '
                + Math.round(px / parentPx * 100) + '% · '
                + (px / parentPx).toFixed(2) + 'em';
        }
        refreshInfo();
        pop.appendChild(info);

        var row = document.createElement('div');
        row.className = 'cms-size-row';
        var num = document.createElement('input');
        num.type = 'number'; num.step = '0.1'; num.min = '0';
        num.className = 'cms-size-num';
        var unit = document.createElement('select');
        unit.className = 'cms-size-unit';
        ['px', 'rem', 'em', '%', 'vw'].forEach(function (u) {
            var o = document.createElement('option'); o.value = u; o.textContent = u; unit.appendChild(o);
        });
        var inline = (el.style.fontSize || '').trim();
        var m = inline.match(/^([\d.]+)\s*(px|rem|em|%|vw)$/);
        if (m) { num.value = m[1]; unit.value = m[2]; }
        else { num.value = Math.round(parseFloat(getComputedStyle(el).fontSize) || rootPx); unit.value = 'px'; }

        var apply = document.createElement('button');
        apply.type = 'button'; apply.className = 'cms-popover-apply'; apply.textContent = t('common.ok');
        function commit() {
            var v = num.value.trim();
            setStyleProp(el, 'font-size', v === '' ? '' : v + unit.value);
            refreshInfo();
        }
        apply.addEventListener('click', function (e) { e.stopPropagation(); commit(); });
        num.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); commit(); } });
        row.appendChild(num); row.appendChild(unit); row.appendChild(apply);
        pop.appendChild(row);

        var reset = document.createElement('button');
        reset.type = 'button'; reset.className = 'cms-popover-item'; reset.textContent = t('panel.size_reset');
        reset.addEventListener('click', function (e) {
            e.stopPropagation();
            setStyleProp(el, 'font-size', '');
            num.value = Math.round(parseFloat(getComputedStyle(el).fontSize) || rootPx);
            unit.value = 'px';
            refreshInfo();
        });
        pop.appendChild(reset);
        num.focus(); num.select();
    }

    // Web-safe stacks always available; custom families come from the registry
    // (CFG.fonts) and render because EditorController injected their @font-face.
    var FONT_STACKS = [
        { key: 'font_system', value: "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif" },
        { key: 'font_sans', value: "Arial, Helvetica, sans-serif" },
        { key: 'font_serif', value: "Georgia, 'Times New Roman', serif" },
        { key: 'font_mono', value: "'Courier New', ui-monospace, monospace" }
    ];

    function showFontFamily(el, anchor) {
        var pop = openPopover(anchor);
        var list = document.createElement('div');
        list.className = 'cms-font-list';

        function addItem(label, value, fontPreview) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cms-font-item';
            btn.textContent = label;
            if (fontPreview) { btn.style.fontFamily = fontPreview; }
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                closePopover();
                setStyleProp(el, 'font-family', value);
            });
            list.appendChild(btn);
        }
        function addHead(text) {
            var h = document.createElement('div');
            h.className = 'cms-font-head';
            h.textContent = text;
            list.appendChild(h);
        }

        FONT_STACKS.forEach(function (s) {
            addItem(t('panel.' + s.key), s.value, s.value);
        });

        var presets = (CFG.presetFonts || []).filter(function (f) { return typeof f === 'string' && f; });
        if (presets.length) {
            addHead(t('panel.font_presets'));
            presets.forEach(function (fam) {
                addItem(fam, "'" + fam + "', sans-serif", "'" + fam + "'");
            });
        }

        var fonts = (CFG.fonts || []).filter(function (f) { return typeof f === 'string' && f; });
        if (fonts.length) {
            addHead(t('panel.font_yours'));
            fonts.forEach(function (fam) {
                addItem(fam, "'" + fam + "', sans-serif", "'" + fam + "'");
            });
        }

        addHead('');
        addItem(t('panel.font_reset'), '', '');
        pop.appendChild(list);
    }

    /* --- background: color + image (any element, incl. bg-image divs) ---- */

    function showBackground(el, anchor) {
        var pop = openPopover(anchor);

        var crow = document.createElement('div');
        crow.className = 'cms-bg-row';
        var clabel = document.createElement('span');
        clabel.className = 'cms-bg-label'; clabel.textContent = t('panel.bg_color');
        var color = document.createElement('input');
        color.type = 'text'; color.className = 'cms-popover-input';
        color.placeholder = '#ffffff / rgba(0,0,0,.5)';
        color.value = el.style.backgroundColor || '';
        function commitColor() { setStyleProp(el, 'background-color', color.value.trim()); }
        color.addEventListener('change', commitColor);
        color.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); commitColor(); } });
        crow.appendChild(clabel); crow.appendChild(color);
        pop.appendChild(crow);

        var irow = document.createElement('div');
        irow.className = 'cms-bg-row';
        var pickBtn = document.createElement('button');
        pickBtn.type = 'button'; pickBtn.className = 'cms-popover-item'; pickBtn.textContent = t('panel.bg_image');
        pickBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            showImagePicker(el, function (url) { setStyleProp(el, 'background-image', 'url("' + url + '")'); });
        });
        var clearBtn = document.createElement('button');
        clearBtn.type = 'button'; clearBtn.className = 'cms-popover-item'; clearBtn.textContent = t('panel.bg_image_remove');
        clearBtn.addEventListener('click', function (e) { e.stopPropagation(); setStyleProp(el, 'background-image', ''); });
        irow.appendChild(pickBtn); irow.appendChild(clearBtn);
        pop.appendChild(irow);

        function selRow(prop, label, opts) {
            var r = document.createElement('div');
            r.className = 'cms-bg-row';
            var l = document.createElement('span'); l.className = 'cms-bg-label'; l.textContent = label;
            var s = document.createElement('select'); s.className = 'cms-size-unit';
            opts.forEach(function (o) {
                var op = document.createElement('option'); op.value = o; op.textContent = o || '—'; s.appendChild(op);
            });
            s.value = el.style.getPropertyValue(prop) || '';
            s.addEventListener('change', function () { setStyleProp(el, prop, s.value); });
            r.appendChild(l); r.appendChild(s);
            pop.appendChild(r);
        }
        selRow('background-size', t('panel.bg_size'), ['', 'cover', 'contain', 'auto']);
        selRow('background-position', t('panel.bg_position'), ['', 'center', 'top', 'bottom', 'left', 'right']);
        selRow('background-repeat', t('panel.bg_repeat'), ['', 'no-repeat', 'repeat', 'repeat-x', 'repeat-y']);
    }

    /* --------------------------- selection ----------------------------- */

    function select(el) {
        deselect(false);
        selected = el;
        el.classList.add('cms-selected');
        var label;
        if (el.hasAttribute('data-cms-block')) {
            showPluginPanel(el);
            var ki = kindInfo(el);
            label = ki && ki.slug
                ? t('plugin.' + ki.slug + '.label', null, ki.label || el.getAttribute('data-cms-block'))
                : ((ki && ki.label) || el.getAttribute('data-cms-block'));
        } else {
            showPanel(el);
            var type = typeFor(el);
            label = type ? t('panel.type_' + type.key.toLowerCase(), null, type.label) : '';
        }
        parent.postMessage({
            type: 'cms:select',
            id: idOf(el),
            tag: el.tagName.toLowerCase(),
            label: label
        }, ORIGIN);
    }

    function deselect(notify) {
        closePopover();
        if (selected) {
            selected.classList.remove('cms-selected');
            selected = null;
        }
        if (panel) panel.hidden = true;
        if (notify !== false) parent.postMessage({ type: 'cms:select', id: null }, ORIGIN);
    }

    /* ----------------------- text editing (debounced) -------------------- */
    /* Typing emits text.set commands on pauses (§7.2): a continuous burst of
     * typing = ONE undo step; a pause closes the step. Inside contenteditable
     * Ctrl+Z stays NATIVE (§7.5) — our log takes over outside. */

    function onEditInput() {
        if (textFlushTimer) clearTimeout(textFlushTimer);
        textFlushTimer = setTimeout(flushText, TEXT_FLUSH_MS);
    }

    function flushText() {
        if (textFlushTimer) { clearTimeout(textFlushTimer); textFlushTimer = null; }
        if (!editing) return;
        var current = editing.innerHTML;
        if (current !== textSnapshot) {
            emitCommand('text.set', idOf(editing), { html: current }, { html: textSnapshot }, null);
            textSnapshot = current;
        }
    }

    function startEditing(el) {
        if (editing === el) return;
        stopEditing();
        editing = el;
        textSnapshot = el.innerHTML;
        el.setAttribute('contenteditable', 'true');
        el.classList.add('cms-editing');
        el.addEventListener('input', onEditInput);
        if (panel) panel.hidden = true;
        closePopover();
        el.focus();
        showFmtBar(el);
    }

    function stopEditing() {
        if (!editing) return;
        var el = editing;
        flushText();
        hideFmtBar();
        editing = null;
        el.removeEventListener('input', onEditInput);
        el.removeAttribute('contenteditable');
        el.classList.remove('cms-editing');
        if (selected === el) showPanel(el);
    }

    /* --- formatting toolbar (rich text while editing) -------------------- */
    /* Buttons drive document.execCommand with styleWithCSS=false so the output
     * is TAG-based (<b>/<i>/<u>/<a>/<ul>/<h2>/<blockquote>…) — the only markup
     * the server RICH sanitizer keeps; a <span style> would be unwrapped on save.
     * Every command flows through the existing text.set path → undo/redo + save
     * for free. */

    var fmtBar = null;

    var FMT_BUTTONS = [
        { key: 'fontsize', icon: 'ri-font-size', run: function (btn) { if (editing) showFontSize(editing, btn); } },
        { key: 'fontfamily', icon: 'ri-text', run: function (btn) { if (editing) showFontFamily(editing, btn); } },
        { key: 'bold', icon: 'ri-bold', run: function () { fmtCmd('bold'); } },
        { key: 'italic', icon: 'ri-italic', run: function () { fmtCmd('italic'); } },
        { key: 'underline', icon: 'ri-underline', run: function () { fmtCmd('underline'); } },
        { key: 'link', icon: 'ri-link', run: function () { promptLink(); } },
        { key: 'ul', icon: 'ri-list-unordered', run: function () { blockTransform({ list: 'ul' }); } },
        { key: 'ol', icon: 'ri-list-ordered', run: function () { blockTransform({ list: 'ol' }); } },
        { key: 'h2', icon: 'ri-h-2', run: function () { blockTransform({ tag: 'h2' }); } },
        { key: 'h3', icon: 'ri-h-3', run: function () { blockTransform({ tag: 'h3' }); } },
        { key: 'quote', icon: 'ri-double-quotes-l', run: function () { blockTransform({ tag: 'blockquote' }); } },
        { key: 'code', icon: 'ri-code-line', run: function () { wrapCode(); } },
        { key: 'clear', icon: 'ri-eraser-line', run: function () { fmtCmd('removeFormat'); } },
        { key: 'done', icon: 'ri-check-line', sep: true, run: function () { closePopover(); stopEditing(); } }
    ];

    function buildFmtBar() {
        fmtBar = document.createElement('div');
        fmtBar.className = 'cms-fmt-bar';
        fmtBar.setAttribute('data-cms-protected', 'true');
        FMT_BUTTONS.forEach(function (b) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cms-fmt-btn' + (b.sep ? ' cms-fmt-sep' : '');
            setIcon(btn, b.icon);
            btn.title = t('fmt.' + b.key);
            // mousedown preventDefault keeps the contenteditable selection alive
            btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
            btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); b.run(btn); });
            fmtBar.appendChild(btn);
        });
        ui.appendChild(fmtBar);
    }

    function showFmtBar(el) {
        if (!fmtBar) buildFmtBar();
        fmtBar.hidden = false;
        positionFmtBar(el);
    }

    function hideFmtBar() {
        if (fmtBar) fmtBar.hidden = true;
    }

    function positionFmtBar(el) {
        if (!fmtBar || fmtBar.hidden) return;
        var rect = el.getBoundingClientRect();
        var top = rect.top + window.scrollY - fmtBar.offsetHeight - 8;
        if (top < window.scrollY + 4) top = rect.bottom + window.scrollY + 8;
        var left = Math.max(4, Math.min(
            rect.left + window.scrollX,
            window.scrollX + document.documentElement.clientWidth - fmtBar.offsetWidth - 8
        ));
        fmtBar.style.top = top + 'px';
        fmtBar.style.left = left + 'px';
    }

    function fmtCmd(cmd, val) {
        if (!editing) return;
        try { document.execCommand('styleWithCSS', false, false); } catch (e) { /* legacy */ }
        try { document.execCommand(cmd, false, val || null); } catch (e) { /* unsupported */ }
        afterFmt();
    }

    function afterFmt() {
        if (!editing) return;
        editing.focus();
        flushText();           // one discrete undo step per format action
        positionFmtBar(editing);
    }

    function wrapCode() {
        if (!editing) return;
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount || sel.isCollapsed) { window.alert(t('fmt.select_first')); return; }
        var range = sel.getRangeAt(0);
        var code = document.createElement('code');
        try {
            // only a clean within-text selection — surroundContents throws on a
            // partial/cross-element range, and we DON'T fall back to extract+insert
            // (that can move id-bearing nodes into <code>) (review M3)
            range.surroundContents(code);
        } catch (e) {
            window.alert(t('fmt.select_clean'));
            return;
        }
        sel.removeAllRanges();
        afterFmt();
    }

    function promptLink() {
        if (!editing) return;
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount || sel.isCollapsed) { window.alert(t('fmt.select_first')); return; }
        var saved = sel.getRangeAt(0).cloneRange();
        var url = window.prompt(t('fmt.link_prompt'), 'https://');
        if (url === null) return;
        url = url.trim();
        editing.focus();
        if (saved) { sel.removeAllRanges(); sel.addRange(saved); }
        if (url === '') {
            fmtCmd('unlink');
            return;
        }
        fmtCmd('createLink', url);
    }

    /* Block-level transforms (heading / quote / list) change the HOST element's
     * TAG via node.replace — never nest a block inside a phrasing host (<p><h2>),
     * which the HTML5 parser reparents on reload (empty-<p> cruft). The host id +
     * class/style are preserved; clicking the same button toggles back to <p>. */
    function escAttr(v) { return String(v).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }

    function cleanClass(host) {
        // strip editor-runtime classes so they never leak into the saved HTML
        return Array.prototype.filter.call(host.classList, function (c) {
            return c !== 'cms-selected' && c !== 'cms-editing';
        }).join(' ');
    }

    function hostAttrs(host) {
        var a = '';
        var cls = cleanClass(host);
        if (cls) a += ' class="' + escAttr(cls) + '"';
        if (host.getAttribute('style')) a += ' style="' + escAttr(host.getAttribute('style')) + '"';
        return a;
    }

    function unwrapListInner(host) {
        var items = host.querySelectorAll(':scope > li');
        if (!items.length) return host.innerHTML;
        return Array.prototype.map.call(items, function (li) { return li.innerHTML; }).join(' ');
    }

    function blockTransform(opts) {
        if (!editing) return;
        var host = editing;
        var id = idOf(host);
        if (!id) return;
        var curTag = host.tagName.toLowerCase();
        var oldOuter = host.outerHTML;
        var inner = host.innerHTML;
        var attrs = hostAttrs(host);
        var newTag, newInner;

        if (opts.list) {
            if (curTag === opts.list) {            // toggle list off → paragraph
                newTag = 'p';
                newInner = unwrapListInner(host);
            } else if (curTag === 'ul' || curTag === 'ol') { // switch list type
                newTag = opts.list;
                newInner = inner;
            } else {                               // wrap a block as a one-item list
                newTag = opts.list;
                newInner = '<li>' + inner + '</li>';
            }
        } else {
            newTag = (curTag === opts.tag) ? 'p' : opts.tag; // toggle heading/quote off
            newInner = inner;
        }

        var newOuter = '<' + newTag + ' data-cms-id="' + id + '"' + attrs + '>' + newInner + '</' + newTag + '>';
        var newEl = sanitizeStructuralFragment(newOuter);
        if (!newEl) return;

        host.parentNode.replaceChild(newEl, host);
        // editing context follows the new element so typing/formatting continues
        editing = newEl;
        selected = newEl;
        newEl.setAttribute('contenteditable', 'true');
        newEl.classList.add('cms-editing');
        newEl.classList.add('cms-selected');
        newEl.addEventListener('input', onEditInput);
        textSnapshot = newEl.innerHTML;

        emitCommand('node.replace', id, { html: newOuter }, { html: oldOuter }, null);
        newEl.focus();
        showFmtBar(newEl);
    }

    /* --------------------------- mutations ------------------------------ */

    function applyStyleLocal(el, prop, value) {
        if (value === '') {
            el.style.removeProperty(prop);
            if (!el.getAttribute('style')) el.removeAttribute('style');
        } else {
            el.style.setProperty(prop, value);
        }
    }

    function setStyleProp(el, prop, value) {
        var before = el.style.getPropertyValue(prop) || '';
        if (before === value) return; // no-op guard
        applyStyleLocal(el, prop, value);
        emitCommand('style.set', idOf(el), { prop: prop, value: value },
            { value: before }, 'style:' + idOf(el) + ':' + prop);
        if (selected === el) positionPanel(el);
    }

    /* --- hover-state styles (data-cms-hover → managed <style>) ---------- */
    var HOVER_ATTR = 'data-cms-hover';

    function parseDecls(s) {
        var o = {};
        (s || '').split(';').forEach(function (d) {
            var i = d.indexOf(':');
            if (i > 0) {
                var p = d.slice(0, i).trim().toLowerCase();
                var v = d.slice(i + 1).trim();
                if (p && v) o[p] = v;
            }
        });
        return o;
    }
    function serializeDecls(o) {
        var a = [];
        for (var k in o) { if (o.hasOwnProperty(k)) a.push(k + ': ' + o[k]); }
        return a.join('; ');
    }

    function applyHoverLocal(el, prop, value) {
        var d = parseDecls(el.getAttribute(HOVER_ATTR));
        if (value === '') delete d[prop]; else d[prop] = value;
        var s = serializeDecls(d);
        if (s === '') el.removeAttribute(HOVER_ATTR); else el.setAttribute(HOVER_ATTR, s);
        renderLiveHover();
    }

    function setStateProp(el, prop, value) {
        var before = parseDecls(el.getAttribute(HOVER_ATTR))[prop] || '';
        if (before === value) return;
        applyHoverLocal(el, prop, value);
        emitCommand('style.state', idOf(el), { state: 'hover', prop: prop, value: value },
            { value: before }, 'state:hover:' + idOf(el) + ':' + prop);
    }

    // mirror the server StateCssRenderer for live preview of unsaved hover edits
    function renderLiveHover() {
        var style = document.getElementById('cms-hover-live');
        if (!style) {
            style = document.createElement('style');
            style.id = 'cms-hover-live';
            style.setAttribute('data-cms-protected', 'true');
            (document.head || document.documentElement).appendChild(style);
        }
        var rules = [];
        Array.prototype.forEach.call(document.querySelectorAll('[' + HOVER_ATTR + ']'), function (el) {
            var id = el.getAttribute(ID_ATTR);
            var d = el.getAttribute(HOVER_ATTR);
            if (id && /^cms-[0-9a-f]{12}$/.test(id) && d) {
                rules.push('[data-cms-id="' + id + '"]:hover { ' + d + ' }');
            }
        });
        style.textContent = rules.join('\n');
    }

    function setAttrValue(el, name, value) {
        var had = el.hasAttribute(name);
        var before = el.getAttribute(name) || '';
        if (had && before === value) return; // no-op guard
        el.setAttribute(name, value);
        emitCommand('attr.set', idOf(el), { name: name, value: value }, { had: had, value: before }, null);
    }

    function moveNode(el, delta) {
        var info = parentInfo(el);
        if (!info) return;
        var siblings = info.parentEl.children;
        var to = info.index + delta;
        if (to < 0 || to >= siblings.length) return;
        var other = siblings[to];
        if (ui && (ui === other || ui.contains(other))) return;

        emitCommand('node.move', idOf(el), {
            before: { parentId: info.parentId, index: info.index },
            after: { parentId: info.parentId, index: to }
        }, {}, null);

        if (delta < 0) {
            info.parentEl.insertBefore(el, other);
        } else {
            info.parentEl.insertBefore(el, other.nextSibling);
        }
        if (selected === el) positionPanel(el);
        el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    // mirror of Annotator::isEditable — an element that may receive an id (not a
    // protected tag, not in a protected subtree, not strictly inside a plugin block)
    var ID_PROTECTED_TAGS = {
        html: 1, head: 1, body: 1, script: 1, style: 1, link: 1, meta: 1, title: 1, base: 1
    };
    function isEditableForId(el) {
        if (ID_PROTECTED_TAGS[el.tagName.toLowerCase()]) return false;
        for (var n = el; n; n = n.parentElement) {
            if (n.getAttribute && n.getAttribute('data-cms-protected') === 'true') return false;
        }
        for (var p = el.parentElement; p; p = p.parentElement) {
            if (p.hasAttribute && p.hasAttribute('data-cms-block')) return false;
        }
        return true;
    }
    // editable descendants in document order (root excluded) — same set+order as
    // Annotator::editableDescendants, so client and server agree on clone ids
    function editableDescendantsClient(root) {
        var out = [], all = root.querySelectorAll('*');
        for (var i = 0; i < all.length; i++) {
            if (isEditableForId(all[i])) out.push(all[i]);
        }
        return out;
    }
    function allDocIds() {
        var t = {}, all = document.querySelectorAll('[' + ID_ATTR + ']');
        for (var i = 0; i < all.length; i++) t[all[i].getAttribute(ID_ATTR)] = true;
        return t;
    }
    function freshIdAvoiding(taken) {
        var id;
        do { id = genId(); } while (taken[id]);
        taken[id] = true;
        return id;
    }

    function duplicateNode(el) {
        var taken = allDocIds();
        var newId = freshIdAvoiding(taken);
        var clone = el.cloneNode(true);
        clone.setAttribute(ID_ATTR, newId);
        // Give the clone's editable descendants FRESH ids (so text/nested galleries
        // inside the copy are immediately selectable) and ship the id list so the
        // server stamps the SAME ids — otherwise an edit to the copy before saving
        // would target an id the server never minted. Order matches the server walk.
        var origList = editableDescendantsClient(el);
        var cloneList = editableDescendantsClient(clone);
        var childIds = [];
        for (var i = 0; i < cloneList.length; i++) {
            var fid = freshIdAvoiding(taken);
            cloneList[i].setAttribute(ID_ATTR, fid);
            childIds.push(fid);
            // a nested plugin block in the copy needs its own live props/element entry
            if (cloneList[i].hasAttribute('data-cms-block')) {
                var oid = origList[i] ? idOf(origList[i]) : null;
                if (oid && nodeProps[oid] != null) nodeProps[fid] = JSON.parse(JSON.stringify(nodeProps[oid]));
                if (oid && ELEMENTS[oid] != null) ELEMENTS[fid] = JSON.parse(JSON.stringify(ELEMENTS[oid]));
            }
        }
        // snapshot (with ids + data-cms-block intact — client sanitizer keeps both)
        // makes redo deterministic even if the original changes later
        emitCommand('node.duplicate', idOf(el), { newId: newId, childIds: childIds }, { cloneHtml: clone.outerHTML }, null);
        // a duplicated plugin block (the root itself) needs its own live props entry
        if (el.hasAttribute('data-cms-block') && nodeProps[idOf(el)] != null) {
            nodeProps[newId] = JSON.parse(JSON.stringify(nodeProps[idOf(el)]));
            if (ELEMENTS[idOf(el)] != null) ELEMENTS[newId] = JSON.parse(JSON.stringify(ELEMENTS[idOf(el)]));
        }
        el.parentNode.insertBefore(clone, el.nextSibling);
        select(clone);
    }

    function deleteNode(el) {
        if (!window.confirm(t('panel.confirm_delete'))) return;
        var info = parentInfo(el);
        emitCommand('node.delete', idOf(el), {}, {
            html: el.outerHTML,
            parentId: info ? info.parentId : null,
            index: info ? info.index : 0
        }, null);
        deselect();
        el.remove();
    }

    function insertTemplate(refEl, templateKey) {
        var tpl = TEMPLATES[templateKey];
        if (!tpl) return;
        var newId = genId();
        emitCommand('node.insert', null, {
            refId: idOf(refEl),
            position: 'after',
            template: templateKey,
            newId: newId
        }, {}, null);
        var node = buildTemplateNode(tpl.html);
        if (node) {
            node.setAttribute(ID_ATTR, newId);
            refEl.parentNode.insertBefore(node, refEl.nextSibling);
            select(node);
        }
    }

    /* ----------------------- plugin editing (§6) ------------------------ */

    function clone(o) { return o === undefined ? undefined : JSON.parse(JSON.stringify(o)); }

    function pathSeg(s) { return /^\d+$/.test(s) ? +s : s; }

    function getByPath(obj, path) {
        var segs = String(path).split('.');
        var cur = obj;
        for (var i = 0; i < segs.length; i++) {
            if (cur == null) return undefined;
            cur = cur[pathSeg(segs[i])];
        }
        return cur;
    }

    function setByPath(obj, path, val) {
        var copy = clone(obj);
        var segs = String(path).split('.');
        var cur = copy;
        for (var i = 0; i < segs.length - 1; i++) cur = cur[pathSeg(segs[i])];
        cur[pathSeg(segs[segs.length - 1])] = val;
        return copy;
    }

    // pure client mirror of the server PropsMutator (§6.4.1)
    function mutateLocal(mode, props, f) {
        var p = clone(props) || {};
        var arr;
        switch (mode) {
            case 'setprop': return setByPath(p, f.path, f.value);
            case 'arrayinsert':
                arr = getByPath(p, f.path);
                arr.splice(Math.max(0, Math.min(f.index, arr.length)), 0, clone(f.item));
                return p;
            case 'arrayremove':
                arr = getByPath(p, f.path); arr.splice(f.index, 1); return p;
            case 'arraymove':
                arr = getByPath(p, f.path);
                arr.splice(f.to, 0, arr.splice(f.from, 1)[0]); return p;
        }
        return p;
    }

    function inverseForward(mode, f, u) {
        switch (mode) {
            case 'setprop': return { mode: 'setprop', forward: { path: f.path, value: u.value } };
            case 'arrayinsert': return { mode: 'arrayremove', forward: { path: f.path, index: f.index } };
            case 'arrayremove': return { mode: 'arrayinsert', forward: { path: f.path, index: f.index, item: u.item } };
            case 'arraymove': return { mode: 'arraymove', forward: { path: f.path, from: f.to, to: f.from } };
        }
        return { mode: mode, forward: f };
    }

    function fieldDefault(spec) {
        switch (spec.type) {
            case 'enum': return spec.default !== undefined ? spec.default : (spec.values && spec.values[0]);
            case 'bool': return !!spec.default;
            case 'number': return spec.default !== undefined ? spec.default : (spec.min !== undefined ? spec.min : 0);
            case 'color': return spec.default !== undefined ? spec.default : '#000000';
            case 'string': case 'richtext': return spec.default !== undefined ? String(spec.default) : '';
            case 'array': return Array.isArray(spec.default) ? JSON.parse(JSON.stringify(spec.default)) : [];
            case 'object':
                var o = {}, sh = spec.shape || {};
                Object.keys(sh).forEach(function (k) { o[k] = fieldDefault(sh[k]); });
                return o;
        }
        return null;
    }

    function schemaDefaults(schema) {
        var o = {};
        Object.keys(schema || {}).forEach(function (k) { o[k] = fieldDefault(schema[k]); });
        return o;
    }

    function itemDefault(arraySpec) {
        return arraySpec.of === 'object'
            ? fieldDefault({ type: 'object', shape: arraySpec.shape || {} })
            : fieldDefault({ type: arraySpec.of });
    }

    function kindInfo(el) {
        if (!el || !el.hasAttribute || !el.hasAttribute('data-cms-block')) return null;
        var kind = el.getAttribute('data-cms-block');
        var k = KINDBYNAME[kind];
        return k
            ? { kind: kind, known: true, slug: k.slug, schema: k.schema, label: k.label, impl: window.__cms.kinds[kind] }
            : { kind: kind, known: false };
    }

    function renderPluginNode(el, props) {
        var kind = el.getAttribute('data-cms-block');
        var impl = window.__cms.kinds[kind];
        if (!impl || typeof impl.previewHtml !== 'function') return;
        var node = sanitizeStructuralFragment(impl.previewHtml(props, ''));
        el.replaceChildren();
        if (node) el.appendChild(node);
    }

    function emitPlugin(mode, el, forward, undoData) {
        var info = kindInfo(el);
        emitCommand('plugin.' + info.slug + '.' + mode, idOf(el), forward, undoData || {}, null);
    }

    function pluginSetProp(el, path, value) {
        var id = idOf(el);
        var before = getByPath(nodeProps[id], path);
        if (JSON.stringify(before) === JSON.stringify(value)) return; // no-op guard
        nodeProps[id] = mutateLocal('setprop', nodeProps[id], { path: path, value: value });
        renderPluginNode(el, nodeProps[id]);
        emitPlugin('setprop', el, { path: path, value: value }, { value: before });
        if (selected === el) positionPanel(el);
    }

    function pluginArrayInsert(el, path, index, item) {
        var id = idOf(el);
        // clamp here so the EMITTED forward.index matches what apply uses — the
        // inverse (arrayremove at the same index) then round-trips exactly, and
        // the server receives the already-clamped index (parity with PropsMutator)
        var arr = getByPath(nodeProps[id], path) || [];
        index = Math.max(0, Math.min(index, arr.length));
        nodeProps[id] = mutateLocal('arrayinsert', nodeProps[id], { path: path, index: index, item: item });
        renderPluginNode(el, nodeProps[id]);
        emitPlugin('arrayinsert', el, { path: path, index: index, item: item }, {});
    }

    function pluginArrayRemove(el, path, index) {
        var id = idOf(el);
        var removed = clone(getByPath(nodeProps[id], path)[index]);
        nodeProps[id] = mutateLocal('arrayremove', nodeProps[id], { path: path, index: index });
        renderPluginNode(el, nodeProps[id]);
        emitPlugin('arrayremove', el, { path: path, index: index }, { item: removed });
    }

    function pluginArrayMove(el, path, from, to) {
        var id = idOf(el);
        nodeProps[id] = mutateLocal('arraymove', nodeProps[id], { path: path, from: from, to: to });
        renderPluginNode(el, nodeProps[id]);
        emitPlugin('arraymove', el, { path: path, from: from, to: to }, {});
    }

    // build the <div data-cms-block> wrapper. A kind with layout:"contents" gets a
    // transparent wrapper (inline display:contents + marker class) so adopting a
    // structural themed element never shifts the page grid (§6 adopt).
    function newPluginBlockEl(kind, newId) {
        var k = KINDBYNAME[kind];
        var block = document.createElement('div');
        block.setAttribute('data-cms-block', kind);
        block.setAttribute(ID_ATTR, newId);
        block.setAttribute('data-cms-v', String((k && k.version) || 1));
        if (k && k.layout === 'contents') {
            block.classList.add('cms-block-contents');
            block.style.display = 'contents';
        }
        return block;
    }

    function insertPluginBlock(refEl, kind) {
        var k = KINDBYNAME[kind];
        if (!k) return;
        var newId = genId();
        var props = schemaDefaults(k.schema);
        emitCommand('plugin.' + k.slug + '.insert', null,
            { kind: kind, refId: idOf(refEl), position: 'after', newId: newId, props: props }, {}, null);
        var block = newPluginBlockEl(kind, newId);
        refEl.parentNode.insertBefore(block, refEl.nextSibling);
        nodeProps[newId] = props;
        ELEMENTS[newId] = { editable: true, status: 'ok', kind: kind, slug: k.slug, label: k.label, props: props };
        renderPluginNode(block, props);
        select(block);
    }

    // adopt an existing themed element into a managed plugin block (§6): read its
    // props straight from the DOM via the kind's extractProps, insert the managed
    // block in its place, then delete the raw node. Two reusable ops (insert +
    // node.delete) so undo/redo and the server replay need no new primitive.
    function adoptAsKind(el, kind) {
        var k = KINDBYNAME[kind];
        var impl = window.__cms.kinds[kind];
        if (!k || !impl || typeof impl.extractProps !== 'function') return;
        var props = null;
        try { props = impl.extractProps(el); } catch (e) { props = null; }
        if (!props || typeof props !== 'object') return;
        var refId = idOf(el);
        if (!refId) return;
        var newId = genId();
        emitCommand('plugin.' + k.slug + '.insert', null,
            { kind: kind, refId: refId, position: 'after', newId: newId, props: props }, {}, null);
        var block = newPluginBlockEl(kind, newId);
        el.parentNode.insertBefore(block, el.nextSibling);
        nodeProps[newId] = props;
        ELEMENTS[newId] = { editable: true, status: 'ok', kind: kind, slug: k.slug, label: k.label, props: props };
        renderPluginNode(block, props);
        var info = parentInfo(el);
        emitCommand('node.delete', refId, {}, {
            html: el.outerHTML,
            parentId: info ? info.parentId : null,
            index: info ? info.index : 0
        }, null);
        el.remove();
        select(block);
    }

    /* ---- plugin selection panel + auto-form (§6.4.1) ---- */

    function readonlyReason(status) {
        var map = {
            'unknown-kind': 'panel.ro_unknown',
            'invalid-import': 'panel.ro_import',
            'invalid-sidecar': 'panel.ro_sidecar',
            'migrate-fail': 'panel.ro_migrate'
        };
        return t(map[status] || 'panel.ro_default');
    }

    function showPluginPanel(el) {
        var id = idOf(el);
        var info = ELEMENTS[id];
        var ki = kindInfo(el);
        panel.replaceChildren();
        advancedRendered = false;

        var title = document.createElement('span');
        title.className = 'cms-panel-type';
        title.textContent = (ki && ki.label) || (info && info.label) || el.getAttribute('data-cms-block');
        panel.appendChild(title);

        var editable = ki && ki.known && info && info.editable;
        if (!editable) {
            var badge = document.createElement('span');
            badge.className = 'cms-panel-badge';
            badge.textContent = readonlyReason(info ? info.status : (ki && !ki.known ? 'unknown-kind' : ''));
            panel.appendChild(badge);
            // the whole block can still be moved/deleted, just not its contents
            addActionButton(el, 'moveUp', panel);
            addActionButton(el, 'moveDown', panel);
            addActionButton(el, 'delete', panel);
            panel.hidden = false;
            positionPanel(el);
            return;
        }

        var editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'cms-panel-btn';
        editBtn.textContent = '✎';
        editBtn.title = t('panel.edit');
        editBtn.addEventListener('click', function (e) { e.stopPropagation(); openPluginEditor(el); });
        panel.appendChild(editBtn);
        addActionButton(el, 'duplicate', panel);
        addActionButton(el, 'moveUp', panel);
        addActionButton(el, 'moveDown', panel);
        addActionButton(el, 'delete', panel);
        panel.hidden = false;
        positionPanel(el);
    }

    function makePluginCtx(el) {
        return {
            setProp: function (p, v) { pluginSetProp(el, p, v); },
            arrayInsert: function (p, i, it) { pluginArrayInsert(el, p, i, it); refreshPluginEditor(el); },
            arrayRemove: function (p, i) { pluginArrayRemove(el, p, i); refreshPluginEditor(el); },
            arrayMove: function (p, f, t) { pluginArrayMove(el, p, f, t); refreshPluginEditor(el); },
            getProps: function () { return clone(nodeProps[idOf(el)]); },
            pickImage: function (cb) { showImagePicker(el, cb); },
            t: function (k, p, f) { return t(k, p, f); }
        };
    }

    function openPluginEditor(el) {
        var ki = kindInfo(el);
        var props = nodeProps[idOf(el)] || {};
        var anchor = panel.querySelector('button') || el;
        var pop = openPopover(anchor);
        pop.classList.add('cms-popover--form');
        if (ki.impl && typeof ki.impl.mountEditor === 'function') {
            ki.impl.mountEditor(pop, clone(props), makePluginCtx(el)); // plugin draws its own panel
        } else {
            buildForm(pop, ki.schema, props, el);                       // core auto-form (§6.4.1)
        }
    }

    function refreshPluginEditor(el) {
        // structure changed (array add/remove/move) → rebuild the open form
        if (popover && popover.classList.contains('cms-popover--form')) openPluginEditor(el);
    }

    function lastSeg(path) { var p = String(path).split('.'); return p[p.length - 1]; }

    // localized field label: plugin.<slug>.<field> (from plugins/<slug>/lang/*),
    // falling back to the raw field name (§8.4 plugin i18n namespace)
    function fieldLabel(el, path) {
        var ki = kindInfo(el);
        var seg = lastSeg(path);
        return ki && ki.slug ? t('plugin.' + ki.slug + '.' + seg, null, seg) : seg;
    }

    function buildForm(container, schema, props, el) {
        container.replaceChildren();
        var note = document.createElement('div');
        note.className = 'cms-popover-note';
        note.textContent = t('panel.form_title');
        container.appendChild(note);
        Object.keys(schema || {}).forEach(function (name) {
            renderField(container, schema[name], name, el);
        });
    }

    function renderField(parent, spec, path, el) {
        var props = nodeProps[idOf(el)] || {};
        var val = getByPath(props, path);

        if (spec.type === 'object') {
            var sh = spec.shape || {};
            Object.keys(sh).forEach(function (k) { renderField(parent, sh[k], path + '.' + k, el); });
            return;
        }
        if (spec.type === 'array') {
            renderArrayField(parent, spec, path, el);
            return;
        }

        var row = document.createElement('label');
        row.className = 'cms-form-row';
        var lab = document.createElement('span');
        lab.className = 'cms-form-label';
        lab.textContent = fieldLabel(el, path);
        row.appendChild(lab);

        var ctrl;
        if (spec.type === 'enum') {
            ctrl = document.createElement('select');
            (spec.values || []).forEach(function (v) {
                var o = document.createElement('option');
                o.value = v; o.textContent = v;
                if (v === val) o.selected = true;
                ctrl.appendChild(o);
            });
            ctrl.addEventListener('change', function () { pluginSetProp(el, path, ctrl.value); });
        } else if (spec.type === 'bool') {
            ctrl = document.createElement('input'); ctrl.type = 'checkbox'; ctrl.checked = !!val;
            ctrl.addEventListener('change', function () { pluginSetProp(el, path, ctrl.checked); });
        } else if (spec.type === 'number') {
            ctrl = document.createElement('input'); ctrl.type = 'number';
            if (spec.min != null) ctrl.min = spec.min;
            if (spec.max != null) ctrl.max = spec.max;
            ctrl.value = val;
            ctrl.addEventListener('change', function () {
                var n = parseFloat(ctrl.value);
                if (isNaN(n)) n = spec.min || 0;
                pluginSetProp(el, path, n);
            });
        } else if (spec.type === 'color') {
            ctrl = document.createElement('input'); ctrl.type = 'color'; ctrl.value = val || '#000000';
            ctrl.addEventListener('change', function () { pluginSetProp(el, path, ctrl.value); });
        } else { // string / richtext
            ctrl = spec.multiline ? document.createElement('textarea') : document.createElement('input');
            if (ctrl.tagName === 'INPUT') ctrl.type = 'text';
            if (spec.max) ctrl.maxLength = spec.max;
            ctrl.value = val || '';
            ctrl.addEventListener('change', function () { pluginSetProp(el, path, ctrl.value); });
        }
        ctrl.className = 'cms-form-control';
        row.appendChild(ctrl);
        parent.appendChild(row);
    }

    function renderArrayField(parent, spec, path, el) {
        var arr = getByPath(nodeProps[idOf(el)], path) || [];
        var group = document.createElement('div');
        group.className = 'cms-form-array';
        var head = document.createElement('div');
        head.className = 'cms-form-array-head';
        head.textContent = fieldLabel(el, path);
        group.appendChild(head);

        arr.forEach(function (item, i) {
            var card = document.createElement('div');
            card.className = 'cms-form-card';
            var bar = document.createElement('div');
            bar.className = 'cms-form-card-bar';
            var n = document.createElement('span'); n.textContent = '#' + (i + 1); bar.appendChild(n);
            bar.appendChild(miniBtn('↑', function () { if (i > 0) makePluginCtx(el).arrayMove(path, i, i - 1); }));
            bar.appendChild(miniBtn('↓', function () { if (i < arr.length - 1) makePluginCtx(el).arrayMove(path, i, i + 1); }));
            bar.appendChild(miniBtn('✕', function () { makePluginCtx(el).arrayRemove(path, i); }));
            card.appendChild(bar);
            var sh = spec.shape || {};
            Object.keys(sh).forEach(function (k) { renderField(card, sh[k], path + '.' + i + '.' + k, el); });
            group.appendChild(card);
        });

        var add = document.createElement('button');
        add.type = 'button';
        add.className = 'cms-form-add';
        add.textContent = t('panel.add_item');
        if (spec.max && arr.length >= spec.max) add.disabled = true;
        add.addEventListener('click', function (e) {
            e.stopPropagation();
            makePluginCtx(el).arrayInsert(path, arr.length, itemDefault(spec));
        });
        group.appendChild(add);
        parent.appendChild(group);
    }

    function miniBtn(txt, fn) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'cms-form-mini';
        b.textContent = txt;
        b.addEventListener('click', function (e) { e.stopPropagation(); fn(); });
        return b;
    }

    /* ---- plugin command replay (undo/redo) ---- */

    function applyPluginForward(cmd) {
        var mode = cmd.kind.split('.')[2];
        var f = cmd.forward || {};
        if (mode === 'insert') {
            var ref = byId(f.refId);
            var k = KINDBYNAME[f.kind];
            if (!ref || !k) return;
            var block = newPluginBlockEl(f.kind, f.newId);
            placeRelative(block, ref, f.position);
            nodeProps[f.newId] = clone(f.props) || schemaDefaults(k.schema);
            renderPluginNode(block, nodeProps[f.newId]);
        } else {
            var el = byId(cmd.nodeId);
            if (!el) return;
            nodeProps[cmd.nodeId] = mutateLocal(mode, nodeProps[cmd.nodeId], f);
            renderPluginNode(el, nodeProps[cmd.nodeId]);
        }
    }

    function applyPluginInverse(cmd) {
        var mode = cmd.kind.split('.')[2];
        var f = cmd.forward || {};
        var u = cmd.undo || {};
        if (mode === 'insert') {
            var ins = byId(f.newId);
            if (ins) ins.remove();
            delete nodeProps[f.newId];
        } else {
            var el = byId(cmd.nodeId);
            if (!el) return;
            var inv = inverseForward(mode, f, u);
            nodeProps[cmd.nodeId] = mutateLocal(inv.mode, nodeProps[cmd.nodeId], inv.forward);
            renderPluginNode(el, nodeProps[cmd.nodeId]);
        }
    }

    function placeRelative(node, ref, position) {
        switch (position) {
            case 'before': ref.parentNode.insertBefore(node, ref); break;
            case 'inside-first': ref.insertBefore(node, ref.firstChild); break;
            case 'inside-last': ref.appendChild(node); break;
            default: ref.parentNode.insertBefore(node, ref.nextSibling); // 'after'
        }
    }

    /* ----------------- undo/redo application (cms:revert/reapply) -------- */

    function setSanitizedChildren(el, html) {
        var holder = sanitizeStructuralFragment('<div>' + html + '</div>');
        el.replaceChildren();
        if (holder) {
            while (holder.firstChild) el.appendChild(holder.firstChild);
        }
    }

    function moveToPlace(el, place) {
        var parentEl = place.parentId === null ? document.body : byId(place.parentId);
        if (!parentEl || !el) return;
        el.remove();
        var elements = Array.prototype.filter.call(parentEl.children, function (c) {
            return !(ui && (c === ui || ui.contains(c)));
        });
        var index = Math.max(0, Math.min(place.index, elements.length));
        parentEl.insertBefore(el, elements[index] || null);
    }

    function applyInverse(cmd) {
        if (cmd.kind && cmd.kind.indexOf('plugin.') === 0) {
            applyPluginInverse(cmd);
            deselect();
            return;
        }
        var el = byId(cmd.nodeId);
        var f = cmd.forward || {};
        var u = cmd.undo || {};
        switch (cmd.kind) {
            case 'text.set':
                if (el) setSanitizedChildren(el, u.html || '');
                break;
            case 'attr.set':
                if (el) {
                    if (u.had) el.setAttribute(f.name, u.value);
                    else el.removeAttribute(f.name);
                }
                break;
            case 'attr.remove':
                if (el && u.had) el.setAttribute(f.name, u.value);
                break;
            case 'style.set':
                if (el) applyStyleLocal(el, f.prop, u.value || '');
                break;
            case 'style.state':
                if (el) applyHoverLocal(el, f.prop, u.value || '');
                break;
            case 'node.insert':
            case 'node.duplicate':
                var inserted = byId(f.newId);
                if (inserted) inserted.remove();
                break;
            case 'node.delete':
                restoreNode(u);
                break;
            case 'node.move':
                if (el) moveToPlace(el, f.before);
                break;
            case 'node.replace':
                if (el) { var back = sanitizeStructuralFragment(u.html || ''); if (back) el.parentNode.replaceChild(back, el); }
                break;
        }
        deselect();
    }

    function applyForward(cmd) {
        if (cmd.kind && cmd.kind.indexOf('plugin.') === 0) {
            applyPluginForward(cmd);
            deselect();
            return;
        }
        var el = byId(cmd.nodeId);
        var f = cmd.forward || {};
        var u = cmd.undo || {};
        switch (cmd.kind) {
            case 'text.set':
                if (el) setSanitizedChildren(el, f.html || '');
                break;
            case 'attr.set':
                if (el) el.setAttribute(f.name, f.value);
                break;
            case 'attr.remove':
                if (el) el.removeAttribute(f.name);
                break;
            case 'style.set':
                if (el) applyStyleLocal(el, f.prop, f.value);
                break;
            case 'style.state':
                if (el) applyHoverLocal(el, f.prop, f.value);
                break;
            case 'node.insert':
                var refEl = byId(f.refId);
                var tpl = TEMPLATES[f.template];
                if (refEl && tpl) {
                    var node = buildTemplateNode(tpl.html);
                    if (node) {
                        node.setAttribute(ID_ATTR, f.newId);
                        refEl.parentNode.insertBefore(node, refEl.nextSibling);
                    }
                }
                break;
            case 'node.duplicate':
                if (el && u.cloneHtml) {
                    var clone = sanitizeStructuralFragment(u.cloneHtml);
                    if (clone) el.parentNode.insertBefore(clone, el.nextSibling);
                }
                break;
            case 'node.delete':
                var victim = byId(cmd.nodeId);
                if (victim) victim.remove();
                break;
            case 'node.move':
                if (el) moveToPlace(el, f.after);
                break;
            case 'node.replace':
                if (el) { var fwd = sanitizeStructuralFragment(f.html || ''); if (fwd) el.parentNode.replaceChild(fwd, el); }
                break;
        }
        deselect();
    }

    function restoreNode(u) {
        if (!u || !u.html) return;
        var node = sanitizeStructuralFragment(u.html);
        if (!node) return;
        var parentEl = u.parentId === null || u.parentId === undefined
            ? document.body
            : byId(u.parentId);
        if (!parentEl) return;
        var elements = Array.prototype.filter.call(parentEl.children, function (c) {
            return !(ui && (c === ui || ui.contains(c)));
        });
        var index = Math.max(0, Math.min(u.index || 0, elements.length));
        parentEl.insertBefore(node, elements[index] || null);
    }

    /* ----------------------------- events ------------------------------- */

    function bindEvents() {
        // selection on POINTERDOWN (animations split mousedown/mouseup targets)
        document.addEventListener('pointerdown', function (e) {
            // dismiss an open popover on any click outside it (back/close UX)
            if (popover && !popover.contains(e.target)) closePopover();
            if (ui && ui.contains(e.target)) return;
            var el = editableTarget(e.target);
            if (editing) {
                if (editing === el || (el && editing.contains(el))) return;
                stopEditing();
            }
            if (!el) { deselect(); return; }
            if (el === selected) return;
            select(el);
        }, true);

        // Esc closes an open popover first (before deselect/stop-editing)
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && popover) { e.stopPropagation(); closePopover(); }
        }, true);

        // links/buttons must not navigate inside the editor; and the site's own
        // click handlers (a site's lightbox bound to .project-card / .ba items,
        // etc.) must not fire over the editor — stop the event at capture so a
        // gallery can be selected/edited without its lightbox popping up.
        document.addEventListener('click', function (e) {
            if (ui && ui.contains(e.target)) return;
            if (editableTarget(e.target)) { e.preventDefault(); e.stopPropagation(); }
        }, true);

        document.addEventListener('dblclick', function (e) {
            if (ui && ui.contains(e.target)) return;
            var el = editableTarget(e.target);
            if (!el) return;
            e.preventDefault();
            if (el !== selected) select(el);
            // plugin blocks are edited through their form, never contenteditable
            if (el.tagName !== 'IMG' && el.tagName !== 'HR' && !el.hasAttribute('data-cms-block')) startEditing(el);
        }, true);

        document.addEventListener('mouseover', function (e) {
            if (ui && ui.contains(e.target)) return;
            var el = editableTarget(e.target);
            document.querySelectorAll('.cms-hover').forEach(function (h) { h.classList.remove('cms-hover'); });
            if (el && el !== selected && !editing) el.classList.add('cms-hover');
        });

        document.addEventListener('keydown', function (e) {
            // modal picker open → swallow nothing here; its own capture handler runs (review M2)
            if (imgPicker) return;
            var k = e.key.toLowerCase();
            if (editing) {
                // Ctrl+Z/Y inside contenteditable = NATIVE browser undo (§7.5)
                if (e.key === 'Escape') { e.preventDefault(); stopEditing(); }
                if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); stopEditing(); }
                if ((e.ctrlKey || e.metaKey) && k === 's') {
                    e.preventDefault();
                    stopEditing(); // flush before save (v1 lesson)
                    parent.postMessage({ type: 'cms:hotkey', key: 'save' }, ORIGIN);
                }
                return;
            }
            if ((e.ctrlKey || e.metaKey) && k === 's') {
                e.preventDefault();
                parent.postMessage({ type: 'cms:hotkey', key: 'save' }, ORIGIN);
                return;
            }
            if ((e.ctrlKey || e.metaKey) && k === 'z' && e.shiftKey) {
                e.preventDefault();
                parent.postMessage({ type: 'cms:hotkey', key: 'redo' }, ORIGIN);
                return;
            }
            if ((e.ctrlKey || e.metaKey) && k === 'z') {
                e.preventDefault();
                parent.postMessage({ type: 'cms:hotkey', key: 'undo' }, ORIGIN);
                return;
            }
            if ((e.ctrlKey || e.metaKey) && k === 'y') {
                e.preventDefault();
                parent.postMessage({ type: 'cms:hotkey', key: 'redo' }, ORIGIN);
                return;
            }
            if (!selected) return;
            if (e.key === 'Escape') { deselect(); return; }
            if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); deleteNode(selected); return; }
            if ((e.ctrlKey || e.metaKey) && k === 'd') { e.preventDefault(); duplicateNode(selected); return; }
            if (e.altKey && e.key === 'ArrowUp') { e.preventDefault(); moveNode(selected, -1); return; }
            if (e.altKey && e.key === 'ArrowDown') { e.preventDefault(); moveNode(selected, 1); return; }
        });

        document.addEventListener('focusout', function (e) {
            if (editing && e.target === editing) {
                setTimeout(function () {
                    // keep editing when focus moved INTO the editor chrome (e.g. the
                    // font-size popover opened from the format bar)
                    var a = document.activeElement;
                    if (editing && a !== editing && !(ui && ui.contains(a))) stopEditing();
                }, 50);
            }
        });

        window.addEventListener('scroll', function () {
            if (selected && panel && !panel.hidden) positionPanel(selected);
            if (editing && fmtBar && !fmtBar.hidden) positionFmtBar(editing);
        }, { passive: true });

        document.addEventListener('auxclick', function (e) {
            if (editableTarget(e.target)) e.preventDefault();
        });
    }

    /* --------------------------- messages ------------------------------- */

    window.addEventListener('message', function (e) {
        if (e.origin !== ORIGIN || !e.data || typeof e.data.type !== 'string') return;
        var d = e.data;
        switch (d.type) {
            case 'cms:shell-ready':
                parent.postMessage({
                    type: 'cms:doc-ready',
                    count: document.querySelectorAll('[' + ID_ATTR + ']').length
                }, ORIGIN);
                break;
            case 'cms:revert':
                if (d.command) {
                    stopEditing();
                    applyInverse(d.command);
                }
                break;
            case 'cms:reapply':
                if (d.command) {
                    stopEditing();
                    applyForward(d.command);
                }
                break;
        }
    });

    function buildKindIndex() {
        Object.keys(PLUGINS).forEach(function (slug) {
            var p = PLUGINS[slug];
            (p.kinds || []).forEach(function (mk) {
                KINDBYNAME[mk.kind] = {
                    slug: slug,
                    schema: mk.props_schema || {},
                    label: mk.label || mk.kind,
                    version: mk.version || 1,
                    layout: mk.layout || '',
                    needsRuntime: mk.needs_runtime,
                    editor_js: p.editor_js,
                    editor_css: p.editor_css
                };
            });
        });
    }

    function seedPluginProps() {
        Object.keys(ELEMENTS).forEach(function (id) {
            var e = ELEMENTS[id];
            if (e && e.editable && e.props) nodeProps[id] = e.props;
        });
    }

    // load each plugin's editor_js/css into the iframe so registerKind runs (§6.4)
    function loadPluginAssets() {
        var seenJs = {}, seenCss = {};
        Object.keys(PLUGINS).forEach(function (slug) {
            var p = PLUGINS[slug];
            if (p.editor_css && !seenCss[p.editor_css]) {
                seenCss[p.editor_css] = 1;
                var l = document.createElement('link');
                l.rel = 'stylesheet'; l.href = p.editor_css;
                l.setAttribute('data-cms-protected', 'true');
                document.head.appendChild(l);
            }
            if (p.editor_js && !seenJs[p.editor_js]) {
                seenJs[p.editor_js] = 1;
                var s = document.createElement('script');
                s.src = p.editor_js;
                s.setAttribute('data-cms-protected', 'true');
                document.head.appendChild(s);
            }
        });
    }

    function init() {
        fetch(CFG.typesUrl, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                TYPES = json;
                buildKindIndex();
                seedPluginProps();
                buildUi();
                bindEvents();
                loadPluginAssets();
                // the server-rendered hover block is replaced by a live one the
                // editor controls (so unsaved hover edits preview instantly);
                // the saved file keeps its own ef-state-styles for the live page
                var srv = document.getElementById('ef-state-styles');
                if (srv) srv.parentNode.removeChild(srv);
                renderLiveHover();
                parent.postMessage({
                    type: 'cms:doc-ready',
                    count: document.querySelectorAll('[' + ID_ATTR + ']').length
                }, ORIGIN);
            })
            .catch(function (err) {
                console.error('EditFront: element-types load failed', err);
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
