/**
 * Tina4 Store — tina4-js PWA Frontend
 *
 * Demonstrates: signals, computed, web components, PWA registration,
 * WebSocket order tracking, SSE sales feed, API client.
 */
const { signal, computed, html, Tina4Element, api, ws, sse, pwa } = Tina4;

// ── PWA Registration ──────────────────────────────────────────
pwa.register({
    name: "Tina4 Store",
    shortName: "T4Store",
    themeColor: "#2d6a4f",
    backgroundColor: "#fefae0",
    display: "standalone",
    cacheStrategy: "network-first",
    precache: ["/", "/products", "/css/tina4.min.css", "/css/store.css"],
    offlineRoute: "/offline"
});

// ── Reactive Cart State ───────────────────────────────────────
const cartCount = signal(0);

function updateCartBadge(count, total) {
    var badge = document.getElementById("cart-badge");
    if (badge) {
        if (count > 0) {
            badge.textContent = count;
            badge.style.display = "inline-block";
        } else {
            badge.style.display = "none";
        }
    }
    var totalEl = document.getElementById("cart-total-display");
    if (totalEl) {
        if (count > 0 && total !== undefined) {
            totalEl.textContent = totalEl.dataset.currency + total.toFixed(2);
            totalEl.style.display = "inline";
        } else if (count === 0) {
            totalEl.style.display = "none";
        }
    }
}

function showCartToast(message) {
    var container = document.querySelector(".cart-toast-container");
    if (!container) {
        container = document.createElement("div");
        container.className = "cart-toast-container";
        document.body.appendChild(container);
    }
    var toast = document.createElement("div");
    toast.className = "cart-toast";
    toast.textContent = "\u2713 " + message;
    container.appendChild(toast);
    requestAnimationFrame(function() { toast.classList.add("show"); });
    setTimeout(function() {
        toast.classList.remove("show");
        setTimeout(function() { toast.remove(); }, 300);
    }, 2500);
}

// Fetch initial count from session
api.get("/api/cart/count").then(function(data) {
    if (data && typeof data.count === "number") {
        cartCount.value = data.count;
        updateCartBadge(data.count);
    }
}).catch(function() {});

// Cart badge web component — re-renders reactively when cartCount changes
class CartBadge extends Tina4Element {
    static shadow = false;
    render() {
        const count = cartCount.value;
        if (count === 0) return html``;
        return html`<span class="badge-cart">${count}</span>`;
    }
}
customElements.define("cart-badge", CartBadge);

// ── Add to Cart (AJAX — stays on page) ──────────────────────
document.addEventListener("click", function(e) {
    var btn = e.target.closest(".add-to-cart-btn");
    if (!btn) return;
    e.preventDefault();

    var productId = btn.dataset.productId;
    var label = btn.dataset.label || "Add to Cart";
    var quantity = 1;
    if (btn.dataset.qtyInput) {
        var qtyEl = document.getElementById(btn.dataset.qtyInput);
        if (qtyEl) quantity = parseInt(qtyEl.value, 10) || 1;
    }

    btn.disabled = true;
    btn.textContent = "Adding...";

    api.post("/cart/add", { product_id: parseInt(productId, 10), quantity: quantity })
    .then(function(data) {
        if (data.ok) {
            cartCount.value = data.count;
            updateCartBadge(data.count, data.total);
            btn.textContent = "\u2713 Added!";
            showCartToast(data.product_name + " added to cart");
            setTimeout(function() {
                btn.textContent = label;
                btn.disabled = false;
            }, 1200);
        } else {
            btn.textContent = label;
            btn.disabled = false;
        }
    })
    .catch(function() {
        btn.textContent = label;
        btn.disabled = false;
    });
});

// ── WebSocket Order Tracking ──────────────────────────────────
const orderStatus = signal("pending");
const orderMessages = signal([]);

