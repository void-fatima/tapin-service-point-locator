/* Admin UI comprehensive acceptance and interaction tests */
const { chromium, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

(async () => {
  const session = JSON.parse(fs.readFileSync(process.env.TAPIN_SESSION_FILE, 'utf8'));
  const browser = await chromium.launch({ headless: true });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  await context.addCookies(session.cookies);
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));

  try {
    // 1. Dashboard UI Verification
    await page.goto(session.url + '/wp-admin/admin.php?page=tapin-locator#dashboard', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('.hero-number')).toBeVisible({ timeout: 20000 });
    await expect(page.locator('.metric-rail h2')).toContainText('نقطه خدماتی');
    await expect(page.locator('.coverage')).toBeVisible();
    await expect(page.locator('.coordinate-totals')).toContainText('دارای مختصات');
    await expect(page.locator('.coordinate-totals')).toContainText('بدون مختصات');
    await expect(page.locator('.issue-list')).toHaveCount(0);
    await expect(page.locator('.dashboard-bottom')).toHaveCount(0);
    await expect(page.locator('.header-actions .primary')).toContainText('افزودن فایل');
    await expect(page.locator('.dashboard-directory-panel')).toBeVisible();

    // 2. Service Points Management Table & Filters
    await page.locator('[data-nav=points]').click();
    await expect(page.locator('#tapin-title')).toHaveText('نقاط خدماتی');
    const pointsTable = page.locator('#tapin-content table');
    await expect(pointsTable).toBeVisible({ timeout: 20000 });
    await expect(pointsTable.locator('thead')).toContainText('ارائه‌دهنده');
    await expect(pointsTable.locator('thead')).toContainText('نام شعبه و کد');
    await expect(pointsTable.locator('thead')).toContainText('نشانی');
    await expect(pointsTable.locator('thead')).toContainText('مختصات');
    await expect(pointsTable.locator('thead')).toContainText('کیفیت');
    await expect(pointsTable.locator('thead')).toContainText('عملیات');

    // Filter controls existence
    const filters = page.locator('#point-filters');
    await expect(filters.locator('input[name=search]')).toBeVisible();
    await expect(filters.locator('select[name=provider_id]')).toBeVisible();
    await expect(filters.locator('select[name=province]')).toBeVisible();
    await expect(filters.locator('select[name=city]')).toBeVisible();
    await expect(filters.locator('select[name=has_coordinates]')).toBeVisible();
    await expect(filters.locator('select[name=status]')).toBeVisible();
    await expect(filters.locator('select[name=issue]')).toBeVisible();

    // 3. View Detail Sheet (Drawer/Modal)
    const firstRow = page.locator('table tbody tr').first();
    await expect(firstRow).toBeVisible();
    const viewBtn = firstRow.locator('[data-view]');
    await expect(viewBtn).toBeVisible();
    await viewBtn.click();

    const dialog = page.locator('dialog.tapin-dialog');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('.point-view-sheet')).toBeVisible();
    await expect(dialog.locator('.sheet-hero')).toBeVisible();
    await expect(dialog.locator('.sheet-grid')).toBeVisible();
    await expect(dialog.locator('[data-switch-edit]')).toBeVisible();
    await expect(dialog.locator('[data-close-sheet]')).toBeVisible();

    // Close view dialog
    await dialog.locator('[data-close-sheet]').click();
    await expect(dialog).not.toBeVisible();

    // 4. Provider Management Page & Toggle
    await page.locator('[data-nav=providers]').click();
    await expect(page.locator('.provider-cards')).toBeVisible({ timeout: 20000 });
    await expect(page.locator('#new-provider')).toBeVisible();
    const firstProviderCard = page.locator('.provider-cards article').first();
    await expect(firstProviderCard).toBeVisible();
    await expect(firstProviderCard.locator('[data-provider-edit]')).toBeVisible();
    await expect(firstProviderCard.locator('[data-provider-toggle]')).toBeVisible();

    // 5. Import Center
    await page.locator('[data-nav=imports]').click();
    await expect(page.locator('.upload-zone')).toBeVisible({ timeout: 20000 });
    await expect(page.locator('.upload-zone input[type=file]')).toBeAttached();
    await expect(page.locator('.upload-zone input[type=file]')).toHaveAttribute('accept', '.csv,.xlsx');
    await expect(page.locator('a[download]')).toContainText('دریافت نمونه ستون‌های CSV');
    await expect(page.locator('.import-history')).toBeVisible();

    // 6. Mobile Responsiveness check
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(session.url + '/wp-admin/admin.php?page=tapin-locator#points', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#tapin-content table')).toBeVisible({ timeout: 20000 });
    const isOverflowing = await page.locator('#tapin-admin').evaluate(el => el.scrollWidth > el.clientWidth + 2);
    if (isOverflowing) throw new Error('Admin panel overflows at mobile width (390px)');

    if (errors.length) throw new Error('Console errors encountered: ' + errors.join('\n'));
    console.log('PASS admin-ui: dashboard metrics, service points table columns & filters, view detail sheet, provider cards & actions, import center, and responsive mobile layout');
  } finally {
    await browser.close();
  }
})().catch(e => {
  console.error(e);
  process.exit(1);
});
