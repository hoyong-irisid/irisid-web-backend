import type { MonitorReport } from "../types.js";
import { env } from "../config.js";
import { buildReportTelegram } from "../report.js";

export async function sendTelegramReport(
  report: MonitorReport,
): Promise<{ sent: boolean; error?: string }> {
  if (env.DRY_RUN) return { sent: false, error: "dry run" };
  if (!env.TELEGRAM_BOT_TOKEN || !env.TELEGRAM_CHAT_ID) {
    return { sent: false, error: "Telegram not configured" };
  }

  const text = buildReportTelegram(report);
  const url = `https://api.telegram.org/bot${env.TELEGRAM_BOT_TOKEN}/sendMessage`;

  const res = await fetch(url, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      chat_id: env.TELEGRAM_CHAT_ID,
      text,
      parse_mode: "Markdown",
      disable_web_page_preview: true,
    }),
  });

  const json = (await res.json()) as { ok: boolean; description?: string };
  if (!json.ok) {
    return { sent: false, error: json.description ?? res.statusText };
  }
  return { sent: true };
}
