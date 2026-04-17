document.addEventListener('DOMContentLoaded', function () {
    const openBtn = document.getElementById('wb-open-ai-modal');
    if (!openBtn) return;

    const modalOverlay = document.getElementById('wb-ai-modal-overlay');
    const closeBtn = document.getElementById('wb-close-ai-modal');
    const clearBtn = document.getElementById('wb-clear-ai-chat');
    const chatForm = document.getElementById('wb-ai-chat-form');
    const inputField = document.getElementById('wb-ai-input');
    const chatBody = document.getElementById('wb-ai-chat-body');
    const emptyState = document.getElementById('wb-ai-empty-state');
    const typingIndicator = document.getElementById('wb-ai-typing-indicator');
    const suggestionBtns = document.querySelectorAll('.wb-ai-suggestion-btn');
    const submitBtn = document.getElementById('wb-ai-submit-btn');

    /**
     * Resolve a localized string from wooboosterAdmin.i18n with an English fallback.
     */
    function t(key, fallback) {
        var i18n = (window.wooboosterAdmin && wooboosterAdmin.i18n) || {};
        if (typeof i18n[key] === 'string' && i18n[key] !== '') {
            return i18n[key];
        }
        return fallback;
    }

    /**
     * Simple sprintf replacement for the first %s only.
     */
    function fmt(str, value) {
        return String(str).replace('%s', value);
    }

    // Detect mode from hidden input.
    const modeInput = document.getElementById('wb-ai-mode');
    const MODE = modeInput ? modeInput.value : 'rule';
    const IS_BUNDLE = MODE === 'bundle';

    const HISTORY_KEY = IS_BUNDLE ? 'wb_ai_bundle_history' : 'wb_ai_chat_history';
    const BLOCK_TAG = IS_BUNDLE ? 'BUNDLE' : 'RULE';
    const CREATE_ACTION = IS_BUNDLE ? 'woobooster_ai_create_bundle' : 'woobooster_ai_create_rule';
    const DATA_PARAM = IS_BUNDLE ? 'bundle_data' : 'rule_data';
    const ENTITY_LABEL = IS_BUNDLE ? t('entityBundle', 'Bundle') : t('entityRule', 'Rule');
    const ENTITY_LABEL_LOWER = IS_BUNDLE ? t('entityBundleLower', 'bundle') : t('entityRuleLower', 'rule');

    let messages = [];

    // ── History ────────────────────────────────────────────────────

    function loadHistory() {
        const stored = localStorage.getItem(HISTORY_KEY);
        if (!stored) return;
        try {
            messages = JSON.parse(stored);
            if (messages.length > 0) {
                if (emptyState) emptyState.style.display = 'none';
                messages.forEach(function (msg) {
                    appendMessage(msg.role, msg.content, false);
                });
                scrollToBottom();
            }
        } catch (e) {
            messages = [];
        }
    }

    function saveHistory() {
        if (messages.length > 20) {
            messages = messages.slice(-20);
        }
        localStorage.setItem(HISTORY_KEY, JSON.stringify(messages));
    }

    // ── Modal ──────────────────────────────────────────────────────

    openBtn.addEventListener('click', function (e) {
        e.preventDefault();
        modalOverlay.classList.add('wb-modal-active');
        inputField.focus();
        scrollToBottom();
    });

    function closeModal() {
        modalOverlay.classList.remove('wb-modal-active');
    }

    closeBtn.addEventListener('click', closeModal);
    modalOverlay.addEventListener('click', function (e) {
        if (e.target === modalOverlay) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modalOverlay.classList.contains('wb-modal-active')) closeModal();
    });

    // ── Clear Chat ─────────────────────────────────────────────────

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            if (!confirm(t('aiConfirmClearChat', 'Are you sure you want to clear the chat history?'))) return;
            messages = [];
            saveHistory();
            var msgs = chatBody.querySelectorAll('.wb-ai-message:not(#wb-ai-typing-indicator)');
            msgs.forEach(function (el) { el.remove(); });
            removeAllSteps();
            if (emptyState) emptyState.style.display = '';
        });
    }

    // ── Auto-resize + submit state ─────────────────────────────────

    inputField.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = this.scrollHeight + 'px';
        submitBtn.disabled = this.value.trim().length === 0;
    });

    inputField.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            chatForm.dispatchEvent(new Event('submit'));
        }
    });

    // ── Suggestions ────────────────────────────────────────────────

    suggestionBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            var text = btn.dataset.prompt || btn.textContent.trim().replace(/^"|"$/g, '');
            inputField.value = text;
            chatForm.dispatchEvent(new Event('submit'));
        });
    });

    // ── Submit ─────────────────────────────────────────────────────

    chatForm.addEventListener('submit', async function (e) {
        e.preventDefault();

        var text = inputField.value.trim();
        if (!text) return;

        inputField.value = '';
        inputField.style.height = 'auto';
        submitBtn.disabled = true;

        appendMessage('user', text, true);
        messages.push({ role: 'user', content: text });
        saveHistory();

        showTyping();
        showLoadingMessage(t('aiSearchingInfo', 'Searching for information…'));

        try {
            var formData = new FormData();
            formData.append('action', 'woobooster_ai_generate');
            formData.append('nonce', wooboosterAdmin.nonce);
            formData.append('chat_history', JSON.stringify(messages.slice(-10)));
            formData.append('mode', MODE);

            var response = await fetch(wooboosterAdmin.ajaxUrl, {
                method: 'POST',
                body: formData,
            });

            var result = await response.json();
            hideTyping();
            removeLoadingMessage();

            if (result.success) {
                // Show tool steps (what the AI did behind the scenes).
                if (result.data.steps && result.data.steps.length > 0) {
                    renderSteps(result.data.steps);
                }

                if (result.data.is_final) {
                    appendMessage('assistant', result.data.message, true);
                    messages.push({ role: 'assistant', content: result.data.message });
                    saveHistory();

                    appendSystemMessage('success', fmt(t('aiCreatedOpening', '%s created! Opening editor…'), ENTITY_LABEL));

                    if (result.data.edit_url && result.data.edit_url.startsWith(window.location.origin)) {
                        setTimeout(function () {
                            window.location.href = result.data.edit_url;
                        }, 1200);
                    } else {
                        setTimeout(function () {
                            window.location.reload();
                        }, 1500);
                    }
                } else {
                    appendMessage('assistant', result.data.message, true);
                    messages.push({ role: 'assistant', content: result.data.message });
                    saveHistory();
                }
            } else {
                appendSystemMessage('error', (result.data && result.data.message) || t('aiUnknownError', 'Unknown error occurred.'));
            }
        } catch (error) {
            hideTyping();
            removeLoadingMessage();
            appendSystemMessage('error', t('aiConnectionError', 'Connection error. Please check your internet and try again.'));
        }
    });

    // ── DOM Helpers ────────────────────────────────────────────────

    /**
     * Append a chat message. User content is text-only (no HTML injection).
     * Assistant content allows safe HTML (already escaped server-side with wp_kses_post).
     *
     * Special handling: If message contains [RULE]...[/RULE] or [BUNDLE]...[/BUNDLE],
     * extract data and show "Create" button.
     */
    function appendMessage(role, content, scroll) {
        if (emptyState) emptyState.style.display = 'none';

        var msgDiv = document.createElement('div');
        msgDiv.className = 'wb-ai-message wb-ai-message--' + role;

        var bubble = document.createElement('div');
        bubble.className = 'wb-ai-message__content';

        // Check if assistant message contains a data block.
        var blockRegex = new RegExp('\\[' + BLOCK_TAG + '\\](.*?)\\[\\/' + BLOCK_TAG + '\\]', 's');
        var blockMatch = role === 'assistant' ? content.match(blockRegex) : null;
        var displayContent = content;
        var blockData = null;

        if (blockMatch) {
            try {
                blockData = JSON.parse(blockMatch[1].trim());
                displayContent = content.replace(blockRegex, '').trim();
            } catch (e) {
                blockData = null;
            }
        }

        if (role === 'user') {
            // User messages: safe text only — prevents XSS.
            bubble.textContent = content;
        } else {
            // Assistant messages: pre-escaped by server (wp_kses_post).
            bubble.innerHTML = formatMarkdown(displayContent);

            // If we extracted data, append a "Create" button.
            if (blockData) {
                var buttonDiv = document.createElement('div');
                buttonDiv.className = 'wb-ai-rule-action';
                buttonDiv.style.marginTop = '12px';

                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'wb-ai-create-rule-btn';
                btn.textContent = fmt(t('aiCreateThis', 'Create This %s'), ENTITY_LABEL);
                btn.dataset.ruleData = JSON.stringify(blockData);

                btn.addEventListener('click', function () {
                    createEntityFromAI(blockData);
                });

                buttonDiv.appendChild(btn);
                bubble.appendChild(buttonDiv);
            }
        }

        msgDiv.appendChild(bubble);
        chatBody.insertBefore(msgDiv, typingIndicator);

        if (scroll) scrollToBottom();
    }

    /**
     * Handle "Create" button click from AI suggestion.
     */
    function createEntityFromAI(data) {
        if (!data || !data.name) {
            alert(t('aiInvalidData', 'Invalid data. Please try again.'));
            return;
        }

        appendSystemMessage('info', fmt(t('aiCreating', 'Creating %s…'), ENTITY_LABEL_LOWER));

        fetch(wooboosterAdmin.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: buildParams({
                action: CREATE_ACTION,
                nonce: wooboosterAdmin.nonce,
                [DATA_PARAM]: JSON.stringify(data)
            })
        })
            .then(function (r) { return r.json(); })
            .then(function (result) {
                if (result.success) {
                    appendSystemMessage('success', fmt(t('aiCreatedOpening', '%s created! Opening editor…'), ENTITY_LABEL));
                    if (result.data.edit_url && result.data.edit_url.startsWith(window.location.origin)) {
                        setTimeout(function () {
                            window.location.href = result.data.edit_url;
                        }, 1200);
                    } else {
                        setTimeout(function () {
                            window.location.reload();
                        }, 1500);
                    }
                } else {
                    var fallback = fmt(t('aiFailedCreate', 'Failed to create %s.'), ENTITY_LABEL_LOWER);
                    appendSystemMessage('error', (result.data && result.data.message) ? result.data.message : fallback);
                }
            })
            .catch(function () {
                appendSystemMessage('error', t('aiConnectionRetry', 'Connection error. Please try again.'));
            });
    }

    /**
     * Utility function to build URL params.
     */
    function buildParams(obj) {
        return Object.keys(obj).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(obj[k]);
        }).join('&');
    }

    /**
     * Show a system status message (success or error).
     */
    function appendSystemMessage(type, text) {
        var div = document.createElement('div');
        div.className = 'wb-ai-message wb-ai-message--system';

        var inner = document.createElement('div');
        inner.className = 'wb-ai-system-msg wb-ai-system-msg--' + type;
        inner.textContent = text;

        div.appendChild(inner);
        chatBody.insertBefore(div, typingIndicator);
        scrollToBottom();
    }

    /**
     * Render tool steps as small status labels above the AI response.
     */
    function renderSteps(steps) {
        var container = document.createElement('div');
        container.className = 'wb-ai-steps';

        steps.forEach(function (step) {
            var el = document.createElement('div');
            el.className = 'wb-ai-step';

            var icon = getToolIcon(step.tool);
            el.innerHTML = '<span class="wb-ai-step__icon">' + icon + '</span>';

            var label = document.createElement('span');
            label.className = 'wb-ai-step__label';
            label.textContent = step.label;
            el.appendChild(label);

            container.appendChild(el);
        });

        chatBody.insertBefore(container, typingIndicator);
        scrollToBottom();
    }

    function removeAllSteps() {
        chatBody.querySelectorAll('.wb-ai-steps').forEach(function (el) { el.remove(); });
    }

    function getToolIcon(tool) {
        switch (tool) {
            case 'search_store':
                return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
            case 'search_web':
                return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>';
            case 'get_rules':
            case 'get_bundles':
                return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>';
            case 'create_rule':
            case 'update_rule':
            case 'create_bundle':
                return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>';
            default:
                return '';
        }
    }

    /**
     * Escape HTML special characters so the input can be safely embedded into
     * innerHTML after the controlled markdown transformation below.
     */
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        return String(text)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    /**
     * Basic markdown: **bold**, newlines → <br>.
     *
     * The input is first HTML-escaped, then we re-introduce a strictly limited
     * set of safe tags (<strong>, <br>). This prevents any HTML or script the
     * server may have let through wp_kses_post from executing in admin.
     */
    function formatMarkdown(text) {
        if (!text) return '';
        return escapeHtml(text)
            .replace(/\*\*([\s\S]*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
    }

    function showTyping() {
        if (emptyState) emptyState.style.display = 'none';
        typingIndicator.style.display = 'flex';
        scrollToBottom();
    }

    function hideTyping() {
        typingIndicator.style.display = 'none';
    }

    /**
     * Show animated loading indicator with animated dots.
     */
    function showLoadingMessage(text) {
        removeLoadingMessage();

        var container = document.createElement('div');
        container.className = 'wb-ai-loading-message';
        container.id = 'wb-ai-loading-msg';

        var content = document.createElement('div');
        content.className = 'wb-ai-loading-content';
        content.textContent = text + ' ';

        var dots = document.createElement('span');
        dots.className = 'wb-ai-dots';
        for (var i = 0; i < 3; i++) {
            var dot = document.createElement('span');
            dot.textContent = '.';
            dots.appendChild(dot);
        }

        content.appendChild(dots);
        container.appendChild(content);
        chatBody.insertBefore(container, typingIndicator);
        scrollToBottom();
    }

    function removeLoadingMessage() {
        var elem = document.getElementById('wb-ai-loading-msg');
        if (elem) elem.remove();
    }

    function scrollToBottom() {
        chatBody.scrollTop = chatBody.scrollHeight;
    }

    // ── Init ───────────────────────────────────────────────────────
    loadHistory();
});
