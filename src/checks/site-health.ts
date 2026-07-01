import type { Browser } from "playwright";
import type { Finding, PageCheckResult } from "../types.js";
import { env, resolveSiteUrl } from "../config.js";

export async function checkSiteHealth(
  browser: Browser,
  paths: string[],
): Promise<{ pages: PageCheckResult[]; findings: Finding[] }> {
  const pages: PageCheckResult[] = [];
  const findings: Finding[] = [];
  const context = await browser.newContext({
    userAgent:
      "IrisID-Monitor/1.0 (+https://github.com/hoyong-irisid/monitoring)",
    locale: "en-US",
  });

  for (const path of paths) {
    const url = resolveSiteUrl(path);
    const page = await context.newPage();
    const started = Date.now();

    try {
      const response = await page.goto(url, {
        waitUntil: "domcontentloaded",
        timeout: 45_000,
      });
      const status = response?.status() ?? 0;
      const title = await page.title();
      const loadTimeMs = Date.now() - started;
      const ok = status >= 200 && status < 400;

      pages.push({ url, status, ok, loadTimeMs, title });

      if (!ok) {
        findings.push({
          id: `health-${hash(url)}`,
          severity: status >= 500 ? "critical" : "warning",
          category: "health",
          title: `Page returned HTTP ${status}`,
          detail: `${url} — "${title}"`,
          url,
          metadata: { status, loadTimeMs },
        });
      } else if (loadTimeMs > 8_000) {
        findings.push({
          id: `health-slow-${hash(url)}`,
          severity: "warning",
          category: "health",
          title: "Page load slow",
          detail: `${url} loaded in ${(loadTimeMs / 1000).toFixed(1)}s`,
          url,
          metadata: { loadTimeMs },
        });
      }

      const bodyText = await page.locator("body").innerText().catch(() => "");
      if (
        /fatal error|database connection|critical error/i.test(bodyText) &&
        /wordpress/i.test(bodyText)
      ) {
        findings.push({
          id: `health-wp-error-${hash(url)}`,
          severity: "critical",
          category: "health",
          title: "WordPress error visible on page",
          detail: url,
          url,
        });
      }
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err);
      pages.push({
        url,
        status: 0,
        ok: false,
        loadTimeMs: Date.now() - started,
        error: message,
      });
      findings.push({
        id: `health-fail-${hash(url)}`,
        severity: "critical",
        category: "health",
        title: "Page failed to load",
        detail: `${url}: ${message}`,
        url,
      });
    } finally {
      await page.close();
    }
  }

  await context.close();
  return { pages, findings };
}

function hash(s: string): string {
  let h = 0;
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0;
  return Math.abs(h).toString(36);
}

export function getPriorityPaths(
  configured: string[],
): string[] {
  const unique = new Set<string>();
  for (const p of configured) unique.add(p.startsWith("/") ? p : `/${p}`);
  unique.add("/");
  return [...unique];
}

export { env };
