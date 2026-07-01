import type { MonitorReport } from "./types.js";

export function buildReportHtml(report: MonitorReport): string {
  const { summary, findings, pages, brokenLinks, forms } = report;
  const statusEmoji =
    summary.critical > 0 ? "🔴" : summary.warning > 0 ? "🟡" : "🟢";

  const findingRows = findings
    .slice(0, 50)
    .map(
      (f) =>
        `<tr><td>${f.severity}</td><td>${f.category}</td><td>${escapeHtml(f.title)}</td><td>${f.url ? `<a href="${escapeHtml(f.url)}">${escapeHtml(f.url)}</a>` : "—"}</td></tr>`,
    )
    .join("");

  const extraFindings =
    findings.length > 50
      ? `<p><em>+ ${findings.length - 50} more in GitHub Issues</em></p>`
      : "";

  return `<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>${escapeHtml(report.siteUrl)} — Daily Monitor</title>
<style>
  body { font-family: Inter, system-ui, sans-serif; max-width: 720px; margin: 2rem auto; color: #111; line-height: 1.5; }
  h1 { font-size: 1.25rem; }
  table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
  th, td { border: 1px solid #e5e5e5; padding: 0.5rem; text-align: left; }
  th { background: #f5f5f5; }
  .meta { color: #666; font-size: 0.875rem; }
  .ok { color: #0a7; } .warn { color: #c80; } .bad { color: #c00; }
</style>
</head>
<body>
  <h1>${statusEmoji} ${escapeHtml(report.siteUrl)} — Daily report</h1>
  <p class="meta">${report.startedAt} → ${report.finishedAt}${report.dryRun ? " (dry run)" : ""}</p>
  <ul>
    <li>Pages checked: <strong>${summary.pagesChecked}</strong></li>
    <li>Links checked: <strong>${summary.linksChecked}</strong></li>
    <li>Broken links: <strong class="${brokenLinks.length ? "bad" : "ok"}">${brokenLinks.length}</strong></li>
    <li>Forms: <strong class="${forms.every((f) => f.ok) ? "ok" : "bad"}">${forms.filter((f) => f.ok).length}/${forms.length} OK</strong></li>
    <li>Findings: <strong>${summary.findings}</strong> (${summary.critical} critical, ${summary.warning} warning)</li>
  </ul>
  ${
    findings.length
      ? `<h2>Findings</h2><table><thead><tr><th>Severity</th><th>Type</th><th>Issue</th><th>URL</th></tr></thead><tbody>${findingRows}</tbody></table>${extraFindings}`
      : "<p class=\"ok\">No issues detected.</p>"
  }
  <h2>Page health</h2>
  <table><thead><tr><th>URL</th><th>Status</th><th>Load</th></tr></thead><tbody>
  ${pages
    .map(
      (p) =>
        `<tr><td><a href="${escapeHtml(p.url)}">${escapeHtml(p.url)}</a></td><td class="${p.ok ? "ok" : "bad"}">${p.status || "—"}</td><td>${(p.loadTimeMs / 1000).toFixed(1)}s</td></tr>`,
    )
    .join("")}
  </tbody></table>
</body>
</html>`;
}

export function buildReportTelegram(report: MonitorReport): string {
  const { summary } = report;
  const icon =
    summary.critical > 0 ? "🔴" : summary.warning > 0 ? "🟡" : "🟢";
  const lines = [
    `${icon} *${report.siteUrl}* — daily monitor`,
    "",
    `Pages: ${summary.pagesChecked} | Links: ${summary.linksChecked}`,
    `Broken: ${report.brokenLinks.length} | Forms OK: ${report.forms.filter((f) => f.ok).length}/${report.forms.length}`,
    `Issues: ${summary.findings} (${summary.critical} critical, ${summary.warning} warn)`,
  ];

  const top = report.findings.filter((f) => f.severity !== "info").slice(0, 8);
  if (top.length) {
    lines.push("", "*Top issues:*");
    for (const f of top) {
      lines.push(`• [${f.severity}] ${f.title}`);
      if (f.url) lines.push(`  ${f.url}`);
    }
  } else {
    lines.push("", "All checks passed.");
  }

  if (report.dryRun) lines.push("", "_Dry run — no issues filed._");
  return lines.join("\n");
}

export function buildReportPlain(report: MonitorReport): string {
  return buildReportTelegram(report).replace(/\*/g, "");
}

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}
