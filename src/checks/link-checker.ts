import type { Browser } from "playwright";
import type { Finding, LinkCheckResult } from "../types.js";
import { env, monitorConfig, resolveSiteUrl, siteOrigin } from "../config.js";

const CONCURRENT_CHECKS = 8;

export async function crawlAndCheckLinks(
  browser: Browser,
  seedPaths: string[],
): Promise<{ links: LinkCheckResult[]; findings: Finding[]; pagesVisited: number }> {
  const origin = siteOrigin();
  const skipPatterns = monitorConfig.skipUrlPatterns;
  const toVisit: { url: string; depth: number }[] = seedPaths.map((p) => ({
    url: resolveSiteUrl(p),
    depth: 0,
  }));
  const visited = new Set<string>();
  const hrefsToCheck = new Map<string, string>();
  const findings: Finding[] = [];

  const context = await browser.newContext({
    userAgent:
      "IrisID-Monitor/1.0 (+https://github.com/hoyong-irisid/monitoring)",
  });
  const page = await context.newPage();

  while (toVisit.length > 0 && visited.size < env.MAX_CRAWL_PAGES) {
    const next = toVisit.shift()!;
    const normalized = normalizeUrl(next.url);
    if (visited.has(normalized)) continue;
    if (shouldSkip(normalized, skipPatterns)) continue;

    visited.add(normalized);

    try {
      const response = await page.goto(normalized, {
        waitUntil: "domcontentloaded",
        timeout: 45_000,
      });
      if (!response || response.status() >= 400) {
        findings.push({
          id: `link-page-${hash(normalized)}`,
          severity: response && response.status() >= 500 ? "critical" : "warning",
          category: "link",
          title: `Seed/crawled page HTTP ${response?.status() ?? "error"}`,
          detail: normalized,
          url: normalized,
        });
        continue;
      }

      const anchors = await page.$$eval("a[href]", (els) =>
        els.map((a) => ({
          href: (a as HTMLAnchorElement).href,
          text: (a.textContent ?? "").trim().slice(0, 80),
        })),
      );

      for (const { href } of anchors) {
        if (!href || shouldSkip(href, skipPatterns)) continue;

        const resolved = resolveMaybeRelative(href, origin);
        if (!resolved) continue;

        if (resolved.startsWith(origin)) {
          const child = normalizeUrl(resolved);
          if (
            !visited.has(child) &&
            next.depth < env.CRAWL_DEPTH &&
            visited.size + toVisit.length < env.MAX_CRAWL_PAGES
          ) {
            toVisit.push({ url: child, depth: next.depth + 1 });
          }
        }

        if (!hrefsToCheck.has(resolved)) {
          hrefsToCheck.set(resolved, normalized);
        }
      }
    } catch (err) {
      const message = err instanceof Error ? err.message : String(err);
      findings.push({
        id: `link-crawl-${hash(normalized)}`,
        severity: "warning",
        category: "link",
        title: "Could not crawl page for links",
        detail: `${normalized}: ${message}`,
        url: normalized,
      });
    }
  }

  await page.close();

  const linkEntries = [...hrefsToCheck.entries()];
  const results: LinkCheckResult[] = [];

  for (let i = 0; i < linkEntries.length; i += CONCURRENT_CHECKS) {
    const batch = linkEntries.slice(i, i + CONCURRENT_CHECKS);
    const batchResults = await Promise.all(
      batch.map(([href, sourceUrl]) =>
        checkSingleLink(context, href, sourceUrl),
      ),
    );
    results.push(...batchResults);
  }

  await context.close();

  for (const link of results) {
    if (!link.ok) {
      findings.push({
        id: `broken-link-${hash(link.href)}`,
        severity: link.status && link.status >= 500 ? "critical" : "warning",
        category: "link",
        title: `Broken link (${link.status ?? "error"})`,
        detail: `${link.href} — found on ${link.sourceUrl}${link.error ? ` — ${link.error}` : ""}`,
        url: link.href,
        metadata: { sourceUrl: link.sourceUrl, status: link.status ?? 0 },
      });
    }
  }

  return {
    links: results.filter((l) => !l.ok),
    findings,
    pagesVisited: visited.size,
  };
}

async function checkSingleLink(
  context: Awaited<ReturnType<Browser["newContext"]>>,
  href: string,
  sourceUrl: string,
): Promise<LinkCheckResult> {
  const page = await context.newPage();
  try {
    const response = await page.request.get(href, {
      timeout: 25_000,
      maxRedirects: 5,
    });
    const status = response.status();
    const ok = status >= 200 && status < 400;
    return { sourceUrl, href, status, ok };
  } catch (err) {
    const message = err instanceof Error ? err.message : String(err);
    return {
      sourceUrl,
      href,
      status: null,
      ok: false,
      error: message,
    };
  } finally {
    await page.close();
  }
}

function shouldSkip(url: string, patterns: string[]): boolean {
  const lower = url.toLowerCase();
  return patterns.some((p) => lower.includes(p.toLowerCase()));
}

function normalizeUrl(url: string): string {
  try {
    const u = new URL(url);
    u.hash = "";
    if (u.pathname !== "/" && u.pathname.endsWith("/")) {
      u.pathname = u.pathname.slice(0, -1);
    }
    return u.toString();
  } catch {
    return url;
  }
}

function resolveMaybeRelative(href: string, origin: string): string | null {
  try {
    if (href.startsWith("//")) return `https:${href}`;
    if (href.startsWith("http")) return href;
    if (href.startsWith("/")) return `${origin}${href}`;
    return new URL(href, origin).toString();
  } catch {
    return null;
  }
}

function hash(s: string): string {
  let h = 0;
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0;
  return Math.abs(h).toString(36);
}
