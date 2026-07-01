import { Resend } from "resend";
import type { MonitorReport } from "../types.js";
import { env } from "../config.js";
import { buildReportHtml, buildReportPlain } from "../report.js";

export async function sendDailyEmail(
  report: MonitorReport,
): Promise<{ sent: boolean; error?: string }> {
  if (env.DRY_RUN) return { sent: false, error: "dry run" };
  if (!env.RESEND_API_KEY || !env.REPORT_EMAIL_FROM || !env.REPORT_EMAIL_TO) {
    return { sent: false, error: "Resend not configured" };
  }

  const resend = new Resend(env.RESEND_API_KEY);
  const date = report.finishedAt.slice(0, 10);
  const status =
    report.summary.critical > 0
      ? "CRITICAL"
      : report.summary.warning > 0
        ? "warnings"
        : "OK";

  const { data, error } = await resend.emails.send(
    {
      from: env.REPORT_EMAIL_FROM,
      to: env.REPORT_EMAIL_TO.split(",").map((e) => e.trim()),
      subject: `[${env.SITE_NAME}] Daily monitor — ${status} (${date})`,
      html: buildReportHtml(report),
      text: buildReportPlain(report),
    },
    { idempotencyKey: `irisid-monitor/daily/${date}` },
  );

  if (error) return { sent: false, error: error.message };
  return { sent: true };
}
