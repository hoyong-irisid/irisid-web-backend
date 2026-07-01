import { Octokit } from "@octokit/rest";
import type { Finding, MonitorReport } from "../types.js";
import { env } from "../config.js";

const LABEL = "monitoring";

export async function syncFindingsToGitHub(
  findings: Finding[],
  report: MonitorReport,
): Promise<{ created: number; skipped: number }> {
  if (env.DRY_RUN || !env.GITHUB_ISSUES_ENABLED || !env.GITHUB_TOKEN) {
    return { created: 0, skipped: findings.length };
  }

  const actionable = findings.filter((f) => f.severity !== "info");
  if (actionable.length === 0) return { created: 0, skipped: 0 };

  const octokit = new Octokit({ auth: env.GITHUB_TOKEN });
  let created = 0;
  let skipped = 0;

  await ensureLabel(octokit);

  const open = await octokit.paginate(octokit.rest.issues.listForRepo, {
    owner: env.GITHUB_OWNER,
    repo: env.GITHUB_REPO,
    state: "open",
    labels: LABEL,
    per_page: 100,
  });

  const openByTitle = new Set(open.map((i) => i.title));

  for (const finding of actionable) {
    const title = `[${finding.category}] ${finding.title}`;
    if (openByTitle.has(title)) {
      skipped++;
      continue;
    }

    const body = [
      finding.detail,
      "",
      finding.url ? `**URL:** ${finding.url}` : "",
      "",
      "### Monitor run",
      "",
      `- Site: ${report.siteUrl}`,
      `- Started: ${report.startedAt}`,
      `- Finished: ${report.finishedAt}`,
      "",
      "<!-- irisid-monitor:auto -->",
    ]
      .filter(Boolean)
      .join("\n");

    await octokit.rest.issues.create({
      owner: env.GITHUB_OWNER,
      repo: env.GITHUB_REPO,
      title,
      body,
      labels: [LABEL],
    });
    created++;
    openByTitle.add(title);
  }

  return { created, skipped };
}

async function ensureLabel(octokit: Octokit): Promise<void> {
  try {
    await octokit.rest.issues.getLabel({
      owner: env.GITHUB_OWNER,
      repo: env.GITHUB_REPO,
      name: LABEL,
    });
  } catch {
    await octokit.rest.issues.createLabel({
      owner: env.GITHUB_OWNER,
      repo: env.GITHUB_REPO,
      name: LABEL,
      color: "1d76db",
      description: "Auto-created by Iris ID website monitor",
    });
  }
}
