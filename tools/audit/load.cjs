// Page-load audit. Asks the DOM whether the page actually survived, rather than
// trusting the server's HTTP status. Run against an ISOLATED database copy only.
const { chromium } = require('playwright-core');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.AUDIT_BASE || 'http://127.0.0.1:8124';

const ROUTES = process.env.AUDIT_ROUTES
    ? process.env.AUDIT_ROUTES.split(',')
    : [
        '/dashboard', '/notifications',
        '/projects', '/projects/table', '/projects/calendar', '/projects/dashboard',
        '/projects/activity', '/projects/archive', '/projects/butler', '/projects/print',
        '/projects/2/settings',
        '/accounting/clients', '/accounting/clients/5',
        '/accounting/estimates', '/accounting/estimates/create',
        '/accounting/expenses', '/accounting/expenses/create', '/accounting/expenses/1/edit',
        '/accounting/invoices', '/accounting/invoices/create', '/accounting/invoices/1',
        '/accounting/invoices/1/edit', '/accounting/recurring', '/accounting/reports',
        '/mail/inbox', '/mail/campaigns', '/mail/campaigns/create', '/mail/campaigns/3',
        '/mail/campaigns/3/edit', '/mail/contacts', '/mail/contacts/import',
        '/mail/providers', '/mail/providers/1/edit', '/mail/suppression',
        '/data/files', '/data/links', '/data/links/create', '/data/passwords',
        '/data/passwords/create', '/data/repos', '/data/repos/3', '/data/backups', '/data/backups/2',
        '/social/accounts', '/social/accounts/connect', '/social/calendar',
        '/social/posts', '/social/posts/1', '/social/publish', '/social/notifications',
        '/blog', '/blog/compose', '/blog/1/edit',
        '/settings/profile', '/settings/security', '/settings/appearance',
        '/settings/notifications', '/settings/application-passwords', '/settings/assistant',
    ];

(async () => {
    const browser = await chromium.launch({ executablePath: CHROME });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();

    // --- log in -----------------------------------------------------------
    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[type=email]', 'admin@admin.com');
    await page.fill('input[type=password]', 'admin');
    await Promise.all([
        page.waitForURL(u => !u.pathname.includes('login'), { timeout: 30000 }),
        page.click('button[type=submit]'),
    ]);
    console.log('logged in ->', page.url());

    const results = [];

    for (const route of ROUTES) {
        const errors = [];
        const failed = [];
        const onConsole = m => { if (m.type() === 'error') errors.push(m.text().slice(0, 300)); };
        const onPageError = e => errors.push('PAGEERROR ' + String(e).slice(0, 300));
        const onFailed = r => failed.push(r.url().replace(BASE, '') + ' ' + (r.failure() || {}).errorText);
        page.on('console', onConsole);
        page.on('pageerror', onPageError);
        page.on('requestfailed', onFailed);

        let status = 0, err = null;
        try {
            const resp = await page.goto(BASE + route, { waitUntil: 'domcontentloaded', timeout: 45000 });
            status = resp ? resp.status() : 0;
            // let lazy islands and any vendor bundle settle
            await page.waitForTimeout(3500);
        } catch (e) {
            err = String(e).slice(0, 200);
        }

        let m = {};
        try {
            m = await page.evaluate(() => ({
                text: (document.body.innerText || '').replace(/\s+/g, ' ').trim().length,
                html: document.documentElement.outerHTML.length,
                overflow1440: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                apex: typeof window.ApexCharts !== 'undefined',
                fc: typeof window.FullCalendar !== 'undefined',
                svg: document.querySelectorAll('.apexcharts-canvas svg').length,
                fcRendered: document.querySelectorAll('.fc-view-harness').length,
                skeleton: document.querySelectorAll('[wire\\:loading], .animate-pulse').length,
            }));
        } catch (e) { /* page gone */ }

        // responsive check at 375
        let overflow375 = null, offender = null;
        try {
            await page.setViewportSize({ width: 375, height: 800 });
            await page.waitForTimeout(1500);
            const r = await page.evaluate(() => {
                const doc = document.documentElement;
                const over = doc.scrollWidth - doc.clientWidth;
                let worst = null;
                if (over > 0) {
                    let max = doc.clientWidth;
                    document.querySelectorAll('body *').forEach(el => {
                        const b = el.getBoundingClientRect();
                        if (b.right > max + 1 && b.width > 0) {
                            max = b.right;
                            worst = el.tagName.toLowerCase() + '.' + (el.className || '').toString().slice(0, 90) + ' right=' + Math.round(b.right);
                        }
                    });
                }
                return { over, worst };
            });
            overflow375 = r.over; offender = r.worst;
            await page.setViewportSize({ width: 1440, height: 900 });
        } catch (e) { /* ignore */ }

        page.off('console', onConsole);
        page.off('pageerror', onPageError);
        page.off('requestfailed', onFailed);

        results.push({ route, status, err, ...m, overflow375, offender, errors, failed });
        const flag = (status !== 200 ? ' HTTP' + status : '') + (errors.length ? ' JS×' + errors.length : '')
            + ((m.text || 0) < 800 ? ' THIN' + m.text : '') + (overflow375 > 0 ? ' OVER375=' + overflow375 : '');
        console.log(String(route).padEnd(38), (m.text || 0) + 'ch', flag || 'ok');
        if (errors.length) errors.slice(0, 3).forEach(e => console.log('      ! ' + e));
        if (offender) console.log('      > ' + offender);
    }

    require('fs').writeFileSync(__dirname + '/audit-load.json', JSON.stringify(results, null, 1));
    await browser.close();
})();
