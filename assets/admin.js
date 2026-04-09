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
    var statusPollCount = 0;
    var lastSeenPhase = '';
    var activeJobId = rbAdmin.activeJobId || '';
    var lastBackupSizes = { db: 0, files: 0, total: 0 };
    var lastProgressSnapshot = null;
    var inlineProgressVisible = false;

    function backupPageUrl() {
        return rbAdmin.backupPageUrl || window.location.href;
    }

    function queryOne(selector, root) {
        return (root || document).querySelector(selector);
    }

    function queryAll(selector, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(selector));
    }

    function formatBytes(bytes) {
        if (!bytes || bytes <= 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = 0;
        var val = bytes;
        while (val >= 1024 && i < units.length - 1) { val /= 1024; i++; }
        return (i === 0 ? val : val.toFixed(1)) + ' ' + units[i];
    }

    function updateProgressText(phase) {
        var text = queryOne('#sp-progress-text, #sprb-progress-text');
        if (text) text.textContent = phaseLabels[phase] || phase || 'Processing backup…';
        var modalText = document.getElementById('sprb-modal-phase');
        if (modalText) modalText.textContent = phaseLabels[phase] || phase || 'Processing backup…';
    }

    function shouldShowInlineProgress() {
        var popupOpen = popupOverlay && popupOverlay.style.display !== 'none';
        return inlineProgressVisible && !backupModal && !popupOpen;
    }

    function updateInlineProgress(data) {
        var overlay = queryOne('#sp-progress-overlay, #sprb-progress-overlay');
        if (!overlay) return;

        if (!shouldShowInlineProgress()) {
            overlay.style.display = 'none';
            return;
        }

        var expectedSize = data.expectedSize || 0;
        var totalSize = data.progressSize || 0;
        var pctValue = 0;

        if (expectedSize > 0 && totalSize > 0) {
            pctValue = Math.min(Math.round((totalSize / expectedSize) * 100), 100);
        }

        overlay.style.display = '';
        overlay.innerHTML = ''
            + '<div id="sp-progress-inline-shell" class="sp-progress-inline-shell">'
            + '<div class="sp-progress-inline-header">'
            + '<div class="sp-progress-inline-title">'
            + '<span id="sp-progress-icon" class="dashicons dashicons-update sp-spin sp-progress-inline-icon"></span>'
            + '<span id="sp-progress-text" class="sp-progress-inline-phase">' + escHtml(phaseLabels[data.phase] || data.phase || 'Processing backup…') + '</span>'
            + '</div>'
            + '<div id="sp-progress-inline-pct" class="sp-progress-inline-pct">' + pctValue + '%</div>'
            + '</div>'
            + '<div id="sp-progress-inline-bar" class="sp-progress-inline-bar">'
            + '<div id="sp-progress-inline-bar-fill" class="sp-progress-inline-bar-fill" style="width:' + pctValue + '%;"></div>'
            + '</div>'
            + '<div id="sp-progress-inline-sizes" class="sp-progress-inline-sizes">'
            + '<span class="sp-progress-inline-stat"><span class="sp-progress-inline-stat-label">Database</span><span id="sp-progress-inline-db" class="sp-progress-inline-stat-value">' + (data.dbSize > 0 ? formatBytes(data.dbSize) : '—') + '</span></span>'
            + '<span class="sp-progress-inline-stat"><span class="sp-progress-inline-stat-label">Files</span><span id="sp-progress-inline-files" class="sp-progress-inline-stat-value">' + (data.filesSize > 0 ? formatBytes(data.filesSize) : '—') + '</span></span>'
            + '<span class="sp-progress-inline-stat sp-progress-inline-stat--total"><span class="sp-progress-inline-stat-label">Total</span><span id="sp-progress-inline-total" class="sp-progress-inline-stat-value">' + (totalSize > 0 ? formatBytes(totalSize) : '—') + '</span></span>'
            + '</div>'
            + '</div>';
    }

    function updateModalProgress(data) {
        var dbRow = document.getElementById('sprb-modal-db-size');
        var filesRow = document.getElementById('sprb-modal-files-size');
        var totalRow = document.getElementById('sprb-modal-total-size');
        var barFill = document.getElementById('sprb-modal-bar-fill');

        var dbSize = data.dbSize || 0;
        var filesSize = data.filesSize || 0;
        var totalSize = data.progressSize || 0;

        lastBackupSizes = { db: dbSize, files: filesSize, total: totalSize };
        lastProgressSnapshot = {
            phase: data.phase,
            dbSize: dbSize,
            filesSize: filesSize,
            progressSize: totalSize,
            expectedSize: data.expectedSize || 0
        };

        if (dbRow) dbRow.textContent = dbSize > 0 ? formatBytes(dbSize) : '—';
        if (filesRow) filesRow.textContent = filesSize > 0 ? formatBytes(filesSize) : '—';
        if (totalRow) totalRow.textContent = totalSize > 0 ? formatBytes(totalSize) : '—';

        // Compute percentage from actual sizes when expected total is available.
        var expectedSize = data.expectedSize || 0;
        var pctValue = 0;
        if (expectedSize > 0 && totalSize > 0) {
            pctValue = Math.min(Math.round((totalSize / expectedSize) * 100), 100);
        } else {
            var phasePercent = {
                queued: 0, starting: 2, database: 10, files: 50, plugins: 80, remote: 92, complete: 100, failed: 100
            };
            pctValue = phasePercent[data.phase] || 0;
        }

        var pctEl = document.getElementById('sprb-modal-pct');
        if (pctEl) pctEl.textContent = pctValue + '%';
        if (barFill) barFill.style.width = pctValue + '%';

        updateInlineProgress(lastProgressSnapshot);
    }

    /* ── Backup modal overlay (full-screen) ──────────── */
    var backupModal = null;
    var backupModalOverlay = null;

    function createBackupModal() {
        removeBackupModal();

        backupModalOverlay = document.createElement('div');
        backupModalOverlay.id = 'sprb-backup-modal-overlay';
        backupModalOverlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:100099;';
        document.body.appendChild(backupModalOverlay);

        backupModal = document.createElement('div');
        backupModal.id = 'sprb-backup-modal';
        backupModal.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border:1px solid #ccd0d4;border-radius:4px;padding:20px 24px;z-index:100100;box-shadow:0 2px 10px rgba(0,0,0,.15);min-width:300px;text-align:center;';

        backupModal.innerHTML = '<div id="sprb-modal-icon" style="margin-bottom:12px;">'
            + '<svg id="sprb-modal-spinner" width="36" height="36" viewBox="0 0 24 24" style="animation:sprb-spin 1s linear infinite;color:#2271b1;">'
            + '<path d="M12 2a10 10 0 0 1 10 10h-3a7 7 0 0 0-7-7V2Z" fill="currentColor"/></svg>'
            + '<style>@keyframes sprb-spin{to{transform:rotate(360deg)}}</style>'
            + '</div>'
            + '<h3 id="sprb-modal-title" style="margin:0 0 6px;">Backup in Progress</h3>'
            + '<p id="sprb-modal-phase" style="margin:0 0 6px;color:#50575e;font-size:13px;">Starting backup…</p>'
            + '<p id="sprb-modal-pct" style="margin:0 0 8px;font-size:24px;font-weight:600;color:#1d2327;font-variant-numeric:tabular-nums;">0%</p>'
            + '<div id="sprb-modal-bar" style="background:#e2e4e7;border-radius:3px;height:6px;margin:0 0 14px;overflow:hidden;">'
            + '<div id="sprb-modal-bar-fill" style="background:#2271b1;height:100%;width:0%;transition:width .4s ease;"></div>'
            + '</div>'
            + '<table id="sprb-modal-sizes" style="width:100%;font-size:12px;color:#50575e;border-collapse:collapse;">'
            + '<tr><td style="text-align:left;padding:3px 0;">Database</td><td id="sprb-modal-db-size" style="text-align:right;padding:3px 0;font-variant-numeric:tabular-nums;">—</td></tr>'
            + '<tr><td style="text-align:left;padding:3px 0;">Files</td><td id="sprb-modal-files-size" style="text-align:right;padding:3px 0;font-variant-numeric:tabular-nums;">—</td></tr>'
            + '<tr style="border-top:1px solid #e2e4e7;"><td style="text-align:left;padding:6px 0 0;font-weight:600;">Total</td><td id="sprb-modal-total-size" style="text-align:right;padding:6px 0 0;font-weight:600;font-variant-numeric:tabular-nums;">—</td></tr>'
            + '</table>';

        document.body.appendChild(backupModal);

        var dismissBtn = document.createElement('button');
        dismissBtn.id = 'sprb-modal-dismiss';
        dismissBtn.type = 'button';
        dismissBtn.className = 'button';
        dismissBtn.style.cssText = 'margin-top:14px;';
        dismissBtn.textContent = 'Dismiss';
        dismissBtn.addEventListener('click', function () {
            dismissBackupModalToInline();
        });
        backupModal.appendChild(dismissBtn);
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

        var sizeSummary = '';
        if (type === 'success' && lastBackupSizes.total > 0) {
            sizeSummary = '<table id="sprb-modal-result-sizes" style="width:100%;font-size:12px;color:#50575e;border-collapse:collapse;margin:0 0 14px;">'
                + '<tr><td style="text-align:left;padding:3px 0;">Database</td><td style="text-align:right;padding:3px 0;font-variant-numeric:tabular-nums;">' + formatBytes(lastBackupSizes.db) + '</td></tr>'
                + '<tr><td style="text-align:left;padding:3px 0;">Files</td><td style="text-align:right;padding:3px 0;font-variant-numeric:tabular-nums;">' + formatBytes(lastBackupSizes.files) + '</td></tr>'
                + '<tr style="border-top:1px solid #e2e4e7;"><td style="text-align:left;padding:6px 0 0;font-weight:600;">Total</td><td style="text-align:right;padding:6px 0 0;font-weight:600;font-variant-numeric:tabular-nums;">' + formatBytes(lastBackupSizes.total) + '</td></tr>'
                + '</table>';
        }

        backupModal.innerHTML = '<div id="sprb-modal-result-icon" style="margin-bottom:12px;">' + iconSvg + '</div>'
            + '<h3 id="sprb-modal-result-title" style="margin:0 0 8px;">' + titleText + '</h3>'
            + '<p id="sprb-modal-result-message" style="margin:0 0 ' + (sizeSummary ? '10' : '16') + 'px;color:#50575e;font-size:13px;">' + escHtml(msg) + '</p>'
            + sizeSummary
            + '<div id="sprb-modal-result-actions" style="display:flex;gap:8px;justify-content:center;">'
            + '<button id="sprb-modal-close" type="button" class="button button-primary" style="min-width:90px;">Close</button>'
            + '</div>';

        document.getElementById('sprb-modal-close').addEventListener('click', function () {
            removeBackupModal();
        });
    }

    function removeBackupModal() {
        if (backupModal) { backupModal.remove(); backupModal = null; }
        if (backupModalOverlay) { backupModalOverlay.remove(); backupModalOverlay = null; }
    }

    function dismissBackupModalToInline() {
        inlineProgressVisible = true;
        removeBackupModal();
        if (lastProgressSnapshot) {
            updateInlineProgress(lastProgressSnapshot);
        }
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

    /**
     * Collect selected folder paths for backup.
     * If a parent is checked and ALL its loaded children are checked, emit only the parent path.
     * Otherwise emit individual checked leaf/child paths.
     */
    function collectFolders() {
        var result = [];
        var tree = document.getElementById('sp-folder-tree');
        if (!tree) return result;

        function collectNode(node) {
            var cb = node.querySelector(':scope > .sp-tree-row .sp-folder-cb');
            if (!cb) return;
            if (!cb.checked && !cb.indeterminate) return;

            var childContainer = node.querySelector(':scope > .sp-tree-children');
            var childNodes = childContainer ? childContainer.querySelectorAll(':scope > .sp-tree-node') : [];

            // Leaf node or no children loaded — emit directly if checked.
            if (!childContainer || childNodes.length === 0) {
                if (cb.checked) result.push(cb.value);
                return;
            }

            // Has children: check if all are checked.
            var allChecked = true;
            var childCbs = childContainer.querySelectorAll(':scope > .sp-tree-node > .sp-tree-row .sp-folder-cb');
            childCbs.forEach(function (ccb) {
                if (!ccb.checked) allChecked = false;
            });

            if (allChecked && childCbs.length > 0) {
                // All children checked — just emit parent.
                result.push(cb.value);
            } else {
                // Recurse into children for partial selection.
                childNodes.forEach(function (cn) { collectNode(cn); });
            }
        }

        tree.querySelectorAll(':scope > .sp-tree-node').forEach(function (topNode) {
            collectNode(topNode);
        });

        return result;
    }

    function refreshBackupPanels() {
        return fetch(backupPageUrl(), { credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                replacePanel('.sp-card--history, .sprb-card--history', doc);
                replacePanel('.sp-card--log, .sprb-card--log', doc);
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
        body.append('action', 'sprb_backup_status');
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
        updateModalProgress(data);

        statusPollCount++;
        if (phase !== lastSeenPhase) {
            statusPollCount = 0;
            lastSeenPhase = phase;
        }

        if (status === 'queued' || status === 'running') {
            return;
        }

        stopStatusPolling();
        inlineProgressVisible = false;
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
        statusPollCount = 0;
        lastSeenPhase = '';
        inlineProgressVisible = false;
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
        body.append('action', 'sprb_backup_status');
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
        var overlay = queryOne('#sp-progress-overlay, #sprb-progress-overlay');
        var actions = queryOne('#sp-manual-actions, #sprb-manual-actions');
        var backupNowBtn = document.getElementById('sprb-backup-now-btn');
        queryAll('.sp-ajax-backup, .sprb-ajax-backup').forEach(function (b) { b.disabled = isBusy; });
        if (backupNowBtn) backupNowBtn.disabled = isBusy;
        if (overlay) overlay.style.display = isBusy && shouldShowInlineProgress() ? '' : 'none';
        if (actions) actions.style.opacity = isBusy ? '0.5' : '';
        if (isBusy) {
            createBackupModal();
        } else if (overlay) {
            overlay.style.display = 'none';
        }
    }

    /* ── Backup Now popup ─────────────────────────────── */
    var popupOverlay = document.getElementById('sprb-backup-popup-overlay');
    var backupNowBtn = document.getElementById('sprb-backup-now-btn');
    var popupCloseBtn = document.getElementById('sprb-backup-popup-close');
    var popupCancelBtn = document.getElementById('sprb-backup-popup-cancel');
    var popupStartBtn = document.getElementById('sprb-backup-popup-start');

    function openBackupPopup() {
        if (popupOverlay) popupOverlay.style.display = '';
    }

    function closeBackupPopup() {
        if (popupOverlay) popupOverlay.style.display = 'none';
    }

    if (backupNowBtn) {
        backupNowBtn.addEventListener('click', openBackupPopup);
    }
    if (popupCloseBtn) {
        popupCloseBtn.addEventListener('click', closeBackupPopup);
    }
    if (popupCancelBtn) {
        popupCancelBtn.addEventListener('click', closeBackupPopup);
    }
    if (popupOverlay) {
        popupOverlay.addEventListener('click', function (e) {
            if (e.target === popupOverlay) closeBackupPopup();
        });
    }

    var saveSettingsBtn = document.getElementById('sprb-save-settings-btn');
    var headerSaveForm = document.getElementById('sprb-header-save-form');
    var headerSavePayload = document.getElementById('sprb-header-save-payload');
    var scheduleFormDb = document.getElementById('sprb-schedule-form-db');
    var scheduleFormFiles = document.getElementById('sprb-schedule-form-files');
    var remoteForm = document.getElementById('sprb-remote-form');
    var pullForm = document.getElementById('sprb-pull-form');

    function appendHeaderSaveFields(form, prefix) {
        if (!form || !headerSavePayload) {
            return;
        }

        Array.prototype.forEach.call(form.elements, function (field, index) {
            if (!field.name || field.disabled) {
                return;
            }

            if (field.type === 'submit' || field.type === 'button' || field.type === 'file') {
                return;
            }

            if (field.name === '_wp_http_referer' || /_nonce$/.test(field.name)) {
                return;
            }

            if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
                return;
            }

            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = field.name;
            hidden.value = field.value;
            hidden.id = prefix + '-' + index;
            headerSavePayload.appendChild(hidden);
        });
    }

    if (saveSettingsBtn && headerSaveForm && headerSavePayload && scheduleFormDb && scheduleFormFiles && remoteForm && pullForm) {
        saveSettingsBtn.addEventListener('click', function () {
            saveSettingsBtn.disabled = true;
            headerSavePayload.innerHTML = '';
            appendHeaderSaveFields(scheduleFormDb, 'sprb-header-save-schedule-db');
            appendHeaderSaveFields(scheduleFormFiles, 'sprb-header-save-schedule-files');
            appendHeaderSaveFields(remoteForm, 'sprb-header-save-remote');
            appendHeaderSaveFields(pullForm, 'sprb-header-save-pull');
            headerSaveForm.requestSubmit();
        });

        var modalSaveBtn = document.getElementById('sprb-settings-modal-save');
        if (modalSaveBtn) {
            modalSaveBtn.addEventListener('click', function () {
                closeGenericModal(document.getElementById('sprb-settings-modal'));
                saveSettingsBtn.click();
            });
        }
    }

    var copyTokenBtn = document.getElementById('sprb-copy-token-btn');
    if (copyTokenBtn) {
        copyTokenBtn.addEventListener('click', function () {
            var codeEl = document.getElementById('sprb-pull-token-display');
            if (codeEl && navigator.clipboard) {
                navigator.clipboard.writeText(codeEl.textContent.trim());
                copyTokenBtn.textContent = 'Copied!';
                setTimeout(function () { copyTokenBtn.textContent = 'Copy Token'; }, 2000);
            }
        });
    }

    if (popupStartBtn) {
        popupStartBtn.addEventListener('click', function () {
            var scopeRadio = document.querySelector('input[name="sprb_backup_scope"]:checked');
            var scope = scopeRadio ? scopeRadio.value : 'database';
            var remoteCheckbox = document.getElementById('sprb_backup_send_remote');
            var remoteMode = (remoteCheckbox && remoteCheckbox.checked) ? 'remote' : 'local';

            closeBackupPopup();
            setBackupUiBusy(true);
            updateProgressText('queued');

            var body = new FormData();
            body.append('action', 'sprb_run_backup');
            body.append('_nonce', rbAdmin.nonce);
            body.append('scope', scope);
            body.append('remote_mode', remoteMode);

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

                    var status = d.data.status || 'queued';
                    // If the server ran the backup synchronously, handle the final result directly.
                    if (status === 'success' || status === 'failed') {
                        setBackupUiBusy(false);
                        var resultType = status === 'success' ? (d.data.noticeType || 'success') : 'error';
                        showBackupModalResult(d.data.message || 'Backup finished.', resultType);
                        showResult(d.data.message || 'Backup finished.', resultType);
                        refreshBackupPanels().catch(function () {});
                        return;
                    }

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
    }

    function showResult(msg, type) {
        showNotice(msg, type);
        showToast(msg, type);
    }

    function showNotice(msg, type) {
        var wrap = queryOne('.sp-wrap');
        if (!wrap) return;
        var anchor = queryOne('.sp-page-header', wrap);
        wrap.querySelectorAll('.sp-runtime-notice').forEach(function (el) { el.remove(); });
        var div = document.createElement('div');
        div.className = 'notice notice-' + type + ' is-dismissible sp-runtime-notice';
        div.innerHTML = '<p>' + escHtml(msg) + '</p>';
        if (anchor && anchor.parentNode === wrap && anchor.nextSibling) {
            wrap.insertBefore(div, anchor.nextSibling);
        } else if (anchor) {
            anchor.insertAdjacentElement('afterend', div);
        } else {
            wrap.prepend(div);
        }
        div.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function toastStack() {
        var stack = document.querySelector('.sp-toast-stack');
        if (stack) return stack;

        stack = document.createElement('div');
        stack.className = 'sp-toast-stack';
        document.body.appendChild(stack);
        return stack;
    }

    function showToast(msg, type) {
        var toast = document.createElement('div');
        var close = document.createElement('button');
        var body = document.createElement('div');
        var timeout = type === 'error' ? 9000 : 5000;

        toast.className = 'sp-toast sp-toast--' + (type || 'info');
        body.className = 'sp-toast__body';
        body.textContent = msg;

        close.className = 'sp-toast__close';
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
    var protocolSelect = document.getElementById('sprb_remote_protocol');
    var authSelect = document.getElementById('sprb_ssh_auth_method');

    function toggleProtocol() {
        if (!protocolSelect) return;
        var protocol = protocolSelect.value;
        var allProtocolClasses = ['.sp-protocol-ssh', '.sp-protocol-ftp', '.sp-protocol-google_drive', '.sp-protocol-onedrive', '.sp-protocol-dropbox'];
        allProtocolClasses.forEach(function (cls) {
            var key = cls.replace('.sp-protocol-', '');
            queryAll(cls).forEach(function (el) {
                el.style.display = protocol === key ? '' : 'none';
            });
        });
    }

    function toggleAuth() {
        if (!authSelect) return;
        var protocol = protocolSelect ? protocolSelect.value : 'ssh';
        var method = authSelect.value;
        queryAll('.sp-auth-key').forEach(function (r) {
            r.style.display = protocol === 'ssh' && method === 'key' ? '' : 'none';
        });
        queryAll('.sp-auth-password').forEach(function (r) {
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

    /* ── Google Drive disconnect ──────────────────────── */
    var gdriveDisconnect = document.getElementById('sprb-gdrive-disconnect');
    if (gdriveDisconnect) {
        gdriveDisconnect.addEventListener('click', function () {
            if (!confirm('Disconnect Google Drive? You will need to re-authorize to use it again.')) return;
            gdriveDisconnect.disabled = true;
            var xhr = new XMLHttpRequest();
            xhr.open('POST', rbAdmin.ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function () { location.reload(); };
            xhr.onerror = function () { gdriveDisconnect.disabled = false; };
            xhr.send('action=sprb_gdrive_disconnect&_nonce=' + encodeURIComponent(rbAdmin.nonce));
        });
    }

    /* ── OneDrive disconnect ─────────────────────────── */
    var onedriveDisconnect = document.getElementById('sprb-onedrive-disconnect');
    if (onedriveDisconnect) {
        onedriveDisconnect.addEventListener('click', function () {
            if (!confirm('Disconnect OneDrive? You will need to re-authorize to use it again.')) return;
            onedriveDisconnect.disabled = true;
            var xhr = new XMLHttpRequest();
            xhr.open('POST', rbAdmin.ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function () { location.reload(); };
            xhr.onerror = function () { onedriveDisconnect.disabled = false; };
            xhr.send('action=sprb_onedrive_disconnect&_nonce=' + encodeURIComponent(rbAdmin.nonce));
        });
    }

    /* ── Dropbox disconnect ─────────────────────────── */
    var dropboxDisconnect = document.getElementById('sprb-dropbox-disconnect');
    if (dropboxDisconnect) {
        dropboxDisconnect.addEventListener('click', function () {
            if (!confirm('Disconnect Dropbox? You will need to re-authorize to use it again.')) return;
            dropboxDisconnect.disabled = true;
            var xhr = new XMLHttpRequest();
            xhr.open('POST', rbAdmin.ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function () { location.reload(); };
            xhr.onerror = function () { dropboxDisconnect.disabled = false; };
            xhr.send('action=sprb_dropbox_disconnect&_nonce=' + encodeURIComponent(rbAdmin.nonce));
        });
    }

    /* ── Cloud auth modal ────────────────────────────── */
    var authModalOverlay = document.getElementById('sprb-auth-modal-overlay');
    var authModalTitle = document.getElementById('sprb-auth-modal-title');
    var authModalLink = document.getElementById('sprb-auth-modal-link');
    var authModalCode = document.getElementById('sprb-auth-modal-code');
    var authModalStatus = document.getElementById('sprb-auth-modal-status');
    var authModalSubmit = document.getElementById('sprb-auth-modal-submit');
    var authModalCustomToggle = document.getElementById('sprb-auth-modal-custom-toggle');
    var authModalCustomFields = document.getElementById('sprb-auth-modal-custom-fields');
    var authModalClientId = document.getElementById('sprb-auth-modal-client-id');
    var authModalClientSecret = document.getElementById('sprb-auth-modal-client-secret');
    var authModalProvider = '';
    var authModalBaseUrl = '';
    var providerActions = { gdrive: 'sprb_gdrive_manual_auth', onedrive: 'sprb_onedrive_manual_auth', dropbox: 'sprb_dropbox_manual_auth' };
    var providerLabels = { gdrive: 'Google Drive', onedrive: 'OneDrive', dropbox: 'Dropbox' };
    var providerIdFields = { gdrive: 'sprb_gdrive_client_id', onedrive: 'sprb_onedrive_client_id', dropbox: 'sprb_dropbox_client_id' };
    var providerSecretFields = { gdrive: 'sprb_gdrive_client_secret', onedrive: 'sprb_onedrive_client_secret', dropbox: 'sprb_dropbox_client_secret' };

    /* Custom OAuth toggle in modal */
    if (authModalCustomToggle) {
        authModalCustomToggle.addEventListener('click', function (e) {
            e.preventDefault();
            var visible = authModalCustomFields.style.display !== 'none';
            authModalCustomFields.style.display = visible ? 'none' : '';
        });
    }

    /* Update auth URL when custom client ID changes */
    if (authModalClientId) {
        authModalClientId.addEventListener('input', function () {
            if (!authModalLink) return;
            var customId = authModalClientId.value.trim();
            if (customId) {
                var url = new URL(authModalBaseUrl);
                url.searchParams.set('client_id', customId);
                authModalLink.href = url.toString();
            } else {
                authModalLink.href = authModalBaseUrl;
            }
        });
    }

    var authModalTest = document.getElementById('sprb-auth-modal-test');

    document.querySelectorAll('.sprb-auth-open').forEach(function (btn) {
        btn.addEventListener('click', function () {
            authModalProvider = btn.getAttribute('data-provider');
            authModalBaseUrl = btn.getAttribute('data-auth-url');
            if (authModalTitle) authModalTitle.textContent = 'Authorize ' + (providerLabels[authModalProvider] || 'Cloud Storage');
            if (authModalLink) authModalLink.href = authModalBaseUrl;
            if (authModalCode) authModalCode.value = '';
            if (authModalStatus) authModalStatus.textContent = '';
            if (authModalSubmit) { authModalSubmit.disabled = false; authModalSubmit.style.display = ''; }
            if (authModalTest) authModalTest.style.display = 'none';
            /* Populate custom fields from hidden form inputs */
            var idField = document.getElementById(providerIdFields[authModalProvider] || '');
            var secretField = document.getElementById(providerSecretFields[authModalProvider] || '');
            if (authModalClientId) authModalClientId.value = idField ? idField.value : '';
            if (authModalClientSecret) authModalClientSecret.value = secretField ? secretField.value : '';
            if (authModalCustomFields) authModalCustomFields.style.display = 'none';
            if (authModalOverlay) authModalOverlay.style.display = '';
        });
    });

    function closeAuthModal() {
        if (authModalOverlay) authModalOverlay.style.display = 'none';
        /* If auth succeeded, reload to reflect new connection state */
        if (authModalSubmit && authModalSubmit.style.display === 'none') {
            location.reload();
        }
    }
    var authModalClose = document.getElementById('sprb-auth-modal-close');
    var authModalCancel = document.getElementById('sprb-auth-modal-cancel');
    if (authModalClose) authModalClose.addEventListener('click', closeAuthModal);
    if (authModalCancel) authModalCancel.addEventListener('click', closeAuthModal);
    if (authModalOverlay) authModalOverlay.addEventListener('click', function (e) { if (e.target === authModalOverlay) closeAuthModal(); });

    if (authModalSubmit) {
        authModalSubmit.addEventListener('click', function () {
            var code = (authModalCode.value || '').trim();
            if (!code) { authModalStatus.textContent = 'Please paste an authorization code.'; return; }
            var action = providerActions[authModalProvider];
            if (!action) { authModalStatus.textContent = 'Unknown provider.'; return; }
            authModalSubmit.disabled = true;
            authModalStatus.textContent = 'Exchanging code\u2026';
            var customClientId = (authModalClientId ? authModalClientId.value : '').trim();
            var customClientSecret = (authModalClientSecret ? authModalClientSecret.value : '').trim();
            /* Sync custom credentials back to hidden form inputs */
            var idField = document.getElementById(providerIdFields[authModalProvider] || '');
            var secretField = document.getElementById(providerSecretFields[authModalProvider] || '');
            if (idField) idField.value = customClientId;
            if (secretField) secretField.value = customClientSecret;
            var payload = 'action=' + encodeURIComponent(action) + '&_nonce=' + encodeURIComponent(rbAdmin.nonce) + '&code=' + encodeURIComponent(code);
            if (customClientId) payload += '&client_id=' + encodeURIComponent(customClientId);
            if (customClientSecret) payload += '&client_secret=' + encodeURIComponent(customClientSecret);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', rbAdmin.ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function () {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        authModalStatus.textContent = res.data.message || 'Connected!';
                        authModalStatus.style.color = '#00a32a';
                        authModalSubmit.style.display = 'none';
                        if (authModalTest) authModalTest.style.display = '';
                    }
                    else { authModalStatus.textContent = res.data || 'Authorization failed.'; authModalSubmit.disabled = false; }
                } catch (e) { authModalStatus.textContent = 'Unexpected response.'; authModalSubmit.disabled = false; }
            };
            xhr.onerror = function () { authModalStatus.textContent = 'Network error.'; authModalSubmit.disabled = false; };
            xhr.send(payload);
        });
    }

    /* ── Test Connection (in auth modal) ─────────────── */
    if (authModalTest) {
        authModalTest.addEventListener('click', function () {
            authModalTest.disabled = true;
            authModalStatus.textContent = 'Testing connection\u2026';
            authModalStatus.style.color = '';
            var xhr = new XMLHttpRequest();
            xhr.open('POST', rbAdmin.ajaxUrl, true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onload = function () {
                authModalTest.disabled = false;
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        authModalStatus.textContent = res.data.message || 'Connection OK.';
                        authModalStatus.style.color = '#00a32a';
                    } else {
                        authModalStatus.textContent = res.data || 'Test failed.';
                        authModalStatus.style.color = '#d63638';
                    }
                } catch (e) { authModalStatus.textContent = 'Unexpected response.'; authModalStatus.style.color = '#d63638'; }
            };
            xhr.onerror = function () { authModalTest.disabled = false; authModalStatus.textContent = 'Network error.'; authModalStatus.style.color = '#d63638'; };
            xhr.send('action=sprb_test_connection&_nonce=' + encodeURIComponent(rbAdmin.nonce));
        });
    }

    /* ── Schedule weekday toggles ────────────────────── */
    function toggleWeeklyScheduleRow(scope) {
        var select = document.getElementById('sprb_schedule_' + scope + '_frequency');
        var fieldId = scope === 'database' ? 'sprb-db-weekday-field' : 'sprb-files-weekday-field';
        var row = document.getElementById(fieldId);
        if (!select || !row) return;
        row.style.display = select.value === 'weekly' ? '' : 'none';
    }

    ['database', 'files'].forEach(function (scope) {
        var select = document.getElementById('sprb_schedule_' + scope + '_frequency');
        if (!select) return;
        select.addEventListener('change', function () {
            toggleWeeklyScheduleRow(scope);
        });
        toggleWeeklyScheduleRow(scope);
    });

    /* ── Folder tree controls (lazy-loading) ─────────── */

    /**
     * Create a tree node DOM element for a directory entry.
     * @param {Object} entry  {name, path, hasChildren}
     * @param {boolean} checked  initial checkbox state
     * @return {HTMLElement}
     */
    function createTreeNode(entry, checked) {
        var node = document.createElement('div');
        node.setAttribute('data-path', entry.path);

        // File node — selectable with checkbox.
        if (entry.isFile) {
            node.className = 'sp-tree-node sp-tree-node--file';
            var frow = document.createElement('div');
            frow.className = 'sp-tree-row';
            var spacer = document.createElement('span');
            spacer.className = 'sp-tree-spacer';
            frow.appendChild(spacer);
            var fileLabel = document.createElement('label');
            fileLabel.className = 'sp-file-item';
            fileLabel.id = 'sp-label-' + entry.path.replace(/[\/\.]/g, '-');
            var fcb = document.createElement('input');
            fcb.type = 'checkbox';
            fcb.className = 'sp-folder-cb';
            fcb.value = entry.path;
            fcb.checked = checked;
            fcb.addEventListener('change', function () { updateAncestorState(node); });
            var ficon = document.createElement('span');
            ficon.className = 'dashicons dashicons-media-default';
            fileLabel.appendChild(fcb);
            fileLabel.appendChild(ficon);
            fileLabel.appendChild(document.createTextNode(' ' + entry.name));
            frow.appendChild(fileLabel);
            node.appendChild(frow);
            return node;
        }

        // Directory node.
        node.className = 'sp-tree-node' + (entry.hasChildren ? ' sp-tree-node--parent' : '');

        var row = document.createElement('div');
        row.className = 'sp-tree-row';

        if (entry.hasChildren) {
            var toggle = document.createElement('span');
            toggle.className = 'sp-tree-toggle';
            toggle.id = 'sp-toggle-' + entry.path.replace(/\//g, '-');
            toggle.addEventListener('click', function () { toggleTreeNode(node); });
            row.appendChild(toggle);
        } else {
            var spacer = document.createElement('span');
            spacer.className = 'sp-tree-spacer';
            row.appendChild(spacer);
        }

        var label = document.createElement('label');
        label.className = 'sp-folder-item';
        label.id = 'sp-label-' + entry.path.replace(/\//g, '-');

        var cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.className = 'sp-folder-cb';
        cb.value = entry.path;
        cb.checked = checked;
        cb.setAttribute('data-has-children', entry.hasChildren ? '1' : '0');
        cb.addEventListener('change', function () { onCheckboxChange(node); });

        var icon = document.createElement('span');
        icon.className = 'dashicons dashicons-category';

        label.appendChild(cb);
        label.appendChild(icon);
        label.appendChild(document.createTextNode(' ' + entry.name + '/'));

        row.appendChild(label);
        node.appendChild(row);

        if (entry.hasChildren) {
            var children = document.createElement('div');
            children.className = 'sp-tree-children';
            children.style.display = 'none';
            node.appendChild(children);
        }

        return node;
    }

    /**
     * Toggle expand/collapse of a tree node — lazy-loads children via AJAX on first expand.
     */
    function toggleTreeNode(node) {
        var childrenContainer = node.querySelector(':scope > .sp-tree-children');
        var toggle = node.querySelector(':scope > .sp-tree-row .sp-tree-toggle');
        if (!childrenContainer) return;

        var isOpen = childrenContainer.style.display !== 'none';
        if (isOpen) {
            childrenContainer.style.display = 'none';
            if (toggle) { toggle.classList.remove('is-open'); }
            return;
        }

        // Show children container.
        childrenContainer.style.display = '';
        if (toggle) { toggle.classList.add('is-open'); }

        // If already loaded, don't re-fetch.
        if (childrenContainer.getAttribute('data-loaded') === '1') return;

        // Lazy load via AJAX.
        var path = node.getAttribute('data-path');
        var parentCb = node.querySelector(':scope > .sp-tree-row .sp-folder-cb');
        var parentChecked = parentCb ? parentCb.checked : true;

        childrenContainer.innerHTML = '<div class="sp-tree-loading" id="sp-loading-' + path.replace(/\//g, '-') + '"><span class="dashicons dashicons-update sp-spin"></span> Loading…</div>';

        var body = new FormData();
        body.append('action', 'sprb_list_dir');
        body.append('_nonce', rbAdmin.nonce);
        body.append('path', path);

        postAjax(body)
            .then(function (d) {
                if (!d.success || !d.data) throw new Error('Failed to load directory.');
                childrenContainer.innerHTML = '';
                childrenContainer.setAttribute('data-loaded', '1');

                if (d.data.length === 0) {
                    // No subdirectories — remove parent indicator.
                    node.classList.remove('sp-tree-node--parent');
                    if (toggle) { toggle.replaceWith(createSpacer()); }
                    childrenContainer.remove();
                    return;
                }

                d.data.forEach(function (entry) {
                    var childNode = createTreeNode(entry, parentChecked);
                    childrenContainer.appendChild(childNode);
                });
            })
            .catch(function () {
                childrenContainer.innerHTML = '<div class="sp-tree-error" id="sp-err-' + path.replace(/\//g, '-') + '">Failed to load.</div>';
            });
    }

    function createSpacer() {
        var s = document.createElement('span');
        s.className = 'sp-tree-spacer';
        return s;
    }

    /**
     * Handle checkbox change — propagate down to loaded children, update parent state.
     */
    function onCheckboxChange(node) {
        var cb = node.querySelector(':scope > .sp-tree-row .sp-folder-cb');
        if (!cb) return;

        // Propagate down to all loaded descendant checkboxes.
        var childContainer = node.querySelector(':scope > .sp-tree-children');
        if (childContainer) {
            childContainer.querySelectorAll('.sp-folder-cb').forEach(function (childCb) {
                childCb.checked = cb.checked;
                childCb.indeterminate = false;
            });
        }

        // Update ancestors.
        updateAncestorState(node);
    }

    /**
     * Walk up from a node and update parent checkbox checked/indeterminate state.
     */
    function updateAncestorState(node) {
        var parentNode = node.parentElement;
        if (!parentNode || !parentNode.classList.contains('sp-tree-children')) return;
        var grandParent = parentNode.parentElement;
        if (!grandParent || !grandParent.classList.contains('sp-tree-node')) return;

        var parentCb = grandParent.querySelector(':scope > .sp-tree-row .sp-folder-cb');
        if (!parentCb) return;

        var kids = parentNode.querySelectorAll(':scope > .sp-tree-node > .sp-tree-row .sp-folder-cb');
        var checkedCount = 0;
        kids.forEach(function (k) { if (k.checked) checkedCount++; });

        parentCb.checked = checkedCount > 0;
        parentCb.indeterminate = checkedCount > 0 && checkedCount < kids.length;

        updateAncestorState(grandParent);
    }

    // Bind initial toggle clicks on server-rendered top-level nodes.
    queryAll('#sp-folder-tree > .sp-tree-node > .sp-tree-row .sp-tree-toggle').forEach(function (toggle) {
        toggle.addEventListener('click', function () {
            toggleTreeNode(toggle.closest('.sp-tree-node'));
        });
    });

    // Bind initial checkbox change events on server-rendered top-level nodes.
    queryAll('#sp-folder-tree > .sp-tree-node > .sp-tree-row .sp-folder-cb').forEach(function (cb) {
        cb.addEventListener('change', function () {
            onCheckboxChange(cb.closest('.sp-tree-node'));
        });
    });

    /* ── Folder picker — always visible in modal, no collapsible ── */

    var allBtn  = queryOne('#sp-folders-all, #sprb-folders-all');
    var noneBtn = queryOne('#sp-folders-none, #sprb-folders-none');
    var saveBtn = queryOne('#sp-folders-save, #sprb-folders-save');

    if (allBtn) {
        allBtn.addEventListener('click', function () {
            queryAll('.sp-folder-cb').forEach(function (cb) {
                cb.checked = true; cb.indeterminate = false;
            });
        });
    }
    if (noneBtn) {
        noneBtn.addEventListener('click', function () {
            queryAll('.sp-folder-cb').forEach(function (cb) {
                cb.checked = false; cb.indeterminate = false;
            });
        });
    }
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var body = new FormData();
            body.append('action', 'sprb_save_folders');
            body.append('_nonce', rbAdmin.nonce);
            collectFolders().forEach(function (f) { body.append('folders[]', f); });

            postAjax(body)
                .then(function (d) {
                    var saved = queryOne('#sp-folders-saved, #sprb-folders-saved');
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

    /* ── Generic modal open/close (settings modals) ──── */

    function openGenericModal(modalId, tabId) {
        var modal = document.getElementById(modalId);
        if (modal) {
            modal.style.display = 'flex';
            if (tabId) activateTab(tabId);
        }
    }

    function closeGenericModal(modal) {
        if (modal) modal.style.display = 'none';
    }

    /* ── Tab switching inside settings modal ──────────── */
    function activateTab(tabId) {
        var tab = document.getElementById(tabId);
        if (!tab) return;
        var panelId = tab.getAttribute('data-tab');
        if (!panelId) return;
        var tabNav = tab.closest('.sp-modal-tabs');
        if (!tabNav) return;
        var modal = tabNav.closest('.sp-modal');
        if (!modal) return;
        queryAll('.sp-tab-button', tabNav).forEach(function (t) { t.classList.remove('active'); });
        tab.classList.add('active');
        queryAll('.sp-tab-content', modal).forEach(function (p) { p.classList.remove('active'); });
        var panel = document.getElementById(panelId);
        if (panel) panel.classList.add('active');
    }

    // Tab click handler.
    document.addEventListener('click', function (e) {
        var tab = e.target.closest('.sp-tab-button');
        if (tab && tab.id) activateTab(tab.id);
    });

    queryAll('.sp-modal-overlay').forEach(function (overlay) {
        // Skip the backup popup and auth modal — they have their own handlers.
        if (overlay.id === 'sprb-backup-popup-overlay' || overlay.id === 'sprb-auth-modal-overlay') return;

        var closeBtn = overlay.querySelector('.sp-modal__close');
        var cancelBtns = overlay.querySelectorAll('[id$="-cancel"]');

        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                closeGenericModal(overlay);
            });
        }
        cancelBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                closeGenericModal(overlay);
            });
        });

        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeGenericModal(overlay);
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        // Find the top-most visible modal overlay.
        var visible = null;
        queryAll('.sp-modal-overlay').forEach(function (o) {
            if (o.style.display === 'flex' || (o.style.display !== 'none' && getComputedStyle(o).display !== 'none')) {
                visible = o;
            }
        });
        if (!visible) return;
        // Auth modal and backup popup have their own Escape handlers — delegate.
        if (visible.id === 'sprb-auth-modal-overlay') {
            closeAuthModal();
        } else if (visible.id === 'sprb-backup-popup-overlay') {
            closeBackupPopup();
        } else {
            closeGenericModal(visible);
        }
    });

    document.addEventListener('click', function (e) {
        var openBtn = e.target.closest('[data-open-modal]');
        if (openBtn) {
            e.preventDefault();
            openGenericModal(openBtn.getAttribute('data-open-modal'), openBtn.getAttribute('data-open-tab') || null);
        }
    });

    /* ── Monitor page actions / live refresh ─────────── */
    var monitorConfig = rbAdmin.monitor || null;
    var monitorSnapshotTimer = null;
    var monitorProgressTimer = null;

    function monitorEnabled() {
        return !!(monitorConfig && monitorConfig.enabled);
    }

    function monitorExpandedUrls() {
        var expanded = [];
        queryAll('.sp-history-detail[data-url]').forEach(function (row) {
            if (row.style.display !== 'none') {
                expanded.push(row.getAttribute('data-url'));
            }
        });
        return expanded;
    }

    function restoreMonitorExpandedUrls(urls) {
        (urls || []).forEach(function (url) {
            var row = queryOne('.sp-history-detail[data-url="' + url + '"]');
            if (row) {
                row.style.display = 'table-row';
            }
        });
    }

    function replaceMonitorSnapshot(data) {
        var expanded = monitorExpandedUrls();
        var summary = queryOne('#sp-monitor-summary, #sprb-monitor-summary');
        var sites = queryOne('#sp-monitor-sites-section, #sprb-monitor-sites');

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
        var panel = queryOne('#sp-monitor-progress-panel, #sprb-monitor-progress-panel');
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
        body.append('action', 'sprb_monitor_snapshot');
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
        body.append('action', 'sprb_monitor_progress');
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
        var actionLink = e.target.closest('.sp-monitor-action');
        if (actionLink && monitorEnabled()) {
            e.preventDefault();

            var body = new FormData();
            body.append('action', 'sprb_monitor_action');
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

        var row = e.target.closest('.sp-monitor-row');
        if (!row || e.target.closest('a, button')) return;

        var url = row.getAttribute('data-url');
        var detail = queryOne('.sp-history-detail[data-url="' + url + '"]');
        if (detail) {
            detail.style.display = detail.style.display === 'none' ? 'table-row' : 'none';
        }
    });

    if (monitorEnabled() && monitorConfig.active) {
        startMonitorPolling();
    }

    /* ── Backup simulation (dev only) ─────────────────── */
    var simTimer = null;

    function startSimulation() {
        var body = new FormData();
        body.append('action', 'sprb_simulate_backup');
        body.append('_nonce', rbAdmin.nonce);
        body.append('sim_action', 'start');

        setBackupUiBusy(true);
        updateProgressText('database');

        postAjax(body).then(function (d) {
            if (!d.success || !d.data || !d.data.jobId) {
                throw new Error(ajaxErrorMessage(d));
            }
            startStatusPolling(d.data.jobId);
            clearInterval(statusPollTimer);
            statusPollTimer = setInterval(pollBackupStatus, 500);
            simTimer = setInterval(tickSimulation, 200);
        }).catch(function (err) {
            setBackupUiBusy(false);
            removeBackupModal();
            showResult('Simulation failed: ' + err.message, 'error');
        });
    }

    function tickSimulation() {
        var body = new FormData();
        body.append('action', 'sprb_simulate_backup');
        body.append('_nonce', rbAdmin.nonce);
        body.append('sim_action', 'tick');

        postAjax(body).then(function (d) {
            if (d.success && d.data && d.data.done) {
                clearInterval(simTimer);
                simTimer = null;
            }
        }).catch(function () {
            clearInterval(simTimer);
            simTimer = null;
        });
    }

    // Expose for console: rbSimulateBackup()
    window.rbSimulateBackup = startSimulation;
})();
