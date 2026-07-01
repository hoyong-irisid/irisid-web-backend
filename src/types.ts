export type Severity = "critical" | "warning" | "info";

export interface Finding {
  id: string;
  severity: Severity;
  category: "health" | "link" | "form" | "content";
  title: string;
  detail: string;
  url?: string;
  metadata?: Record<string, string | number | boolean>;
}

export interface PageCheckResult {
  url: string;
  status: number;
  ok: boolean;
  loadTimeMs: number;
  title?: string;
  error?: string;
}

export interface LinkCheckResult {
  sourceUrl: string;
  href: string;
  status: number | null;
  ok: boolean;
  error?: string;
}

export interface FormCheckResult {
  formId: string;
  pageUrl: string;
  ok: boolean;
  submitted: boolean;
  error?: string;
  notes?: string[];
}

export interface ContentFix {
  id: string;
  pageUrl: string;
  selector: string;
  find: string;
  replace: string;
  description: string;
  enabled: boolean;
}

export interface MonitorReport {
  startedAt: string;
  finishedAt: string;
  siteUrl: string;
  dryRun: boolean;
  summary: {
    pagesChecked: number;
    linksChecked: number;
    formsChecked: number;
    findings: number;
    critical: number;
    warning: number;
  };
  pages: PageCheckResult[];
  brokenLinks: LinkCheckResult[];
  forms: FormCheckResult[];
  findings: Finding[];
  contentFixResults: { id: string; applied: boolean; message: string }[];
}
