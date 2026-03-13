import { describe, it, expect } from "vitest";
import { spawn } from "child_process";
import { join } from "path";
import { createInterface } from "readline";

function createJsonRpcClient(proc: ReturnType<typeof spawn>) {
  const rl = createInterface({ input: proc.stdout! });
  const pending = new Map<number, { resolve: (v: unknown) => void; reject: (e: Error) => void }>();

  rl.on("line", (line) => {
    try {
      const msg = JSON.parse(line);
      if (msg.id !== undefined && pending.has(msg.id)) {
        pending.get(msg.id)!.resolve(msg);
        pending.delete(msg.id);
      }
    } catch { /* ignore non-JSON lines */ }
  });

  return {
    request(method: string, params: Record<string, unknown> = {}, id: number = 1): Promise<unknown> {
      return new Promise((resolve, reject) => {
        pending.set(id, { resolve, reject });
        const msg = JSON.stringify({ jsonrpc: "2.0", id, method, params });
        proc.stdin!.write(msg + "\n");
        setTimeout(() => {
          if (pending.has(id)) {
            pending.delete(id);
            reject(new Error(`Timeout waiting for response to ${method}`));
          }
        }, 5000);
      });
    },
    notify(method: string, params: Record<string, unknown> = {}) {
      proc.stdin!.write(JSON.stringify({ jsonrpc: "2.0", method, params }) + "\n");
    },
  };
}

describe("MCP Server Integration", () => {
  it("responds to initialize and lists all 5 tools", async () => {
    const distPath = join(import.meta.dirname, "..", "dist", "index.js");
    const proc = spawn("node", [distPath], { stdio: ["pipe", "pipe", "pipe"] });
    proc.stderr!.resume();

    const client = createJsonRpcClient(proc);

    try {
      const initResult = await client.request("initialize", {
        protocolVersion: "2025-03-26",
        capabilities: {},
        clientInfo: { name: "test", version: "1.0.0" },
      }) as { result: { serverInfo: { name: string } } };
      expect(initResult.result.serverInfo.name).toBe("efactura");

      client.notify("notifications/initialized");

      const toolsResult = await client.request("tools/list", {}, 2) as {
        result: { tools: { name: string }[] }
      };
      const toolNames = toolsResult.result.tools.map((t) => t.name);

      expect(toolNames).toContain("get-wrapper-docs");
      expect(toolNames).toContain("get-model-integration-guide");
      expect(toolNames).toContain("get-event-reference");
      expect(toolNames).toContain("get-job-reference");
      expect(toolNames).toContain("get-wrapper-config-reference");
      expect(toolNames).toHaveLength(5);
    } finally {
      proc.kill();
    }
  });
});
