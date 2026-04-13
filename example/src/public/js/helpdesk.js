/**
 * Admin Help Desk — Multi-session WebSocket chat manager
 *
 * Connects as admin to /ws/chat, tracks individual customer sessions,
 * and allows switching between active chats.
 */
(function() {
    var sessionList = document.getElementById("chat-session-list");
    var noChatsMsg = document.getElementById("no-chats-msg");
    var chatTitle = document.getElementById("helpdesk-chat-title");
    var chatStatus = document.getElementById("helpdesk-chat-status");
    var messagesEl = document.getElementById("helpdesk-messages");
    var inputEl = document.getElementById("helpdesk-input");
    var sendBtn = document.getElementById("helpdesk-send");
    if (!sessionList || !messagesEl) return;

    // Track chat sessions: { clientId: { name, messages: [{sender, text, isAdmin, isSystem}], unread } }
    var sessions = {};
    var activeSession = null;
    var chatSocket = null;

    // Clear sidebar badge since we're on the helpdesk page
    var sidebarBadge = document.getElementById("helpdesk-badge");
    if (sidebarBadge) {
        sidebarBadge.textContent = "";
        sidebarBadge.classList.remove("active");
    }

    // Load chat history from database, then connect WebSocket
    loadChatHistory(function() {
        connectChat();
    });

    function loadChatHistory(callback) {
        var xhr = new XMLHttpRequest();
        xhr.open("GET", "/api/chat/history", true);
        xhr.setRequestHeader("Accept", "application/json");
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                if (xhr.status === 200) {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.sessions && data.sessions.length > 0) {
                            for (var i = 0; i < data.sessions.length; i++) {
                                var s = data.sessions[i];
                                addSession(s.client_id, s.name);
                                for (var j = 0; j < s.messages.length; j++) {
                                    var m = s.messages[j];
                                    addMessageToSession(s.client_id, m.sender, m.text, m.is_admin, false);
                                }
                            }
                        }
                    } catch(e) {
                        console.error("Failed to parse chat history:", e);
                    }
                }
                if (callback) callback();
            }
        };
        xhr.send();
    }

    function connectChat() {
        var wsPort = location.port || (location.protocol === "https:" ? "443" : "80");
        var wsProtocol = location.protocol === "https:" ? "wss:" : "ws:";
        chatSocket = new WebSocket(wsProtocol + "//" + location.hostname + ":" + wsPort + "/ws/chat");

        chatSocket.onopen = function() {
            if (chatStatus) {
                chatStatus.classList.add("online");
                chatStatus.title = "Connected";
            }
            // Join as admin
            chatSocket.send(JSON.stringify({ type: "join_admin" }));
        };

        chatSocket.onmessage = function(e) {
            try {
                var data = JSON.parse(e.data);

                if (data.type === "system") {
                    // Customer joined or left
                    if (data.client_id) {
                        if (data.message && data.message.indexOf("joined") !== -1) {
                            addSession(data.client_id, data.client_id);
                            addMessageToSession(data.client_id, null, data.message, false, true);
                        } else if (data.message && data.message.indexOf("left") !== -1) {
                            addMessageToSession(data.client_id, null, data.message, false, true);
                            markSessionOffline(data.client_id);
                        }
                    }
                    return;
                }

                if (data.type === "message" && data.client_id) {
                    // Don't show our own admin messages back (we already rendered them locally)
                    if (data.is_admin) return;

                    var clientId = data.client_id;
                    if (!sessions[clientId]) {
                        addSession(clientId, data.sender || clientId);
                    }
                    addMessageToSession(clientId, data.sender, data.text, data.is_admin, false);

                    // Mark unread if not viewing this session
                    if (activeSession !== clientId) {
                        sessions[clientId].unread++;
                        renderSessionList();
                    }
                }
            } catch(err) {
                console.error("Helpdesk parse error:", err);
            }
        };

        chatSocket.onclose = function() {
            if (chatStatus) {
                chatStatus.classList.remove("online");
                chatStatus.title = "Disconnected";
            }
            chatSocket = null;
            setTimeout(connectChat, 3000);
        };
    }

    function addSession(clientId, name) {
        if (sessions[clientId]) return;
        sessions[clientId] = {
            name: name || clientId,
            messages: [],
            unread: 0,
            online: true
        };
        renderSessionList();

        // Auto-select first session
        if (!activeSession) {
            selectSession(clientId);
        }
    }

    function markSessionOffline(clientId) {
        if (sessions[clientId]) {
            sessions[clientId].online = false;
            renderSessionList();
        }
    }

    function addMessageToSession(clientId, sender, text, isAdmin, isSystem) {
        if (!sessions[clientId]) {
            addSession(clientId, sender || clientId);
        }
        sessions[clientId].messages.push({
            sender: sender,
            text: text,
            isAdmin: isAdmin,
            isSystem: isSystem
        });

        // If this is the active session, render the message immediately
        if (activeSession === clientId) {
            renderMessages();
        }
    }

    function selectSession(clientId) {
        activeSession = clientId;
        var sess = sessions[clientId];
        if (sess) {
            sess.unread = 0;
            chatTitle.textContent = "Chat with " + escapeHtml(sess.name);
            inputEl.disabled = false;
            sendBtn.disabled = false;
            inputEl.focus();
        }
        renderSessionList();
        renderMessages();
    }

    function renderSessionList() {
        var keys = Object.keys(sessions);
        if (keys.length === 0) {
            sessionList.innerHTML = '<li class="helpdesk-empty" id="no-chats-msg">Waiting for customers...</li>';
            return;
        }

        var html = "";
        for (var i = 0; i < keys.length; i++) {
            var id = keys[i];
            var sess = sessions[id];
            var isActive = activeSession === id;
            var statusClass = sess.online ? "online" : "";
            var unreadBadge = sess.unread > 0 ? '<span class="helpdesk-unread">' + sess.unread + '</span>' : '';

            html += '<li class="helpdesk-session-item' + (isActive ? ' active' : '') + '" data-client-id="' + id + '">'
                + '<span class="chat-status ' + statusClass + '"></span>'
                + '<span class="helpdesk-session-name">' + escapeHtml(sess.name) + '</span>'
                + unreadBadge
                + '</li>';
        }
        sessionList.innerHTML = html;

        // Attach click handlers
        var items = sessionList.querySelectorAll(".helpdesk-session-item");
        for (var j = 0; j < items.length; j++) {
            items[j].addEventListener("click", function() {
                selectSession(this.dataset.clientId);
            });
        }
    }

    function renderMessages() {
        if (!activeSession || !sessions[activeSession]) {
            messagesEl.innerHTML = '';
            return;
        }

        var msgs = sessions[activeSession].messages;
        var html = "";
        for (var i = 0; i < msgs.length; i++) {
            var m = msgs[i];
            if (m.isSystem) {
                html += '<div class="chat-msg-system">' + escapeHtml(m.text) + '</div>';
            } else {
                var cls = m.isAdmin ? "chat-msg-admin" : "chat-msg-user";
                html += '<div class="chat-msg ' + cls + '">'
                    + '<span class="chat-msg-sender">' + escapeHtml(m.sender || "Guest") + '</span>'
                    + '<span class="chat-msg-text">' + escapeHtml(m.text) + '</span>'
                    + '</div>';
            }
        }
        messagesEl.innerHTML = html;
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function sendReply() {
        var text = inputEl.value.trim();
        if (!text || !chatSocket || chatSocket.readyState !== WebSocket.OPEN || !activeSession) return;

        chatSocket.send(JSON.stringify({
            type: "message",
            sender: "Support",
            text: text,
            target_client_id: activeSession
        }));

        // Add to local session immediately (don't wait for echo)
        addMessageToSession(activeSession, "Support", text, true, false);
        inputEl.value = "";
        inputEl.focus();
    }

    sendBtn.addEventListener("click", sendReply);
    inputEl.addEventListener("keydown", function(e) {
        if (e.key === "Enter") sendReply();
    });

    function escapeHtml(str) {
        var d = document.createElement("div");
        d.textContent = str || "";
        return d.innerHTML;
    }
})();
