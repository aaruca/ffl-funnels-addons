document.addEventListener('DOMContentLoaded', function () {
    const openBtn = document.getElementById('wb-open-ai-modal');
    if (!openBtn) return;

    const modalOverlay = document.getElementById('wb-ai-modal-overlay');
    const closeBtn = document.getElementById('wb-close-ai-modal');
    const chatForm = document.getElementById('wb-ai-chat-form');
    const inputField = document.getElementById('wb-ai-input');
    const chatBody = document.getElementById('wb-ai-chat-body');
    const emptyState = document.getElementById('wb-ai-empty-state');
    const typingIndicator = document.getElementById('wb-ai-typing-indicator');
    const suggestionBtns = document.querySelectorAll('.wb-ai-suggestion-btn');
    const submitBtn = document.getElementById('wb-ai-submit-btn');

    // Create Clear Chat button if it doesn't exist in DOM, or we can just append it to the header
    let clearChatBtn = document.getElementById('wb-clear-ai-chat');
    if (!clearChatBtn) {
        clearChatBtn = document.createElement('button');
        clearChatBtn.id = 'wb-clear-ai-chat';
        clearChatBtn.type = 'button';
        clearChatBtn.className = 'button button-secondary button-small';
        clearChatBtn.style.marginLeft = 'auto';
        clearChatBtn.style.marginRight = '10px';
        clearChatBtn.textContent = 'Clear Chat';

        const header = document.querySelector('.wb-ai-modal__header');
        if (header) {
            header.insertBefore(clearChatBtn, closeBtn);
        }
    }

    const HISTORY_KEY = 'wb_ai_chat_history';
    let messages = [];

    // Load history from localStorage
    function loadHistory() {
        const stored = localStorage.getItem(HISTORY_KEY);
        if (stored) {
            try {
                messages = JSON.parse(stored);
                // Render existing messages
                if (messages.length > 0) {
                    if (emptyState) emptyState.style.display = 'none';
                    messages.forEach(msg => {
                        appendMessageDOM(msg.role, msg.content, false);
                    });
                    scrollToBottom();
                }
            } catch (e) {
                console.error('Failed to parse chat history', e);
                messages = [];
            }
        }
    }

    // Save history to localStorage
    function saveHistory() {
        // Keep only the last 20 messages to prevent excessive growth
        if (messages.length > 20) {
            messages = messages.slice(-20);
        }
        localStorage.setItem(HISTORY_KEY, JSON.stringify(messages));
    }

    // Clear history
    clearChatBtn.addEventListener('click', () => {
        if (confirm('Are you sure you want to clear the chat history?')) {
            messages = [];
            saveHistory();
            // Remove all message divs
            const messageDivs = chatBody.querySelectorAll('.wb-ai-message:not(#wb-ai-typing-indicator)');
            messageDivs.forEach(div => div.remove());
            if (emptyState) emptyState.style.display = 'block';
        }
    });

    // Open Modal
    openBtn.addEventListener('click', (e) => {
        e.preventDefault();
        modalOverlay.classList.add('wb-modal-active');
        inputField.focus();
        scrollToBottom();
    });

    // Close Modal
    const closeModal = () => {
        modalOverlay.classList.remove('wb-modal-active');
    };
    closeBtn.addEventListener('click', closeModal);
    modalOverlay.addEventListener('click', (e) => {
        if (e.target === modalOverlay) closeModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modalOverlay.classList.contains('wb-modal-active')) closeModal();
    });

    // Auto-resize textarea
    inputField.addEventListener('input', function () {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';

        // Enable/Disable submit
        if (this.value.trim().length > 0) {
            submitBtn.style.opacity = '1';
            submitBtn.style.cursor = 'pointer';
        } else {
            submitBtn.style.opacity = '0.5';
            submitBtn.style.cursor = 'not-allowed';
        }
    });

    // Submit via Enter
    inputField.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            chatForm.dispatchEvent(new Event('submit'));
        }
    });

    // Suggestions
    suggestionBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const text = btn.textContent.trim().replace(/^"|"$/g, '');
            inputField.value = text;
            chatForm.dispatchEvent(new Event('submit'));
        });
    });

    // Form Submit
    chatForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const text = inputField.value.trim();
        if (!text) return;

        inputField.value = '';
        inputField.style.height = 'auto';
        submitBtn.style.opacity = '0.5';

        appendMessageDOM('user', text, true);
        messages.push({ role: 'user', content: text });
        saveHistory();

        showTyping();

        try {
            const formData = new FormData();
            formData.append('action', 'woobooster_ai_generate');
            formData.append('nonce', wooboosterAdmin.nonce);

            // Send conversation history limit strictly for context window safely
            // Send the last 10 messages for context (tool messages handles backend)
            formData.append('chat_history', JSON.stringify(messages.slice(-10)));

            const response = await fetch(wooboosterAdmin.ajaxUrl, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            hideTyping();

            if (result.success) {
                // If the AI generated the JSON and the rule was created
                if (result.data.is_final) {
                    appendMessageDOM('assistant', result.data.message, true);
                    messages.push({ role: 'assistant', content: result.data.message });
                    saveHistory();

                    // Show success block and reload
                    const div = document.createElement('div');
                    div.className = 'wb-ai-message wb-ai-message--system';
                    div.innerHTML = `<div class="wb-ai-message__content" style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0;">
                        Rule generated successfully! Reloading page...</div>`;
                    chatBody.insertBefore(div, typingIndicator);
                    scrollToBottom();

                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    // Normal conversation / clarifying question
                    appendMessageDOM('assistant', result.data.message, true);
                    messages.push({ role: 'assistant', content: result.data.message });
                    saveHistory();
                }
            } else {
                appendMessageDOM('system', 'Error: ' + (result.data.message || 'Unknown error occurred.'), true);
            }

        } catch (error) {
            console.error('AI Error:', error);
            hideTyping();
            appendMessageDOM('system', 'Connection error. Please check your internet and try again.', true);
        }
    });

    function appendMessageDOM(role, content, scroll = true) {
        if (emptyState) emptyState.style.display = 'none';

        const msgDiv = document.createElement('div');
        msgDiv.className = `wb-ai-message wb-ai-message--${role}`;

        // Simple markdown parsing for bold text
        const formattedContent = content
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');

        msgDiv.innerHTML = `<div class="wb-ai-message__content">${formattedContent}</div>`;
        chatBody.insertBefore(msgDiv, typingIndicator);

        if (scroll) {
            scrollToBottom();
        }
    }

    function showTyping() {
        if (emptyState) emptyState.style.display = 'none';
        typingIndicator.style.display = 'flex';
        scrollToBottom();
    }

    function hideTyping() {
        typingIndicator.style.display = 'none';
    }

    function scrollToBottom() {
        chatBody.scrollTop = chatBody.scrollHeight;
    }

    // Initialize
    loadHistory();
});
