/* SIE Chat Widget */
(function () {
    'use strict';

    const cfg = window.sieChat || {};
    var agents = cfg.agents || [];
    var selectedAgent = agents.length ? agents[0].key : '';

    function init() {
        const root = document.getElementById('sie-chat-root');
        if (!root) return;

        // Agent select dropdown
        var agentSelect = '';
        if (agents.length > 1) {
            agentSelect = '<select id="sie-chat-agent">';
            for (var a = 0; a < agents.length; a++) {
                agentSelect += '<option value="' + agents[a].key + '">' + agents[a].name + '</option>';
            }
            agentSelect += '</select>';
        }

        root.innerHTML =
            '<div id="sie-chat-panel" hidden>' +
                '<div id="sie-chat-header">' +
                    '<span id="sie-chat-title">' + (cfg.title || 'Ask the Knowledge Base') + '</span>' +
                    '<button id="sie-chat-close" aria-label="Close chat">&times;</button>' +
                '</div>' +
                (agentSelect ? '<div id="sie-chat-agent-row">' + agentSelect + '</div>' : '') +
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

        var agentEl = document.getElementById('sie-chat-agent');
        if (agentEl) {
            agentEl.addEventListener('change', function () {
                selectedAgent = this.value;
            });
        }

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
            body: JSON.stringify({ query: query, agent: selectedAgent }),
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
