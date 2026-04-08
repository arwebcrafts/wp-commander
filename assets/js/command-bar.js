/**
 * WP Commander — Command Bar UI
 *
 * Vanilla JS, no localStorage/sessionStorage, all AI calls via server.
 * Conventional Commits: feat/fix/chore naming in comments.
 */
/* global WPC_Data */
(function () {
    'use strict';

    if (typeof WPC_Data === 'undefined') return;

    // ── State (in-memory only, no localStorage) ─────────────────────────
    const state = {
        open:          false,
        activeTab:     'edit',
        editHistory:   [],   // max 10
        genHistory:    [],   // max 10
        blueprint:     null,
        plugins:       [],
        genJobId:      null,
        pollTimer:     null,
        lastUndoKey:   null,
        isLoading:     false,
    };

    // ── DOM refs ─────────────────────────────────────────────────────────
    const $ = id => document.getElementById(id);
    const root         = $('wpc-root');
    const trigger      = $('wpc-trigger');
    const modal        = $('wpc-modal');
    const backdrop     = $('wpc-backdrop');
    const closeBtn     = $('wpc-close');
    const aiBadge      = $('wpc-ai-badge');

    // Edit panel
    const editInput    = $('wpc-edit-input');
    const editSubmit   = $('wpc-edit-submit');
    const editFeedback = $('wpc-edit-feedback');
    const undoWrap     = $('wpc-undo-wrap');
    const undoBtn      = $('wpc-undo');
    const editHistWrap = $('wpc-edit-history');
    const editHistList = $('wpc-edit-history-list');

    // Generate panel
    const genInput     = $('wpc-gen-input');
    const refUrlInput  = $('wpc-ref-url');
    const genSubmit    = $('wpc-gen-submit');
    const genFeedback  = $('wpc-gen-feedback');
    const pluginsPanel = $('wpc-plugins-panel');
    const pluginsList  = $('wpc-plugins-list');
    const installAll   = $('wpc-install-all');
    const installMiss  = $('wpc-install-missing');
    const installSkip  = $('wpc-install-skip');
    const genProgress  = $('wpc-gen-progress');
    const progressBar  = $('wpc-progress-bar');
    const progressLbl  = $('wpc-progress-label');
    const progressSteps= $('wpc-progress-steps');
    const genHistWrap  = $('wpc-gen-history');
    const genHistList  = $('wpc-gen-history-list');

    if (!root || !trigger || !modal) return;

    // ── AI badge (provider label stored server-side, fetch once) ────────
    function initAiBadge() {
        // Badge text is injected via PHP into WPC_Data
        if (WPC_Data.ai_label) {
            aiBadge.textContent = '⚡ ' + WPC_Data.ai_label;
        }
    }

    // ── Open / Close ─────────────────────────────────────────────────────
    function openModal() {
        state.open = true;
        modal.hidden = false;
        modal.removeAttribute('hidden');
        trigger.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
        // Focus appropriate input
        requestAnimationFrame(() => {
            const inp = state.activeTab === 'edit' ? editInput : genInput;
            if (inp) inp.focus();
        });
        document.addEventListener('keydown', onEsc);
    }

    function closeModal() {
        state.open = false;
        modal.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        document.removeEventListener('keydown', onEsc);
        clearFeedback();
    }

    function onEsc(e) {
        if (e.key === 'Escape') closeModal();
    }

    trigger.addEventListener('click', () => state.open ? closeModal() : openModal());
    closeBtn.addEventListener('click', closeModal);
    backdrop.addEventListener('click', closeModal);

    // ── Keyboard shortcut Ctrl+Shift+K / Cmd+Shift+K ────────────────────
    document.addEventListener('keydown', function (e) {
        const isMac = navigator.platform.toUpperCase().includes('MAC');
        const modifier = isMac ? e.metaKey : e.ctrlKey;
        if (modifier && e.shiftKey && e.key === 'K') {
            e.preventDefault();
            state.open ? closeModal() : openModal();
        }
    });

    // ── Tab switching ─────────────────────────────────────────────────────
    document.querySelectorAll('.wpc-tab').forEach(tab => {
        tab.addEventListener('click', () => switchTab(tab.dataset.tab));
    });

    function switchTab(tabName) {
        state.activeTab = tabName;
        document.querySelectorAll('.wpc-tab').forEach(t => {
            const isActive = t.dataset.tab === tabName;
            t.classList.toggle('wpc-tab--active', isActive);
            t.setAttribute('aria-selected', String(isActive));
        });
        document.querySelectorAll('.wpc-panel').forEach(p => {
            const isActive = p.id === 'panel-' + tabName;
            p.classList.toggle('wpc-panel--active', isActive);
            if (isActive) {
                p.hidden = false;
                p.removeAttribute('hidden');
            } else {
                p.hidden = true;
            }
        });
        clearFeedback();
        requestAnimationFrame(() => {
            const inp = tabName === 'edit' ? editInput : genInput;
            if (inp) inp.focus();
        });
    }

    // ── Feedback helpers ─────────────────────────────────────────────────
    function showFeedback(el, message, type = 'info') {
        el.textContent = message;
        el.className = 'wpc-feedback wpc-feedback--' + type;
        el.hidden = false;
        el.removeAttribute('hidden');
    }

    function clearFeedback() {
        [editFeedback, genFeedback].forEach(el => {
            if (el) { el.hidden = true; el.textContent = ''; }
        });
    }

    function setLoading(btn, loading) {
        state.isLoading = loading;
        const textEl    = btn.querySelector('.wpc-btn-text');
        const spinnerEl = btn.querySelector('.wpc-spinner');
        btn.disabled    = loading;
        if (textEl)    textEl.style.opacity    = loading ? '0.6' : '1';
        if (spinnerEl) spinnerEl.hidden         = !loading;
    }

    // ── History management ───────────────────────────────────────────────
    function addToHistory(arr, listEl, wrapEl, text) {
        arr.unshift(text);
        if (arr.length > 10) arr.pop();
        renderHistory(arr, listEl, wrapEl);
    }

    function renderHistory(arr, listEl, wrapEl) {
        if (!arr.length) { if (wrapEl) wrapEl.hidden = true; return; }
        listEl.innerHTML = '';
        arr.forEach(cmd => {
            const li = document.createElement('li');
            li.className = 'wpc-history-item';
            li.textContent = cmd;
            li.setAttribute('role', 'button');
            li.setAttribute('tabindex', '0');
            li.addEventListener('click', () => {
                const target = state.activeTab === 'edit' ? editInput : genInput;
                if (target) { target.value = cmd; target.focus(); }
            });
            li.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') li.click();
            });
            listEl.appendChild(li);
        });
        if (wrapEl) { wrapEl.hidden = false; wrapEl.removeAttribute('hidden'); }
    }

    // ── REST API helper ──────────────────────────────────────────────────
    async function apiPost(endpoint, data) {
        const res = await fetch(WPC_Data.rest_url + endpoint, {
            method:  'POST',
            headers: {
                'Content-Type':  'application/json',
                'X-WP-Nonce':    WPC_Data.nonce,
            },
            body: JSON.stringify({ ...data, _wpnonce: WPC_Data.wpc_nonce }),
        });
        return res.json();
    }

    async function apiGet(endpoint, params = {}) {
        const qs  = new URLSearchParams({ ...params, _wpnonce: WPC_Data.wpc_nonce });
        const res = await fetch(WPC_Data.rest_url + endpoint + '?' + qs, {
            headers: { 'X-WP-Nonce': WPC_Data.nonce },
        });
        return res.json();
    }

    // ── Edit command ──────────────────────────────────────────────────────
    editSubmit.addEventListener('click', runEditCommand);
    editInput.addEventListener('keydown', e => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) runEditCommand();
    });

    async function runEditCommand() {
        const cmd = editInput.value.trim();
        if (!cmd || state.isLoading) return;

        setLoading(editSubmit, true);
        clearFeedback();
        if (undoWrap) undoWrap.hidden = true;

        try {
            const result = await apiPost('execute-command', {
                command: cmd,
                post_id: WPC_Data.post_id,
            });

            if (result.success) {
                showFeedback(editFeedback, result.message || 'Change applied!', 'success');
                addToHistory(state.editHistory, editHistList, editHistWrap, cmd);
                editInput.value = '';

                if (result.undo_key) {
                    state.lastUndoKey = result.undo_key;
                    if (undoWrap) { undoWrap.hidden = false; undoWrap.removeAttribute('hidden'); }
                }

                // Apply CSS live if returned
                if (result.css) {
                    applyLiveCSS(result.css, WPC_Data.post_id);
                }
            } else {
                showFeedback(editFeedback, result.message || 'Something went wrong.', 'error');
            }
        } catch (err) {
            showFeedback(editFeedback, 'Network error. Please try again.', 'error');
        }

        setLoading(editSubmit, false);
    }

    // ── Undo ──────────────────────────────────────────────────────────────
    if (undoBtn) {
        undoBtn.addEventListener('click', async () => {
            setLoading(undoBtn, true);
            try {
                const result = await apiPost('undo-last', { post_id: WPC_Data.post_id });
                if (result.success) {
                    showFeedback(editFeedback, 'Last change undone.', 'info');
                    if (undoWrap) undoWrap.hidden = true;
                    if (result.css) applyLiveCSS(result.css, WPC_Data.post_id);
                } else {
                    showFeedback(editFeedback, result.message || 'Could not undo.', 'error');
                }
            } catch (e) {
                showFeedback(editFeedback, 'Network error.', 'error');
            }
            setLoading(undoBtn, false);
        });
    }

    // ── Live CSS injection ────────────────────────────────────────────────
    function applyLiveCSS(css, postId) {
        const styleId = 'wpc-live-css-' + postId;
        let style = document.getElementById(styleId);
        if (!style) {
            style = document.createElement('style');
            style.id = styleId;
            document.head.appendChild(style);
        }
        style.textContent = css;
    }

    // ── Generate site ─────────────────────────────────────────────────────
    genSubmit.addEventListener('click', runGenerateSite);
    genInput.addEventListener('keydown', e => {
        if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) runGenerateSite();
    });

    async function runGenerateSite() {
        const prompt = genInput.value.trim();
        if (!prompt || state.isLoading) return;

        setLoading(genSubmit, true);
        clearFeedback();
        hidePluginsPanel();
        hideProgress();

        showFeedback(genFeedback, 'Generating site blueprint…', 'info');

        try {
            const result = await apiPost('generate-site', {
                prompt:        prompt,
                reference_url: refUrlInput ? refUrlInput.value.trim() : '',
            });

            if (result.success) {
                state.blueprint = result.blueprint;
                addToHistory(state.genHistory, genHistList, genHistWrap, prompt);
                genInput.value = '';

                if (result.required_plugins && result.required_plugins.length) {
                    state.plugins = result.required_plugins;
                    showPluginsPanel(result.required_plugins);
                    showFeedback(genFeedback, 'Blueprint ready! Review the required plugins below.', 'success');
                } else {
                    // No plugins needed — build immediately
                    showFeedback(genFeedback, 'Building your site…', 'info');
                    startSiteBuild();
                }
            } else {
                showFeedback(genFeedback, result.message || 'Failed to generate blueprint.', 'error');
            }
        } catch (err) {
            showFeedback(genFeedback, 'Network error. Please try again.', 'error');
        }

        setLoading(genSubmit, false);
    }

    // ── Plugin installer panel ────────────────────────────────────────────
    function showPluginsPanel(plugins) {
        pluginsList.innerHTML = '';
        plugins.forEach(plugin => {
            const li = document.createElement('li');
            li.className = 'wpc-plugin-item wpc-status-waiting';
            li.id = 'wpc-plugin-' + plugin.slug;
            li.innerHTML = `
                <span class="wpc-plugin-status" aria-hidden="true">⏳</span>
                <div class="wpc-plugin-info">
                    <div class="wpc-plugin-name">${escHtml(plugin.name)}</div>
                    <div class="wpc-plugin-reason">${escHtml(plugin.reason || '')}</div>
                </div>`;
            pluginsList.appendChild(li);
        });
        pluginsPanel.hidden = false;
        pluginsPanel.removeAttribute('hidden');
    }

    function hidePluginsPanel() {
        if (pluginsPanel) pluginsPanel.hidden = true;
    }

    installAll.addEventListener('click', () => doInstallPlugins(state.plugins));
    installMiss.addEventListener('click', () => doInstallPlugins(state.plugins, true));
    installSkip.addEventListener('click', () => {
        hidePluginsPanel();
        startSiteBuild();
    });

    async function doInstallPlugins(plugins, skipExisting = false) {
        const toInstall = skipExisting
            ? plugins // server handles skip logic
            : plugins;

        // Mark all as loading
        toInstall.forEach(p => setPluginStatus(p.slug, 'loading', ''));

        installAll.disabled  = true;
        installMiss.disabled = true;
        installSkip.disabled = true;

        try {
            const result = await apiPost('install-plugins', { plugins: toInstall });

            if (result.results) {
                result.results.forEach(r => {
                    const icon = r.status === 'activated' || r.status === 'already_active' ? '✅' : '❌';
                    setPluginStatus(r.slug, r.status === 'install_failed' ? 'error' : 'done', icon);
                });
            }

            if (result.build_started) {
                showFeedback(genFeedback, 'Plugins ready! Building your site…', 'success');
                setTimeout(() => startPolling(), 500);
            } else {
                showFeedback(genFeedback, 'Plugins installed. Starting build…', 'success');
                startSiteBuild();
            }
        } catch (err) {
            showFeedback(genFeedback, 'Plugin installation failed. You can install manually and retry.', 'error');
            installSkip.disabled = false;
        }
    }

    function setPluginStatus(slug, status, icon) {
        const item = document.getElementById('wpc-plugin-' + slug);
        if (!item) return;
        item.className = 'wpc-plugin-item wpc-status-' + status;
        const statusEl = item.querySelector('.wpc-plugin-status');
        if (statusEl && icon) statusEl.textContent = icon;
    }

    // ── Site build trigger ────────────────────────────────────────────────
    async function startSiteBuild() {
        hidePluginsPanel();
        showProgress(0, 'Starting site build…', []);

        // The build was already triggered server-side (generate-site + install-plugins)
        // Start polling for status
        startPolling();
    }

    // ── Progress polling ──────────────────────────────────────────────────
    function startPolling() {
        if (state.pollTimer) clearInterval(state.pollTimer);
        state.pollTimer = setInterval(pollStatus, 1500);
    }

    async function pollStatus() {
        try {
            const status = await apiGet('generation-status', { job_id: state.genJobId || '' });

            if (status.progress !== undefined) {
                showProgress(status.progress, status.message || 'Building…', status.steps || []);
            }

            if (status.status === 'complete') {
                clearInterval(state.pollTimer);
                state.pollTimer = null;
                showProgress(100, 'Site built successfully! 🎉', status.steps || []);
                showFeedback(genFeedback, status.message || 'Your site is ready!', 'success');

                if (status.front_page_url) {
                    setTimeout(() => {
                        window.location.href = status.front_page_url;
                    }, 2000);
                }
            } else if (status.status === 'failed') {
                clearInterval(state.pollTimer);
                state.pollTimer = null;
                showFeedback(genFeedback, status.message || 'Build failed.', 'error');
                hideProgress();
            }
        } catch (e) {
            // Silently retry
        }
    }

    // ── Progress UI helpers ───────────────────────────────────────────────
    function showProgress(pct, label, steps) {
        genProgress.hidden = false;
        genProgress.removeAttribute('hidden');

        progressBar.style.width = pct + '%';
        progressBar.setAttribute('aria-valuenow', pct);
        progressLbl.textContent = label;

        if (steps && steps.length) {
            progressSteps.innerHTML = '';
            steps.forEach(step => {
                const li = document.createElement('li');
                li.className = 'wpc-progress-step' +
                    (step.done   ? ' wpc-progress-step--done'   : '') +
                    (step.active ? ' wpc-progress-step--active' : '');
                li.textContent = step.label;
                progressSteps.appendChild(li);
            });
        }
    }

    function hideProgress() {
        if (genProgress) genProgress.hidden = true;
    }

    // ── XSS-safe HTML escape ──────────────────────────────────────────────
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // ── Textarea auto-resize ──────────────────────────────────────────────
    function autoResize(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 160) + 'px';
    }

    [editInput, genInput].forEach(el => {
        if (!el) return;
        el.addEventListener('input', () => autoResize(el));
    });

    // ── Init ──────────────────────────────────────────────────────────────
    function init() {
        initAiBadge();
        // Ensure modal starts hidden
        if (modal) modal.hidden = true;
        // Set trigger aria state
        trigger.setAttribute('aria-expanded', 'false');
        trigger.setAttribute('aria-haspopup', 'dialog');
        // Init tab panels
        document.querySelectorAll('.wpc-panel').forEach(p => {
            if (p.id !== 'panel-edit') p.hidden = true;
        });
    }

    init();

})();
