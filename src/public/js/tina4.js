/**
 * Tina4 CSS — Lightweight JS components
 * Replaces Bootstrap's JavaScript for: modals, alerts, navbar toggler
 * ~3KB unminified, zero dependencies
 */
(function () {
  "use strict";

  // ── Modals ──────────────────────────────────────────────────
  // Usage: <button data-t4-toggle="modal" data-t4-target="#myModal">Open</button>
  //        <button data-t4-dismiss="modal">Close</button>
  //        Also supports Bootstrap syntax: data-bs-toggle, data-bs-target, data-bs-dismiss

  function getModalEl(selector) {
    if (!selector) return null;
    return document.querySelector(selector);
  }

  function openModal(modal) {
    if (!modal) return;
    // Create backdrop if not exists
    var backdrop = modal._t4Backdrop;
    if (!backdrop) {
      backdrop = document.createElement("div");
      backdrop.className = "modal-backdrop";
      document.body.appendChild(backdrop);
      modal._t4Backdrop = backdrop;
      backdrop.addEventListener("click", function () {
        closeModal(modal);
      });
    }
    modal.style.display = "block";
    backdrop.style.display = "block";
    // Force reflow then add .show for transition
    void modal.offsetHeight;
    modal.classList.add("show");
    backdrop.classList.add("show");
    document.body.style.overflow = "hidden";
    // Focus trap — focus first focusable element
    var focusable = modal.querySelector("input, select, textarea, button, [tabindex]");
    if (focusable) focusable.focus();
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove("show");
    var backdrop = modal._t4Backdrop;
    if (backdrop) backdrop.classList.remove("show");
    // Wait for transition
    setTimeout(function () {
      modal.style.display = "none";
      if (backdrop) backdrop.style.display = "none";
      document.body.style.overflow = "";
    }, 150);
  }

  // Delegated click handler for modal triggers
  document.addEventListener("click", function (e) {
    var trigger = e.target.closest("[data-t4-toggle='modal'], [data-bs-toggle='modal']");
    if (trigger) {
      e.preventDefault();
      var target = trigger.getAttribute("data-t4-target") || trigger.getAttribute("data-bs-target") || trigger.getAttribute("href");
      var modal = getModalEl(target);
      if (modal) openModal(modal);
      return;
    }

    // Dismiss button
    var dismiss = e.target.closest("[data-t4-dismiss='modal'], [data-bs-dismiss='modal'], .btn-close");
    if (dismiss) {
      var modal = dismiss.closest(".modal");
      if (modal) closeModal(modal);
      return;
    }
  });

  // ESC key closes top modal
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") {
      var modals = document.querySelectorAll(".modal.show");
      if (modals.length > 0) closeModal(modals[modals.length - 1]);
    }
  });

  // ── Alerts (dismissible) ────────────────────────────────────
  // Usage: <div class="alert alert-danger alert-dismissible">
  //          Message <button class="btn-close" data-t4-dismiss="alert">&times;</button>
  //        </div>

  document.addEventListener("click", function (e) {
    var dismiss = e.target.closest("[data-t4-dismiss='alert'], [data-bs-dismiss='alert']");
    if (dismiss) {
      var alert = dismiss.closest(".alert");
      if (alert) {
        alert.style.opacity = "0";
        setTimeout(function () { alert.remove(); }, 150);
      }
    }
  });

  // ── Navbar toggler ──────────────────────────────────────────
  // Usage: <button class="navbar-toggler" data-t4-toggle="collapse" data-t4-target="#navContent">
  //          &#9776;
  //        </button>
  //        <div class="navbar-collapse collapse" id="navContent">...</div>

  document.addEventListener("click", function (e) {
    var toggler = e.target.closest("[data-t4-toggle='collapse'], [data-bs-toggle='collapse']");
    if (toggler) {
      e.preventDefault();
      var target = toggler.getAttribute("data-t4-target") || toggler.getAttribute("data-bs-target") || toggler.getAttribute("href");
      var el = document.querySelector(target);
      if (el) {
        el.classList.toggle("show");
      }
    }
  });

  // ── Programmatic API ────────────────────────────────────────
  // window.tina4.modal.open("#myModal")
  // window.tina4.modal.close("#myModal")

  window.tina4 = window.tina4 || {};
  window.tina4.modal = {
    open: function (selector) {
      var modal = typeof selector === "string" ? document.querySelector(selector) : selector;
      openModal(modal);
    },
    close: function (selector) {
      var modal = typeof selector === "string" ? document.querySelector(selector) : selector;
      closeModal(modal);
    }
  };
})();
