/* SIE Full-Page Chat — search-style conversational interface */
(function () {
    'use strict';

    var cfg = window.sieChat || {};
    var agents = cfg.agents || [];
    var selectedAgent = agents.length ? agents[0].key : '';
    var sendArrowSVG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';

    function init() {
        var root = document.getElementById('sie-chat-page-root');
        if (!root) return;

        var title    = cfg.pageTitle || cfg.title || 'Chat with an AI Expert';
        var subtitle = cfg.pageSubtitle || 'Ask anything — powered by our knowledge base.';

        // Build agent selector pills
        var agentHtml = '';
        if (agents.length > 1) {
            agentHtml = '<div class="sie-agent-selector">';
            for (var a = 0; a < agents.length; a++) {
                var ag = agents[a];
                var active = ag.key === selectedAgent ? ' sie-agent-active' : '';
                agentHtml += '<button class="sie-agent-pill' + active + '" data-agent="' + escAttr(ag.key) + '" title="' + escAttr(ag.description) + '">' +
                    escHtml(ag.name) +
                '</button>';
            }
            agentHtml += '</div>';
        }

        root.innerHTML =
            '<div class="sie-chat-page">' +
                '<div class="sie-page-header">' +
                    '<h2>' + escHtml(title) + '</h2>' +
                    '<p>' + escHtml(subtitle) + '</p>' +
                '</div>' +
                agentHtml +
                '<div class="sie-search-bar">' +
                    '<input type="text" class="sie-search-input" placeholder="Ask a question\u2026" autocomplete="off" />' +
                    '<button class="sie-search-btn" aria-label="Send">' + sendArrowSVG + '</button>' +
                '</div>' +
                (cfg.disclaimer ? '<p class="sie-disclaimer">' + escHtml(cfg.disclaimer) + '</p>' : '') +
                '<div class="sie-conversation"></div>' +
            '</div>';

        var input = root.querySelector('.sie-search-input');
        var btn   = root.querySelector('.sie-search-btn');
        var conv  = root.querySelector('.sie-conversation');

        // Agent pill handlers
        var pills = root.querySelectorAll('.sie-agent-pill');
        pills.forEach(function (pill) {
            pill.addEventListener('click', function () {
                selectedAgent = this.getAttribute('data-agent');
                pills.forEach(function (p) { p.classList.remove('sie-agent-active'); });
                this.classList.add('sie-agent-active');
            });
        });

        btn.addEventListener('click', function () { send(input, btn, conv); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(input, btn, conv); }
        });

        input.focus();
    }

    function send(input, btn, conv) {
        var query = input.value.trim();
        if (!query) return;

        // Remove welcome message on first send
        var welcome = conv.querySelector('.sie-welcome');
        if (welcome) welcome.remove();

        // Create exchange container
        var exchange = document.createElement('div');
        exchange.className = 'sie-exchange';
        exchange.innerHTML =
            '<div class="sie-question">' +
                '<span class="sie-question-icon">Q</span>' +
                '<span>' + escHtml(query) + '</span>' +
            '</div>' +
            '<div class="sie-answer sie-thinking">Searching the knowledge base\u2026</div>';
        conv.appendChild(exchange);

        input.value = '';
        setBusy(input, btn, true);

        // Scroll to the new exchange
        exchange.scrollIntoView({ behavior: 'smooth', block: 'start' });

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
            setBusy(input, btn, false);
            var answerEl = exchange.querySelector('.sie-answer');

            if (data.response) {
                answerEl.className = 'sie-answer';
                answerEl.textContent = data.response;

                // Sources
                if (data.sources && data.sources.length) {
                    var sourcesHtml = '<div class="sie-sources">';
                    for (var i = 0; i < data.sources.length; i++) {
                        var s = data.sources[i];
                        sourcesHtml += '<a class="sie-source-tag" href="' + escAttr(s.url) + '" target="_blank">' +
                            escHtml(s.title || 'Source') +
                            '<span class="sie-source-score">' + (s.score ? s.score.toFixed(2) : '') + '</span>' +
                        '</a>';
                    }
                    sourcesHtml += '</div>';
                    exchange.insertAdjacentHTML('beforeend', sourcesHtml);
                }

                // Feedback buttons
                if (data.log_id) {
                    var fbHtml = '<div class="sie-feedback" data-log-id="' + data.log_id + '">' +
                        '<button class="sie-feedback-btn" data-fb="positive" title="Helpful">&#x1F44D;</button>' +
                        '<button class="sie-feedback-btn" data-fb="negative" title="Not helpful">&#x1F44E;</button>' +
                    '</div>';
                    exchange.insertAdjacentHTML('beforeend', fbHtml);

                    // Bind feedback handlers
                    var fbDiv = exchange.querySelector('.sie-feedback');
                    fbDiv.querySelectorAll('.sie-feedback-btn').forEach(function (fbBtn) {
                        fbBtn.addEventListener('click', function () {
                            sendFeedback(fbDiv, this.getAttribute('data-fb'));
                        });
                    });
                }

                // Confidence warning
                if (data.confidence === 'low') {
                    answerEl.style.borderLeft = '3px solid #f59e0b';
                    answerEl.style.paddingLeft = '12px';
                }
            } else if (data.message) {
                answerEl.className = 'sie-answer sie-error';
                answerEl.textContent = data.message;
            } else {
                answerEl.className = 'sie-answer sie-error';
                answerEl.textContent = 'Something went wrong. Please try again.';
            }

            input.focus();
        })
        .catch(function () {
            setBusy(input, btn, false);
            var answerEl = exchange.querySelector('.sie-answer');
            answerEl.className = 'sie-answer sie-error';
            answerEl.textContent = 'Could not connect. Please try again.';
            input.focus();
        });
    }

    function sendFeedback(fbDiv, type) {
        var logId = fbDiv.getAttribute('data-log-id');
        var btns  = fbDiv.querySelectorAll('.sie-feedback-btn');

        // Clear previous
        btns.forEach(function (b) {
            b.className = 'sie-feedback-btn';
        });

        // Highlight selected
        var activeBtn = fbDiv.querySelector('[data-fb="' + type + '"]');
        activeBtn.className = 'sie-feedback-btn sie-active-' + type;

        fetch(cfg.feedbackUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce':   cfg.nonce || '',
            },
            body: JSON.stringify({ log_id: parseInt(logId, 10), feedback: type }),
        });
    }

    function setBusy(input, btn, busy) {
        btn.disabled = busy;
        input.disabled = busy;
    }

    function escHtml(str) {
        var d = document.createElement('div');
        d.textContent = str;
        return d.innerHTML;
    }

    function escAttr(str) {
        return str.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
