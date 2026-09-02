<?php
/**
 * Site nav + mobile menu, shared by every page. Set $activeNav to one of
 * the array keys below before requiring this file to highlight that link;
 * leave it unset for pages that aren't in the top nav (suburb pages, 404, etc).
 * $ctaHref overrides the "Get a Quote" button target — contact.html points
 * it at its own #quote form instead of reloading itself.
 */
$navItems = [
    'home'     => ['index.html', 'Home'],
    'services' => ['services.html', 'Services'],
    'pricing'  => ['pricing.html', 'Pricing'],
    'about'    => ['about.html', 'About'],
    'areas'    => ['service-areas.html', 'Areas'],
    'faq'      => ['faq.html', 'FAQ'],
    'contact'  => ['contact.html', 'Contact'],
];
$activeNav = $activeNav ?? null;
$ctaHref = $ctaHref ?? 'contact.html';
?>
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-K6RML9SR"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->


  <!-- ===================== HEADER ===================== -->
  <header class="site-head">
    <div class="wrap">
      <nav class="nav">
        <a class="brand" href="index.html" aria-label="WasteMates home"><picture><source srcset="assets/logo.webp" type="image/webp" /><img src="assets/logo.png" alt="WasteMates" width="89" height="58" /></picture></a>
        <div class="nav-links">
<?php foreach ($navItems as $key => [$href, $label]): ?>
          <a href="<?= $href ?>"<?= $key === $activeNav ? ' class="active"' : '' ?>><?= $label ?></a>
<?php endforeach; ?>
        </div>
        <div class="nav-cta">
          <a class="phone-link" href="tel:+61494013254"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>0494 013 254</a>
          <a class="btn btn--green" href="<?= $ctaHref ?>">Get a Quote</a>
          <button class="burger" aria-label="Menu"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
        </div>
      </nav>
    </div>
  </header>
  <div class="mobile-menu">
<?php foreach ($navItems as [$href, $label]): ?>
    <a href="<?= $href ?>"><?= $label ?></a>
<?php endforeach; ?>
  </div>
