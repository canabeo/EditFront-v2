/* EditFront v2 — editor shell (C2).
 * ONE ordered CommandLog (§7) is simultaneously: the undo/redo history
 * (cursor), the save queue (entries[0..cursor) and nothing else ever reaches
 * the server) and the draft payload (persisted as-is). There is no second
 * structure to drift out of sync — "an undone op gets saved" is structurally
 * impossible. Heavy command payloads are offloaded to a server sidecar so no
 * command is ever too big to stay undoable (§7.2). */
(function () {
    'use strict';

    var CFG = window.__cmsEditor;
    var CSRF = document.body.getAttribute('data-cms-csrf') || '';
    var ORIGIN = window.location.origin;

    // i18n (§8.3): full active-language dict injected by _bootstrap_head.twig
    var I18N = window.__i18n || {};
    function t(key, params, fallback) {
        var s = (key in I18N) ? I18N[key] : (fallback != null ? fallback : key);
        if (params) for (var k in params) s = s.split(':' + k).join(String(params[k]));
        return s;
    }

    var INLINE_CAP = 200 * 1024;      // JSON chars; bigger entries go to the sidecar
    var STYLE_COALESCE_MS = 800;      // same (node, prop) merges within this window
    var DRAFT_DEBOUNCE_MS = 2000;
    var AUTO_SAVE_AT = 100;           // soft checkpoint, keeps the log bounded

    var iframe = document.getElementById('ef-preview');
    var btnSave = document.getElementById('ef-save');
    var btnUndo = document.getElementById('ef-undo');
    var btnRedo = document.getElementById('ef-redo');
    var statusEl = document.getElementById('ef-status');
    var chipEl = document.getElementById('ef-selected-chip');
    var openLive = document.getElementById('ef-open-live');
    var btnBackups = document.getElementById('ef-backups');
    var backupsPanel = document.getElementById('ef-backups-panel');
    var backupsList = document.getElementById('ef-backups-list');
    var backupsClose = document.getElementById('ef-backups-close');

    /* --- CommandLog ----------------------------------------------------- */
    var log = { entries: [], cursor: 0, serial: 0 };
    var baseSha = CFG.baseSha;
    var saving = false;
    var statusTimer = null;
    var draftTimer = null;
    var draftRestoreDone = false;

    openLive.href = CFG.liveUrl;

    function canUndo() { return log.cursor > 0; }
    function canRedo() { return log.cursor < log.entries.length; }

    function pushCommand(cmd) {
        if (log.cursor < log.entries.length) {
            log.entries.length = log.cursor; // linear branching: drop the redo tail
        }
        var top = log.entries[log.cursor - 1];
        if (
            top && cmd.coalesceKey && top.coalesceKey === cmd.coalesceKey
            && cmd.kind === 'style.set'
            && (cmd.ts - top.ts) < STYLE_COALESCE_MS
        ) {
            // slider-style merge: forward advances, undo keeps the FIRST before
            top.forward = cmd.forward;
            top.ts = cmd.ts;
        } else {
            log.entries.push(cmd);
            log.cursor++;
        }
        log.serial++;
        afterLogChange();
        if (log.cursor >= AUTO_SAVE_AT && !saving) {
            doSave(false); // checkpoint long sessions (server cap is 200 ops)
        }
    }

    function undo() {
        if (!canUndo() || saving) return;
        var cmd = log.entries[--log.cursor];
        log.serial++;
        ensurePayload(cmd).then(function () {
            post({ type: 'cms:revert', command: cmd });
        });
        afterLogChange();
    }

    function redo() {
        if (!canRedo() || saving) return;
        var cmd = log.entries[log.cursor++];
        log.serial++;
        ensurePayload(cmd).then(function () {
            post({ type: 'cms:reapply', command: cmd });
        });
        afterLogChange();
    }

    function afterLogChange() {
        refreshButtons();
        scheduleDraftSave();
    }

    function refreshButtons() {
        btnSave.disabled = saving || log.cursor === 0;
        btnSave.textContent = log.cursor > 0 ? t('editor.save_count', { n: log.cursor }) : t('editor.save');
        btnUndo.disabled = !canUndo() || saving;
        btnRedo.disabled = !canRedo() || saving;
    }

    /* --- heavy payload offload (§7.2) ------------------------------------ */

    function entrySize(cmd) {
        return JSON.stringify({ forward: cmd.forward, undo: cmd.undo }).length;
    }

    function ensurePayload(cmd) {
        if (cmd.forward !== undefined && cmd.forward !== null) return Promise.resolve();
        return fetch(CFG.payloadUrl + '?page=' + encodeURIComponent(CFG.page) + '&cmd_id=' + encodeURIComponent(cmd.id), {
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.payload) {
                var parsed = JSON.parse(d.payload);
                cmd.forward = parsed.forward;
                cmd.undo = parsed.undo;
            } else {
                throw new Error('undo payload missing for ' + cmd.id);
            }
        });
    }

    function offloadIfHeavy(cmd) {
        if (cmd.__offloaded || entrySize(cmd) <= INLINE_CAP) return Promise.resolve();
        return postJson(CFG.payloadUrl, {
            page: CFG.page,
            cmd_id: cmd.id,
            payload: JSON.stringify({ forward: cmd.forward, undo: cmd.undo })
        }).then(function (r) {
            if (r.status === 200) cmd.__offloaded = true;
        });
    }

    /* --- draft = the log itself (§7.3) ----------------------------------- */

    function scheduleDraftSave() {
        if (draftTimer) clearTimeout(draftTimer);
        draftTimer = setTimeout(saveDraft, DRAFT_DEBOUNCE_MS);
    }

    function saveDraft() {
        if (saving) { scheduleDraftSave(); return; }
        if (log.entries.length === 0) {
            postJson(CFG.draftDeleteUrl, { page: CFG.page });
            return;
        }
        var offloads = log.entries
            .filter(function (c) { return entrySize(c) > INLINE_CAP; })
            .map(offloadIfHeavy);

        Promise.all(offloads).then(function () {
            var entries = log.entries.map(function (c) {
                if (c.__offloaded) {
                    return { id: c.id, kind: c.kind, nodeId: c.nodeId, ts: c.ts, coalesceKey: c.coalesceKey, ref: true };
                }
                return { id: c.id, kind: c.kind, nodeId: c.nodeId, ts: c.ts, coalesceKey: c.coalesceKey, forward: c.forward, undo: c.undo };
            });
            return postJson(CFG.draftUrl, {
                page: CFG.page,
                draft: { base_sha256: baseSha, serial: log.serial, cursor: log.cursor, entries: entries }
            });
        }).catch(function (err) {
            console.warn('draft save failed:', err);
        });
    }

    function restoreDraft() {
        fetch(CFG.draftUrl + '?page=' + encodeURIComponent(CFG.page), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.draft || !Array.isArray(d.draft.entries) || d.draft.entries.length === 0) return;

                if (d.draft.base_sha256 !== baseSha) {
                    // stale draft (§4.3.1): never applied silently
                    var applyOver = window.confirm(t('editor.conflict_stale'));
                    if (!applyOver) {
                        postJson(CFG.draftDeleteUrl, { page: CFG.page });
                        return;
                    }
                    restoreLogFromDraft(d.draft);
                    doSave(true); // force + pre-save backup; orphans land in warnings
                    return;
                }

                restoreLogFromDraft(d.draft);
                replayApplied().then(function () {
                    setStatus(t('editor.draft_restored', { n: log.cursor }), 'ok', 8000);
                    refreshButtons();
                });
            })
            .catch(function (err) { console.warn('draft load failed:', err); });
    }

    function restoreLogFromDraft(draft) {
        log.entries = draft.entries.map(function (e) {
            var cmd = {
                id: e.id, kind: e.kind, nodeId: e.nodeId, ts: e.ts, coalesceKey: e.coalesceKey || null,
                forward: e.ref ? undefined : e.forward,
                undo: e.ref ? undefined : e.undo
            };
            if (e.ref) cmd.__offloaded = true;
            return cmd;
        });
        log.cursor = Math.min(draft.cursor, log.entries.length);
        log.serial = draft.serial || 0;
    }

    function replayApplied() {
        // re-apply entries[0..cursor) into the freshly loaded preview, in order
        var i = 0;
        function step() {
            if (i >= log.cursor) return Promise.resolve();
            var cmd = log.entries[i++];
            return ensurePayload(cmd).then(function () {
                post({ type: 'cms:reapply', command: cmd });
                return step();
            });
        }
        return step();
    }

    /* --- save ------------------------------------------------------------ */

    function doSave(force) {
        if (saving || log.cursor === 0) return;
        saving = true;
        setStatus(t('editor.saving'), '', 0);
        refreshButtons();

        var toSave = log.entries.slice(0, log.cursor);
        Promise.all(toSave.map(ensurePayload)).then(function () {
            var ops = toSave.map(function (c) {
                return { op: c.kind, target: c.nodeId, forward: c.forward };
            });
            return postJson(CFG.saveUrl, {
                page: CFG.page,
                base_sha256: baseSha,
                ops: ops,
                force: !!force
            });
        }).then(function (r) {
            saving = false;
            if (r.status === 200 && r.data.ok) {
                // saved prefix is baked into the new base; commands queued
                // mid-flight survive at the head of the log
                log.entries = log.entries.slice(toSave.length);
                log.cursor = Math.max(0, log.cursor - toSave.length);
                baseSha = r.data.new_sha256;

                var msg = t('editor.saved', { n: r.data.applied });
                if (r.data.warnings && r.data.warnings.length) {
                    msg += ' · ' + t('editor.warnings', { n: r.data.warnings.length });
                    console.warn('save warnings:', r.data.warnings);
                }
                setStatus(msg, 'ok');
                refreshButtons();

                if (log.entries.length > 0 && log.cursor > 0) {
                    doSave(false); // late arrivals chase the new sha
                    return;
                }
                postJson(CFG.draftDeleteUrl, { page: CFG.page });
                log.entries = [];
                log.cursor = 0;
                refreshButtons();
                reloadPreview();
                return;
            }
            if (r.status === 409) {
                var over = window.confirm(t('editor.conflict_external'));
                if (over) {
                    doSave(true);
                } else {
                    log.entries = [];
                    log.cursor = 0;
                    baseSha = r.data.current_sha256 || baseSha;
                    postJson(CFG.draftDeleteUrl, { page: CFG.page });
                    refreshButtons();
                    setStatus(t('editor.discarded'), '');
                    reloadPreview();
                }
                return;
            }
            setStatus(t('editor.save_error', { msg: (r.data.error || r.status) }), 'error', 0);
            refreshButtons();
        }).catch(function (err) {
            saving = false;
            setStatus(t('editor.net_error', { msg: err.message }), 'error', 0);
            refreshButtons();
        });
    }

    function reloadPreview() {
        // contentWindow.location.reload() is cross-origin now that the preview
        // is sandboxed; re-pointing src is the supported way to reload it.
        var src = iframe.getAttribute('src');
        iframe.setAttribute('src', 'about:blank');
        iframe.setAttribute('src', src);
        chipEl.hidden = true;
    }

    /* --- plumbing --------------------------------------------------------- */

    function post(msg) {
        // The sandboxed preview has an opaque origin, so it cannot be named as a
        // targetOrigin. '*' is safe here because the receiver is not chosen by
        // origin at all: we post into a specific frame we created ourselves.
        if (iframe.contentWindow) iframe.contentWindow.postMessage(msg, '*');
    }

    /**
     * Run an API call the sandboxed preview can no longer make for itself and
     * hand the result back. The CSRF token and the session stay here, in the
     * shell — the preview never sees either.
     */
    function handleProxy(d) {
        var reply = function (ok, data, error) {
            post({ type: 'cms:proxy-result', id: d.id, ok: ok, data: data, error: error });
        };
        var fail = function (err) { reply(false, null, String((err && err.message) || err)); };

        if (d.kind === 'types') {
            fetch(CFG.typesUrl, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { reply(true, j); })
                .catch(fail);
        } else if (d.kind === 'images') {
            fetch(CFG.imagesUrl, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { reply(true, j); })
                .catch(fail);
        } else if (d.kind === 'upload') {
            var file = d.payload && d.payload.file;
            if (!file) { fail(new Error('no file')); return; }
            var fd = new FormData();
            fd.append('file', file);
            fetch(CFG.uploadUrl, {
                method: 'POST',
                headers: { 'X-CSRF-Token': CSRF },
                credentials: 'same-origin',
                body: fd
            }).then(function (r) {
                return r.json().catch(function () { return {}; })
                    .then(function (j) { reply(true, { status: r.status, data: j }); });
            }).catch(fail);
        } else {
            fail(new Error('unknown proxy kind'));
        }
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            credentials: 'same-origin',
            body: JSON.stringify(body)
        }).then(function (resp) {
            return resp.json()
                .catch(function () { return {}; })
                .then(function (data) { return { status: resp.status, data: data }; });
        });
    }

    function setStatus(text, kind, autohideMs) {
        statusEl.textContent = text;
        statusEl.className = 'ef-status' + (kind ? ' ef-status--' + kind : '');
        if (statusTimer) clearTimeout(statusTimer);
        var ms = autohideMs === undefined ? 6000 : autohideMs;
        if (ms > 0) {
            statusTimer = setTimeout(function () { statusEl.textContent = ''; }, ms);
        }
    }

    /* --- handshake --------------------------------------------------------- */

    var handshakeDone = false;
    var pings = 0;
    var pingTimer = setInterval(function () {
        if (handshakeDone || pings >= 12) {
            clearInterval(pingTimer);
            if (!handshakeDone && pings >= 12) {
                setStatus(t('editor.not_responded'), 'error', 0);
            }
            return;
        }
        pings++;
        post({ type: 'cms:shell-ready' });
    }, 500);

    window.addEventListener('message', function (e) {
        // The preview's origin is opaque under the sandbox, so authenticate by
        // SOURCE instead: only the frame we created can be e.source.
        if (e.source !== iframe.contentWindow || !e.data || typeof e.data.type !== 'string') return;
        var d = e.data;

        switch (d.type) {
            case 'cms:proxy':
                handleProxy(d);
                break;
            case 'cms:doc-ready':
                handshakeDone = true;
                setStatus(t('editor.status_ready', { n: (d.count || 0) }), 'ok');
                if (!draftRestoreDone) {
                    draftRestoreDone = true;
                    restoreDraft();
                }
                break;
            case 'cms:op':
                if (d.command && typeof d.command.kind === 'string') {
                    pushCommand(d.command);
                }
                break;
            case 'cms:select':
                if (d.id) {
                    chipEl.hidden = false;
                    chipEl.textContent = (d.label || d.tag || '') + ' · ' + d.id.slice(0, 10) + '…';
                } else {
                    chipEl.hidden = true;
                }
                break;
            case 'cms:hotkey':
                if (d.key === 'save') doSave(false);
                if (d.key === 'undo') undo();
                if (d.key === 'redo') redo();
                break;
        }
    });

    /* --- backups / restore (§9 C5) --------------------------------------- */

    function backupDate(id) {
        // id = YYYYMMDD-HHMMSS-uuuuuu-hex → DD.MM.YYYY HH:MM:SS
        var m = (id || '').match(/^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})/);
        return m ? (m[3] + '.' + m[2] + '.' + m[1] + ' ' + m[4] + ':' + m[5] + ':' + m[6]) : id;
    }

    var backupsGen = 0;

    function toggleBackups() {
        if (!backupsPanel.hidden) { backupsPanel.hidden = true; backupsGen++; return; }
        backupsPanel.hidden = false;
        var gen = ++backupsGen; // ignore a stale response if re-opened/closed (review M4)
        backupsList.replaceChildren();
        var loading = document.createElement('p');
        loading.className = 'ef-backups-empty';
        loading.textContent = t('common.loading');
        backupsList.appendChild(loading);

        fetch(CFG.backupsUrl + '?page=' + encodeURIComponent(CFG.page), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (gen !== backupsGen || backupsPanel.hidden) return;
                backupsList.replaceChildren();
                var items = (d && d.backups) || [];
                if (!items.length) {
                    var empty = document.createElement('p');
                    empty.className = 'ef-backups-empty';
                    empty.textContent = t('backups.empty');
                    backupsList.appendChild(empty);
                    return;
                }
                items.forEach(function (b) {
                    var row = document.createElement('div');
                    row.className = 'ef-backup-row';
                    var meta = document.createElement('span');
                    meta.className = 'ef-backup-meta';
                    meta.textContent = backupDate(b.id) + ' · ' + Math.round((b.size || 0) / 1024 * 10) / 10 + ' ' + t('dashboard.kb');
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn';
                    btn.textContent = t('backups.restore');
                    btn.addEventListener('click', function () { restoreBackup(b.id); });
                    row.appendChild(meta);
                    row.appendChild(btn);
                    backupsList.appendChild(row);
                });
            })
            .catch(function (err) {
                if (gen !== backupsGen || backupsPanel.hidden) return;
                backupsList.replaceChildren();
                var e = document.createElement('p');
                e.className = 'ef-backups-empty';
                e.textContent = String(err && err.message || err);
                backupsList.appendChild(e);
            });
    }

    function reloadAfterRestore() { window.location.reload(); }

    function restoreBackup(id) {
        var warn = log.cursor > 0 ? t('backups.confirm_unsaved') : t('backups.confirm');
        if (!window.confirm(warn)) return;
        postJson(CFG.restoreUrl, { page: CFG.page, id: id }).then(function (r) {
            if (r.status === 200 && r.data.ok) {
                // current state was backed up server-side; reload to the restored version
                log.entries = [];
                log.cursor = 0;
                // cancel any pending debounced draft-save, then await the delete so
                // the reload doesn't abort it / resurrect the draft (review M3)
                if (draftTimer) clearTimeout(draftTimer);
                postJson(CFG.draftDeleteUrl, { page: CFG.page })
                    .then(reloadAfterRestore, reloadAfterRestore);
            } else {
                setStatus(t('editor.save_error', { msg: (r.data.error || r.status) }), 'error', 0);
            }
        });
    }

    btnBackups.addEventListener('click', toggleBackups);
    backupsClose.addEventListener('click', function () { backupsPanel.hidden = true; });

    /* --- SEO modal (§9) ---------------------------------------------------- */
    var seoBtn = document.getElementById('ef-seo');
    var seoModal = document.getElementById('ef-seo-modal');
    var seoBackdrop = document.getElementById('ef-seo-backdrop');
    var seoClose = document.getElementById('ef-seo-close');
    var seoCancel = document.getElementById('ef-seo-cancel');
    var seoSave = document.getElementById('ef-seo-save');
    var seoTitle = document.getElementById('ef-seo-title');
    var seoDesc = document.getElementById('ef-seo-desc');
    var seoCanon = document.getElementById('ef-seo-canonical');
    var seoIndex = document.getElementById('ef-seo-index');
    var seoFollow = document.getElementById('ef-seo-follow');
    var seoTitleCount = document.getElementById('ef-seo-title-count');
    var seoDescCount = document.getElementById('ef-seo-desc-count');
    var snipTitle = document.getElementById('ef-seo-snip-title');
    var snipUrl = document.getElementById('ef-seo-snip-url');
    var snipDesc = document.getElementById('ef-seo-snip-desc');

    function seoTruncate(s, n) { s = String(s || ''); return s.length > n ? s.slice(0, n - 1) + '…' : s; }

    function liveDisplayUrl() {
        try { return ORIGIN.replace(/^https?:\/\//, '') + CFG.liveUrl; }
        catch (e) { return CFG.liveUrl; }
    }

    function updateSnippet() {
        var title = seoTitle.value.trim() || CFG.page;
        var desc = seoDesc.value.trim();
        snipTitle.textContent = seoTruncate(title, 60);
        snipUrl.textContent = liveDisplayUrl();
        snipDesc.textContent = desc ? seoTruncate(desc, 160) : '—';
        seoTitleCount.textContent = seoTitle.value.length + ' / 60';
        seoDescCount.textContent = seoDesc.value.length + ' / 160';
        seoTitleCount.classList.toggle('ef-count--over', seoTitle.value.length > 60);
        seoDescCount.classList.toggle('ef-count--over', seoDesc.value.length > 160);
    }

    function closeSeo() { seoModal.hidden = true; }

    function openSeo() {
        seoModal.hidden = false;
        seoSave.disabled = true;
        snipTitle.textContent = '';
        snipUrl.textContent = liveDisplayUrl();
        snipDesc.textContent = t('common.loading');
        fetch(CFG.seoUrl + '?page=' + encodeURIComponent(CFG.page), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var s = (d && d.seo) || {};
                seoTitle.value = s.title || '';
                seoDesc.value = s.description || '';
                seoCanon.value = s.canonical || '';
                seoIndex.checked = s.index !== false;
                seoFollow.checked = s.follow !== false;
                seoSave.disabled = false;
                updateSnippet();
                seoTitle.focus();
            })
            .catch(function (err) {
                setStatus(t('seo.error', { msg: String((err && err.message) || err) }), 'error', 0);
                closeSeo();
            });
    }

    function saveSeo() {
        seoSave.disabled = true;
        postJson(CFG.seoUrl, {
            page: CFG.page,
            seo: {
                title: seoTitle.value,
                description: seoDesc.value,
                canonical: seoCanon.value,
                index: seoIndex.checked,
                follow: seoFollow.checked
            }
        }).then(function (r) {
            if (r.status === 200 && r.data.ok) {
                if (r.data.new_sha256) { baseSha = r.data.new_sha256; } // keep the save-lock baseline current
                setStatus(t('seo.saved'), 'ok');
                closeSeo();
            } else {
                seoSave.disabled = false;
                setStatus(t('seo.error', { msg: (r.data.error || r.status) }), 'error', 0);
            }
        }).catch(function (err) {
            seoSave.disabled = false;
            setStatus(t('seo.error', { msg: err.message }), 'error', 0);
        });
    }

    if (seoBtn) {
        seoBtn.addEventListener('click', openSeo);
        seoClose.addEventListener('click', closeSeo);
        seoCancel.addEventListener('click', closeSeo);
        seoBackdrop.addEventListener('click', closeSeo);
        seoSave.addEventListener('click', saveSeo);
        seoTitle.addEventListener('input', updateSnippet);
        seoDesc.addEventListener('input', updateSnippet);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !seoModal.hidden) { e.stopPropagation(); closeSeo(); }
        }, true);
    }

    /* --- UI events ---------------------------------------------------------- */

    btnSave.addEventListener('click', function () { doSave(false); });
    btnUndo.addEventListener('click', undo);
    btnRedo.addEventListener('click', redo);

    document.addEventListener('keydown', function (e) {
        var k = e.key.toLowerCase();
        if ((e.ctrlKey || e.metaKey) && k === 's') {
            e.preventDefault();
            doSave(false);
        } else if ((e.ctrlKey || e.metaKey) && k === 'z' && e.shiftKey) {
            e.preventDefault();
            redo();
        } else if ((e.ctrlKey || e.metaKey) && k === 'z') {
            e.preventDefault();
            undo();
        } else if ((e.ctrlKey || e.metaKey) && k === 'y') {
            e.preventDefault();
            redo();
        }
    });

    window.addEventListener('beforeunload', function (e) {
        if (log.cursor > 0) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
}());
