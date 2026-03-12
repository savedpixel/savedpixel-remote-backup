/* Remote Backup — admin progressive enhancement */
(function () {
    'use strict';

    var phaseLabels = {
        queued:   'Waiting for the background worker…',
        starting: 'Starting backup…',
        database: 'Backing up database…',
        files:    'Archiving files…',
        plugins:  'Archiving plugins…',
        remote:   'Uploading to remote storage…',
        complete: 'Finalizing…',
        failed:   'Backup failed.',
        idle:     'Waiting…',
    };

    /* ── AJAX backup with progress polling ────────────── */
    var statusPollTimer = null;
    var statusPollFailures = 0;
    var activeJobId = rbAdmin.activeJobId || '';

    function backupPageUrl() {
        return rbAdmin.backupPageUrl || window.location.href;
    }

    function queryOne(selector, root) {
        return (root || document).querySelector(selector);
    }

    function queryAll(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function updateProgressText(phase) {
        var text = queryOne('#sp-progress-text, #rb-progress-text');
        if (text) text.textContent = phaseLabels[phase] || phase || 'Processing backup…';
        var modalText = document.getElementById('rb-modal-phase');
        if (modalText) modalText.textContent = phaseLabels[phase] || phase || 'Processing backup…';
    }

    /* ── Backup modal overlay (full-screen) ──────────── */
    var backupModal = null;
    var backupModalOverlay = null;

    function createBackupModal() {
        removeBackupModal();

        backupModalOverlay = document.createElement('div');
        backupModalOverlay.id = 'rb-backup-modal-overlay';
        backupModalOverlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100099;';
        document.body.appendChild(backupModalOverlay);

        backupModal = document.createElement('div');
        backupModal.id = 'rb-backup-modal';
        backupModal.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px 24px;z-index:100100;box-shadow:0 2px 10px rgba(0,0,0,.15);min-width:300px;text-align:center;';

        backupModal.innerHTML = '<div id="rb-modal-icon" style="margin-bottom:12px;">'
            + '<svg id="rb-modal-spinner" width="36" height="36" viewBox="0 0 24 24" style="animation:rb-spin 1s linear infinite;color:#2271b1;">'
            + '<path d="M12 2a10 10 0 0 1 10 10h-3a7 7 0 0 0-7-7V2Z" fill="currentColor"/></svg>'
            + '<style>@keyframes rb-spin{to{transform:rotate(360deg)}}</style>'
            + '</div>'
            + '<h3 id="rb-modal-title" style="margin:0 0 6px;">Backup in Progress</h3>'
            + '<p id="rb-modal-phase" style="margin:0;color:#50575e;font-size:13px;">Starting backup…</p>';

        document.body.appendChild(backupModal);
    }

    function showBackupModalResult(msg, type) {
        if (!backupModal) return;

        var iconSvg;
        var titleText;
        if (type === 'success') {
            iconSvg = '<svg viewBox="0 0 24 24" width="48" height="48" style="color:#00a32a;">'
                + '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2Zm-2 15-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9Z" fill="currentColor"/></svg>';
            titleText = 'Backup Complete';
        } else {
            iconSvg = '<svg viewBox="0 0 24 24" width="48" height="48" style="color:#d63638;">'
                + '<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2Zm1 15h-2v-2h2v2Zm0-4h-2V7h2v6Z" fill="currentColor"/></svg>';
            titleText = type === 'error' ? 'Backup Failed' : 'Backup Finished';
        }

        backupModal.innerHTML = '<div id="rb-modal-result-icon" style="margin-bottom:12px;">' + iconSvg + '</div>'
            + '<h3 id="rb-modal-result-title" style="margin:0 0 8px;">' + titleText + '</h3>'
            + '<p id="rb-modal-result-message" style="margin:0 0 16px;color:#50575e;font-size:13px;">' + escHtml(msg) + '</p>'
            + '<div id="rb-modal-result-actions" style="display:flex;gap:8px;justify-content:center;">'
            + '<button id="rb-modal-close" type="button" class="button button-primary" style="min-width:90px;">Close</button>'
            + '</div>';

        document.getElementById('rb-modal-close').addEventListener('click', function () {
            removeBackupModal();
        });
    }

    function removeBackupModal() {
        if (backupModal) { backupModal.remove(); backupModal = null; }
        if (backupModalOverlay) { backupModalOverlay.remove(); backupModalOverlay = null; }
    }

    function ajaxErrorMessage(data) {
        if (!data) return 'Unknown error.';
        if (typeof data === 'string') return data;
        if (typeof data.message === 'string') return data.message;
        if (typeof data.data === 'string') return data.data;
        if (data.data && typeof data.data.message === 'string') return data.data.message;
        return 'Unknown error.';
    }

    function responseErrorMessage(response, text) {
        var snippet = (text || '').replace(/\s+/g, ' ').trim();
        if (response.status === 504) {
            return 'Backup request timed out on the server (504 Gateway Timeout). The backup may still be running in the background.';
        }
        if (!snippet) {
            return 'Request failed with ' + response.status + ' ' + response.statusText + '.';
        }
        if (snippet.charAt(0) === '<') {
            return 'Request failed with ' + response.status + ' ' + response.statusText + '. The server returned HTML instead of JSON.';
        }
        if (snippet.length > 180) {
            snippet = snippet.slice(0, 177) + '…';
        }
        return 'Request failed with ' + response.status + ' ' + response.statusText + ': ' + snippet;
    }

    function parseAjaxResponse(response) {
        var contentType = response.headers.get('content-type') || '';
        if (contentType.indexOf('application/json') !== -1) {
            return response.json();
        }

        return response.text().then(function (text) {
            throw new Error(responseErrorMessage(response, text));
        });
    }

    function postAjax(body) {
        return fetch(rbAdmin.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(parseAjaxResponse);
    }

    /** Collect smart folder paths (parent if all children selected, else individual child paths). */
    function collectFolders() {
        var result = [];
        queryAll('.sp-folder-cb:checked, .rb-folder-cb:checked').forEach(function (cb) {
            if (cb.getAttribute('data-children') === '1') {
                var node = cb.closest('.sp-tree-node, .rb-tree-node');
                var allKids = node.querySelectorAll('.sp-child-cb, .rb-child-cb');
                var checkedKids = node.querySelectorAll('.sp-child-cb:checked, .rb-child-cb:checked');
                if (allKids.length === 0 || allKids.length === checkedKids.length) {
                    result.push(cb.value);
                }
            } else if (!cb.classList.contains('sp-child-cb') && !cb.classList.contains('rb-child-cb')) {
                result.push(cb.value);
            }
        });
        queryAll('.sp-child-cb:checked, .rb-child-cb:checked').forEach(function (cb) {
            var parentVal = cb.getAttribute('data-parent');
            var parentCb = queryOne('.sp-folder-cb[value="' + parentVal + '"], .rb-folder-cb[value="' + parentVal + '"]');
            if (!parentCb) return;
            var node = parentCb.closest('.sp-tree-node, .rb-tree-node');
            var allKids = node.querySelectorAll('.sp-child-cb, .rb-child-cb');
            var checkedKids = node.querySelectorAll('.sp-child-cb:checked, .rb-child-cb:checked');
            if (allKids.length !== checkedKids.length) {
                result.push(cb.value);
            }
        });
        return result;
    }

    function refreshBackupPanels() {
        return fetch(backupPageUrl(), { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                replacePanel('.sp-card--history, .rb-card--history', doc);
                replacePanel('.sp-card--log, .rb-card--log', doc);
            });
    }

    function replacePanel(selector, doc) {
        var current = document.querySelector(selector);
        var fresh = doc.querySelector(selector);
        if (current && fresh) {
            current.replaceWith(fresh);
        }
    }

    function pollBackupStatus() {
        if (!activeJobId) return Promise.resolve(null);

        var body = new FormData();
        body.append('action', 'rb_backup_status');
        body.append('_nonce', rbAdmin.nonce);
        body.append('job_id', activeJobId);

        return postAjax(body)
            .then(function (d) {
                if (!d.success || !d.data) {
                    throw new Error(ajaxErrorMessage(d));
                }

                handleBackupStatus(d.data);
                statusPollFailures = 0;
                return d.data;
            })
            .catch(function (err) {
                statusPollFailures += 1;
                if (statusPollFailures < 3) {
                    updateProgressText('queued');
                    return null;
                }

                stopStatusPolling();
                setBackupUiBusy(false);
                removeBackupModal();
                showResult('Backup status check failed: ' + err.message, 'error');
                return refreshBackupPanels().catch(function () {});
            });
    }

    function handleBackupStatus(data) {
        var status = data.status || 'idle';
        var phase = data.phase || (status === 'queued' ? 'queued' : 'starting');

        updateProgressText(phase);

        if (status === 'queued' || status === 'running') {
            return;
        }

        stopStatusPolling();
        setBackupUiBusy(false);

        var resultType = 'warning';
        var resultMsg = data.message || 'Backup finished.';

        if (status === 'success') {
            resultType = data.noticeType || 'success';
            resultMsg = data.message || 'Backup completed.';
        } else if (status === 'failed') {
            resultType = 'error';
            resultMsg = data.message || 'Backup failed.';
        } else if (status === 'missing') {
            resultMsg = data.message || 'Backup job state was not found.';
        } else if (status !== 'idle') {
            resultMsg = data.message || 'Backup finished with an unexpected status.';
        }

        showBackupModalResult(resultMsg, resultType);
        showResult(resultMsg, resultType);

        activeJobId = '';
        return refreshBackupPanels().catch(function () {});
    }

    function startStatusPolling(jobId) {
        activeJobId = jobId || activeJobId;
        statusPollFailures = 0;
        setBackupUiBusy(true);
        updateProgressText('queued');
        stopStatusPolling();
        pollBackupStatus();
        statusPollTimer = setInterval(pollBackupStatus, 2000);
    }

    function stopStatusPolling() {
        if (statusPollTimer) {
            clearInterval(statusPollTimer);
            statusPollTimer = null;
        }
    }

    function probeActiveJob() {
        var body = new FormData();
        body.append('action', 'rb_backup_status');
        body.append('_nonce', rbAdmin.nonce);

        return postAjax(body)
            .then(function (d) {
                if (!d.success || !d.data || !d.data.jobId) {
                    return false;
                }

                if (d.data.status === 'queued' || d.data.status === 'running') {
                    startStatusPolling(d.data.jobId);
                    return true;
                }

                return false;
            })
            .catch(function () {
                return false;
            });
    }

    function setBackupUiBusy(isBusy) {
        var overlay = queryOne('#sp-progress-overlay, #rb-progress-overlay');
        var actions = queryOne('#sp-manual-actions, #rb-manual-actions');
        queryAll('.sp-ajax-backup, .rb-ajax-backup').forEach(function (b) { b.disabled = isBusy; });
        if (overlay) overlay.style.display = isBusy ? '' : 'none';
        if (actions) actions.style.opacity = isBusy ? '0.5' : '';
        if (isBusy) {
            createBackupModal();
        }
    }

    queryAll('.sp-ajax-backup, .rb-ajax-backup').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var scope = btn.getAttribute('data-scope');
            var remoteMode = document.getElementById('rb_manual_remote_mode');

            setBackupUiBusy(true);
            updateProgressText('queued');

            var body = new FormData();
            body.append('action', 'rb_run_backup');
            body.append('_nonce', rbAdmin.nonce);
            body.append('scope', scope);
            body.append('remote_mode', remoteMode ? remoteMode.value : 'local');

            // Attach selected folders when scope includes files.
            if (scope === 'files' || scope === 'both') {
                collectFolders().forEach(function (f) { body.append('folders[]', f); });
            }

            postAjax(body)
                .then(function (d) {
                    if (!d.success || !d.data) {
                        throw new Error(ajaxErrorMessage(d));
                    }

                    if (!d.data.jobId) {
                        throw new Error('The backup worker did not return a job ID.');
                    }

                    showResult(d.data.message || 'Backup started. The page will update automatically.', d.data.noticeType || 'info');
                    startStatusPolling(d.data.jobId);
                })
                .catch(function (err) {
                    probeActiveJob().then(function (jobFound) {
                        if (jobFound) {
                            showResult('The first request did not return cleanly, but the backup is running in the background.', 'warning');
                            return;
                        }

                        stopStatusPolling();
                        setBackupUiBusy(false);
                        removeBackupModal();
                        showResult('Request failed: ' + err.message, 'error');
                        refreshBackupPanels().catch(function () {});
                    });
                });
        });
    });

    function showResult(msg, type) {
        showNotice(msg, type);
        showToast(msg, type);
    }

    function showNotice(msg, type) {
        var wrap = queryOne('.sp-wrap, .rb-wrap');
        if (!wrap) return;
        var anchor = queryOne('.sp-page-header, .rb-title', wrap);
        wrap.querySelectorAll('.rb-runtime-notice').forEach(function (el) { el.remove(); });
        var div = document.createElement('div');
        div.className = 'notice notice-' + type + ' is-dismissible rb-runtime-notice';
        div.innerHTML = '<p>' + escHtml(msg) + '</p>';
        if (anchor && anchor.nextSibling) {
            wrap.insertBefore(div, anchor.nextSibling);
        } else {
            wrap.appendChild(div);
        }
        div.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function toastStack() {
        var stack = document.querySelector('.rb-toast-stack');
        if (stack) return stack;

        stack = document.createElement('div');
        stack.className = 'rb-toast-stack';
        document.body.appendChild(stack);
        return stack;
    }

    function showToast(msg, type) {
        var toast = document.createElement('div');
        var close = document.createElement('button');
        var body = document.createElement('div');
        var timeout = type === 'error' ? 9000 : 5000;

        toast.className = 'rb-toast rb-toast--' + (type || 'info');
        body.className = 'rb-toast__body';
        body.textContent = msg;

        close.className = 'rb-toast__close';
        close.type = 'button';
        close.setAttribute('aria-label', 'Dismiss notification');
        close.textContent = '×';

        close.addEventListener('click', function () {
            toast.remove();
        });

        toast.appendChild(body);
        toast.appendChild(close);
        toastStack().appendChild(toast);

        window.setTimeout(function () {
            toast.remove();
        }, timeout);
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(s));
        return d.innerHTML;
    }

    if (activeJobId && (rbAdmin.activeJobStatus === 'queued' || rbAdmin.activeJobStatus === 'running')) {
        startStatusPolling(activeJobId);
    }

    /* ── Remote protocol / auth toggles ──────────────── */
    var protocolSelect = document.getElementById('rb_remote_protocol');
    var authSelect = document.getElementById('rb_ssh_auth_method');

    function toggleProtocol() {
        if (!protocolSelect) return;
        var protocol = protocolSelect.value;
        queryAll('.sp-protocol-ssh, .rb-protocol-ssh').forEach(function (row) {
            row.style.display = protocol === 'ssh' ? '' : 'none';
        });
        queryAll('.sp-protocol-ftp, .rb-protocol-ftp').forEach(function (row) {
            row.style.display = protocol === 'ftp' ? '' : 'none';
        });
    }

    function toggleAuth() {
        if (!authSelect) return;
        var protocol = protocolSelect ? protocolSelect.value : 'ssh';
        var method = authSelect.value;
        queryAll('.sp-auth-key, .rb-auth-key').forEach(function (r) {
            r.style.display = protocol === 'ssh' && method === 'key' ? '' : 'none';
        });
        queryAll('.sp-auth-password, .rb-auth-password').forEach(function (r) {
            r.style.display = protocol === 'ssh' && method === 'password' ? '' : 'none';
        });
    }
    if (protocolSelect) {
        protocolSelect.addEventListener('change', function () {
            toggleProtocol();
            toggleAuth();
        });
    }
    if (authSelect) {
        authSelect.addEventListener('change', toggleAuth);
    }
    toggleProtocol();
    toggleAuth();

    /* ── Schedule weekday toggles ────────────────────── */
    function toggleWeeklyScheduleRow(scope) {
        var select = document.getElementById('rb_schedule_' + scope + '_frequency');
        var row = document.getElementById('rb_schedule_' + scope + '_weekday_row');
        if (!select || !row) return;
        row.style.display = select.value === 'weekly' ? '' : 'none';
    }

    ['database', 'files'].forEach(function (scope) {
        var select = document.getElementById('rb_schedule_' + scope + '_frequency');
        if (!select) return;
        select.addEventListener('change', function () {
            toggleWeeklyScheduleRow(scope);
        });
        toggleWeeklyScheduleRow(scope);
    });

    /* ── Folder tree controls ─────────────────────────── */

    // Toggle expand/collapse.
    queryAll('.sp-tree-toggle, .rb-tree-toggle').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            var node = toggle.closest('.sp-tree-node, .rb-tree-node');
            var children = node.querySelector('.sp-tree-children, .rb-tree-children');
            if (!children) return;
            var open = children.style.display !== 'none';
            children.style.display = open ? 'none' : '';
            toggle.classList.toggle('is-open', !open);
            toggle.classList.toggle('rb-open', !open);
        });
    });

    // Parent checkbox → sync children.
    queryAll('.sp-folder-cb[data-children="1"], .rb-folder-cb[data-children="1"]').forEach(function (parentCb) {
        parentCb.addEventListener('change', function () {
            var node = parentCb.closest('.sp-tree-node, .rb-tree-node');
            node.querySelectorAll('.sp-child-cb, .rb-child-cb').forEach(function (child) {
                child.checked = parentCb.checked;
            });
        });
    });

    // Child checkbox → update parent state.
    queryAll('.sp-child-cb, .rb-child-cb').forEach(function (childCb) {
        childCb.addEventListener('change', function () {
            var parentVal = childCb.getAttribute('data-parent');
            var parentCb = queryOne('.sp-folder-cb[value="' + parentVal + '"], .rb-folder-cb[value="' + parentVal + '"]');
            if (!parentCb) return;
            var node = parentCb.closest('.sp-tree-node, .rb-tree-node');
            var all = node.querySelectorAll('.sp-child-cb, .rb-child-cb');
            var checkedCount = node.querySelectorAll('.sp-child-cb:checked, .rb-child-cb:checked').length;
            parentCb.checked = checkedCount > 0;
            parentCb.indeterminate = checkedCount > 0 && checkedCount < all.length;
        });
    });

    // Set initial indeterminate state.
    queryAll('.sp-folder-cb[data-children="1"], .rb-folder-cb[data-children="1"]').forEach(function (parentCb) {
        var node = parentCb.closest('.sp-tree-node, .rb-tree-node');
        var all = node.querySelectorAll('.sp-child-cb, .rb-child-cb');
        var checkedCount = node.querySelectorAll('.sp-child-cb:checked, .rb-child-cb:checked').length;
        if (checkedCount > 0 && checkedCount < all.length) {
            parentCb.indeterminate = true;
        }
    });

    var allBtn  = queryOne('#sp-folders-all, #rb-folders-all');
    var noneBtn = queryOne('#sp-folders-none, #rb-folders-none');
    var saveBtn = queryOne('#sp-folders-save, #rb-folders-save');

    if (allBtn) {
        allBtn.addEventListener('click', function () {
            queryAll('.sp-folder-cb, .rb-folder-cb').forEach(function (cb) {
                cb.checked = true; cb.indeterminate = false;
            });
        });
    }
    if (noneBtn) {
        noneBtn.addEventListener('click', function () {
            queryAll('.sp-folder-cb, .rb-folder-cb').forEach(function (cb) {
                cb.checked = false; cb.indeterminate = false;
            });
        });
    }
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var body = new FormData();
            body.append('action', 'rb_save_folders');
            body.append('_nonce', rbAdmin.nonce);
            collectFolders().forEach(function (f) { body.append('folders[]', f); });

            postAjax(body)
                .then(function (d) {
                    var saved = queryOne('#sp-folders-saved, #rb-folders-saved');
                    if (d.success && saved) {
                        saved.style.display = '';
                        setTimeout(function () { saved.style.display = 'none'; }, 2000);
                        return;
                    }

                    throw new Error(ajaxErrorMessage(d));
                })
                .catch(function (err) {
                    showResult('Folder selection could not be saved: ' + err.message, 'error');
                });
        });
    }

    /* ── Monitor page actions / live refresh ─────────── */
    var monitorConfig = rbAdmin.monitor || null;
    var monitorSnapshotTimer = null;
    var monitorProgressTimer = null;

    function monitorEnabled() {
        return !!(monitorConfig && monitorConfig.enabled);
    }

    function monitorExpandedUrls() {
        var expanded = [];
        queryAll('.sp-history-detail[data-url], .rb-history-detail[data-url]').forEach(function (row) {
            if (row.style.display !== 'none') {
                expanded.push(row.getAttribute('data-url'));
            }
        });
        return expanded;
    }

    function restoreMonitorExpandedUrls(urls) {
        (urls || []).forEach(function (url) {
            var row = queryOne('.sp-history-detail[data-url="' + url + '"], .rb-history-detail[data-url="' + url + '"]');
            if (row) {
                row.style.display = 'table-row';
            }
        });
    }

    function replaceMonitorSnapshot(data) {
        var expanded = monitorExpandedUrls();
        var summary = queryOne('#sp-monitor-summary, #rb-monitor-summary');
        var sites = queryOne('#sp-monitor-sites-section, #rb-monitor-sites');

        if (summary && typeof data.summaryHtml === 'string') {
            summary.innerHTML = data.summaryHtml;
        }

        if (sites && typeof data.sitesHtml === 'string') {
            var wrapper = document.createElement('div');
            wrapper.innerHTML = data.sitesHtml;
            if (wrapper.firstElementChild) {
                sites.replaceWith(wrapper.firstElementChild);
            }
        }

        restoreMonitorExpandedUrls(expanded);
    }

    function replaceMonitorProgress(data) {
        var panel = queryOne('#sp-monitor-progress-panel, #rb-monitor-progress-panel');
        if (!panel || typeof data.progressHtml !== 'string') return;

        var wrapper = document.createElement('div');
        wrapper.innerHTML = data.progressHtml;
        var next = wrapper.firstElementChild;
        if (next) {
            panel.replaceWith(next);
        }
    }

    function stopMonitorPolling() {
        if (monitorSnapshotTimer) {
            clearInterval(monitorSnapshotTimer);
            monitorSnapshotTimer = null;
        }
        if (monitorProgressTimer) {
            clearInterval(monitorProgressTimer);
            monitorProgressTimer = null;
        }
    }

    function refreshMonitorSnapshot() {
        if (!monitorEnabled()) return Promise.resolve(null);

        var body = new FormData();
        body.append('action', 'rb_monitor_snapshot');
        body.append('_nonce', rbAdmin.nonce);

        return postAjax(body)
            .then(function (d) {
                if (!d.success || !d.data) {
                    throw new Error(ajaxErrorMessage(d));
                }

                replaceMonitorSnapshot(d.data);
                return d.data;
            })
            .catch(function (err) {
                if (monitorSnapshotTimer) {
                    clearInterval(monitorSnapshotTimer);
                    monitorSnapshotTimer = null;
                }
                showResult('Monitor snapshot refresh failed: ' + err.message, 'error');
                return null;
            });
    }

    function refreshMonitorProgress() {
        if (!monitorEnabled()) return Promise.resolve(null);

        var body = new FormData();
        body.append('action', 'rb_monitor_progress');
        body.append('_nonce', rbAdmin.nonce);

        return postAjax(body)
            .then(function (d) {
                if (!d.success || !d.data) {
                    throw new Error(ajaxErrorMessage(d));
                }

                replaceMonitorProgress(d.data);
                if (!d.data.active) {
                    if (monitorProgressTimer) {
                        clearInterval(monitorProgressTimer);
                        monitorProgressTimer = null;
                    }
                    refreshMonitorSnapshot();
                }
                return d.data;
            })
            .catch(function (err) {
                if (monitorProgressTimer) {
                    clearInterval(monitorProgressTimer);
                    monitorProgressTimer = null;
                }
                showResult('Monitor progress refresh failed: ' + err.message, 'error');
                return null;
            });
    }

    function startMonitorPolling() {
        if (!monitorEnabled()) return;
        refreshMonitorSnapshot();
        refreshMonitorProgress();
        if (!monitorSnapshotTimer) {
            monitorSnapshotTimer = setInterval(refreshMonitorSnapshot, monitorConfig.snapshotInterval || 5000);
        }
        if (!monitorProgressTimer) {
            monitorProgressTimer = setInterval(refreshMonitorProgress, monitorConfig.progressInterval || 1000);
        }
    }

    document.addEventListener('click', function (e) {
        var actionLink = e.target.closest('.sp-monitor-action, .rb-monitor-action, .rbm-monitor-action');
        if (actionLink && monitorEnabled()) {
            e.preventDefault();

            var body = new FormData();
            body.append('action', 'rb_monitor_action');
            body.append('_nonce', rbAdmin.nonce);
            body.append('monitor_action', actionLink.getAttribute('data-monitor-action') || '');
            body.append('url', actionLink.getAttribute('data-url') || '');

            actionLink.classList.add('is-busy');

            postAjax(body)
                .then(function (d) {
                    if (!d.success || !d.data) {
                        throw new Error(ajaxErrorMessage(d));
                    }

                    var monitorAction = actionLink.getAttribute('data-monitor-action') || '';
                    if (monitorAction === 'cancel_transfer') {
                        showResult(d.data.message || 'Transfer cancelled.', 'warning');
                        refreshMonitorProgress();
                    } else {
                        showResult(d.data.message || 'Monitor action started.', 'info');
                        startMonitorPolling();
                    }
                })
                .catch(function (err) {
                    showResult('Monitor action failed: ' + err.message, 'error');
                })
                .finally(function () {
                    actionLink.classList.remove('is-busy');
                });

            return;
        }

        var row = e.target.closest('.sp-monitor-row, .rb-monitor-row');
        if (!row || e.target.closest('a, button')) return;

        var url = row.getAttribute('data-url');
        var detail = queryOne('.sp-history-detail[data-url="' + url + '"], .rb-history-detail[data-url="' + url + '"]');
        if (detail) {
            detail.style.display = detail.style.display === 'none' ? 'table-row' : 'none';
        }
    });

    if (monitorEnabled() && monitorConfig.active) {
        startMonitorPolling();
    }
})();
