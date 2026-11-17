import { describe, it, expect, beforeAll, afterAll } from "vitest";
import { spawn } from "child_process";
import { readFileSync } from "fs";
import { join } from "path";
import { createInterface } from "readline";

// Runs the committed bundle, the file consumers start, over stdio.
const distPath = join(import.meta.dirname, "..", "dist", "index.js");
const packageRoot = join(import.meta.dirname, "..", "..");

type ToolResult = { content: { type: string; text: string }[]; isError?: boolean };

function createJsonRpcClient(proc: ReturnType<typeof spawn>) {
  const rl = createInterface({ input: proc.stdout! });
  const pending = new Map<number, (v: unknown) => void>();
  let nextId = 1;

  rl.on("line", (line) => {
    try {
      const msg = JSON.parse(line);
      if (msg.id !== undefined && pending.has(msg.id)) {
        pending.get(msg.id)!(msg);
        pending.delete(msg.id);
      }
    } catch { /* ignore non-JSON lines */ }
  });

  return {
    request(method: string, params: Record<string, unknown> = {}): Promise<any> {
      const id = nextId++;
      return new Promise((resolve, reject) => {
        pending.set(id, resolve);
        proc.stdin!.write(JSON.stringify({ jsonrpc: "2.0", id, method, params }) + "\n");
        setTimeout(() => {
          if (pending.delete(id)) reject(new Error(`Timeout waiting for response to ${method}`));
        }, 5000);
      });
    },
    notify(method: string, params: Record<string, unknown> = {}) {
      proc.stdin!.write(JSON.stringify({ jsonrpc: "2.0", method, params }) + "\n");
    },
  };
}

// H2/H3 headings of the README, outside code fences, in order. Independent of the server's parser.
function readmeHeadings(): string[] {
  let inFence = false;
  return readFileSync(join(packageRoot, "README.md"), "utf8")
    .split("\n")
    .filter((line) => {
      if (/^\s*(```|~~~)/.test(line)) inFence = !inFence;
      return !inFence && /^#{2,3} /.test(line);
    });
}

describe("MCP server (thin reader over README, CHANGELOG and config)", () => {
  let proc: ReturnType<typeof spawn>;
  let client: ReturnType<typeof createJsonRpcClient>;
  const call = async (name: string, args: Record<string, unknown> = {}): Promise<ToolResult> =>
    (await client.request("tools/call", { name, arguments: args })).result;

  beforeAll(async () => {
    proc = spawn("node", [distPath], { stdio: ["pipe", "pipe", "pipe"] });
    proc.stderr!.resume();
    client = createJsonRpcClient(proc);
    const init = await client.request("initialize", {
      protocolVersion: "2025-03-26",
      capabilities: {},
      clientInfo: { name: "test", version: "1.0.0" },
    });
    expect(init.result.serverInfo.name).toBe("efactura");
    client.notify("notifications/initialized");
  });

  afterAll(() => proc?.kill());

  it("keeps the five tool names", async () => {
    const tools = (await client.request("tools/list")).result.tools.map((t: { name: string }) => t.name);
    expect(tools.sort()).toEqual([
      "get-event-reference",
      "get-job-reference",
      "get-model-integration-guide",
      "get-wrapper-config-reference",
      "get-wrapper-docs",
    ]);
  });

  it("serves every README H2/H3 heading under its own topic", async () => {
    const unknown = await call("get-wrapper-docs", { topic: "no-such-topic" });
    expect(unknown.isError).toBe(true);
    const topics = unknown.content[0].text.split("Valid topics: ")[1].split(", ");
    expect(topics[0]).toBe("changelog");

    const headings = readmeHeadings();
    const slugs = topics.slice(1);
    expect(slugs).toHaveLength(headings.length);
    expect(new Set(slugs).size).toBe(slugs.length);

    for (const [i, slug] of slugs.entries()) {
      const result = await call("get-wrapper-docs", { topic: slug });
      expect(result.isError, slug).toBeFalsy();
      expect(result.content[0].text.split("\n")[0], slug).toBe(headings[i]);
    }
  });

  it("serves CHANGELOG.md as the changelog topic", async () => {
    const result = await call("get-wrapper-docs", { topic: "changelog" });
    expect(result.isError).toBeFalsy();
    expect(result.content[0].text).toBe(readFileSync(join(packageRoot, "CHANGELOG.md"), "utf8"));
  });

  it.each([
    ["get-model-integration-guide", "## Model Integration"],
    ["get-event-reference", "## Events"],
    ["get-job-reference", "## Job Scheduling (Required)"],
  ])("%s answers with its README section", async (tool, heading) => {
    const result = await call(tool);
    expect(result.isError).toBeFalsy();
    expect(result.content[0].text.split("\n")[0]).toBe(heading);
  });

  it("get-wrapper-config-reference answers with config/efactura.php", async () => {
    const result = await call("get-wrapper-config-reference");
    expect(result.isError).toBeFalsy();
    expect(result.content[0].text).toContain("// config/efactura.php\n");
    expect(result.content[0].text).toContain(readFileSync(join(packageRoot, "config", "efactura.php"), "utf8"));
  });
});
