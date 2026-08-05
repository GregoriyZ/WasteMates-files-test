// WasteMates — shared behaviour

// Leaflet service-area map — CartoDB light tiles, 130 km radius centred on Mooroolbark
function initMap() {
  if (typeof L === 'undefined') return;

  var serviceCentre = [-37.78247, 145.31682];

  ['about-map', 'contact-map'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;

    var map = L.map(el, {
      center: serviceCentre,
      zoom: 8,
      zoomControl: true,
      scrollWheelZoom: false,
    });

    L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
      subdomains: 'abcd',
      maxZoom: 19,
    }).addTo(map);

    L.circle(serviceCentre, {
      radius: 130000,
      color: '#61DE2A',
      fillColor: '#61DE2A',
      fillOpacity: 0.12,
      weight: 2,
      opacity: 0.8,
    }).addTo(map);
  });
}
(function () {
  // Mobile menu toggle
  function bindMenu() {
    var burger = document.querySelector('.burger');
    var menu = document.querySelector('.mobile-menu');
    if (!burger || !menu) return;
    burger.addEventListener('click', function () {
      menu.classList.toggle('open');
    });
    menu.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () { menu.classList.remove('open'); });
    });
  }

  // FAQ accordion — also opens any item with class="open" on load
  function bindFaq() {
    document.querySelectorAll('.faq-q').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var item = btn.closest('.faq-item');
        var ans = item.querySelector('.faq-a');
        var open = item.classList.toggle('open');
        ans.style.maxHeight = open ? ans.scrollHeight + 'px' : '0';
        // Track FAQ opens as content engagement
        if (open && typeof fbq === 'function') {
          var question = btn.textContent.trim().slice(0, 100);
          fbq('trackCustom', 'FAQOpen', { question: question });
        }
      });
    });
    // Initialise any pre-opened items
    document.querySelectorAll('.faq-item.open .faq-a').forEach(function (ans) {
      ans.style.maxHeight = ans.scrollHeight + 'px';
    });
  }

  // ─── Identity helpers ────────────────────────────────────────────────────

  // Read a browser cookie by name. Used to retrieve Meta's _fbc (click ID)
  // and _fbp (browser ID) cookies that the pixel writes automatically.
  function getCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  // Capture fbclid from the URL when someone arrives from a Facebook ad
  // (?fbclid=AbCd...) and store it for the session. Meta's pixel also reads
  // the _fbc cookie it sets automatically, but this ensures the click ID is
  // available even before that cookie is written.
  function captureClickId() {
    var m = window.location.search.match(/[?&]fbclid=([^&]+)/);
    if (m) {
      try {
        // _fbc format used by Meta: fb.{version}.{timestamp}.{fbclid}
        sessionStorage.setItem('_wm_fbc', 'fb.1.' + Date.now() + '.' + decodeURIComponent(m[1]));
      } catch(e) {}
    }
  }

  // Persist email/phone captured from a form so subsequent events in the same
  // browsing session (e.g. a phone-call click after filling a quote form) can
  // include them — improving match quality on Contact and InitiateCheckout.
  function storeUser(data) {
    if (!data) return;
    try {
      var stored = JSON.parse(sessionStorage.getItem('_wm_user') || '{}');
      ['em', 'ph', 'fn', 'ln'].forEach(function(k) { if (data[k]) stored[k] = data[k]; });
      sessionStorage.setItem('_wm_user', JSON.stringify(stored));
    } catch(e) {}
  }

  // Build the richest possible user-data object for a pixel event by merging:
  //   1. Any PII stored from earlier form interactions this session (em, ph, fn, ln)
  //   2. PII passed directly to this call (overrides stored values)
  //   3. Meta click ID (fbc) — from _fbc cookie or our fbclid capture above
  //   4. Meta browser ID (fbp) — from _fbp cookie set by the pixel
  // fbq() automatically hashes em/ph before sending to Meta.
  function buildEventUser(extra) {
    var user = {};
    try { user = JSON.parse(sessionStorage.getItem('_wm_user') || '{}'); } catch(e) {}
    if (extra) ['em', 'ph', 'fn', 'ln'].forEach(function(k) { if (extra[k]) user[k] = extra[k]; });
    var fbc = getCookie('_fbc') || (function() {
      try { return sessionStorage.getItem('_wm_fbc'); } catch(e) { return null; }
    })();
    var fbp = getCookie('_fbp');
    if (fbc) user.fbc = fbc;
    if (fbp) user.fbp = fbp;
    return user;
  }

  // ─── Conversion events (pixel — server-side handled by Stape CAPIG) ────────
  // Server-side deduplication is now managed by the Conversions API Gateway
  // at capig.wastemates.com.au — no custom relay needed.

  // Fire a Lead event. Saves PII to session so later events can use it.
  function trackLead(userData) {
    storeUser(userData);
    if (typeof fbq === 'function') {
      fbq('track', 'Lead', buildEventUser(userData));
    }
  }

  // Fire a Contact event. Includes stored email/phone + Meta click ID.
  function trackContact() {
    if (typeof fbq === 'function') {
      fbq('track', 'Contact', buildEventUser());
    }
  }

  // ─── Behavioural engagement events (pixel only) ──────────────────────────
  // These inform Meta's audience model and ad optimisation.

  function trackView(contentName, contentCategory) {
    if (typeof fbq === 'function') {
      fbq('track', 'ViewContent', {
        content_name: contentName,
        content_category: contentCategory || '',
      });
    }
  }

  // Fire an InitiateCheckout event. Includes stored email/phone + click ID.
  function trackQuoteIntent(source) {
    if (typeof fbq === 'function') {
      var user = buildEventUser();
      user.content_name = 'Get a Quote';
      user.content_category = source || '';
      fbq('track', 'InitiateCheckout', user);
    }
  }

  // ─── Form submit tracking ─────────────────────────────────────────────────

  // Wire up all three quote/enquiry forms (hero, contact, pricing).
  // Fires trackLead on submit with PII captured from the form fields.
  function bindFormEvents() {
    ['hero-quote-form', 'contact-form', 'pricing-form'].forEach(function (id) {
      var form = document.getElementById(id);
      if (!form) return;

      form.addEventListener('submit', function () {
        var data = {};

        // Name → split into fn (first) / ln (last) for advanced matching
        var nameEl = form.querySelector('input[type="text"][name="name"]');
        if (nameEl && nameEl.value.trim()) {
          var parts = nameEl.value.trim().split(/\s+/);
          data.fn = parts[0].toLowerCase();
          if (parts.length > 1) data.ln = parts.slice(1).join(' ').toLowerCase();
        }

        // Phone → digits only, convert Australian 04xx → 614xx (E.164)
        var phoneEl = form.querySelector('input[type="tel"]');
        if (phoneEl && phoneEl.value.trim()) {
          var digits = phoneEl.value.replace(/\D/g, '');
          if (digits.charAt(0) === '0') digits = '61' + digits.slice(1);
          if (digits.length >= 9) data.ph = digits;
        }

        // Email
        var emailEl = form.querySelector('input[type="email"]');
        if (emailEl && emailEl.value.trim()) {
          data.em = emailEl.value.trim().toLowerCase();
        }

        trackLead(data);
      });
    });
  }

  // ─── Phone link tracking ──────────────────────────────────────────────────

  // Fire Contact event whenever any tel: link is clicked (nav, footer,
  // sticky bar, CTA sections — everywhere).
  function bindPhoneEvents() {
    document.querySelectorAll('a[href^="tel:"]').forEach(function (link) {
      link.addEventListener('click', function () {
        trackContact();
      });
    });
  }

  // ─── CTA & navigation tracking ────────────────────────────────────────────

  function bindCtaEvents() {

    // "Get a Quote" / "Get a Free Quote" / "Contact Us" buttons that lead to
    // the contact or pricing form. These are the highest-intent pre-lead clicks.
    document.querySelectorAll(
      'a[href="/contact/"], a[href="#quote"]'
    ).forEach(function (link) {
      // Don't double-track nav links that are purely informational
      // (phone links are already caught by bindPhoneEvents above)
      link.addEventListener('click', function () {
        // Identify where on the page the click came from
        var source = 'unknown';
        if (link.closest('.site-head'))   source = 'Nav';
        else if (link.closest('.sticky-cta')) source = 'Sticky bar';
        else if (link.closest('.cta-band'))   source = 'CTA band';
        else if (link.closest('.hero'))       source = 'Hero';
        else if (link.closest('.section'))    source = 'Section';
        else if (link.closest('footer'))      source = 'Footer';
        trackQuoteIntent(source);
      });
    });

    // Service cards — which service is the visitor interested in?
    document.querySelectorAll('.svc-card').forEach(function (card) {
      card.addEventListener('click', function () {
        var h3 = card.querySelector('h3');
        trackView(h3 ? h3.textContent.trim() : 'Service', 'Service');
      });
    });

    // Pricing page clicks — price researchers are warm leads
    document.querySelectorAll('a[href="/pricing/"]').forEach(function (link) {
      link.addEventListener('click', function () {
        trackView('Pricing', 'Pricing');
      });
    });

    // About page clicks — brand trust signals
    document.querySelectorAll('a[href="/about/"]').forEach(function (link) {
      link.addEventListener('click', function () {
        trackView('About Us', 'About');
      });
    });

    // Services page clicks
    document.querySelectorAll('a[href="/services/"]').forEach(function (link) {
      link.addEventListener('click', function () {
        trackView('Services', 'Services');
      });
    });

    // Social media clicks — shows brand engagement off-site
    var socialPlatforms = {
      'instagram.com': 'Instagram',
      'youtube.com':   'YouTube',
      'facebook.com':  'Facebook',
      'tiktok.com':    'TikTok',
    };
    document.querySelectorAll('.socials a').forEach(function (link) {
      link.addEventListener('click', function () {
        var platform = 'Social';
        Object.keys(socialPlatforms).forEach(function (domain) {
          if (link.href.indexOf(domain) !== -1) platform = socialPlatforms[domain];
        });
        if (typeof fbq === 'function') {
          fbq('trackCustom', 'SocialClick', { platform: platform });
        }
      });
    });
  }

  // ─── Multi-photo picker: filename preview + client-side limit checks ──────

  // Mirrors the limits enforced server-side in send-enquiry.php so users see
  // a clear inline message instead of silently losing a photo on submit.
  var MAX_PHOTOS = 8;
  var MAX_PHOTO_BYTES = 6 * 1024 * 1024;
  var MAX_TOTAL_BYTES = 15 * 1024 * 1024;

  function describePhotoError(files) {
    if (files.length > MAX_PHOTOS) {
      return 'Please select at most ' + MAX_PHOTOS + ' photos (you selected ' + files.length + ').';
    }
    var total = 0;
    for (var i = 0; i < files.length; i++) {
      if (files[i].size > MAX_PHOTO_BYTES) {
        return '"' + files[i].name + '" is over 6MB — please use a smaller photo.';
      }
      total += files[i].size;
    }
    if (total > MAX_TOTAL_BYTES) {
      return 'Those photos add up to more than 15MB combined — please remove a few.';
    }
    return null;
  }

  function bindPhotoInputs() {
    document.querySelectorAll('input[type="file"][name="photos[]"]').forEach(function (input) {
      var hint = input.parentNode.querySelector('.file-hint');
      var defaultHint = hint ? hint.textContent : '';

      input.addEventListener('change', function () {
        var files = input.files;
        var error = files.length ? describePhotoError(files) : null;
        input.setCustomValidity(error || '');

        if (!hint) return;

        if (error) {
          hint.textContent = error;
          hint.style.color = '#c0392b';
        } else if (files.length) {
          var names = Array.prototype.map.call(files, function (f) { return f.name; });
          hint.textContent = files.length + ' photo' + (files.length > 1 ? 's' : '') + ' selected: ' + names.join(', ');
          hint.style.color = '';
        } else {
          hint.textContent = defaultHint;
          hint.style.color = '';
        }
      });
    });
  }

  // ─── Form status (loading / success / error) ───────────────────────────────

  function setFormStatus(statusEl, state, text) {
    if (!statusEl) return;
    statusEl.className = 'form-status is-visible form-status--' + state;
    var textEl = statusEl.querySelector('.form-status__text');
    if (textEl) textEl.textContent = text;
  }

  // Show success banner when redirected back after a no-JS form submission
  // (?sent=1) — the fallback path for browsers with JS disabled, since
  // bindFormSubmission() below handles the normal case without a page reload.
  function handleFormSuccess() {
    if (window.location.search.indexOf('sent=1') === -1) return;
    var statusEl = document.querySelector('.form-status');
    if (statusEl) {
      setFormStatus(statusEl, 'success', "Thanks — we'll be in touch soon!");
      statusEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    // Clean the URL so a refresh doesn't re-show the banner
    if (window.history && window.history.replaceState) {
      var clean = window.location.pathname + window.location.hash;
      window.history.replaceState(null, '', clean);
    }
  }

  // Submit the quote/enquiry forms via fetch instead of a full page POST, so
  // we can show a loading state while send-enquiry.php does its (several
  // seconds long — it emails, then notifies Telegram and Discord) work,
  // then confirm success in place rather than leaving the customer looking
  // at a blank wait with no feedback.
  function bindFormSubmission() {
    ['hero-quote-form', 'contact-form', 'pricing-form'].forEach(function (id) {
      var form = document.getElementById(id);
      if (!form) return;
      var statusEl = form.querySelector('.form-status');
      var submitBtn = form.querySelector('button[type="submit"]');

      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (form.dataset.submitting === 'true') return;
        form.dataset.submitting = 'true';

        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.dataset.originalText = submitBtn.textContent;
          submitBtn.textContent = 'Sending…';
        }
        setFormStatus(statusEl, 'sending', 'Sending your enquiry — this can take up to 15 seconds…');

        fetch(form.action, { method: 'POST', body: new FormData(form) })
          .then(function (response) {
            if (response.ok && response.url.indexOf('sent=1') !== -1) {
              setFormStatus(statusEl, 'success', "Thanks — we'll be in touch soon!");
              form.reset();
            } else {
              throw new Error('Unexpected response');
            }
          })
          .catch(function () {
            setFormStatus(statusEl, 'error', 'Something went wrong sending that — please call us on 0494 013 254 instead.');
          })
          .finally(function () {
            form.dataset.submitting = 'false';
            if (submitBtn) {
              submitBtn.disabled = false;
              submitBtn.textContent = submitBtn.dataset.originalText;
            }
          });
      });
    });
  }

  // ─── Sticky mobile CTA bar ────────────────────────────────────────────────

  // Keeps the two highest-value actions (call now, get a quote) within
  // thumb's reach on phones. Injected once so every page picks it up.
  function injectStickyCta() {
    if (document.querySelector('.sticky-cta')) return;
    var bar = document.createElement('div');
    bar.className = 'sticky-cta';
    bar.innerHTML =
      '<a class="btn btn--ghost" href="tel:+61494013254">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:17px;height:17px"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>' +
        'Call now</a>' +
      '<a class="btn btn--green" href="/contact/">Get a Free Quote</a>';
    document.body.appendChild(bar);
  }

  function bindMarquee() {
    var track = document.querySelector('.marquee-track');
    if (!track) return;
    var marquee = track.closest('.marquee');
    marquee.addEventListener('mouseenter', function () {
      track.style.animationPlayState = 'paused';
    });
    marquee.addEventListener('mouseleave', function () {
      track.style.animationPlayState = 'running';
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    captureClickId();     // store fbclid from URL before any events fire
    bindMenu();
    bindFaq();
    bindFormEvents();
    bindFormSubmission();
    bindPhotoInputs();
    handleFormSuccess();
    bindMarquee();
    injectStickyCta();    // must come before bindPhoneEvents/bindCtaEvents
    bindPhoneEvents();    // catches sticky bar phone links too
    bindCtaEvents();      // catches sticky bar CTA links too
    initMap();
  });
})();
