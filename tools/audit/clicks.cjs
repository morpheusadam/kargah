// Interactive audit: click EVERY wire:click target on a page, one per fresh load,
// and report any JS error, Livewire error dialog or HTTP failure it causes.
// ISOLATED DATABASE COPY ONLY — this deletes things.
const { chromium } = require('playwright-core');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.AUDIT_BASE || 'http://127.0.0.1:8124';
const ROUTE = process.env.AUDIT_ROUTE || '/mail/inbox';
const START = parseInt(process.env.AUDIT_START || '0', 10);
const END = parseInt(process.env.AUDIT_END || '9999', 10);

const describe = el => {
    const w = el.getAttribute('wire:click') || el.getAttribute('wire:click.prevent') || '';
    const t = (el.innerText || el.getAttribute('aria-label') || el.getAttribute('title') || '').replace(/\s+/g, ' ').trim().slice(0, 40);
    return w + ' | ' + t;
};

(async () => {
    const browser = await chromium.launch({ executablePath: CHROME });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    page.on('dialog', d => d.accept());

    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[type=email]', 'admin@admin.com');
    await page.fill('input[type=password]', 'admin');
    await Promise.all([
        page.waitForURL(u => !u.pathname.includes('login'), { timeout: 30000 }),
        page.click('button[type=submit]'),
    ]);

    await page.goto(BASE + ROUTE, { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);
    const total = await page.evaluate(() => document.querySelectorAll('[wire\\:click], [wire\\:click\\.prevent]').length);
    console.log('targets on ' + ROUTE + ': ' + total);

    const findings = [];
    const last = Math.min(total - 1, END);

    for (let i = START; i <= last; i++) {
        const errors = [];
        const onConsole = m => { if (m.type() === 'error') errors.push(m.text().slice(0, 400)); };
        const onPageError = e => errors.push('PAGEERROR ' + String(e).slice(0, 400));
        const onResponse = r => { if (r.status() >= 500) errors.push('HTTP' + r.status() + ' ' + r.url().replace(BASE, '')); };

        await page.goto(BASE + ROUTE, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2200);

        page.on('console', onConsole);
        page.on('pageerror', onPageError);
        page.on('response', onResponse);

        let label = '(gone)';
        try {
            label = await page.evaluate((idx) => {
                const els = document.querySelectorAll('[wire\\:click], [wire\\:click\\.prevent]');
                const el = els[idx];
                if (!el) return '(gone)';
                const w = el.getAttribute('wire:click') || el.getAttribute('wire:click.prevent') || '';
                const t = (el.innerText || el.getAttribute('aria-label') || el.getAttribute('title') || '').replace(/\s+/g, ' ').trim().slice(0, 40);
                el.scrollIntoView({ block: 'center' });
                return w + ' | ' + t;
            }, i);

            if (label !== '(gone)') {
                await page.evaluate((idx) => {
                    const els = document.querySelectorAll('[wire\\:click], [wire\\:click\\.prevent]');
                    if (els[idx]) els[idx].click();
                }, i);
                await page.waitForTimeout(1800);
            }
        } catch (e) {
            errors.push('CLICKFAIL ' + String(e).slice(0, 200));
        }

        // Livewire's error modal, and a page that emptied itself
        let after = {};
        try {
            after = await page.evaluate(() => ({
                dialog: !!document.querySelector('[wire\\:dialog], .livewire-error, #livewire-error'),
                text: (document.body.innerText || '').replace(/\s+/g, ' ').trim().length,
                url: location.pathname,
            }));
        } catch (e) { errors.push('EVALFAIL ' + String(e).slice(0, 150)); }

        page.off('console', onConsole);
        page.off('pageerror', onPageError);
        page.off('response', onResponse);

        const bad = errors.length || after.dialog || (after.text || 0) < 200;
        if (bad) {
            findings.push({ i, label, errors, after });
            console.log('#' + i + ' ' + label + '  ✗ ' + (errors[0] || ('dialog=' + after.dialog + ' text=' + after.text)));
        } else {
            console.log('#' + i + ' ' + label + '  ok (' + after.text + 'ch ' + after.url + ')');
        }
    }

    require('fs').writeFileSync(__dirname + '/audit-clicks.json', JSON.stringify(findings, null, 1));
    console.log('--- findings: ' + findings.length + ' ---');
    await browser.close();
})();
