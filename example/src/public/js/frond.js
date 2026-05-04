var _frondModule = (() => {
  var __defProp = Object.defineProperty;
  var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __export = (target, all) => {
    for (var name in all)
      __defProp(target, name, { get: all[name], enumerable: true });
  };
  var __copyProps = (to, from, except, desc) => {
    if (from && typeof from === "object" || typeof from === "function") {
      for (let key of __getOwnPropNames(from))
        if (!__hasOwnProp.call(to, key) && key !== except)
          __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
    }
    return to;
  };
  var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);

  // src/js/frond.ts
  var frond_exports = {};
  __export(frond_exports, {
    frond: () => frond
  });
  var _token = null;
  function request(url, options) {
    let opts;
    if (typeof options === "function") {
      opts = { onSuccess: options };
    } else {
      opts = options || {};
    }
    const method = (opts.method || "GET").toUpperCase();
    const xhr = new XMLHttpRequest();
    xhr.open(method, url, true);
    if (_token !== null) {
      xhr.setRequestHeader("Authorization", "Bearer " + _token);
    }
    if (opts.headers) {
      for (const key in opts.headers) {
        if (Object.prototype.hasOwnProperty.call(opts.headers, key)) {
          xhr.setRequestHeader(key, opts.headers[key]);
        }
      }
    }
    let body = null;
    if (opts.body !== void 0 && opts.body !== null) {
      if (opts.body instanceof FormData) {
        body = opts.body;
      } else if (typeof opts.body === "object") {
        body = JSON.stringify(opts.body);
        xhr.setRequestHeader("Content-Type", "application/json; charset=UTF-8");
      } else if (typeof opts.body === "string") {
        body = opts.body;
        xhr.setRequestHeader("Content-Type", "text/plain; charset=UTF-8");
      }
    }
    xhr.onload = function() {
      const freshToken = xhr.getResponseHeader("FreshToken");
      if (freshToken && freshToken !== "") {
        _token = freshToken;
      }
      let content = xhr.response;
      try {
        content = JSON.parse(content);
      } catch {
      }
      if (xhr.responseURL) {
        const requested = new URL(url, window.location.href).href;
        if (xhr.responseURL !== requested) {
          window.location.href = xhr.responseURL;
          return;
        }
      }
      if (xhr.status >= 200 && xhr.status < 400) {
        if (opts.onSuccess) opts.onSuccess(content, xhr.status, xhr);
      } else {
        if (opts.onError) opts.onError(xhr.status, xhr);
      }
    };
    xhr.onerror = function() {
      if (opts.onError) opts.onError(xhr.status, xhr);
    };
    xhr.send(body);
  }
  function inject(html, target) {
    if (!html) return "";
    const parser = new DOMParser();
    const wrapped = html.includes("<html>") ? html : "<body>" + html + "</body></html>";
    const doc = parser.parseFromString(wrapped, "text/html");
    const body = doc.querySelector("body");
    const scripts = body.querySelectorAll("script");
    scripts.forEach(function(s) {
      s.remove();
    });
    if (target !== null) {
      const el = document.getElementById(target);
      if (!el) return "";
      if (body.children.length > 0) {
        el.replaceChildren.apply(el, Array.from(body.children));
      } else {
        el.innerHTML = body.innerHTML;
      }
      scripts.forEach(function(script) {
        const ns = document.createElement("script");
        ns.type = "text/javascript";
        ns.async = true;
        if (script.src) {
          ns.src = script.src;
        } else {
          ns.textContent = script.textContent;
        }
        el.appendChild(ns);
      });
      return "";
    }
    scripts.forEach(function(script) {
      const ns = document.createElement("script");
      ns.type = "text/javascript";
      ns.async = true;
      ns.textContent = script.textContent;
      document.body.appendChild(ns);
    });
    return body.innerHTML;
  }
  function load(url, target, callback) {
    const targetId = target || "content";
    request(url, {
      method: "GET",
      onSuccess: function(data, _status) {
        if (document.getElementById(targetId)) {
          const html = inject(data, targetId);
          if (callback) callback(html, data);
        } else {
          if (callback) callback(data);
        }
      }
    });
  }
  function post(url, data, target, callback) {
    const targetId = target || "content";
    request(url, {
      method: "POST",
      body: data,
      onSuccess: function(responseData) {
        let html = "";
        if (responseData && responseData.message !== void 0) {
          html = inject(responseData.message, targetId);
        } else if (document.getElementById(targetId)) {
          html = inject(responseData, targetId);
        } else {
          if (callback) callback(responseData);
          return;
        }
        if (callback) callback(html, responseData);
      }
    });
  }
  var form = {
    /**
     * Collect all form field values into a FormData object.
     *
     * Handles inputs, selects, textareas, file uploads (including
     * multi-file), checkboxes, and radio buttons. Updates formToken
     * hidden fields automatically.
     *
     * @param formId - DOM id of the form (without '#').
     * @returns Populated FormData instance.
     */
    collect: function(formId) {
      const fd = new FormData();
      const elements = document.querySelectorAll("#" + formId + " select, #" + formId + " input, #" + formId + " textarea");
      for (let i = 0; i < elements.length; i++) {
        const el = elements[i];
        if (el.name === "formToken" && _token !== null) {
          el.value = _token;
        }
        if (!el.name) continue;
        if (el.type === "file") {
          const files = el.files;
          if (files) {
            for (let f = 0; f < files.length; f++) {
              const file = files[f];
              if (file !== void 0) {
                let name = el.name;
                if (files.length > 1 && !name.includes("[")) {
                  name = name + "[]";
                }
                fd.append(name, file, file.name);
              }
            }
          }
        } else if (el.type === "checkbox" || el.type === "radio") {
          if (el.checked) {
            fd.append(el.name, el.value);
          } else if (el.type !== "radio") {
            fd.append(el.name, "0");
          }
        } else {
          fd.append(el.name, el.value === "" ? "" : el.value);
        }
      }
      return fd;
    },
    /**
     * Collect form data and POST it to a URL. Inject response into target.
     *
     * @param formId   - DOM id of the form.
     * @param url      - URL to POST to.
     * @param target   - DOM id to inject response into (default: "message").
     * @param callback - Optional callback.
     */
    submit: function(formId, url, target, callback) {
      const data = form.collect(formId);
      post(url, data, target || "message", callback);
    },
    /**
     * Load a form via the given action and inject response HTML.
     *
     * Accepts friendly names: "create", "edit" map to GET; "delete" maps
     * to DELETE.
     *
     * @param action  - HTTP method or friendly name.
     * @param url     - URL to fetch.
     * @param target  - DOM id to inject into (default: "form").
     * @param callback - Optional callback.
     */
    show: function(action, url, target, callback) {
      let method = action.toUpperCase();
      if (action === "create" || action === "edit") method = "GET";
      if (action === "delete") method = "DELETE";
      const targetId = target || "form";
      request(url, {
        method,
        onSuccess: function(data) {
          let html = "";
          if (data && data.message !== void 0) {
            html = inject(data.message, targetId);
          } else if (document.getElementById(targetId)) {
            html = inject(data, targetId);
          } else {
            if (callback) callback(data);
            return;
          }
          if (callback) callback(html);
        }
      });
    }
  };
  function wsConnect(url, options) {
    const opts = {
      reconnect: true,
      reconnectDelay: 1e3,
      maxReconnectDelay: 3e4,
      maxReconnectAttempts: Infinity,
      protocols: [],
      onOpen: function() {
      },
      onClose: function() {
      },
      onError: function() {
      },
      ...options || {}
    };
    let socket = null;
    let intentionalClose = false;
    let currentDelay = opts.reconnectDelay;
    let attempts = 0;
    let reconnectTimer = null;
    const listeners = {
      message: [],
      open: [],
      close: [],
      error: []
    };
    const managed = {
      status: "connecting",
      send: function(data) {
        if (!socket || socket.readyState !== WebSocket.OPEN) {
          throw new Error("[frond] WebSocket is not connected");
        }
        socket.send(typeof data === "string" ? data : JSON.stringify(data));
      },
      on: function(event, handler) {
        if (!listeners[event]) listeners[event] = [];
        listeners[event].push(handler);
        return function() {
          const arr = listeners[event];
          const idx = arr.indexOf(handler);
          if (idx >= 0) arr.splice(idx, 1);
        };
      },
      close: function(code, reason) {
        intentionalClose = true;
        if (reconnectTimer) {
          clearTimeout(reconnectTimer);
          reconnectTimer = null;
        }
        if (socket) {
          socket.close(code || 1e3, reason || "");
        }
        managed.status = "closed";
      }
    };
    function parseMessage(data) {
      if (typeof data !== "string") return data;
      try {
        return JSON.parse(data);
      } catch {
        return data;
      }
    }
    function scheduleReconnect() {
      if (!opts.reconnect || attempts >= opts.maxReconnectAttempts) return;
      attempts++;
      managed.status = "reconnecting";
      reconnectTimer = setTimeout(function() {
        reconnectTimer = null;
        connect();
      }, currentDelay);
      currentDelay = Math.min(currentDelay * 2, opts.maxReconnectDelay);
    }
    function connect() {
      managed.status = attempts > 0 ? "reconnecting" : "connecting";
      try {
        socket = new WebSocket(url, opts.protocols);
      } catch {
        managed.status = "closed";
        return;
      }
      socket.onopen = function() {
        managed.status = "open";
        attempts = 0;
        currentDelay = opts.reconnectDelay;
        opts.onOpen();
        for (const fn of listeners.open) fn();
      };
      socket.onmessage = function(event) {
        const parsed = parseMessage(event.data);
        for (const fn of listeners.message) fn(parsed);
      };
      socket.onclose = function(event) {
        managed.status = "closed";
        opts.onClose(event.code, event.reason);
        for (const fn of listeners.close) fn(event.code, event.reason);
        if (!intentionalClose) {
          scheduleReconnect();
        }
      };
      socket.onerror = function(event) {
        opts.onError(event);
        for (const fn of listeners.error) fn(event);
      };
    }
    connect();
    return managed;
  }
  function sseConnect(url, options) {
    const opts = {
      reconnect: true,
      reconnectDelay: 1e3,
      maxReconnectDelay: 3e4,
      maxReconnectAttempts: Infinity,
      events: [],
      json: true,
      onOpen: function() {
      },
      onClose: function() {
      },
      onError: function() {
      },
      ...options || {}
    };
    let source = null;
    let intentionalClose = false;
    let currentDelay = opts.reconnectDelay;
    let attempts = 0;
    let reconnectTimer = null;
    const listeners = {
      message: [],
      open: [],
      close: [],
      error: []
    };
    const managed = {
      status: "connecting",
      on: function(event, handler) {
        if (!listeners[event]) listeners[event] = [];
        listeners[event].push(handler);
        return function() {
          const arr = listeners[event];
          const idx = arr.indexOf(handler);
          if (idx >= 0) arr.splice(idx, 1);
        };
      },
      close: function() {
        intentionalClose = true;
        if (reconnectTimer) {
          clearTimeout(reconnectTimer);
          reconnectTimer = null;
        }
        if (source) {
          source.close();
          source = null;
        }
        managed.status = "closed";
      }
    };
    function parseData(raw) {
      if (!opts.json) return raw;
      try {
        return JSON.parse(raw);
      } catch {
        return raw;
      }
    }
    function dispatch(data, eventName) {
      for (const fn of listeners.message) fn(data, eventName || void 0);
    }
    function scheduleReconnect() {
      if (!opts.reconnect || attempts >= opts.maxReconnectAttempts) return;
      attempts++;
      managed.status = "reconnecting";
      reconnectTimer = setTimeout(function() {
        reconnectTimer = null;
        connect();
      }, currentDelay);
      currentDelay = Math.min(currentDelay * 2, opts.maxReconnectDelay);
    }
    function connect() {
      managed.status = attempts > 0 ? "reconnecting" : "connecting";
      try {
        source = new EventSource(url);
      } catch {
        managed.status = "closed";
        return;
      }
      source.onopen = function() {
        managed.status = "open";
        attempts = 0;
        currentDelay = opts.reconnectDelay;
        opts.onOpen();
        for (const fn of listeners.open) fn(null);
      };
      source.onmessage = function(event) {
        dispatch(parseData(event.data), null);
      };
      for (const name of opts.events) {
        source.addEventListener(name, function(e) {
          dispatch(parseData(e.data), name);
        });
      }
      source.onerror = function(event) {
        opts.onError(event);
        for (const fn of listeners.error) fn(event);
        if (source && source.readyState === 2) {
          source = null;
          managed.status = "closed";
          opts.onClose();
          for (const fn of listeners.close) fn(null);
          if (!intentionalClose) {
            scheduleReconnect();
          }
        }
      };
    }
    connect();
    return managed;
  }
  var cookie = {
    /**
     * Set a browser cookie.
     *
     * @param name  - Cookie name.
     * @param value - Cookie value.
     * @param days  - Optional lifetime in days.
     */
    set: function(name, value, days) {
      let expires = "";
      if (days) {
        const d = /* @__PURE__ */ new Date();
        d.setTime(d.getTime() + days * 24 * 60 * 60 * 1e3);
        expires = "; expires=" + d.toUTCString();
      }
      document.cookie = name + "=" + (value || "") + expires + "; path=/";
    },
    /**
     * Retrieve a cookie value by name.
     *
     * @param name - Cookie name.
     * @returns Cookie value, or null if not found.
     */
    get: function(name) {
      const nameEQ = name + "=";
      const parts = document.cookie.split(";");
      for (let i = 0; i < parts.length; i++) {
        let c = parts[i];
        while (c.charAt(0) === " ") c = c.substring(1);
        if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length);
      }
      return null;
    },
    /**
     * Delete a cookie by name.
     *
     * @param name - Cookie name.
     */
    remove: function(name) {
      document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/";
    }
  };
  function message(text, type) {
    const el = document.getElementById("message");
    if (!el) return;
    const alertType = type || "info";
    el.innerHTML = '<div class="alert alert-' + alertType + ' alert-dismissible">' + text + '<button type="button" class="btn-close" data-t4-dismiss="alert">&times;</button></div>';
  }
  function popup(url, title, w, h) {
    const dualLeft = window.screenLeft !== void 0 ? window.screenLeft : window.screenX;
    const dualTop = window.screenTop !== void 0 ? window.screenTop : window.screenY;
    const width = window.innerWidth || document.documentElement.clientWidth || screen.width;
    const height = window.innerHeight || document.documentElement.clientHeight || screen.height;
    const zoom = width / window.screen.availWidth;
    const left = (width - w) / 2 / zoom + dualLeft;
    const top = (height - h) / 2 / zoom + dualTop;
    const win = window.open(
      url,
      title,
      "directories=no,toolbar=no,location=no,status=no,menubar=no,scrollbars=yes,resizable=yes,width=" + w / zoom + ",height=" + h / zoom + ",top=" + top + ",left=" + left
    );
    if (window.focus && win) win.focus();
    return win;
  }
  function report(url) {
    if (url.indexOf("No data available") >= 0) {
      window.alert("No data available for this report.");
      return;
    }
    window.open(
      url,
      "_blank",
      "toolbar=no,scrollbars=yes,resizable=yes,width=800,height=600,top=0,left=0"
    );
  }
  function graphql(url, query, variables, callback) {
    request(url, {
      method: "POST",
      body: { query, variables: variables || {} },
      onSuccess: function(response) {
        if (callback) {
          callback(response.data || null, response.errors || void 0);
        }
      },
      onError: function(status) {
        if (callback) {
          callback(null, [{ message: "GraphQL request failed with status " + status }]);
        }
      }
    });
  }
  var frond = {
    /** Core HTTP request. */
    request,
    /** GET + inject HTML into target element. */
    load,
    /** POST + inject HTML into target element. */
    post,
    /** Parse HTML string, inject into element, execute scripts. */
    inject,
    /** Form helpers: collect, submit, show. */
    form,
    /** WebSocket with auto-reconnect. */
    ws: wsConnect,
    /** Server-Sent Events with auto-reconnect. */
    sse: sseConnect,
    /** Cookie helpers: get, set, remove. */
    cookie,
    /** Display alert message in #message element. */
    message,
    /** Open centred popup window. */
    popup,
    /** Open PDF report in new window. */
    report,
    /** Execute a GraphQL query/mutation. */
    graphql,
    /** Current bearer token (read/write). */
    get token() {
      return _token;
    },
    set token(value) {
      _token = value;
    }
  };
  if (typeof window !== "undefined") {
    window.frond = frond;
  }
  return __toCommonJS(frond_exports);
})();
/* Frond v2.1.3 — tina4.com */
//# sourceMappingURL=frond.js.map
