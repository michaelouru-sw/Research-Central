/* Research Central interaction layer: loaded on every plugin page. Everything here is progressive enhancement;
   each page still works as a normal form post / link without it. */
(function () {
    'use strict';
    // Themes with a tall banner: shrink whichever ancestor of the page title is oversized.
    function slimHeader() {
        var h = document.querySelector('#page-header h1, #page-header .h2, header h1') || document.querySelector('h1');
        var main = document.getElementById('region-main');
        if (!h || !main) { return; }
        var el = h.parentElement, n = 0;
        while (el && el !== document.body && n++ < 8) {
            if (el.contains(main)) { break; }
            if (el.offsetHeight > 110) { el.classList.add('tt-slimmed'); }
            el = el.parentElement;
        }
    }

    function init() {
        try { slimHeader(); } catch (e) { /* cosmetic only */ }
    var S = {};
    try { var sj = document.getElementById('tt-strings'); S = sj ? JSON.parse(sj.textContent) : (window.ttStrings || {}); } catch (e) { S = window.ttStrings || {}; }
    var $ = function (sel, root) { return (root || document).querySelector(sel); };
    var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };
    var t = function (k, vars) {
        var s = S[k] || k;
        if (vars) { Object.keys(vars).forEach(function (v) { s = s.split('{' + v + '}').join(vars[v]); }); }
        return s;
    };
    var fmtSize = function (b) {
        if (b >= 1048576) { return (b / 1048576).toFixed(1) + ' MB'; }
        if (b >= 1024) { return Math.round(b / 1024) + ' KB'; }
        return b + ' B';
    };
    var esc = function (x) { var d = document.createElement('div'); d.textContent = x; return d.innerHTML; };

    /* ---------- Accessible confirm dialog ---------- */
    function confirmDialog(message, okLabel) {
        return new Promise(function (resolve) {
            var dlg = document.createElement('dialog');
            dlg.className = 'tt-dialog';
            dlg.setAttribute('aria-label', message);
            dlg.innerHTML = '<p class="tt-dialog-msg"></p><div class="tt-dialog-btns"><button type="button" class="btn btn-secondary" data-r="0"></button> <button type="button" class="btn btn-primary" data-r="1"></button></div>';
            dlg.querySelector('p').textContent = message;
            dlg.querySelector('[data-r="0"]').textContent = t('cancel');
            dlg.querySelector('[data-r="1"]').textContent = okLabel || t('ok');
            document.body.appendChild(dlg);
            var done = function (r) { try { dlg.close(); } catch (e) { /* ignore */ } dlg.remove(); resolve(r); };
            dlg.addEventListener('click', function (e) { var r = e.target.getAttribute && e.target.getAttribute('data-r'); if (r !== null) { done(r === '1'); } });
            dlg.addEventListener('cancel', function (e) { e.preventDefault(); done(false); });
            if (dlg.showModal) { dlg.showModal(); } else { dlg.setAttribute('open', 'open'); }
            dlg.querySelector('[data-r="1"]').focus();
        });
    }
    window.ttConfirm = confirmDialog;

    /* Destructive buttons ask first. Anything marked data-confirm, plus Delete/Withdraw style buttons. */
    document.addEventListener('click', function (e) {
        var el = e.target.closest && e.target.closest('button[type="submit"], a, input[type="submit"]');
        if (!el || el.__ttOk) { return; }
        var msg = el.getAttribute('data-confirm');
        if (!msg && !el.matches('.tt-fig-remove') && el.matches('.btn-outline-danger, .text-danger, .btn-danger') && (el.tagName === 'BUTTON' || el.tagName === 'A')) {
            msg = t('confirm_generic');
        }
        if (!msg) { return; }
        e.preventDefault();
        e.stopPropagation();
        confirmDialog(msg).then(function (ok) {
            if (!ok) { return; }
            el.__ttOk = true;
            el.click();
            setTimeout(function () { el.__ttOk = false; }, 50);
        });
    }, true);

    /* ---------- Busy states ---------- */
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || e.defaultPrevented || f.classList.contains('tt-upload-form') || f.method !== 'post') { return; }
        var btn = f.querySelector('button[type="submit"]:not([disabled])');
        if (btn) {
            setTimeout(function () {
                btn.setAttribute('aria-busy', 'true');
                btn.classList.add('tt-busy');
                btn.disabled = true;
            }, 0);
        }
    });
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a[href*="download.php"], a[href*="document.php"], a[href*="export.php"], a[href*="grades.php?"], a[href*="dataset.php"], a[href*="leadership.php?"]');
        if (!a || a.classList.contains('tt-busy')) { return; }
        a.classList.add('tt-busy');
        a.setAttribute('aria-busy', 'true');
        setTimeout(function () { a.classList.remove('tt-busy'); a.removeAttribute('aria-busy'); }, 5000);
    });

    /* ---------- Toasts: success and info notices fade out on their own ---------- */
    $$('#user-notifications .alert-success, #user-notifications .alert-info, .tt-flash').forEach(function (n) {
        n.classList.add('tt-toast');
        setTimeout(function () { n.classList.add('tt-toast-out'); setTimeout(function () { n.remove(); }, 600); }, 7000);
    });

    /* ---------- Due-date countdowns ---------- */
    $$('.tt-due[data-ts]').forEach(function (el) {
        var ts = parseInt(el.getAttribute('data-ts'), 10) * 1000;
        if (!ts) { return; }
        var days = Math.ceil((ts - Date.now()) / 86400000);
        var txt;
        if (days > 0) { txt = t('days_left', {n: days}); el.classList.add(days <= 7 ? 'tt-due-soon' : 'tt-due-ok'); }
        else if (days === 0) { txt = t('due_today'); el.classList.add('tt-due-soon'); }
        else { txt = t('days_late', {n: -days}); el.classList.add('tt-due-late'); }
        el.setAttribute('title', el.textContent);
        el.textContent = el.textContent + ' · ' + txt;
    });

    /* ---------- Draft autosave for long text boxes ---------- */
    var draftKey = function (ta) { return 'ttdraft:' + location.pathname + location.search + ':' + (ta.getAttribute('data-draft') || ta.name); };
    $$('main textarea[name], #region-main textarea[name]').forEach(function (ta) {
        if (ta.closest('.editor_atto, .tox, .editor_tiny') || ta.id.indexOf('_editor') > -1 || ta.hidden || ta.readOnly) { return; }
        var key = draftKey(ta), store = null;
        try { store = window.localStorage; store.getItem(key); } catch (e) { return; }
        var saved = store.getItem(key);
        if (saved && !ta.value.trim()) {
            ta.value = saved;
            var note = document.createElement('div');
            note.className = 'tt-sub tt-draftnote';
            note.setAttribute('role', 'status');
            note.innerHTML = esc(t('draft_restored')) + ' <button type="button" class="btn btn-link btn-sm p-0">' + esc(t('discard')) + '</button>';
            note.querySelector('button').addEventListener('click', function () { ta.value = ''; store.removeItem(key); note.remove(); ta.focus(); });
            ta.parentNode.insertBefore(note, ta.nextSibling);
        }
        var timer;
        ta.addEventListener('input', function () {
            clearTimeout(timer);
            timer = setTimeout(function () { try { if (ta.value.trim()) { store.setItem(key, ta.value); } else { store.removeItem(key); } } catch (e) { /* full */ } }, 400);
        });
        var f = ta.form;
        if (f && !f.classList.contains('tt-upload-form')) { f.addEventListener('submit', function () { try { store.removeItem(key); } catch (e) { /* ignore */ } }); }
        ta.__ttKey = key;
    });

    /* ---------- Sortable and filterable long tables ---------- */
    $$('table.tt-table').forEach(function (tbl) {
        var body = tbl.tBodies[0];
        if (!body || body.rows.length < 6 || tbl.hasAttribute('data-nosort')) { return; }
        var heads = $$('thead th', tbl);
        heads.forEach(function (th, i) {
            if (!th.textContent.trim()) { return; }
            th.classList.add('tt-sortable');
            th.setAttribute('tabindex', '0');
            th.setAttribute('role', 'columnheader');
            var go = function () {
                var dir = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
                heads.forEach(function (h) { h.removeAttribute('aria-sort'); });
                th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
                var rows = Array.prototype.slice.call(body.rows);
                rows.sort(function (a, b) {
                    return dir * (a.cells[i] ? a.cells[i].textContent.trim() : '').localeCompare(b.cells[i] ? b.cells[i].textContent.trim() : '', undefined, {numeric: true, sensitivity: 'base'});
                });
                rows.forEach(function (r) { body.appendChild(r); });
            };
            th.addEventListener('click', go);
            th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
        });
        if (body.rows.length >= 12) {
            var box = document.createElement('input');
            box.type = 'search';
            box.className = 'form-control form-control-sm tt-tablefilter';
            box.placeholder = t('filter_rows');
            box.setAttribute('aria-label', t('filter_rows'));
            box.addEventListener('input', function () {
                var q = box.value.toLowerCase();
                Array.prototype.forEach.call(body.rows, function (r) { r.hidden = q && r.textContent.toLowerCase().indexOf(q) === -1; });
            });
            var wrap = tbl.closest('.tt-tablewrap') || tbl;
            wrap.parentNode.insertBefore(box, wrap);
        }
    });

    /* ---------- Reader: j / k jump between commented paragraphs ---------- */
    var paras = $$('.tt-para.has-comments');
    if (paras.length) {
        var idx = -1;
        document.addEventListener('keydown', function (e) {
            if (e.ctrlKey || e.metaKey || e.altKey || /^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName || '')) || e.target.isContentEditable) { return; }
            if (e.key !== 'j' && e.key !== 'k') { return; }
            idx = Math.max(0, Math.min(paras.length - 1, idx + (e.key === 'j' ? 1 : -1)));
            paras[idx].scrollIntoView({behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'center'});
            paras[idx].classList.add('tt-flash-row');
            setTimeout(function () { paras[idx] && paras[idx].classList.remove('tt-flash-row'); }, 1200);
        });
        var hint = document.createElement('div');
        hint.className = 'tt-sub mb-2';
        hint.textContent = t('reader_keys');
        var first = paras[0].parentNode;
        first.insertBefore(hint, first.firstChild);
    }

    /* ---------- Submission page: drop zones, figure slots, progress ---------- */
    var form = $('.tt-upload-form');
    if (!form) { return; }

    var errBox = document.createElement('div');
    errBox.className = 'alert alert-danger tt-errbox';
    errBox.setAttribute('role', 'alert');
    errBox.hidden = true;
    form.parentNode.insertBefore(errBox, form);
    var showErrors = function (list) {
        errBox.innerHTML = '<ul class="mb-0">' + list.map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>';
        errBox.hidden = !list.length;
        if (list.length) { errBox.scrollIntoView({block: 'center'}); }
    };

    /* Check a file input against type and size before anything is sent. Returns an error string or ''. */
    var problem = function (input, file) {
        var kind = input.getAttribute('data-kind'), max = parseFloat(input.getAttribute('data-maxmb')) * 1048576;
        var ext = (file.name.split('.').pop() || '').toLowerCase();
        if (kind === 'docx' && ext !== 'docx') { return t('err_type', {name: file.name}); }
        if (kind === 'image' && ['png', 'jpg', 'jpeg', 'gif', 'webp'].indexOf(ext) === -1) { return t('err_imgtype', {name: file.name}); }
        if (max && file.size > max) { return t('err_size', {name: file.name, size: fmtSize(file.size), limit: input.getAttribute('data-maxmb')}); }
        return '';
    };

    var chosen = function (input) {
        var zone = input.closest('.tt-drop'), out = zone && $('.tt-file-chosen', zone);
        var f = input.files && input.files[0];
        if (!out) { return; }
        if (!f) { out.textContent = ''; zone.classList.remove('has-file', 'has-error'); return; }
        var p = problem(input, f);
        zone.classList.toggle('has-error', !!p);
        zone.classList.toggle('has-file', !p);
        out.textContent = p ? p : '✓ ' + f.name + ' (' + fmtSize(f.size) + ')';
        if (p) { input.value = ''; }
    };

    $$('.tt-drop').forEach(function (zone) {
        var input = $('input[type="file"]', zone);
        ['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('is-over'); }); });
        zone.addEventListener('drop', function (e) {
            if (e.dataTransfer && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; chosen(input); }
        });
        input.addEventListener('change', function () { chosen(input); });
    });

    /* Figure slots */
    var list = $('.tt-fig-list'), tpl = $('#tt-fig-tpl'), addBtn = $('.tt-fig-add');
    var counter = list ? $$('.tt-fig-new', list).length : 0;
    var maxFigs = list ? parseInt(list.getAttribute('data-max'), 10) : 0;
    var kept = function () { return $$('input[name^="figkeep"]:checked', list).length; };
    var rowCount = function () { return $$('.tt-fig-new', list).filter(function (r) { var f = $('.tt-fig-file', r); return f && f.files && f.files.length; }).length + kept(); };
    var bindRow = function (row) {
        var file = $('.tt-fig-file', row), cap = $('input[name^="figcap"]', row), thumb = $('.tt-fig-thumb', row);
        file.addEventListener('change', function () {
            var f = file.files[0];
            if (!f) { return; }
            var p = problem(file, f);
            if (p) { showErrors([p]); file.value = ''; return; }
            showErrors([]);
            if (thumb && window.URL) {
                var img = document.createElement('img');
                img.className = 'tt-fig-thumb';
                img.alt = '';
                img.src = URL.createObjectURL(f);
                thumb.replaceWith(img);
                thumb = img;
            }
            if (cap && !cap.value) { cap.value = 'Figure ' + (rowCount()) + ': '; }
            if (!$('.tt-fig-new:last-child .tt-fig-file', list) || ($('.tt-fig-new:last-child .tt-fig-file', list).files || []).length) { /* keep a spare empty slot */ addRow(); }
        });
        var rm = $('.tt-fig-remove', row);
        if (rm) { rm.addEventListener('click', function () {
            if ($$('.tt-fig-new', list).length > 1) { row.remove(); } else { file.value = ''; $$('input[type="text"]', row).forEach(function (x) { x.value = ''; }); }
        }); }
    };
    var addRow = function () {
        if (!list || !tpl) { return null; }
        if (counter - 0 + kept() >= maxFigs) { return null; }
        var html = tpl.innerHTML.split('__i__').join(String(counter++));
        var holder = document.createElement('div');
        holder.innerHTML = html;
        var row = holder.firstElementChild;
        list.appendChild(row);
        bindRow(row);
        return row;
    };
    if (list) {
        $$('.tt-fig-new', list).forEach(bindRow);
        if (addBtn) { addBtn.addEventListener('click', function () { var r = addRow(); if (r) { $('.tt-fig-file', r).focus(); } }); }
        ['dragenter', 'dragover'].forEach(function (ev) { list.addEventListener(ev, function (e) { e.preventDefault(); list.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { list.addEventListener(ev, function (e) { e.preventDefault(); list.classList.remove('is-over'); }); });
        list.addEventListener('drop', function (e) {
            var files = e.dataTransfer ? Array.prototype.slice.call(e.dataTransfer.files) : [];
            var errs = [];
            files.forEach(function (f) {
                var row = $$('.tt-fig-new', list).filter(function (r) { return !($('.tt-fig-file', r).files || []).length; })[0] || addRow();
                if (!row) { errs.push(t('err_toomany', {n: maxFigs})); return; }
                var input = $('.tt-fig-file', row), p = problem(input, f);
                if (p) { errs.push(p); return; }
                var dt = new DataTransfer();
                dt.items.add(f);
                input.files = dt.files;
                input.dispatchEvent(new Event('change'));
            });
            showErrors(errs);
        });
    }

    /* Submit with progress. Falls back to a normal post if XHR / FormData are missing. */
    var uploading = false;
    window.addEventListener('beforeunload', function (e) { if (uploading) { e.preventDefault(); e.returnValue = ''; } });
    form.addEventListener('submit', function (e) {
        if (!window.XMLHttpRequest || !window.FormData) { return; }
        e.preventDefault();
        var errs = [];
        var doc = $('#tt-doc');
        if (!doc.files.length) { errs.push(t('err_nodoc')); } else { var p = problem(doc, doc.files[0]); if (p) { errs.push(p); } }
        $$('.tt-fig-row', list || form).forEach(function (row) {
            var f = $('.tt-fig-file', row);
            if (f && f.files.length && !$('input[name^="figcap"]', row).value.trim()) { errs.push(t('err_nocaption', {name: f.files[0].name})); }
        });
        showErrors(errs);
        if (errs.length) { return; }
        var btn = $('.tt-submit', form), bar = $('.tt-progress', form), fill = $('.tt-progress-bar', form), txt = $('.tt-progress-text', form);
        confirmDialog(form.getAttribute('data-confirm') || t('confirm_generic'), btn.textContent).then(function (ok) {
            if (!ok) { return; }
            var fd = new FormData(form);
            fd.append('ajax', '1');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', form.action);
            uploading = true;
            var label = btn.textContent;
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.textContent = btn.getAttribute('data-busy') || label;
            bar.hidden = false;
            var reset = function () { uploading = false; btn.disabled = false; btn.removeAttribute('aria-busy'); btn.textContent = label; bar.hidden = true; txt.textContent = ''; };
            xhr.upload.onprogress = function (ev) {
                if (!ev.lengthComputable) { return; }
                var pct = Math.round(100 * ev.loaded / ev.total);
                fill.style.width = pct + '%';
                bar.setAttribute('aria-valuenow', pct);
                txt.textContent = pct + '% · ' + fmtSize(ev.loaded) + ' / ' + fmtSize(ev.total);
            };
            xhr.onload = function () {
                var r = null;
                try { r = JSON.parse(xhr.responseText); } catch (x) { /* not json */ }
                if (r && r.ok) { uploading = false; window.location.href = r.redirect; return; }
                reset();
                showErrors(r && r.errors ? r.errors : [t('err_failed')]);
            };
            xhr.onerror = function () { reset(); showErrors([t('err_network')]); };
            xhr.send(fd);
        });
    });
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
