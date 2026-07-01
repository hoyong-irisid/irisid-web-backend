import { mkdirSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { chromium } from "playwright";
import { runContentFixChecks } from "./checks/content-fixes.js";
import { checkForms } from "./checks/form-checker.js";
import { crawlAndCheckLinks } from "./checks/link-checker.js";
import {
  checkSiteHealth,
  getPriorityPaths,
} from "./checks/site-health.js";
import { env, monitorConfig } from "./config.js";
import { buildReportPlain } from "./report.js";
import { syncFindingsToGitHub } from "./reporters/github-issues.js";
import { sendDailyEmail } from "./reporters/resend-email.js";
import { sendTelegramReport } from "./reporters/telegram.js";
import type { Finding, MonitorReport } from "./types.js";

async function main(): Promise<void> {
  const startedAt = new Date().toISOString();
  console.log(`Iris ID monitor — ${env.SITE_URL}${env.DRY_RUN ? " (DRY RUN)" : ""}`);

  const browser = await chromium.launch({ headless: env.HEADLESS });
  const allFindings: Finding[] = [];

  const priorityPaths = getPriorityPaths(monitorConfig.priorityPages);
  console.log(`Health check: ${priorityPaths.length} priority pages`);
  const health = await checkSiteHealth(browser, priorityPaths);
  allFindings.push(...health.findings);

  console.log(`Link crawl from ${monitorConfig.seedPaths.length} seeds (depth ${env.CRAWL_DEPTH}, max ${env.MAX_CRAWL_PAGES})`);
  const links = await crawlAndCheckLinks(browser, monitorConfig.seedPaths);
  allFindings.push(...links.findings);

  console.log(`Form check: ${monitorConfig.forms.length} form(s)`);
  const forms = await checkForms(browser);
  allFindings.push(...forms.findings);

  console.log("Content fix scan");
  const content = await runContentFixChecks(browser);
  allFindings.push(...content.findings);

  await browser.close();

  const finishedAt = new Date().toISOString();
  const critical = allFindings.filter((f) => f.severity === "critical").length;
  const warning = allFindings.filter((f) => f.severity === "warning").length;

  const report: MonitorReport = {
    startedAt,
    finishedAt,
    siteUrl: env.SITE_URL,
    dryRun: env.DRY_RUN,
    summary: {
      pagesChecked: health.pages.length,
      linksChecked: links.pagesVisited,
      formsChecked: forms.forms.length,
      findings: allFindings.length,
      critical,
      warning,
    },
    pages: health.pages,
    brokenLinks: links.links,
    forms: forms.forms,
    findings: allFindings,
    contentFixResults: content.results,
  };

  const reportsDir = join(process.cwd(), "reports");
  mkdirSync(reportsDir, { recursive: true });
  const reportPath = join(
    reportsDir,
    `report-${finishedAt.slice(0, 10)}.json`,
  );
  writeFileSync(reportPath, JSON.stringify(report, null, 2));
  console.log(`Report saved: ${reportPath}`);
  console.log(buildReportPlain(report));

  const gh = await syncFindingsToGitHub(allFindings, report);
  console.log(`GitHub issues: ${gh.created} created, ${gh.skipped} skipped`);

  const email = await sendDailyEmail(report);
  console.log(
    email.sent ? `Email sent (${email.error})` : `Email skipped: ${email.error}`,
  );

  const telegram = await sendTelegramReport(report);
  console.log(
    telegram.sent
      ? "Telegram sent"
      : `Telegram skipped: ${telegram.error}`,
  );

  if (critical > 0 && !env.DRY_RUN) {
    process.exitCode = 1;
  }
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
