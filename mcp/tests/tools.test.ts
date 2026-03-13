import { describe, it, expect } from "vitest";
import { wrapperDocsContent } from "../src/content/wrapper-docs.js";
import { modelIntegrationContent } from "../src/content/model-integration.js";
import { eventReferenceContent } from "../src/content/event-reference.js";
import { jobReferenceContent } from "../src/content/job-reference.js";
import { wrapperConfigContent } from "../src/content/wrapper-config.js";

const VALID_TOPICS = [
  "overview", "setup", "upload-pipeline", "token-management", "commands",
];

describe("get-wrapper-docs", () => {
  it.each(VALID_TOPICS)("returns content for topic '%s'", (topic) => {
    expect(wrapperDocsContent[topic]).toBeTruthy();
    expect(typeof wrapperDocsContent[topic]).toBe("string");
    expect(wrapperDocsContent[topic].length).toBeGreaterThan(50);
  });

  it("overview covers key services", () => {
    const content = wrapperDocsContent["overview"];
    expect(content).toContain("TokenService");
    expect(content).toContain("UploadService");
    expect(content).toContain("DownloadService");
    expect(content).toContain("MessageSyncService");
    expect(content).toContain("EFactura");
  });

  it("setup covers installation steps", () => {
    expect(wrapperDocsContent["setup"]).toContain("composer require");
    expect(wrapperDocsContent["setup"]).toContain("vendor:publish");
    expect(wrapperDocsContent["setup"]).toContain("migrate");
  });

  it("upload-pipeline covers status flow", () => {
    expect(wrapperDocsContent["upload-pipeline"]).toContain("Pending");
    expect(wrapperDocsContent["upload-pipeline"]).toContain("Processing");
    expect(wrapperDocsContent["upload-pipeline"]).toContain("Completed");
    expect(wrapperDocsContent["upload-pipeline"]).toContain("RateLimitExceededException");
  });

  it("token-management covers concurrent safety", () => {
    expect(wrapperDocsContent["token-management"]).toContain("executeWithClient");
    expect(wrapperDocsContent["token-management"]).toContain("lock");
  });

  it("commands covers all 4 commands", () => {
    const content = wrapperDocsContent["commands"];
    expect(content).toContain("efactura:auth");
    expect(content).toContain("efactura:upload");
    expect(content).toContain("efactura:status");
    expect(content).toContain("efactura:sync");
  });

  it("returns undefined for unknown topic", () => {
    expect(wrapperDocsContent["fake-topic"]).toBeUndefined();
  });
});

describe("get-model-integration-guide", () => {
  it("returns non-empty content", () => {
    expect(modelIntegrationContent).toBeTruthy();
    expect(typeof modelIntegrationContent).toBe("string");
    expect(modelIntegrationContent.length).toBeGreaterThan(100);
  });

  it("covers interface and trait", () => {
    expect(modelIntegrationContent).toContain("EFacturaUploadableInterface");
    expect(modelIntegrationContent).toContain("HasEfacturaUpload");
    expect(modelIntegrationContent).toContain("toEfacturaData");
    expect(modelIntegrationContent).toContain("getEfacturaCui");
  });

  it("covers queueing uploads", () => {
    expect(modelIntegrationContent).toContain("queueUpload");
  });

  it("covers credit notes", () => {
    expect(modelIntegrationContent).toContain("credit note");
    expect(modelIntegrationContent).toContain("precedingInvoiceNumber");
  });

  it("covers taxAmount requirement", () => {
    expect(modelIntegrationContent).toContain("taxAmount");
  });
});

describe("get-event-reference", () => {
  it("returns non-empty content", () => {
    expect(eventReferenceContent).toBeTruthy();
    expect(typeof eventReferenceContent).toBe("string");
    expect(eventReferenceContent.length).toBeGreaterThan(100);
  });

  it("covers all 6 events", () => {
    expect(eventReferenceContent).toContain("TokenStored");
    expect(eventReferenceContent).toContain("TokenRefreshed");
    expect(eventReferenceContent).toContain("InvoiceUploaded");
    expect(eventReferenceContent).toContain("InvoiceProcessed");
    expect(eventReferenceContent).toContain("InvoiceFailed");
    expect(eventReferenceContent).toContain("InvoiceReceived");
  });
});

describe("get-job-reference", () => {
  it("returns non-empty content", () => {
    expect(jobReferenceContent).toBeTruthy();
    expect(typeof jobReferenceContent).toBe("string");
    expect(jobReferenceContent.length).toBeGreaterThan(100);
  });

  it("covers all 8 jobs", () => {
    expect(jobReferenceContent).toContain("ProcessPendingUploads");
    expect(jobReferenceContent).toContain("ProcessSingleUpload");
    expect(jobReferenceContent).toContain("CheckUploadStatuses");
    expect(jobReferenceContent).toContain("CheckSingleUploadStatus");
    expect(jobReferenceContent).toContain("DownloadResponses");
    expect(jobReferenceContent).toContain("DownloadReceivedInvoices");
    expect(jobReferenceContent).toContain("SyncMessages");
    expect(jobReferenceContent).toContain("RetryRateLimitedUploads");
  });

  it("covers scheduling recommendations", () => {
    expect(jobReferenceContent).toContain("Schedule");
  });
});

describe("get-wrapper-config-reference", () => {
  it("returns non-empty content", () => {
    expect(wrapperConfigContent).toBeTruthy();
    expect(typeof wrapperConfigContent).toBe("string");
    expect(wrapperConfigContent.length).toBeGreaterThan(100);
  });

  it("covers key config sections", () => {
    expect(wrapperConfigContent).toContain("EFACTURA_ENABLED");
    expect(wrapperConfigContent).toContain("features");
    expect(wrapperConfigContent).toContain("queue");
    expect(wrapperConfigContent).toContain("storage");
    expect(wrapperConfigContent).toContain("routes");
    expect(wrapperConfigContent).toContain("rate_limit");
  });
});