function trackOrder(orderId) {
    var wsPort = location.port || (location.protocol === "https:" ? "443" : "80");
    var wsProtocol = location.protocol === "https:" ? "wss:" : "ws:";
    var socket = ws.connect(wsProtocol + "//" + location.hostname + ":" + wsPort + "/ws/orders", {
        reconnect: true,
        reconnectDelay: 3000,
        onOpen: function() {
            socket.send({ action: "track", order_id: orderId });
        }
    });

    socket.on("message", function(data) {
        if (data.event === "status_changed") {
            orderStatus.value = data.status;
        }
        orderMessages.value = [...orderMessages.value, data];
    });
}

// Order tracker web component
class OrderTracker extends Tina4Element {
    static shadow = false;

    connectedCallback() {
        super.connectedCallback();
        var orderId = this.getAttribute("order-id");
        var initialStatus = this.getAttribute("status") || "pending";
        orderStatus.value = initialStatus;
        if (orderId) {
            trackOrder(parseInt(orderId, 10));
        }
    }

    render() {
        var statuses = ["pending", "processing", "shipped", "delivered"];
        var current = statuses.indexOf(orderStatus.value);
        return html`
            <div class="order-tracker">
                ${statuses.map(function(s, i) {
                    return html`
                        <div class="step ${i <= current ? 'active' : ''}">
                            <div class="dot"></div>
                            <span>${s}</span>
                        </div>
                    `;
                })}
            </div>
        `;
    }
}
customElements.define("order-tracker", OrderTracker);

// ── SSE Toast Notifications (storefront) ─────────────────────
(function() {
    var evtSource = new EventSource("/api/events/sales");
    evtSource.onmessage = function(e) {
        try {
            var data = JSON.parse(e.data);
            if (data.event === "cart.item_added") {
                showToast(data.data.customer + " added " + data.data.product_name + " to cart", "info");
            }
        } catch(err) {}
    };

    function showToast(message, type) {
        var container = document.querySelector(".sse-toast-container");
        if (!container) {
            container = document.createElement("div");
            container.className = "sse-toast-container";
            document.body.appendChild(container);
        }
        var toast = document.createElement("div");
        toast.className = "sse-toast sse-toast-" + (type || "info");
        toast.textContent = message;
        container.appendChild(toast);
        requestAnimationFrame(function() { toast.classList.add("show"); });
        setTimeout(function() {
            toast.classList.remove("show");
            setTimeout(function() { toast.remove(); }, 300);
        }, 3000);
    }
})();

// ── GraphQL Product Search (handles both desktop + mobile inputs) ──
(function() {
    var pairs = [
        { input: document.getElementById("product-search-desktop"), results: document.getElementById("search-results-desktop") },
        { input: document.getElementById("product-search-mobile"), results: document.getElementById("search-results-mobile") }
    ];

    pairs.forEach(function(pair) {
        var searchInput = pair.input;
        var searchResults = pair.results;
        if (!searchInput || !searchResults) return;

        var debounceTimer = null;

        searchInput.addEventListener("input", function() {
            clearTimeout(debounceTimer);
            var term = searchInput.value.trim();

            if (term.length < 2) {
                searchResults.classList.remove("open");
                searchResults.innerHTML = "";
                return;
            }

            debounceTimer = setTimeout(function() {
                api.graphql("/api/graphql",
                    '{ search_products(term: "' + term.replace(/"/g, '\\"') + '", limit: 8) { id name slug price image_url } }'
                ).then(function(result) {
                    var data = result.data || result;
                    var errors = result.errors;

                    if (errors || !data || !data.search_products) {
                        searchResults.classList.remove("open");
                        return;
                    }

                    var products = data.search_products;
                    if (products.length === 0) {
                        searchResults.innerHTML = '<div class="search-empty">No products found</div>';
                        searchResults.classList.add("open");
                        return;
                    }

                    var items = "";
                    for (var i = 0; i < products.length; i++) {
                        var p = products[i];
                        items += '<div class="search-item">'
                            + '<a href="/products/' + p.slug + '" class="search-item-link">'
                            + '<img src="' + (p.image_url || '/img/placeholder.png') + '" alt="">'
                            + '<div class="search-item-info">'
                            + '<div class="search-item-name">' + p.name + '</div>'
                            + '<div class="search-item-price">$' + Number(p.price).toFixed(2) + '</div>'
                            + '</div></a>'
                            + '<button class="search-cart-btn add-to-cart-btn" data-product-id="' + p.id + '" data-label="&#128722;" title="Add to Cart">&#128722;</button>'
                            + '</div>';
                    }
                    searchResults.innerHTML = items;
                    searchResults.classList.add("open");
                });
            }, 300);
        });

        // Close dropdown when clicking outside
        document.addEventListener("click", function(e) {
            if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                searchResults.classList.remove("open");
            }
        });

        // Close on Escape
        searchInput.addEventListener("keydown", function(e) {
            if (e.key === "Escape") {
                searchResults.classList.remove("open");
                searchInput.blur();
            }
        });
    });
})();

