/**
 * previewRelease.js
 *
 * Generates a LOCAL preview of the next release: the computed next version and
 * the rendered release notes, for the commits on the currently checked-out
 * branch that are not yet in the latest release.
 *
 * Why not just `semantic-release --dry-run`?
 *   semantic-release measures "unreleased commits" from the last version tag
 *   that is an ANCESTOR of the current branch. In this repo the version tags
 *   (v1.6.0 ...) live on main's `chore(release)` commits, while `dev` only
 *   receives squash-sync commits — so no recent tag is an ancestor of dev, and
 *   a dry-run on dev would measure all the way back to v1.5.0. To get a useful
 *   answer on any branch, this script measures against the highest release tag
 *   in the whole repo (the real "latest release"), i.e. the range
 *   <latest-tag>..HEAD.
 *
 * It calls the commit-analyzer + release-notes-generator plugins directly, so
 * it needs no GITHUB_TOKEN / git / npm credentials and never commits, tags,
 * pushes, publishes, or edits any tracked file.
 *
 * Output: writes the markdown notes to RELEASE_NOTES_PREVIEW.md and prints a
 * machine-readable JSON summary on the last stdout line.
 *
 * Usage:
 *   node previewRelease.js                 # preview the checked-out branch (HEAD)
 *   node previewRelease.js --ref dev       # preview another branch without checkout
 *   node previewRelease.js --base v1.11.0  # measure against a specific ref/tag
 *   node previewRelease.js --out notes.md  # custom output file
 */

const fs = require("fs");
const path = require("path");
const { execSync } = require("child_process");
const semver = require("semver");

const { analyzeCommits } = require("@semantic-release/commit-analyzer");
const { generateNotes } = require("@semantic-release/release-notes-generator");

const baseConfig = require("./release.config.js");

const RECORD_SEP = "\x1e";
const FIELD_SEP = "\x1f";

// --- tiny helpers ----------------------------------------------------------
function git(args) {
  return execSync(`git ${args}`, { cwd: __dirname, maxBuffer: 64 * 1024 * 1024 })
    .toString()
    .trim();
}

function getArg(flag) {
  const i = process.argv.indexOf(flag);
  return i !== -1 ? process.argv[i + 1] : undefined;
}

// Pull the release-notes-generator config out of release.config.js so the
// preview formatting matches the real pipeline exactly (sections, Jira links).
function notesPluginConfig() {
  const entry = baseConfig.plugins.find(
    (p) => Array.isArray(p) && p[0] === "@semantic-release/release-notes-generator"
  );
  return entry ? entry[1] : {};
}

// Highest semver tag across the whole repo (not just the current branch).
function highestReleaseTag() {
  const tags = git("tag -l")
    .split("\n")
    .map((t) => t.trim())
    .filter((t) => semver.valid(t));
  if (tags.length === 0) return null;
  return semver.rsort(tags)[0];
}

// Resolve the base version from a ref: prefer a semver-looking tag name,
// otherwise read package.json at that ref.
function versionForRef(ref) {
  if (semver.valid(ref)) return semver.valid(ref);
  try {
    const pkg = JSON.parse(git(`show ${ref}:package.json`));
    if (pkg.version) return semver.valid(pkg.version);
  } catch {
    /* fall through */
  }
  return null;
}

function readCommits(range) {
  const raw = git(`log ${range} --format=%H${FIELD_SEP}%B${RECORD_SEP}`);
  if (!raw) return [];
  return raw
    .split(RECORD_SEP)
    .map((r) => r.replace(/^\s+/, ""))
    .filter(Boolean)
    .map((record) => {
      const [hash, ...rest] = record.split(FIELD_SEP);
      return { hash: hash.trim(), message: rest.join(FIELD_SEP).trim() };
    })
    .filter((c) => c.hash && c.message);
}

// --- main ------------------------------------------------------------------
(async () => {
  const targetRef = getArg("--ref") || "HEAD";
  const targetName =
    targetRef === "HEAD" ? git("rev-parse --abbrev-ref HEAD") : targetRef;
  const headSha = git(`rev-parse ${targetRef}`);

  const baseRef = getArg("--base") || highestReleaseTag();
  if (!baseRef) {
    console.error("Could not determine a base release tag, and none given via --base.");
    process.exit(1);
  }
  const baseVersion = versionForRef(baseRef);
  if (!baseVersion) {
    console.error(`Could not determine a version for base ref "${baseRef}".`);
    process.exit(1);
  }
  const baseSha = git(`rev-list -n 1 ${baseRef}`);

  const outFile = path.resolve(__dirname, getArg("--out") || "RELEASE_NOTES_PREVIEW.md");

  console.log(`Branch       : ${targetName}`);
  console.log(`Base release : ${baseVersion} (${baseRef})`);

  const commits = readCommits(`${baseSha}..${headSha}`);
  console.log(`Commits      : ${commits.length} since base`);

  if (commits.length === 0) {
    console.log("No unreleased commits — nothing to preview.");
    console.log(JSON.stringify({ willRelease: false, reason: "no-commits" }));
    return;
  }

  const repositoryUrl = git("config --get remote.origin.url");
  const logger = { log: () => {}, error: console.error };

  // 1) Decide the bump (matches release.config.js: default commit-analyzer).
  const releaseType = await analyzeCommits(
    {},
    { commits, logger, cwd: __dirname, env: process.env }
  );

  if (!releaseType) {
    console.log("No version-bumping commits (only chore/docs/etc.) — no release.");
    console.log(JSON.stringify({ willRelease: false, reason: "no-bump" }));
    return;
  }

  const nextVersion = semver.inc(baseVersion, releaseType);
  const lastRelease = { version: baseVersion, gitTag: baseRef, gitHead: baseSha };
  const nextRelease = {
    version: nextVersion,
    gitTag: `v${nextVersion}`,
    gitHead: headSha,
    type: releaseType,
    channel: null,
  };

  // 2) Render the notes (matches release.config.js formatting).
  const notes = await generateNotes(notesPluginConfig(), {
    commits,
    lastRelease,
    nextRelease,
    options: { repositoryUrl },
    cwd: __dirname,
    env: process.env,
    logger,
  });

  fs.writeFileSync(outFile, notes || "", "utf8");

  console.log(`Next release : ${nextVersion}  [${releaseType}]`);
  console.log(`Notes written: ${outFile}`);

  console.log(
    JSON.stringify({
      willRelease: true,
      branch: targetName,
      baseVersion,
      nextVersion,
      type: releaseType,
      commitCount: commits.length,
      notesFile: outFile,
    })
  );
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
