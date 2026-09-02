/**
 * Bitrail Hyva Checkout setup script.
 *
 * This file is referenced by `view/frontend/templates/setup_script.phtml` via:
 *   Bitrail_HyvaCheckout::js/setup_script.js
 *
 * It bootstraps `window.checkoutConfig.payment.bitrail_gateway`, updates the
 * Hyvä checkout payment-method UI (title + logo), and exposes helper functions
 * used by `checkout_index.js`.
 */
(function () {
  'use strict';

  const SETUP_ELEMENT_ID = 'bitrail-hyva-setup';
  const VENDOR_SCRIPT_ATTR = 'data-vendor-js-url';
  const NONCE_ATTR = 'data-nonce-code';
  const BITRAIL_OPTION_IDS = ['payment-method-option-bitrail', 'payment-method-option-bitrail_gateway'];
  const BITRAIL_METHOD_RADIO_IDS = ['payment-method-bitrail', 'payment-method-bitrail_gateway'];

  let vendorJsLoadingPromise = null;
  let configLoadingPromise = null;

  function registerBitrailCheckoutValidator(evaluationNamespace) {
    if (!evaluationNamespace?.registerValidator) return false;

    // Prevent double-registering if the script is evaluated multiple times.
    if (evaluationNamespace.validators?.['bitrail-checkout']) return true;

    evaluationNamespace
      .registerValidator('bitrail-checkout', async (_component, _el, _evaluation) => {
        // This validator exists primarily to satisfy the backend evaluation instruction.
        // The actual Bitrail payment flow is handled by `checkout_index.js` (modal + order callback).
        //
        // Only do any checks when Bitrail is the active payment method.
        const selected = BITRAIL_METHOD_RADIO_IDS.some((id) => !!document.getElementById(id)?.checked);
        if (!selected) return true;

        ensureBitrailConfigContainer();

        return true;
      })
      .catch((err) => console.error('[Bitrail] Failed to register checkout validator:', err));

    return true;
  }

  function tryRegisterBitrailCheckoutValidator() {
    let hc = null;
    try {
      if (typeof hyvaCheckout !== 'undefined') {
        hc = hyvaCheckout;
      }
    } catch (e) {
      hc = null;
    }

    hc = hc || window.hyvaCheckout || null;
    if (!hc?.evaluation) return false;

    return registerBitrailCheckoutValidator(hc.evaluation);
  }

  // Hyvä Checkout initializes its API asynchronously and fires `checkout:init:*` events.
  // This module is loaded from `head.additional`, so the load order can vary.
  // We register the validator as soon as possible, with multiple fallbacks.
  (function bootstrapBitrailCheckoutValidator() {
    if (tryRegisterBitrailCheckoutValidator()) return;

    const attempt = (event) => {
      // Best case: Hyvä passes us the namespace that just initialized.
      const evaluationNamespace = event?.detail?.namespace;
      if (registerBitrailCheckoutValidator(evaluationNamespace)) return;

      // Fallback: try global access.
      tryRegisterBitrailCheckoutValidator();
    };

    window.addEventListener('checkout:init:evaluation', attempt, { once: true });
    window.addEventListener('checkout:init:after', attempt, { once: true });
    window.addEventListener('magewire:available', attempt, { once: true });

    // Fallback in case init events fired before this script loaded.
    let attempts = 0;
    const timer = window.setInterval(() => {
      attempts += 1;
      if (tryRegisterBitrailCheckoutValidator() || attempts > 100) {
        window.clearInterval(timer);
      }
    }, 50);
  })();

  function getBaseUrl() {
    const base = window.BASE_URL || document.querySelector('base')?.href || '/';
    try {
      return new URL(base, window.location.href).toString();
    } catch (e) {
      return '/';
    }
  }

  function buildUrl(path, params = {}) {
    const url = new URL(path.replace(/^\//, ''), getBaseUrl());
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '') {
        url.searchParams.set(key, String(value));
      }
    });
    return url.toString();
  }

  function getSetupElement() {
    return document.getElementById(SETUP_ELEMENT_ID);
  }

  function readSetupData() {
    const el = getSetupElement();
    const nonceCode = el?.getAttribute(NONCE_ATTR) || '';
    const vendorJsUrl = el?.getAttribute(VENDOR_SCRIPT_ATTR) || '';
    return { nonceCode, vendorJsUrl };
  }

  function ensureBitrailConfigContainer() {
    window.checkoutConfig = window.checkoutConfig || {};
    window.checkoutConfig.payment = window.checkoutConfig.payment || {};
    window.checkoutConfig.payment.bitrail_gateway = window.checkoutConfig.payment.bitrail_gateway || {};
    return window.checkoutConfig.payment.bitrail_gateway;
  }

  async function fetchJson(url) {
    const response = await fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const json = await response.json().catch(() => null);
    if (!response.ok) {
      return { success: false, error: `HTTP ${response.status}`, data: null, raw: json };
    }
    return json ?? { success: false, error: 'Invalid JSON response', data: null };
  }

  function getFormKey() {
    return (
      window.FORM_KEY ||
      window.checkoutConfig?.formKey ||
      document.querySelector('input[name="form_key"]')?.value ||
      ''
    );
  }

  // Exposed helpers (used by `checkout_index.js`)
  window.showLoadingSpinner = function showLoadingSpinner() {
    const spinner = document.getElementById('spinner-wrapper');
    if (spinner) spinner.style.display = 'flex';
  };

  window.hideLoadingSpinner = function hideLoadingSpinner() {
    const spinner = document.getElementById('spinner-wrapper');
    if (spinner) spinner.style.display = 'none';
  };

  window.loadVendorJsScript = function loadVendorJsScript() {
    if (vendorJsLoadingPromise) return vendorJsLoadingPromise;

    const { vendorJsUrl } = readSetupData();
    if (!vendorJsUrl) {
      console.error('[Bitrail] Vendor JS url not found.');
      vendorJsLoadingPromise = Promise.resolve();
      return vendorJsLoadingPromise;
    }

    // If it's already on the page, resolve immediately.
    const existing = Array.from(document.scripts || []).find((s) => s?.src === vendorJsUrl);
    if (existing) {
      vendorJsLoadingPromise = Promise.resolve();
      return vendorJsLoadingPromise;
    }

    vendorJsLoadingPromise = new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.src = vendorJsUrl;
      script.async = true;
      script.onload = () => resolve();
      script.onerror = () => reject(new Error(`Failed to load vendor JS: ${vendorJsUrl}`));
      document.head.appendChild(script);
    })
      .catch((err) => console.error(err));

    return vendorJsLoadingPromise;
  };

  window.fetchQuoteDetail = async function fetchQuoteDetail(nonceCode) {
    const { nonceCode: fallbackNonce } = readSetupData();
    const url = buildUrl('/bitrail/checkout/getquotedetail', { nonceCode: nonceCode || fallbackNonce });
    return await fetchJson(url);
  };

  window.registerPayment = async function registerPayment(orderId, verificationToken, fcTransactionId, nonceCode) {
    const { nonceCode: fallbackNonce } = readSetupData();
    const formKey = getFormKey();

    if (formKey) {
      const url = buildUrl('/bitrail/checkout/registerpayment');
      const body = new URLSearchParams({
        nonceCode: nonceCode || fallbackNonce,
        orderId: orderId || '',
        verificationToken: verificationToken || '',
        fcTransactionId: fcTransactionId || '',
        form_key: formKey
      });

      const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
          'X-Requested-With': 'XMLHttpRequest'
        },
        body
      });

      const json = await response.json().catch(() => null);
      if (!response.ok) {
        return { success: false, error: `HTTP ${response.status}`, data: null, raw: json };
      }
      return json ?? { success: false, error: 'Invalid JSON response', data: null };
    }

    const url = buildUrl('/bitrail/checkout/registerpayment', {
      nonceCode: nonceCode || fallbackNonce,
      orderId: orderId || '',
      verificationToken: verificationToken || '',
      fcTransactionId: fcTransactionId || ''
    });
    return await fetchJson(url);
  };

  function updateTitleContent(titleHtml, appName, logoUrl) {
    const safeName = appName ?? '';
    const safeLogoUrl = logoUrl ?? '';
    const titleContent = `
        <div id="bitrail-title-div">
            <span class="left">${safeName}</span>
            <img class="right" width="80" src="${safeLogoUrl}" alt="" />
        </div>`;

    titleHtml.style.width = '100%';
    titleHtml.innerHTML = titleContent;
  }

  function findPaymentTitleNode(labelElement) {
    if (!labelElement) return null;

    // Legacy markup: label contains a div for the title.
    const div = labelElement.querySelector('div');
    if (div) return div;

    // Hyvä markup: label children = input + wrapper (usually a <span>).
    const children = Array.from(labelElement.children || []);
    const wrapper = children.find((el) => el.tagName?.toLowerCase() !== 'input') || null;
    if (wrapper?.firstElementChild) return wrapper.firstElementChild;
    if (wrapper) return wrapper;

    // Last resort.
    return labelElement.querySelector('span') || null;
  }

  function updatePaymentContentTitle(clientAppName) {
    const paymentContentTitle = document.getElementById('bitrail-payment-content-title');
    const paymentContent = document.getElementById('bitrail-payment-content');

    if (paymentContentTitle) {
      paymentContentTitle.innerHTML = clientAppName ?? '';
    }
    if (paymentContent) {
      paymentContent.style.display = 'block';
    }
  }

  function getBitrailOptionElement() {
    for (const id of BITRAIL_OPTION_IDS) {
      const el = document.getElementById(id);
      if (el) return el;
    }
    return null;
  }

  async function loadBitrailConfigOnce() {
    if (configLoadingPromise) return configLoadingPromise;

    const { nonceCode, vendorJsUrl } = readSetupData();
    const url = buildUrl('/bitrail/checkout/getconfig', { nonceCode });

    configLoadingPromise = (async () => {
      const container = ensureBitrailConfigContainer();
      container.nonceCode = nonceCode;
      container.vendorJsUrl = vendorJsUrl;

      const result = await fetchJson(url);
      if (result?.success && result?.data) {
        Object.assign(container, result.data);
      } else if (result?.error) {
        console.warn('[Bitrail] Failed to fetch checkout config:', result.error);
      }
      return container;
    })();

    return configLoadingPromise;
  }

  async function setupClient(titleLiElement) {
    if (!titleLiElement) return;

    const titleLabelElement = titleLiElement.querySelector('label');
    const titleHtml = findPaymentTitleNode(titleLabelElement);
    if (!titleHtml) return;
    if (titleHtml.querySelector?.('#bitrail-title-div')) return;

    window.showLoadingSpinner?.();
    try {
      const loadedConfig = await loadBitrailConfigOnce();
      const config = loadedConfig?.paymentMethodTitle ? loadedConfig : null;
      if (!config) return;
      const nonceCode = loadedConfig.nonceCode || readSetupData().nonceCode;

      updateTitleContent(titleHtml, loadedConfig.paymentMethodTitle, loadedConfig.logo?.small);
      updatePaymentContentTitle(config.paymentMethodTitle);

      // Reveal the payment method option (CSS hides it by default).
      titleLiElement.style.display = 'block';

      // Ensure checkoutConfig is hydrated for later flows.
      window.checkoutConfig = {
        ...(window.checkoutConfig ?? {}),
        payment: {
          ...(window.checkoutConfig?.payment ?? {}),
          bitrail_gateway: {
            ...(window.checkoutConfig?.payment?.bitrail_gateway ?? {}),
            ...loadedConfig,
            nonceCode
          }
        }
      };

      // Keep the shared container updated for consumers expecting it.
      const container = ensureBitrailConfigContainer();
      Object.assign(container, { ...config, nonceCode });
    } finally {
      window.hideLoadingSpinner?.();
    }
  }

  function setupClientIfPresent() {
    const titleLiElement = getBitrailOptionElement();
    if (titleLiElement) void setupClient(titleLiElement);
  }

  let livewireHookBound = false;
  function bindLivewireProcessedHook() {
    if (livewireHookBound) return;
    if (!window.Livewire?.hook) return;
    livewireHookBound = true;

    // Re-apply after Livewire patches replace DOM nodes.
    window.Livewire.hook('message.processed', () => {
      // Ensure this runs after DOM changes are applied.
      window.setTimeout(() => setupClientIfPresent(), 0);
    });
  }

  function init() {
    ensureBitrailConfigContainer();
    void loadBitrailConfigOnce();

    setupClientIfPresent();

    try {
      bindLivewireProcessedHook();
    } catch (e) {
      // Ignore.
    }

    document.addEventListener('livewire:load', function () {
      try {
        bindLivewireProcessedHook();
      } catch (e) {
        // Ignore.
      }
      setupClientIfPresent();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

