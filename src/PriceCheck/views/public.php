<?php defined( 'ABSPATH' ) || exit; ?>
<!doctype html>
<html lang="fa" dir="rtl" class="tapin-price-document">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="theme-color" content="#2d2b74">
  <title>استعلام قیمت ارسال</title>
  <link rel="icon" href="/static/tapin-mark.png" type="image/png">
  <link rel="apple-touch-icon" href="/static/tapin-mark.png">
  <link rel="stylesheet" href="/static/style.css">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<?php if ( $embedded ) : ?>
  <link rel="stylesheet" href="/static/embedded.css">
<?php endif; ?>
</head>
<body>
  <a class="skip-link" href="#main">پرش به محتوای اصلی</a>
<?php if ( ! $embedded ) : ?>
  <header class="topbar">
    <div class="wrap wrap-pro">
      <span class="brand"><img class="brand-mark" src="/static/tapin-mark.png" alt="تاپین"><span class="brand-word">تاپین</span><span class="brand-page">استعلام قیمت ارسال</span></span>
      <nav class="nav" aria-label="نوع استعلام">
        <button class="btn" type="button" id="nav-bulk">استعلام</button>
        <button class="btn btn-ghost" type="button" id="nav-advanced">استعلام پیشرفته</button>
      </nav>
    </div>
  </header>
<?php endif; ?>

  <main class="wrap wrap-pro" id="main">
    <?php include __DIR__ . '/theme-choice.php'; ?>
    <section id="bulk-view" aria-label="استعلام">
      <div class="hero pro-hero pro-hero-bulk">
        <div class="pro-hero-text">
          <h1>استعلام قیمت ارسال</h1>
          <p class="lead">مبدا و مشخصات مرسوله را یک‌بار وارد کنید تا همه شرکت‌ها همزمان استعلام شوند. ارزان‌ترین گزینه به‌صورت خودکار مشخص می‌شود.</p>
          <div class="hero-cta-row">
            <p class="hero-cta-text">برای استعلام از یک شرکت خاص، فرم اختصاصی آن را باز کنید:</p>
            <button class="adv-cta" type="button" id="bulk-advanced-toggle" aria-expanded="false" aria-controls="bulk-advanced-panel">
              <span class="adv-cta-text">
                <strong>استعلام پیشرفته</strong>
                <small>انتخاب مستقیم یک شرکت فعال</small>
              </span>
              <span class="adv-cta-count" id="bulk-advanced-count" hidden></span>
              <span class="adv-cta-chev" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
              </span>
            </button>
            <div class="adv-menu" id="bulk-advanced-panel" hidden>
              <div class="adv-panel-head">
                <span class="adv-panel-title">شرکت‌های فعال</span>
                <span class="adv-panel-sub" id="bulk-advanced-sub">در حال بارگذاری…</span>
              </div>
              <div class="adv-grid" id="bulk-advanced-grid"></div>
            </div>
          </div>
        </div>
      </div>
      <div class="pro-layout">
        <div class="pro-form-col">
          <form id="bulk-form" novalidate></form>
        </div>
        <div class="pro-result-col" aria-live="polite">
          <div id="bulk-result"></div>
        </div>
      </div>
    </section>

      <section id="advanced-view" hidden aria-label="استعلام پیشرفته">
      <section id="choose">
        <div class="hero pro-hero">
          <div class="pro-hero-text">
            <h1>استعلام <span class="hl">پیشرفته</span> از یک شرکت</h1>
            <p class="lead">شرکت مورد نظر را جستجو و انتخاب کنید تا فرم اختصاصی آن نمایش داده شود.</p>
          </div>
        </div>
        <div class="card search-card">
          <input type="text" id="provider-search" placeholder="جستجوی شرکت… (مثلاً تیپاکس)" autocomplete="off">
        </div>
        <div id="provider-list" class="provider-grid"><p class="spinner">در حال بارگذاری…</p></div>
      </section>

      <section id="form-panel" hidden>
        <div class="card pro-form-head">
          <div class="row pro-form-head-row"><span id="form-logo"></span><h1 id="form-title"></h1></div>
          <div class="row">
            <button class="btn btn-ghost btn-sm" type="button" id="back-to-list">تغییر شرکت</button>
          </div>
        </div>
        <div class="pro-layout">
          <div class="pro-form-col">
            <form id="price-form" novalidate></form>
          </div>
          <div class="pro-result-col" aria-live="polite">
            <div id="result"></div>
          </div>
        </div>
      </section>
    </section>
  </main>

<?php if ( ! $embedded ) : ?>
  <footer class="footer wrap"><strong>تاپین</strong> • استعلام و استعلام پیشرفته قیمت ارسال</footer>
<?php endif; ?>

  <div id="toast" role="status" aria-live="polite" hidden></div>
  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script type="module" src="/static/index.js"></script>
<?php if ( $embedded ) : ?>
  <script type="module" src="/static/embedded.js"></script>
<?php endif; ?>
</body>
</html>
