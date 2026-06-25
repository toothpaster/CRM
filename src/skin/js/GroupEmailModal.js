/**
 * GroupEmailModal.js — Email Compose Modal Plugin (SMTP)
 *
 * ChurchCRM Group View plugin that replaces the native `mailto:`
 * email actions with a Bootstrap 5 compose modal that sends email
 * via the system's configured SMTP server (PHPMailer).
 *
 * The "From:" address comes from the church information settings
 * (admin/system/church-info → Church Email). The "Reply-To:" is
 * set to the current logged-in user's email.
 *
 * Three modes:
 *   to           — one email, all recipients in To:
 *   bcc          — one email, all recipients in Bcc:
 *   individual   — separate email to each recipient (individually)
 *
 * Dependencies: jQuery, Bootstrap 5, i18next
 *
 * Installation:
 *   <script src="<?= $sRootPath ?>/skin/js/GroupEmailModal.js?..."></script>
 *
 * @version 1.2.0
 */
(function () {
  "use strict";

  var PLUGIN_ID = "groupEmailModal";

  // ------------------------------------------------------------------ //
  // Guard: prevent double-load
  // ------------------------------------------------------------------ //
  if (window.CRM && window.CRM.plugins && window.CRM.plugins[PLUGIN_ID]) {
    return;
  }

  // ------------------------------------------------------------------ //
  // Modal state
  // ------------------------------------------------------------------ //
  var MODAL_ID = "gem-modal";
  var modalDom = null;
  var bootstrapModal = null;

  var ctx = {
    emails: "", // CSV of email addresses
    mode: "to", // "to" | "bcc" | "individual"
    groupId: window.CRM && window.CRM.currentGroup ? String(window.CRM.currentGroup) : "",
    // cartPage — auto-detected: true when in the cart view (no group ID)
    isCartPage: !!(window.CRM && window.CRM.currentGroup ? false : /\/cart/.test(window.location.pathname)),
  };

  // ------------------------------------------------------------------ //
  // i18n (fallback for safety)
  // ------------------------------------------------------------------ //
  var fallbackMap = {
    "Compose Email": "Compose Email",
    To: "To",
    Subject: "Subject",
    Message: "Message",
    Send: "Send",
    "Send Individually": "Send Individually",
    Sending: "Sending...",
    Cancel: "Cancel",
    "Email sent successfully": "Email sent successfully",
    "Email sent to {sent} of {total} recipients": "Email sent to {sent} of {total} recipients",
    "({fail} failed)": "({fail} failed)",
    "Failed to send email": "Failed to send email",
    "Send successful": "Email sent!",
    "No email addresses": "No email addresses",
    "1 recipient": "1 recipient",
    "{count} recipients": "{count} recipients",
    Tip: "Tip",
    "Ctrl+Enter to send": "Ctrl+Enter to send",
    "Use {firstName} and {lastName} as placeholders":
      "Use {firstName} and {lastName} as placeholders — they will be replaced with each person's name",
  };

  function _t(key) {
    if (typeof i18next !== "undefined" && i18next.isInitialized) {
      return i18next.t(key);
    }
    return fallbackMap[key] || key;
  }

  // ------------------------------------------------------------------ //
  // Helpers
  // ------------------------------------------------------------------ //
  // Normalize email CSV: split on either comma or semicolon (handles user's sMailtoDelimiter)
  function _normalizeEmails(str) {
    if (!str) return "";
    return str
      .split(/[,;]/)
      .map(function (e) {
        return e.trim();
      })
      .filter(Boolean)
      .join(",");
  }

  function _recipientCount(emails) {
    if (!emails) return 0;
    return _normalizeEmails(emails)
      .split(",")
      .filter(function (e) {
        return e.trim().length > 0;
      }).length;
  }

  function _recipientLabel(emails) {
    var n = _recipientCount(emails);
    if (n === 0) return _t("No email addresses");
    if (n === 1) return _t("1 recipient");
    return _t("{count} recipients").replace("{count}", String(n));
  }

  // ------------------------------------------------------------------ //
  // Build modal DOM
  // ------------------------------------------------------------------ //
  function _buildModal() {
    if (modalDom) {
      var old = window.bootstrap.Modal.getInstance(modalDom);
      if (old) old.dispose();
      modalDom.remove();
      modalDom = null;
    }

    var el = document.createElement("div");
    el.id = MODAL_ID;
    el.className = "modal fade";
    el.setAttribute("tabindex", "-1");
    el.setAttribute("aria-hidden", "true");
    el.innerHTML = [
      '<div class="modal-dialog modal-dialog-centered modal-lg">',
      '  <div class="modal-content">',
      '    <div class="modal-header">',
      '      <h5 class="modal-title">' + _t("Compose Email") + "</h5>",
      '      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>',
      "    </div>",
      '    <form id="gem-form" class="modal-body">',
      '      <div class="mb-3">',
      '        <label class="form-label" for="gem-to">' +
        _t("To") +
        ' <span id="gem-to-count" class="text-body-tertiary small"></span>' +
        '          <span id="gem-mode-badge" class="badge bg-info-lt text-info ms-2"></span></label>',
      '        <input type="text" id="gem-to" class="form-control" readonly />',
      "      </div>",
      '      <div class="mb-3">',
      '        <label class="form-label" for="gem-subject">' + _t("Subject") + "</label>",
      '        <input type="text" id="gem-subject" class="form-control" placeholder="' + _t("Subject") + '" />',
      "      </div>",
      '      <div id="gem-placeholder-hint" class="alert alert-info d-none py-2 mb-3" role="alert">',
      '        <i class="fa-solid fa-circle-info me-1"></i> ' +
        _t("Use {firstName} and {lastName} as placeholders") +
        "",
      "      </div>",
      '      <div class="mb-3">',
      '        <label class="form-label" for="gem-body">' + _t("Message") + "</label>",
      '        <textarea id="gem-body" class="form-control" rows="8" placeholder="' + _t("Message") + '"></textarea>',
      '        <div class="text-body-tertiary small mt-1"><i class="fa-regular fa-keyboard me-1"></i> ' +
        _t("Tip") +
        ": " +
        _t("Ctrl+Enter to send") +
        "</div>",
      "      </div>",
      '      <div id="gem-status" class="alert d-none mb-0" role="alert"></div>',
      "    </form>",
      '    <div class="modal-footer">',
      '      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="gem-cancel-btn">' +
        _t("Cancel") +
        "</button>",
      '      <button type="button" class="btn btn-primary" id="gem-send-btn">',
      '        <i class="fa-solid fa-paper-plane me-1"></i>' + _t("Send") + "</button>",
      "    </div>",
      "  </div>",
      "</div>",
    ].join("\n");

    document.body.appendChild(el);
    modalDom = el;

    // Tear down on hide
    el.addEventListener(
      "hidden.bs.modal",
      function () {
        var inst = window.bootstrap.Modal.getInstance(el);
        if (inst) inst.dispose();
        bootstrapModal = null;
      },
      { once: true },
    );
  }

  // ------------------------------------------------------------------ //
  // Show status message inside modal
  // ------------------------------------------------------------------ //
  function _showStatus(type, message) {
    var statusEl = modalDom.querySelector("#gem-status");
    if (!statusEl) return;
    statusEl.className = "alert alert-" + type + " mb-0";
    statusEl.textContent = message;
    statusEl.classList.remove("d-none");
  }

  function _hideStatus() {
    var statusEl = modalDom.querySelector("#gem-status");
    if (statusEl) {
      statusEl.classList.add("d-none");
    }
  }

  // ------------------------------------------------------------------ //
  // Set modal buttons loading/disabled state
  // ------------------------------------------------------------------ //
  function _setLoading(loading) {
    var sendBtn = modalDom.querySelector("#gem-send-btn");
    var cancelBtn = modalDom.querySelector("#gem-cancel-btn");
    var subjectField = modalDom.querySelector("#gem-subject");
    var bodyField = modalDom.querySelector("#gem-body");

    if (sendBtn) {
      sendBtn.disabled = loading;
      sendBtn.innerHTML = loading
        ? '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + _t("Sending")
        : '<i class="fa-solid fa-paper-plane me-1"></i>' + _t("Send");
    }
    if (cancelBtn) cancelBtn.disabled = loading;
    if (subjectField) subjectField.disabled = loading;
    if (bodyField) bodyField.disabled = loading;
  }

  // ------------------------------------------------------------------ //
  // Send email via API
  // ------------------------------------------------------------------ //
  function _sendEmail(emails, subject, body) {
    _hideStatus();

    if (!subject && !body) {
      _showStatus("warning", _t("Subject") + " / " + _t("Message") + " cannot be empty");
      _setLoading(false);
      return;
    }

    var root = window.CRM && window.CRM.root ? window.CRM.root : "";
    var url;
    if (ctx.groupId) {
      url = root + "/api/groups/" + encodeURIComponent(ctx.groupId) + "/send-email";
    } else {
      url = root + "/api/cart/send-email";
    }

    window.jQuery.ajax({
      type: "POST",
      url: url,
      contentType: "application/json",
      data: JSON.stringify({
        to: emails,
        subject: subject,
        body: body,
        individual: ctx.mode === "individual",
      }),
      dataType: "json",
      success: function (data) {
        if (data && data.success) {
          var msg = data.message || _t("Email sent successfully");
          _showStatus("success", msg);
          // Auto-close after a brief delay
          window.setTimeout(function () {
            if (bootstrapModal) bootstrapModal.hide();
            if (window.CRM && window.CRM.notify) {
              // Build a more specific notification for individual sends
              if (ctx.mode === "individual" && data.sentCount != null) {
                var note = _t("Email sent to {sent} of {total} recipients")
                  .replace("{sent}", String(data.sentCount))
                  .replace("{total}", String(emails.split(",").filter(Boolean).length));
                window.CRM.notify(note, { type: "info", delay: 4000 });
              } else {
                window.CRM.notify(_t("Send successful"), { type: "info", delay: 3000 });
              }
            }
          }, 1500);
        } else {
          _showStatus("danger", (data && data.message) || _t("Failed to send email"));
          _setLoading(false);
        }
      },
      error: function (jqXHR) {
        var msg = _t("Failed to send email");
        try {
          var resp = JSON.parse(jqXHR.responseText);
          if (resp && resp.message) msg = resp.message;
        } catch (_) {}
        _showStatus("danger", msg);
        _setLoading(false);
      },
    });
  }

  // ------------------------------------------------------------------ //
  // Show the compose modal
  // ------------------------------------------------------------------ //
  function _showModal(emails, mode) {
    ctx.emails = _normalizeEmails(String(emails || ""));
    ctx.mode = mode === "bcc" ? "bcc" : mode === "individual" ? "individual" : "to";

    if (!ctx.emails) {
      if (window.CRM && window.CRM.notify) {
        window.CRM.notify(_t("No email addresses available"), {
          type: "warning",
          delay: 3000,
        });
      }
      return;
    }

    _buildModal();

    var toInput = modalDom.querySelector("#gem-to");
    var toCount = modalDom.querySelector("#gem-to-count");
    var modeBadge = modalDom.querySelector("#gem-mode-badge");
    var sendBtn = modalDom.querySelector("#gem-send-btn");
    var bodyField = modalDom.querySelector("#gem-body");
    var subjectField = modalDom.querySelector("#gem-subject");

    // Populate To field
    var parts = ctx.emails.split(",").filter(Boolean);
    if (parts.length <= 3) {
      toInput.value = ctx.emails;
    } else {
      toInput.value =
        parts
          .slice(0, 2)
          .map(function (p) {
            return p.trim();
          })
          .join(", ") + ", \u2026";
    }
    toCount.textContent = "(" + _recipientLabel(ctx.emails) + ")";

    // Toggle placeholder hint visibility based on mode
    var hintEl = modalDom.querySelector("#gem-placeholder-hint");
    if (hintEl) {
      if (ctx.mode === "individual") {
        hintEl.classList.remove("d-none");
      } else {
        hintEl.classList.add("d-none");
      }
    }

    // Mode badge + button label
    if (ctx.mode === "individual") {
      modeBadge.textContent = _t("Send Individually");
      modeBadge.classList.remove("d-none");
      sendBtn.innerHTML = '<i class="fa-regular fa-envelope-open me-1"></i>' + _t("Send Individually");
    } else if (ctx.mode === "bcc") {
      modeBadge.textContent = _t("BCC All");
      modeBadge.classList.remove("d-none");
      sendBtn.innerHTML = '<i class="fa-solid fa-user-secret me-1"></i>' + _t("Send");
    } else {
      modeBadge.classList.add("d-none");
      sendBtn.innerHTML = '<i class="fa-solid fa-paper-plane me-1"></i>' + _t("Send");
    }

    // Focus subject on show
    modalDom.addEventListener(
      "shown.bs.modal",
      function () {
        subjectField.focus();
      },
      { once: true },
    );

    // Send button (fresh clone to avoid stale listeners)
    var newBtn = sendBtn.cloneNode(true);
    sendBtn.parentNode.replaceChild(newBtn, sendBtn);
    newBtn.addEventListener("click", function () {
      _setLoading(true);
      _sendEmail(ctx.emails, subjectField.value.trim(), bodyField.value.trim());
    });

    // Ctrl+Enter / Cmd+Enter to send
    bodyField.addEventListener("keydown", function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key === "Enter") {
        e.preventDefault();
        _setLoading(true);
        _sendEmail(ctx.emails, subjectField.value.trim(), bodyField.value.trim());
      }
    });

    bootstrapModal = new window.bootstrap.Modal(modalDom, {
      backdrop: "static",
      keyboard: true,
    });
    bootstrapModal.show();
  }

  // ------------------------------------------------------------------ //
  // Install: override window.CRM.comm.openMailto, openBcc, and add openIndividual
  // ------------------------------------------------------------------ //
  function install() {
    if (!window.CRM || !window.CRM.comm) {
      var maxRetries = 10;
      var retry = function (n) {
        if (n <= 0) return;
        if (window.CRM && window.CRM.comm) {
          _override();
          return;
        }
        window.setTimeout(function () {
          retry(n - 1);
        }, 100);
      };
      retry(maxRetries);
      return;
    }
    _override();
  }

  function _override() {
    var comm = window.CRM.comm;

    // Preserve originals
    if (!comm._origOpenMailto) comm._origOpenMailto = comm.openMailto;
    if (!comm._origOpenBcc) comm._origOpenBcc = comm.openBcc;

    comm.openMailto = function (emailCsv) {
      _showModal(emailCsv, "to");
    };
    comm.openBcc = function (emailCsv) {
      _showModal(emailCsv, "bcc");
    };
    comm.openIndividual = function (emailCsv) {
      _showModal(emailCsv, "individual");
    };

    if (window.CRM.plugins) {
      window.CRM.plugins[PLUGIN_ID]._active = true;
    }
  }

  // ------------------------------------------------------------------ //
  // Register plugin
  // ------------------------------------------------------------------ //
  if (!window.CRM) window.CRM = {};
  if (!window.CRM.plugins) window.CRM.plugins = {};

  window.CRM.plugins[PLUGIN_ID] = {
    id: PLUGIN_ID,
    name: "Group Email Compose Modal",
    version: "1.2.0",
    description: "Sends group emails via the system's configured SMTP server.",
    _active: false,
    install: install,
    _show: _showModal,
    _ctx: ctx,
  };

  // ------------------------------------------------------------------ //
  // Auto-install
  // ------------------------------------------------------------------ //
  function _auto() {
    if (typeof window.jQuery === "undefined") {
      if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", _auto);
      }
      return;
    }
    window.setTimeout(install, 0);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", _auto);
  } else {
    _auto();
  }
})();
