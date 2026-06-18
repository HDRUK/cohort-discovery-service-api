/**
 * tagJiraFixVersion.js
 *
 * Reads the release-notes preview (RELEASE_NOTES_PREVIEW.md), extracts the Jira
 * issue keys from it, and sets a "fix version" (release) on each ticket in Jira.
 *
 * The set of valid issue prefixes (Jira projects) is read from release.config.js
 * (`issuePrefixes`, e.g. ["GAT", "DP"]) so it stays in sync with semantic-release.
 * Jira "versions" are per-project, so the fix version is created/ensured in each
 * project that has matching tickets.
 *
 * SAFETY: this is a dry-run by default — it only prints what it would do. Pass
 * --apply (or APPLY=true) to actually call the Jira API.
 *
 * Usage:
 *   node tagJiraFixVersion.js --version 1.12.0                 # dry-run, all projects
 *   node tagJiraFixVersion.js --version 1.12.0 --projects DP   # limit to DP only
 *   node tagJiraFixVersion.js --version 1.12.0 --apply         # do it
 *   node tagJiraFixVersion.js --version 1.12.0 --notes other.md
 *
 * Env (required only with --apply; plain JIRA_* names also accepted):
 *   RELEASE_JIRA_BASE_URL   e.g. https://hdruk.atlassian.net
 *   RELEASE_JIRA_USER       Jira account email
 *   RELEASE_JIRA_TOKEN      Jira API token
 */

const fs = require("fs");
const path = require("path");

const baseConfig = require("./release.config.js");

// --- args ------------------------------------------------------------------
function getArg(flag) {
  const i = process.argv.indexOf(flag);
  return i !== -1 ? process.argv[i + 1] : undefined;
}
const hasFlag = (flag) => process.argv.includes(flag);

const version = getArg("--version") || process.env.FIX_VERSION;
const notesFile = path.resolve(
  __dirname,
  getArg("--notes") || "RELEASE_NOTES_PREVIEW.md"
);
const apply = hasFlag("--apply") || process.env.APPLY === "true";

// Optional allow-list of projects (prefixes) to tag. Defaults to all configured.
const projectsFilter = (getArg("--projects") || process.env.JIRA_PROJECTS || "")
  .split(",")
  .map((s) => s.trim().toUpperCase())
  .filter(Boolean);

// --- issue prefixes from release.config.js ---------------------------------
function issuePrefixes() {
  try {
    const rng = baseConfig.plugins.find(
      (p) => Array.isArray(p) && p[0] === "@semantic-release/release-notes-generator"
    );
    const prefixes = rng && rng[1] && rng[1].presetConfig && rng[1].presetConfig.issuePrefixes;
    if (Array.isArray(prefixes) && prefixes.length) return prefixes;
  } catch {
    /* fall through */
  }
  return ["DP", "GAT"];
}

// Group unique issue keys by project (the prefix before the dash).
function extractKeysByProject(text, prefixes) {
  const re = new RegExp(`\\b(${prefixes.join("|")})-\\d+\\b`, "g");
  const byProject = {};
  for (const match of text.matchAll(re)) {
    const key = match[0];
    const project = match[1];
    (byProject[project] = byProject[project] || new Set()).add(key);
  }
  return Object.fromEntries(
    Object.entries(byProject).map(([p, set]) => [p, [...set].sort()])
  );
}

