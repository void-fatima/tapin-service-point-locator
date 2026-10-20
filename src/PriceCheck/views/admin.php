<?php defined( 'ABSPATH' ) || exit; ?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>مدیریت شرکت‌های پست</title>
  <link rel="icon" href="/static/tapin-mark.png" type="image/png">
  <link rel="apple-touch-icon" href="/static/tapin-mark.png">
  <link rel="stylesheet" href="/static/style.css">
</head>
<body>
  <a class="skip-link" href="#main">پرش به محتوای اصلی</a>
  <div class="layout pc-admin-shell">
    <aside class="sidebar">
      <button class="brand pc-sidebar-toggle" type="button" aria-label="جمع کردن منوی مدیریت" aria-expanded="true" aria-controls="pc-admin-navigation" title="جمع کردن منوی مدیریت"><img class="brand-mark" src="/static/tapin-mark.png" alt=""><span class="brand-word">تاپین</span><span class="brand-page">مدیریت</span></button>
      <nav class="tabs menu" id="pc-admin-navigation" aria-label="منوی مدیریت">
        <button class="btn" type="button" data-tab="providers" data-tooltip="شرکت‌ها" aria-label="شرکت‌ها"><svg class="menu-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="1.5"/><path d="M9 7h2M13 7h2M9 11h2M13 11h2M9 15h2M13 15h2M10 21v-3h4v3"/></svg><span class="pc-menu-label">شرکت‌ها</span></button>
        <button class="btn btn-ghost" type="button" data-tab="shops" data-tooltip="شعب" aria-label="شعب"><svg class="menu-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10a2 2 0 0 0 2 2 2 2 0 0 0 2-2 2 2 0 0 0 2 2 2 2 0 0 0 2-2 2 2 0 0 0 2 2 2 2 0 0 0 2-2V6H3v4z"/><path d="M5 12v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8M10 21v-4h4v4"/></svg><span class="pc-menu-label">شعب</span></button>
        <button class="btn btn-ghost" type="button" data-tab="bulk" data-tooltip="استعلام گروهی" aria-label="استعلام گروهی"><svg class="menu-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4v15a1 1 0 0 0 1 1h15"/><path d="M8 15v-4M12 15V8M16 15v-6"/></svg><span class="pc-menu-label">استعلام گروهی</span></button>
        <button class="btn btn-ghost" type="button" data-tab="logs" data-tooltip="گزارش خطاها" aria-label="گزارش خطاها"><svg class="menu-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h12v18l-2-1.4-2 1.4-2-1.4L10 21l-2-1.4L6 21V3z"/><path d="M9 8h6M9 12h6"/></svg><span class="pc-menu-label">گزارش خطاها</span></button>
        <button class="btn btn-ghost" type="button" data-tab="cache" data-tooltip="کش" aria-label="کش"><svg class="menu-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><ellipse cx="12" cy="6" rx="7" ry="3"/><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"/><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"/></svg><span class="pc-menu-label">کش</span></button>
        <button class="btn btn-ghost" type="button" data-tab="security" data-tooltip="امنیت" aria-label="امنیت"><svg class="menu-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5c0 5-3.5 8-7 10-3.5-2-7-5-7-10V6l7-3z"/><path d="M9.5 12l2 2 3.5-4"/></svg><span class="pc-menu-label">امنیت</span></button>
      </nav>
      <a class="btn btn-ghost pc-sidebar-public-link" href="{{public-url}}" target="_parent" data-tooltip="صفحه عمومی" aria-label="صفحه عمومی"><svg class="menu-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.5 2.3 3.8 5.2 3.8 8.5s-1.3 6.2-3.8 8.5c-2.5-2.3-3.8-5.2-3.8-8.5s1.3-6.2 3.8-8.5z"/></svg><span class="pc-menu-label">صفحه عمومی</span></a>
    </aside>

    <div class="content">
      <main class="wrap" id="main">
        <section id="list-view">
          <h1>مدیریت داده شرکت‌ها</h1>
          <p class="lead">
            هر چیزی که اینجا ذخیره شود، مستقیم در صفحه استعلام قیمت نمایش داده می‌شود.
            ذخیره‌سازی از طریق <span class="code">/api/admin/providers</span> انجام می‌شود.
          </p>
          <div id="provider-rows"><p class="spinner">در حال بارگذاری…</p></div>
        </section>

        <section id="editor-view" hidden></section>

        <section id="bulk-view" hidden></section>

        <section id="shops-view" hidden>
          <h1>مدیریت شعب</h1>
          <p class="lead">
            هر ردیف یک <span class="code">shop_id</span> است که در پارامترهای شرکت‌ها به آن ارجاع داده می‌شود.
            ستون <span class="code">id</span> همان <span class="code">shop_id</span> است و هر شعبه برای تماس زنده با سرویس قیمت به توکن خودش نیاز دارد.
            ذخیره‌سازی از طریق <span class="code">/api/admin/shops</span> انجام می‌شود.
          </p>
          <div id="shops-rows"><p class="spinner">در حال بارگذاری…</p></div>
        </section>

        <section id="logs-view" hidden>
          <h1>گزارش خطاها</h1>
          <p class="lead">
            فقط استعلام‌های ناموفق ثبت می‌شوند — همراه با شرکت، پیام خطا، پارامترها،
            درخواست و پاسخ. استعلام‌های موفق ذخیره نمی‌شوند و مدت نگهداری از تب کش قابل تنظیم است.
          </p>
          <div id="logs-rows"><p class="spinner">در حال بارگذاری…</p></div>
        </section>

        <section id="security-view" hidden>
          <h1>امنیت و محدودیت‌ها</h1>
          <p class="lead">
            محدودیت نرخ هر API، و مدیریت کاربران مسدودشده همراه با درخواست‌های ثبت‌شده آن‌ها.
          </p>
          <div id="security-rows"><p class="spinner">در حال بارگذاری…</p></div>
        </section>

        <section id="cache-view" hidden>
          <h1>مدیریت کش</h1>
          <p class="lead">
            مدت‌زمان نگهداری کش قیمت (تازه/نگهداری)، لیست استان/شهر (تازه + استیل دائمی هنگام خطا) و لاگ استعلام‌ها، همراه با پاک‌سازی دستی هر کدام. همه عددها به ثانیه‌اند.
          </p>
          <div id="cache-rows"><p class="spinner">در حال بارگذاری…</p></div>
        </section>
      </main>

      <footer class="footer wrap"><strong>تاپین</strong> • مدیریت داده شرکت‌ها</footer>
    </div>
  </div>

  <div id="toast" role="status" aria-live="polite" hidden></div>
  <script type="module" src="/static/admin.js"></script>
</body>
</html>