// ── Language Switcher ─────────────────────────────────────────
document.querySelectorAll("[data-lang]").forEach(function(btn) {
    btn.addEventListener("click", function() {
        api.get("/api/locale/" + btn.dataset.lang).then(function() {
            location.reload();
        });
    });
});

// ── Currency Switcher (Forex via Api client) ─────────────────
(function() {
    var select = document.getElementById("currency-select");
    if (!select) return;

    var rates = null;
    var baseCurrency = "USD";
    var symbols = { USD: "$", EUR: "€", GBP: "£", ZAR: "R", JPY: "¥" };

    // Restore saved currency
    var saved = localStorage.getItem("store_currency");
    if (saved && select.querySelector('option[value="' + saved + '"]')) {
        select.value = saved;
    }

    // Fetch rates once
    api.get("/api/forex/rates").then(function(data) {
        if (data && data.rates) {
            rates = data.rates;
            // Apply if not USD
            if (select.value !== "USD") {
                convertAllPrices(select.value);
            }
        }
    }).catch(function() {});

    select.addEventListener("change", function() {
        var currency = select.value;
        localStorage.setItem("store_currency", currency);
        if (rates) {
            convertAllPrices(currency);
        }
    });

    function convertAllPrices(currency) {
        var rate = rates[currency] || 1;
        var sym = symbols[currency] || "$";
        // Find all price elements and convert from USD
        var priceEls = document.querySelectorAll("[data-price-usd], .product-price, .price");
        priceEls.forEach(function(el) {
            var usd = parseFloat(el.dataset.priceUsd || el.textContent.replace(/[^0-9.]/g, ""));
            if (isNaN(usd)) return;
            if (!el.dataset.priceUsd) el.dataset.priceUsd = usd.toFixed(2);
            var converted = (usd * rate).toFixed(currency === "JPY" ? 0 : 2);
            el.textContent = sym + converted;
        });
    }
})();

