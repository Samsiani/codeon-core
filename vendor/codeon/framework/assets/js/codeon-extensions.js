/* global jQuery, wp, ajaxurl, CodeOnFramework */
/**
 * CodeOn Extensions tab — modal + AJAX install flow.
 *
 * Bound to the markup emitted by ExtensionsTab::render(). Three
 * triggers:
 *   - .codeon-extensions-refresh: forces a server-side catalog
 *     refresh by full-page reload with ?codeon_refresh=1.
 *   - .codeon-extension-unlock: opens the install modal pre-filled
 *     with the card's plugin id + slug.
 *   - .codeon-modal-submit: POSTs the license key to the
 *     codeon_install AJAX action, then redirects to the new
 *     plugin's submenu on success.
 *
 * Vanilla JS — no jQuery dependency. The framework's own admin
 * bundle has its global namespace under `window.CodeOnFramework`,
 * which is localised by Assets::enqueue() on every codeon page.
 */
(function () {
  'use strict';

  function $$(sel, root) {
    return Array.from((root || document).querySelectorAll(sel));
  }

  function escapeHtml(input) {
    const div = document.createElement('div');
    div.textContent = String(input == null ? '' : input);
    return div.innerHTML;
  }

  document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('codeon-install-modal');
    if (!modal) {
      // The extensions tab isn't on this page; nothing to wire.
      return;
    }

    const titleEl = modal.querySelector('.codeon-modal-title');
    const errEl = modal.querySelector('.codeon-modal-error');
    const keyInput = modal.querySelector('.codeon-modal-key');
    const submitBtn = modal.querySelector('.codeon-modal-submit');
    const cancelBtn = modal.querySelector('.codeon-modal-cancel');
    const closeBtn = modal.querySelector('.codeon-modal-close');

    let pendingPlugin = null;

    function openModal(card) {
      pendingPlugin = {
        id: card.getAttribute('data-plugin-id') || '',
        slug: card.getAttribute('data-plugin-slug') || '',
        name: (card.querySelector('.codeon-extension-name') || {}).textContent || ''
      };
      titleEl.textContent = (titleEl.dataset.template || 'Install %s').replace(
        '%s',
        pendingPlugin.name
      );
      keyInput.value = '';
      errEl.hidden = true;
      errEl.textContent = '';
      modal.hidden = false;
      modal.classList.add('codeon-modal-open');
      keyInput.focus();
    }

    function closeModal() {
      modal.hidden = true;
      modal.classList.remove('codeon-modal-open');
      pendingPlugin = null;
    }

    function showError(message) {
      errEl.hidden = false;
      errEl.textContent = message;
      submitBtn.disabled = false;
      submitBtn.textContent = submitBtn.dataset.label || 'Install plugin';
    }

    function submit() {
      if (!pendingPlugin) {
        return;
      }
      const key = keyInput.value.trim();
      if (key === '') {
        showError('Paste a license key first.');
        return;
      }

      submitBtn.dataset.label = submitBtn.dataset.label || submitBtn.textContent;
      submitBtn.disabled = true;
      submitBtn.textContent = 'Installing…';

      const body = new URLSearchParams();
      body.append('action', 'codeon_install');
      body.append('_ajax_nonce', submitBtn.dataset.nonce || '');
      body.append('plugin_id', pendingPlugin.id);
      body.append('plugin_slug', pendingPlugin.slug);
      body.append('license_key', key);

      fetch(window.ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'Accept': 'application/json'
        },
        body: body.toString()
      })
        .then(function (res) { return res.json().catch(function () { return null; }); })
        .then(function (json) {
          if (!json || !json.success) {
            const msg = (json && json.data && json.data.message) || 'Install failed.';
            showError(msg);
            return;
          }
          // Success — redirect to the new plugin's page so its admin
          // chrome (and per-plugin License tab) is the next thing
          // the merchant sees.
          const redirect = (json.data && json.data.redirect) || window.location.href;
          window.location.assign(redirect);
        })
        .catch(function () {
          showError('Network error. Try again.');
        });
    }

    // ── wire triggers ────────────────────────────────────────────
    $$('.codeon-extension-unlock').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const card = btn.closest('.codeon-extension-card');
        if (card) {
          openModal(card);
        }
      });
    });

    if (cancelBtn) {
      cancelBtn.addEventListener('click', closeModal);
    }
    if (closeBtn) {
      closeBtn.addEventListener('click', closeModal);
    }
    modal.addEventListener('click', function (e) {
      if (e.target === modal) {
        closeModal();
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.hidden) {
        closeModal();
      }
    });
    if (submitBtn) {
      submitBtn.addEventListener('click', submit);
      keyInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          submit();
        }
      });
    }

    $$('.codeon-extensions-refresh').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const url = new URL(window.location.href);
        url.searchParams.set('codeon_refresh', '1');
        window.location.assign(url.toString());
      });
    });

    // Localised modal title template — injected via the markup so
    // translators can localise without touching JS.
    titleEl.dataset.template =
      (window.CodeOnFramework &&
        window.CodeOnFramework.i18n &&
        window.CodeOnFramework.i18n.installModalTitle) ||
      'Install %s';
  });
})();
