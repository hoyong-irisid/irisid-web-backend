import "dotenv/config";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { z } from "zod";
import type { ContentFix } from "./types.js";

const __dirname = dirname(fileURLToPath(import.meta.url));

const envSchema = z.object({
  SITE_URL: z.string().url().default("https://irisid.com"),
  SITE_NAME: z.string().default("Iris ID"),
  MAX_CRAWL_PAGES: z.coerce.number().int().positive().default(80),
  CRAWL_DEPTH: z.coerce.number().int().min(0).max(5).default(2),
  HEADLESS: z
    .string()
    .optional()
    .transform((v) => v !== "false"),
  GITHUB_TOKEN: z.string().optional(),
  GITHUB_OWNER: z.string().default("hoyong-irisid"),
  GITHUB_REPO: z.string().default("monitoring"),
  GITHUB_ISSUES_ENABLED: z
    .string()
    .optional()
    .transform((v) => v !== "false"),
  RESEND_API_KEY: z.string().optional(),
  REPORT_EMAIL_FROM: z.string().optional(),
  REPORT_EMAIL_TO: z.string().optional(),
  TELEGRAM_BOT_TOKEN: z.string().optional(),
  TELEGRAM_CHAT_ID: z.string().optional(),
  FORM_SUBMIT_ENABLED: z
    .string()
    .optional()
    .transform((v) => v === "true"),
  FORM_TEST_NAME: z.string().default("Monitoring Bot"),
  FORM_TEST_EMAIL: z.string().email().default("monitoring+irisid@example.com"),
  FORM_TEST_MESSAGE: z
    .string()
    .default("Automated monitoring test — please ignore."),
  WEBSITE_REPO_OWNER: z.string().optional(),
  WEBSITE_REPO_NAME: z.string().optional(),
  CONTENT_FIXES_ENABLED: z
    .string()
    .optional()
    .transform((v) => v === "true"),
  DRY_RUN: z
    .string()
    .optional()
    .transform((v) => v === "true"),
});

const parsed = envSchema.safeParse(process.env);
if (!parsed.success) {
  console.error("Invalid environment:", parsed.error.flatten().fieldErrors);
  process.exit(1);
}

export const env = parsed.data;

export function loadJsonConfig<T>(filename: string): T {
  const path = join(__dirname, "..", "config", filename);
  return JSON.parse(readFileSync(path, "utf8")) as T;
}

export interface MonitorConfigFile {
  priorityPages: string[];
  seedPaths: string[];
  forms: {
    id: string;
    pageUrl: string;
    /** Playwright selector for the <form> element */
    formSelector: string;
    fields: { selector: string; value: string }[];
    successSelector?: string;
    successTextIncludes?: string[];
  }[];
  skipUrlPatterns: string[];
  contentFixes: ContentFix[];
}

export const monitorConfig = loadJsonConfig<MonitorConfigFile>("monitor.json");

export function siteOrigin(): string {
  return new URL(env.SITE_URL).origin;
}

export function resolveSiteUrl(pathOrUrl: string): string {
  if (pathOrUrl.startsWith("http")) return pathOrUrl;
  const base = env.SITE_URL.replace(/\/$/, "");
  const path = pathOrUrl.startsWith("/") ? pathOrUrl : `/${pathOrUrl}`;
  return `${base}${path}`;
}