// ── WebSocket Live Chat (Customer Widget) ────────────────────
(function() {
    var isAdmin = document.body.dataset.role === "admin";

    // Customer-side chat widget (only for non-admin pages)
    if (!isAdmin) {
        var toggle = document.getElementById("chat-toggle");
        var panel = document.getElementById("chat-panel");
        var input = document.getElementById("chat-input");
        var sendBtn = document.getElementById("chat-send");
        var messages = document.getElementById("chat-messages");
        var statusDot = document.getElementById("chat-status");
        if (!toggle || !panel) return;

        var chatSocket = null;
        var senderName = document.body.dataset.customerName || "Guest";

        toggle.addEventListener("click", function() {
            panel.classList.toggle("open");
            if (panel.classList.contains("open") && !chatSocket) {
                connectChat();
            }
            if (panel.classList.contains("open") && input) {
                input.focus();
            }
        });

        function connectChat() {
            var wsPort = location.port || (location.protocol === "https:" ? "443" : "80");
            var wsProtocol = location.protocol === "https:" ? "wss:" : "ws:";
            chatSocket = new WebSocket(wsProtocol + "//" + location.hostname + ":" + wsPort + "/ws/chat");

            chatSocket.onopen = function() {
                if (statusDot) {
                    statusDot.classList.add("online");
                    statusDot.title = "Connected";
                }
            };

            chatSocket.onmessage = function(e) {
                try {
                    var data = JSON.parse(e.data);
                    if (data.type === "message") {
                        addMessage(data.sender, data.text, data.is_admin);
                    } else if (data.type === "system") {
                        addSystemMessage(data.message);
                    }
                } catch(err) {}
            };

            chatSocket.onclose = function() {
                if (statusDot) {
                    statusDot.classList.remove("online");
                    statusDot.title = "Disconnected";
                }
                chatSocket = null;
                setTimeout(function() {
                    if (panel.classList.contains("open")) connectChat();
                }, 3000);
            };
        }

        function sendMessage() {
            var text = input.value.trim();
            if (!text || !chatSocket || chatSocket.readyState !== WebSocket.OPEN) return;
            chatSocket.send(JSON.stringify({
                type: "message",
                sender: senderName,
                text: text
            }));
            input.value = "";
        }

        sendBtn.addEventListener("click", sendMessage);
        input.addEventListener("keydown", function(e) {
            if (e.key === "Enter") sendMessage();
        });

        function addMessage(sender, text, fromAdmin) {
            var div = document.createElement("div");
            div.className = "chat-msg " + (fromAdmin ? "chat-msg-admin" : "chat-msg-user");
            div.innerHTML = '<span class="chat-msg-sender">' + escapeHtml(sender) + '</span>'
                + '<span class="chat-msg-text">' + escapeHtml(text) + '</span>';
            messages.appendChild(div);
            messages.scrollTop = messages.scrollHeight;
        }

        function addSystemMessage(text) {
            var div = document.createElement("div");
            div.className = "chat-msg-system";
            div.textContent = text;
            messages.appendChild(div);
            messages.scrollTop = messages.scrollHeight;
        }
    }

    // Admin-side: sidebar notification badge for incoming chats
    // (runs on ALL admin pages, not just helpdesk)
    if (isAdmin) {
        var badge = document.getElementById("helpdesk-badge");
        var navLink = document.getElementById("helpdesk-nav-link");
        if (!badge) return;

        var pendingCount = 0;
        var adminNotifySocket = null;

        function connectAdminNotify() {
            var wsPort = location.port || (location.protocol === "https:" ? "443" : "80");
            var wsProtocol = location.protocol === "https:" ? "wss:" : "ws:";
            adminNotifySocket = new WebSocket(wsProtocol + "//" + location.hostname + ":" + wsPort + "/ws/chat");

            adminNotifySocket.onopen = function() {
                adminNotifySocket.send(JSON.stringify({ type: "join_admin" }));
            };

            adminNotifySocket.onmessage = function(e) {
                try {
                    var data = JSON.parse(e.data);
                    if (data.type === "system" && data.client_id) {
                        if (data.message && data.message.indexOf("joined") !== -1) {
                            pendingCount++;
                            updateBadge();
                        } else if (data.message && data.message.indexOf("left") !== -1) {
                            pendingCount = Math.max(0, pendingCount - 1);
                            updateBadge();
                        }
                    }
                    if (data.type === "message" && !data.is_admin) {
                        // Customer sent a message — pulse the badge
                        badge.classList.add("active");
                        if (pendingCount === 0) pendingCount = 1;
                        updateBadge();
                    }
                } catch(err) {}
            };

            adminNotifySocket.onclose = function() {
                adminNotifySocket = null;
                setTimeout(connectAdminNotify, 5000);
            };
        }

        function updateBadge() {
            if (pendingCount > 0) {
                badge.textContent = pendingCount;
                badge.classList.add("active");
            } else {
                badge.textContent = "";
                badge.classList.remove("active");
            }
        }

        // Don't connect notify socket on helpdesk page (helpdesk.js handles it)
        if (!document.getElementById("helpdesk-chat")) {
            connectAdminNotify();
        }
    }

    function escapeHtml(str) {
        var d = document.createElement("div");
        d.textContent = str || "";
        return d.innerHTML;
    }
})();
