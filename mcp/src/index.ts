import { readFileSync, readdirSync } from "node:fs";
import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { z } from "zod";

// A thin reader: the package's own README.md, CHANGELOG.md and config/ are the only content.
// They are read on every call from the installed package (mcp/dist/index.js → ../../).
const packageRoot = new URL("../../", import.meta.url);
const read = (path: string): string => readFileSync(new URL(path, packageRoot), "utf8");

type Section = { slug: string; text: string };

// README H2/H3 sections under GitHub-style slugs. A section runs to the next heading of the
// same or a higher level. Lines inside code fences (`# comment` in bash) are not headings.
function readmeSections(): Section[] {
  const lines = read("README.md").split("\n");
  const headings: { at: number; level: number; slug: string }[] = [];
  const seen = new Map<string, number>();
  let inFence = false;

  lines.forEach((line, at) => {
    if (/^\s*(```|~~~)/.test(line)) {
      inFence = !inFence;
      return;
    }
    const match = inFence ? null : /^(#{1,6})\s+(.*?)\s*#*\s*$/.exec(line);
    if (!match) return;
    const base = match[2].toLowerCase().replace(/[^\p{L}\p{N}_\- ]/gu, "").replace(/ /g, "-");
    const repeats = seen.get(base) ?? 0;
    seen.set(base, repeats + 1);
    headings.push({ at, level: match[1].length, slug: repeats ? `${base}-${repeats}` : base });
  });

  return headings
    .filter((h) => h.level === 2 || h.level === 3)
    .map((h) => {
      const end = headings.find((next) => next.at > h.at && next.level <= h.level)?.at ?? lines.length;
      return { slug: h.slug, text: lines.slice(h.at, end).join("\n").trim() };
    });
}

const text = (body: string, isError = false) => ({
  content: [{ type: "text" as const, text: body }],
  ...(isError ? { isError: true } : {}),
});

function section(slug: string) {
  const sections = readmeSections();
  const found = sections.find((s) => s.slug === slug);
  if (found) return text(found.text);
  return text(`Unknown topic "${slug}". Valid topics: changelog, ${sections.map((s) => s.slug).join(", ")}`, true);
}

const server = new McpServer({ name: "efactura", version: "4.1.0" });

server.registerTool(
  "get-wrapper-docs",
  {
    description:
      "Read a section of the Laravel e-Factura wrapper README by its heading slug (for example 'oauth-flow' or " +
      "'status-check-outcomes'), or 'changelog' for CHANGELOG.md, which lists every 4.0 change and is the upgrade " +
      "guide. An unknown topic returns the list of valid topics.",
    inputSchema: { topic: z.string().describe("README heading slug, or 'changelog'") },
  },
  async ({ topic }) => (topic === "changelog" ? text(read("CHANGELOG.md")) : section(topic)),
);

server.registerTool(
  "get-model-integration-guide",
  { description: "The README's 'Model Integration' section: the interface, the trait and toEfacturaData()." },
  async () => section("model-integration"),
);

server.registerTool(
  "get-event-reference",
  { description: "The README's 'Events' section: every event the wrapper fires and its payload." },
  async () => section("events"),
);

server.registerTool(
  "get-job-reference",
  { description: "The README's 'Job Scheduling (Required)' section: the jobs and the recommended schedule." },
  async () => section("job-scheduling-required"),
);

server.registerTool(
  "get-wrapper-config-reference",
  { description: "The wrapper's config files (config/*.php), with their comments, env vars and defaults." },
  async () =>
    text(
      readdirSync(new URL("config/", packageRoot))
        .filter((file) => file.endsWith(".php"))
        .sort()
        .map((file) => `// config/${file}\n${read(`config/${file}`)}`)
        .join("\n\n"),
    ),
);

async function main() {
  await server.connect(new StdioServerTransport());
  console.error("efactura MCP server running on stdio");
}

main().catch((error) => {
  console.error("Fatal error:", error);
  process.exit(1);
});
