import type { Browser } from "playwright";
import type { Finding, FormCheckResult } from "../types.js";
import { env, monitorConfig, resolveSiteUrl } from "../config.js";

export async function checkForms(
  browser: Browser,
): Promise<{ forms: FormCheckResult[]; findings: Finding[] }> {
  const forms: FormCheckResult[] = [];
  const findings: Finding[] = [];
  const context = await browser.newContext({
    userAgent:
      "IrisID-Monitor/1.0 (+https://github.com/hoyong-irisid/monitoring)",
  });

  for (const formConfig of monitorConfig.forms) {
    const pageUrl = resolveSiteUrl(formConfig.pageUrl);
    const page = await context.newPage();
    const notes: string[] = [];
    let ok = false;
    let submitted = false;
    let error: string | undefined;

    try {
      await page.goto(pageUrl, {
        waitUntil: "networkidle",
        timeout: 60_000,
      });

      const form = page.locator(formConfig.formSelector).first();
      const formCount = await form.count();
      if (formCount === 0) {
        throw new Error(`No form matching "${formConfig.formSelector}"`);
      }
      notes.push("Form element found");

      for (const field of formConfig.fields) {
        const value = interpolate(field.value);
        const locator = form.locator(field.selector).first();
        const count = await locator.count();
        if (count === 0) {
          notes.push(`Optional field not found: ${field.selector}`);
          continue;
        }
        const tag = await locator.evaluate((el) => el.tagName.toLowerCase());
        if (tag === "select") {
          await locator.selectOption({ index: 1 }).catch(() => undefined);
        } else {
          await locator.fill(value);
        }
        notes.push(`Filled ${field.selector}`);
      }

      if (!env.FORM_SUBMIT_ENABLED) {
        ok = true;
        notes.push("Dry validation only (FORM_SUBMIT_ENABLED=false)");
        forms.push({
          formId: formConfig.id,
          pageUrl,
          ok,
          submitted,
          notes,
        });
        await page.close();
        continue;
      }

      const submit = form.locator(
        "button[type='submit'], input[type='submit'], button:has-text('Submit'), button:has-text('Send')",
      ).first();
      if ((await submit.count()) === 0) {
        throw new Error("Submit button not found");
      }

      await Promise.all([
        page.waitForLoadState("networkidle", { timeout: 30_000 }).catch(() => undefined),
        submit.click(),
      ]);
      submitted = true;
      notes.push("Form submitted");

      await page.waitForTimeout(2_000);
      const bodyText = (await page.locator("body").innerText()).toLowerCase();
      const successPhrases =
        formConfig.successTextIncludes ?? ["thank", "success", "received"];
      const matched = successPhrases.some((p) =>
        bodyText.includes(p.toLowerCase()),
      );

      if (formConfig.successSelector) {
        const visible = await page
          .locator(formConfig.successSelector)
          .first()
          .isVisible()
          .catch(() => false);
        ok = visible || matched;
      } else {
        ok = matched;
      }

      if (!ok) {
        error = "No success indicator after submit";
      }
    } catch (err) {
      error = err instanceof Error ? err.message : String(err);
      ok = false;
    } finally {
      await page.close();
    }

    forms.push({
      formId: formConfig.id,
      pageUrl,
      ok,
      submitted,
      error,
      notes,
    });

    if (!ok) {
      findings.push({
        id: `form-${formConfig.id}`,
        severity: "critical",
        category: "form",
        title: `Form check failed: ${formConfig.id}`,
        detail: error ?? "Validation failed",
        url: pageUrl,
      });
    }
  }

  await context.close();
  return { forms, findings };
}

function interpolate(template: string): string {
  return template
    .replace(/\{\{FORM_TEST_NAME\}\}/g, env.FORM_TEST_NAME)
    .replace(/\{\{FORM_TEST_EMAIL\}\}/g, env.FORM_TEST_EMAIL)
    .replace(/\{\{FORM_TEST_MESSAGE\}\}/g, env.FORM_TEST_MESSAGE);
}
