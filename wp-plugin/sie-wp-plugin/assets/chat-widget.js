/* SIE Chat Widget */
(function () {
    'use strict';

    const cfg = window.sieChat || {};

    function init() {
        const root = document.getElementById('sie-chat-root');
        if (!root) return;

        root.innerHTML =
            '<div id="sie-chat-panel" hidden>' +
                '<div id="sie-chat-header">' +
                    '<span id="sie-chat-title">' + (cfg.title || 'Ask the Knowledge Base') + '</span>' +
                    '<button id="sie-chat-close" aria-label="Close chat">&times;</button>' +
                '</div>' +
                '<div id="sie-chat-messages" role="log" aria-live="polite"></div>' +
                '<div id="sie-chat-input-row">' +
                    '<input type="text" id="sie-chat-input" placeholder="Ask a question\u2026" autocomplete="off" />' +
                    '<button id="sie-chat-send">Send</button>' +
                '</div>' +
            '</div>' +
            '<button id="sie-chat-toggle" aria-label="Open knowledge chat">&#x1F4AC;</button>';

        document.getElementById('sie-chat-toggle').addEventListener('click', function () {
            var panel = document.getElementById('sie-chat-panel');
            panel.hidden = !panel.hidden;
            if (!panel.hidden) document.getElementById('sie-chat-input').focus();
        });

        document.getElementById('sie-chat-close').addEventListener('click', function () {
            document.getElementById('sie-chat-panel').hidden = true;
        });

        document.getElementById('sie-chat-send').addEventListener('click', send);
        document.getElementById('sie-chat-input').addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); }
        });
    }

    function send() {
        var input = document.getElementById('sie-chat-input');
        var query = input.value.trim();
        if (!query) return;

        addMsg(query, 'user');
        input.value = '';
        setBusy(true);

        var thinkingId = addMsg('Thinking\u2026', 'assistant thinking');

        fetch(cfg.apiUrl, {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   cfg.nonce || '',
            },
            body: JSON.stringify({ query: query }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            removeMsg(thinkingId);
            setBusy(false);
            if (data.response) {
                addMsg(data.response, 'assistant');
            } else if (data.message) {
                addMsg('Error: ' + data.message, 'assistant error');
            } else {
                addMsg('Something went wrong. Please try again.', 'assistant error');
            }
        })
        .catch(function () {
            removeMsg(thinkingId);
            setBusy(false);
            addMsg('Could not connect. Please try again.', 'assistant error');
        });
    }

    var _id = 0;
    function addMsg(text, cls) {
        var id   = 'sie-m-' + (++_id);
        var msgs = document.getElementById('sie-chat-messages');
        var div  = document.createElement('div');
        div.id        = id;
        div.className = 'sie-msg sie-' + cls.split(' ')[0];
        div.textContent = text;
        msgs.appendChild(div);
        msgs.scrollTop = msgs.scrollHeight;
        return id;
    }

    function removeMsg(id) {
        var el = document.getElementById(id);
        if (el) el.remove();
    }

    function setBusy(busy) {
        var btn = document.getElementById('sie-chat-send');
        var inp = document.getElementById('sie-chat-input');
        btn.disabled = busy;
        inp.disabled = busy;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
