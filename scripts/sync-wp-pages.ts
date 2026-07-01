/**
 * Fetches published pages from WordPress REST API and updates config/wp-pages.json.
 * Run: npm run sync-pages
 */
import { writeFileSync } from "node:fs";
import { join } from "node:path";
import { env } from "../src/config.js";

interface WpPage {
  link: string;
  slug: string;
}

async function fetchAllPages(): Promise<WpPage[]> {
  const base = env.SITE_URL.replace(/\/$/, "");
  const pages: WpPage[] = [];
  let page = 1;

  while (true) {
    const url = `${base}/wp-json/wp/v2/pages?per_page=100&page=${page}&status=publish`;
    const res = await fetch(url, {
      headers: { "User-Agent": "IrisID-Monitor/1.0" },
    });
    if (!res.ok) throw new Error(`WP API ${res.status}: ${url}`);
    const batch = (await res.json()) as { link: string; slug: string }[];
    if (batch.length === 0) break;
    pages.push(...batch.map((p) => ({ link: p.link, slug: p.slug })));
    const totalPages = Number(res.headers.get("x-wp-totalpages") ?? 1);
    if (page >= totalPages) break;
    page++;
  }

  return pages;
}

async function main(): Promise<void> {
  const pages = await fetchAllPages();
  const paths = pages.map((p) => {
    try {
      return new URL(p.link).pathname;
    } catch {
      return p.link;
    }
  });

  const out = join(process.cwd(), "config", "wp-pages.json");
  writeFileSync(
    out,
    JSON.stringify(
      {
        syncedAt: new Date().toISOString(),
        count: paths.length,
        paths: [...new Set(paths)].sort(),
      },
      null,
      2,
    ),
  );
  console.log(`Wrote ${paths.length} paths to ${out}`);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
