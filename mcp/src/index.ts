import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";
import { wrapperDocsContent } from "./content/wrapper-docs.js";
import { modelIntegrationContent } from "./content/model-integration.js";
import { eventReferenceContent } from "./content/event-reference.js";
import { jobReferenceContent } from "./content/job-reference.js";
import { wrapperConfigContent } from "./content/wrapper-config.js";

const server = new McpServer({
  name: "efactura",
  version: "1.0.0",
});

// Tool 1: get-wrapper-docs (parameterized by topic)
const VALID_TOPICS = [
  "overview", "setup", "upload-pipeline", "token-management", "commands",
] as const;

server.tool(
  "get-wrapper-docs",
  "Get documentation about the Laravel e-Factura wrapper package for a specific topic",
  { topic: z.enum(VALID_TOPICS).describe("Documentation topic") },
  async ({ topic }) => {
    const content = wrapperDocsContent[topic];
    if (!content) {
      return {
        isError: true,
        content: [{ type: "text" as const, text: `Unknown topic "${topic}". Valid topics: ${VALID_TOPICS.join(", ")}` }],
      };
    }
    return { content: [{ type: "text" as const, text: content }] };
  }
);

// Tool 2: get-model-integration-guide (no params)
server.tool(
  "get-model-integration-guide",
  "Get the complete guide for integrating a Laravel model with e-Factura",
  {},
  async () => ({
    content: [{ type: "text" as const, text: modelIntegrationContent }],
  })
);

// Tool 3: get-event-reference (no params)
server.tool(
  "get-event-reference",
  "Get all events fired by the Laravel e-Factura wrapper package",
  {},
  async () => ({
    content: [{ type: "text" as const, text: eventReferenceContent }],
  })
);

// Tool 4: get-job-reference (no params)
server.tool(
  "get-job-reference",
  "Get all background jobs and scheduling guidance for Laravel e-Factura",
  {},
  async () => ({
    content: [{ type: "text" as const, text: jobReferenceContent }],
  })
);

// Tool 5: get-wrapper-config-reference (no params)
server.tool(
  "get-wrapper-config-reference",
  "Get the full configuration schema for the Laravel e-Factura wrapper package",
  {},
  async () => ({
    content: [{ type: "text" as const, text: wrapperConfigContent }],
  })
);

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error("efactura MCP server running on stdio");
}

main().catch((error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
