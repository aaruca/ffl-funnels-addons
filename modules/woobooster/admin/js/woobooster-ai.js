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

    let messages = [];

    // Open Modal
    openBtn.addEventListener('click', (e) => {
        e.preventDefault();
        modalOverlay.classList.add('active');
        inputField.focus();
    });

    // Close Modal
    const closeModal = () => {
        modalOverlay.classList.remove('active');
    };
    closeBtn.addEventListener('click', closeModal);
    modalOverlay.addEventListener('click', (e) => {
        if (e.target === modalOverlay) closeModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modalOverlay.classList.contains('active')) closeModal();
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

        appendMessage('user', text);
        messages.push({ role: 'user', content: text });

        showTyping();

        try {
            const formData = new FormData();
            formData.append('action', 'woobooster_ai_generate');
            formData.append('nonce', wooboosterAdmin.nonce);

            // Send conversation history (last few messages to save tokens)
            formData.append('chat_history', JSON.stringify(messages.slice(-6)));

            const response = await fetch(ajaxurl, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            hideTyping();

            if (result.success) {
                // If the AI generated the JSON and the rule was created
                if (result.data.is_final) {
                    appendMessage('assistant', result.data.message);

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
                    appendMessage('assistant', result.data.message);
                    messages.push({ role: 'assistant', content: result.data.message });
                }
            } else {
                appendMessage('system', 'Error: ' + (result.data.message || 'Unknown error occurred.'));
            }

        } catch (error) {
            console.error('AI Error:', error);
            hideTyping();
            appendMessage('system', 'Connection error. Please check your internet and try again.');
        }
    });

    function appendMessage(role, content) {
        if (emptyState) emptyState.style.display = 'none';

        const msgDiv = document.createElement('div');
        msgDiv.className = `wb-ai-message wb-ai-message--${role}`;

        // Simple markdown parsing for bold text
        const formattedContent = content
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');

        msgDiv.innerHTML = `<div class="wb-ai-message__content">${formattedContent}</div>`;
        chatBody.insertBefore(msgDiv, typingIndicator);
        scrollToBottom();
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
});
