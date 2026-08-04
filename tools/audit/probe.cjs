// One-off probes. Each entry loads a page, runs a snippet, and reports what the
// browser ended up with. ISOLATED DATABASE COPY ONLY.
const { chromium } = require('playwright-core');
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BASE = process.env.AUDIT_BASE || 'http://127.0.0.1:8124';

(async () => {
    const browser = await chromium.launch({ executablePath: CHROME });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();
    page.on('dialog', d => d.accept());
    const errors = [];
    page.on('console', m => { if (m.type() === 'error') errors.push(m.text().slice(0, 300)); });
    page.on('pageerror', e => errors.push('PAGEERROR ' + String(e).slice(0, 300)));
    page.on('response', r => { if (r.status() >= 500) errors.push('HTTP' + r.status() + ' ' + r.url().replace(BASE, '')); });

    await page.goto(BASE + '/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[type=email]', 'admin@admin.com');
    await page.fill('input[type=password]', 'admin');
    await Promise.all([
        page.waitForURL(u => !u.pathname.includes('login'), { timeout: 30000 }),
        page.click('button[type=submit]'),
    ]);

    const wire = () => page.evaluate(() => window.Livewire.all()[0].$wire);

    // --- 1. unknown `kind` on /data/links/create ---------------------------
    await page.goto(BASE + '/data/links/create', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(2500);
    errors.length = 0;
    const setResult = await page.evaluate(async () => {
        try { await window.Livewire.all()[0].$wire.$set('kind', 'not-a-kind'); return 'resolved'; }
        catch (e) { return 'rejected: ' + JSON.stringify(e).slice(0, 120); }
    });
    const preview = await page.evaluate(() => {
        const t = document.body.innerText.replace(/\s+/g, ' ');
        return { says: t.includes('Not a known kind'), len: t.trim().length };
    });
    console.log('1 link-create unknown kind: $set ' + setResult + ' · preview says "Not a known kind": ' + preview.says + ' · ' + preview.len + 'ch');
    errors.forEach(e => console.log('   ! ' + e));

    // --- 2. the void dialog on a paid invoice ------------------------------
    for (const [id, label] of [[1, 'INV-0038 paid, 2 payments'], [4, 'INV-0041 sent, no payments']]) {
        await page.goto(BASE + '/accounting/invoices/' + id, { waitUntil: 'domcontentloaded' });
        await page.waitForTimeout(2500);
        errors.length = 0;
        await page.evaluate(() => window.Livewire.all()[0].$wire.call('openVoid'));
        await page.waitForTimeout(1800);
        const modal = await page.evaluate(() => {
            const m = [...document.querySelectorAll('.kt-modal')].find(e => e.innerText.includes('Void'));
            if (!m) return { found: false };
            return {
                found: true,
                open: m.classList.contains('open'),
                text: m.innerText.replace(/\s+/g, ' ').trim().slice(0, 220),
                hasVoidButton: /Void it/.test(m.innerText),
            };
        });
        console.log('2 invoice ' + id + ' (' + label + '): voidButton=' + modal.hasVoidButton);
        console.log('   ' + modal.text);
        errors.forEach(e => console.log('   ! ' + e));

        // and prove the action itself refuses, not just the button being absent
        const status = await page.evaluate(async () => {
            try { await window.Livewire.all()[0].$wire.call('voidInvoice'); return 'called'; }
            catch (e) { return 'threw ' + JSON.stringify(e).slice(0, 100); }
        });
        await page.waitForTimeout(1500);
        console.log('   voidInvoice() -> ' + status);
    }

    // --- 3. the clients tab strip at 375 -----------------------------------
    await page.setViewportSize({ width: 375, height: 800 });
    await page.goto(BASE + '/accounting/clients', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(3000);
    const strip = await page.evaluate(() => {
        const doc = document.documentElement;
        const el = document.querySelector('.kt-tabs');
        const wrap = el ? el.parentElement : null;
        return {
            bodyOverflow: doc.scrollWidth - doc.clientWidth,
            wrapClass: wrap ? wrap.className : '(none)',
            wrapScrolls: wrap ? wrap.scrollWidth > wrap.clientWidth : null,
            stripWidth: el ? Math.round(el.getBoundingClientRect().width) : null,
        };
    });
    console.log('3 clients @375: bodyOverflow=' + strip.bodyOverflow + ' wrapper="' + strip.wrapClass + '" scrolls=' + strip.wrapScrolls + ' strip=' + strip.stripWidth + 'px');

    await browser.close();
})();
