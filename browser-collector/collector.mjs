import { chromium } from 'playwright';

const LOGIN_URL = process.env.SOURCE_LOGIN_URL;
const START_URL = process.env.SOURCE_START_URL;
const USERNAME = process.env.SOURCE_USERNAME;
const PASSWORD = process.env.SOURCE_PASSWORD;
const STORAGE_STATE_JSON = process.env.STORAGE_STATE_JSON || '';
const CATEGORY = process.env.SOURCE_CATEGORY || 'Photography';
const MAX_LEADS = Number(process.env.MAX_LEADS || 50);
const DRY_RUN = process.env.DRY_RUN === '1';

if (!LOGIN_URL || !START_URL) {
  throw new Error('Missing SOURCE_LOGIN_URL or SOURCE_START_URL');
}
if (!STORAGE_STATE_JSON && (!USERNAME || !PASSWORD)) {
  throw new Error('Provide STORAGE_STATE_JSON or SOURCE_USERNAME + SOURCE_PASSWORD');
}

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext(
  STORAGE_STATE_JSON ? { storageState: JSON.parse(STORAGE_STATE_JSON) } : {}
);
const page = await context.newPage();

try {
  if (!STORAGE_STATE_JSON) {
    await page.goto(LOGIN_URL, { waitUntil: 'domcontentloaded', timeout: 60000 });
    await page.locator('input[type="email"], input[name="email"], input[name="username"]').first().fill(USERNAME);
    await page.locator('input[type="password"]').first().fill(PASSWORD);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
    await page.waitForLoadState('domcontentloaded').catch(() => {});
  }

  await page.goto(START_URL, { waitUntil: 'domcontentloaded', timeout: 60000 });

  const links = await page.locator('a[href]').evaluateAll(as =>
    as.map(a => ({ href: a.href, text: (a.textContent || '').trim() }))
      .filter(x => x.href && x.text)
  );

  const profiles = [];
  const seen = new Set();

  for (const item of links) {
    if (seen.has(item.href)) continue;
    const text = item.text.toLowerCase();
    if (
      text.includes('view profile') ||
      text.includes('view details') ||
      text.includes('view contact') ||
      /photograph|makeup|artist|studio/.test(text)
    ) {
      seen.add(item.href);
      profiles.push(item.href);
    }
    if (profiles.length >= MAX_LEADS * 3) break;
  }

  const results = [];

  for (const url of profiles) {
    if (results.length >= MAX_LEADS) break;

    const p = await context.newPage();
    try {
      await p.goto(url, { waitUntil: 'domcontentloaded', timeout: 60000 });

      const contactButton = p.getByRole('button', { name: /view contact|view phone|show phone/i }).first();
      if (await contactButton.count()) {
        await contactButton.click();
        await p.waitForTimeout(1000);
      }

      const body = await p.locator('body').innerText();
      const phoneMatch = body.match(/(?:\+?91[\s-]?)?[6-9]\d{9}/);
      if (!phoneMatch) continue;

      const title = await p.locator('h1').first().textContent().catch(() => null);
      const name = (title || '').trim();

      const instagram = await p.locator('a[href*="instagram.com"]').first().getAttribute('href').catch(() => null);
      const website = await p.locator('a[href^="http"]').evaluateAll(as =>
        as.map(a => a.href).find(h => !h.includes(location.hostname) && !h.includes('instagram.com')) || null
      ).catch(() => null);

      const lead = {
        business_name: name || 'Unknown vendor',
        category: CATEGORY,
        phone: phoneMatch[0],
        instagram: instagram || '',
        website: website || '',
        source: new URL(START_URL).hostname,
        source_url: url
      };

      results.push(lead);
      console.log(JSON.stringify(lead));

    } finally {
      await p.close();
    }
  }

  console.log(JSON.stringify({
    category: CATEGORY,
    collected: results.length,
    dry_run: DRY_RUN,
    leads: results
  }));

} finally {
  await browser.close();
}