// --- Jira REST helpers (only used with --apply) ----------------------------
function jiraClient() {
  const server = (
    process.env.RELEASE_JIRA_BASE_URL ||
    process.env.JIRA_SERVER ||
    ""
  ).replace(/\/+$/, "");
  const user = process.env.RELEASE_JIRA_USER || process.env.JIRA_USER;
  const token = process.env.RELEASE_JIRA_TOKEN || process.env.JIRA_TOKEN;
  if (!server || !user || !token) {
    throw new Error(
      "RELEASE_JIRA_BASE_URL, RELEASE_JIRA_USER and RELEASE_JIRA_TOKEN must be set to apply changes."
    );
  }
  const auth = "Basic " + Buffer.from(`${user}:${token}`).toString("base64");
  const headers = {
    Authorization: auth,
    Accept: "application/json",
    "Content-Type": "application/json",
  };
  const api = `${server}/rest/api/3`;

  async function request(method, endpoint, body) {
    const res = await fetch(`${api}${endpoint}`, {
      method,
      headers,
      body: body ? JSON.stringify(body) : undefined,
    });
    if (!res.ok) {
      const detail = await res.text().catch(() => "");
      throw new Error(`${method} ${endpoint} -> ${res.status} ${res.statusText} ${detail}`);
    }
    return res.status === 204 ? null : res.json();
  }

  return {
    getProjectId: (key) => request("GET", `/project/${key}`).then((p) => p.id),
    getVersions: (key) => request("GET", `/project/${key}/versions`),
    createVersion: (name, projectId) =>
      request("POST", "/version", { name, projectId, released: false }),
    addFixVersion: (issue, name) =>
      request("PUT", `/issue/${issue}`, {
        update: { fixVersions: [{ add: { name } }] },
      }),
  };
}

async function ensureVersion(jira, projectKey, name) {
  const projectId = await jira.getProjectId(projectKey);
  const versions = await jira.getVersions(projectKey);
  const existing = versions.find((v) => v.name === name);
  if (existing) {
    console.log(`  fixVersion "${name}" already exists in ${projectKey}`);
    return;
  }
  await jira.createVersion(name, projectId);
  console.log(`  created fixVersion "${name}" in ${projectKey}`);
}

// --- main ------------------------------------------------------------------
(async () => {
  if (!version) {
    console.error("Missing --version (or FIX_VERSION). e.g. --version 1.12.0");
    process.exit(1);
  }
  if (!fs.existsSync(notesFile)) {
    console.error(`Notes file not found: ${notesFile}`);
    console.error("Run previewRelease.js first to generate it.");
    process.exit(1);
  }

  // When --projects (or JIRA_PROJECTS) is given it is authoritative — the
  // reusable workflow passes the Jira project keys directly, so a project that
  // isn't in this repo's release.config.js (e.g. REGISTRY) still works.
  // Otherwise fall back to the prefixes configured in release.config.js.
  const prefixes = projectsFilter.length ? projectsFilter : issuePrefixes();
  const text = fs.readFileSync(notesFile, "utf8");
  const byProject = extractKeysByProject(text, prefixes);
  const projects = Object.keys(byProject);
  const total = projects.reduce((n, p) => n + byProject[p].length, 0);

  console.log(`Fix version : ${version}`);
  console.log(`Notes file  : ${notesFile}`);
  console.log(`Prefixes    : ${prefixes.join(", ")}`);
  console.log(`Mode        : ${apply ? "APPLY (writing to Jira)" : "dry-run (no changes)"}`);
  console.log(`Found ${total} issue(s) across ${projects.length} project(s):`);
  for (const p of projects) {
    console.log(`  ${p}: ${byProject[p].join(", ")}`);
  }

  if (total === 0) {
    console.log("No matching Jira issues in the notes — nothing to do.");
    console.log(JSON.stringify({ applied: false, total: 0 }));
    return;
  }

  if (!apply) {
    console.log("\nDry-run only. Re-run with --apply to set the fix version in Jira.");
    console.log(JSON.stringify({ applied: false, version, issues: byProject }));
    return;
  }

  const jira = jiraClient();
  for (const project of projects) {
    console.log(`\nProject ${project}:`);
    await ensureVersion(jira, project, version);
    for (const issue of byProject[project]) {
      await jira.addFixVersion(issue, version);
      console.log(`  tagged ${issue} with ${version}`);
    }
  }

  console.log("\nDone.");
  console.log(JSON.stringify({ applied: true, version, issues: byProject }));
})().catch((err) => {
  console.error(err.message || err);
  process.exit(1);
});
