/**
 * Workflow result → Resend email + Telegram
 * Used by agent-from-issue and daily-monitor workflows.
 */
import "dotenv/config";
import { Resend } from "resend";

const status = process.env.WORKFLOW_STATUS ?? "unknown";
const workflowName =
  process.env.WORKFLOW_NAME ?? "Iris ID GitHub Actions";
const agentSummary = process.env.AGENT_SUMMARY ?? "—";
const testSummary = process.env.TEST_SUMMARY ?? "—";
const gitSummary = process.env.GIT_SUMMARY ?? "—";
const issueUrl = process.env.ISSUE_URL ?? "";

const icon =
  status === "success" ? "✅" : status === "failure" ? "❌" : "⚠️";

const subject = `${icon} ${workflowName} — ${status}`;
const text = [
  `${icon} *${workflowName}*`,
  `Status: *${status}*`,
  "",
  issueUrl ? `Issue: ${issueUrl}` : "",
  "*Agent:*",
  agentSummary,
  "",
  "*Tests:*",
  testSummary,
  "",
  "*Git:*",
  gitSummary,
]
  .filter(Boolean)
  .join("\n");

const html = `<pre style="font-family:system-ui;white-space:pre-wrap">${escapeHtml(text)}</pre>`;

async function sendTelegram(): Promise<void> {
  const token = process.env.TELEGRAM_BOT_TOKEN;
  const chatId = process.env.TELEGRAM_CHAT_ID;
  if (!token || !chatId) {
    console.log("Telegram skipped (not configured)");
    return;
  }
  const res = await fetch(
    `https://api.telegram.org/bot${token}/sendMessage`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        chat_id: chatId,
        text,
        parse_mode: "Markdown",
      }),
    },
  );
  const json = (await res.json()) as { ok: boolean; description?: string };
  if (!json.ok) throw new Error(json.description ?? "Telegram failed");
  console.log("Telegram sent");
}

async function sendEmail(): Promise<void> {
  const apiKey = process.env.RESEND_API_KEY;
  const from = process.env.REPORT_EMAIL_FROM;
  const to = process.env.REPORT_EMAIL_TO;
  if (!apiKey || !from || !to) {
    console.log("Email skipped (Resend not configured)");
    return;
  }
  const resend = new Resend(apiKey);
  const date = new Date().toISOString().slice(0, 10);
  const { error } = await resend.emails.send(
    {
      from,
      to: to.split(",").map((e) => e.trim()),
      subject,
      html,
      text,
    },
    { idempotencyKey: `workflow/${workflowName}/${date}/${status}` },
  );
  if (error) throw new Error(error.message);
  console.log("Email sent");
}

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;");
}

async function main(): Promise<void> {
  await Promise.all([sendTelegram(), sendEmail()]);
}

main().catch((err) => {
  console.error(err);
  process.exit(1);
});
