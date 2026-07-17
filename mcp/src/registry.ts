// Single source of truth for every tool's valid argument values.
//
// This list was previously duplicated between src/index.ts and
// tests/tools.test.ts. That is exactly how the `migration` topic shipped
// untested: the suite iterated its own stale copy of the list, so a topic added
// to the server was never exercised and the suite still went green.
//
// Import from here. Never re-declare the list in a test — tests/tools.test.ts
// asserts it stays in lockstep with wrapperDocsContent in both directions.

export const VALID_TOPICS = [
  "overview", "setup", "upload-pipeline", "token-management", "commands", "migration",
] as const;
