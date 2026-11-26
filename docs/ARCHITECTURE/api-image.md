# API container image

`ghcr.io/hdruk/cohort-discovery-api:<tag>` is published for anyone to run. It is separate from HDR UK's own deployment pipelines, which build and deploy their own images.

## What is in the image

- Only files tracked in this repository, built from a clean checkout in GitHub Actions. The repository is public, so the image contains nothing that is not already public.
- `.env*` (apart from `.env.example`), OAuth key files, `vendor`, `node_modules` and `.git` are excluded by `.dockerignore`.
- The build takes no secrets and no build arguments. The optional `github_token` build secret exists only for HDR UK's own pipelines and is never used by the publish workflow.
- `.env.example` is included. Its values are placeholders and must be overridden.

## Standalone and integrated mode

Nothing mode-specific is baked in at build time. The same image runs in either mode, selected at runtime:

- `APP_OPERATION_MODE` is `standalone` (the default) or `integrated`.
- `docker/start.sh` runs `config:cache` when the container starts, so configuration is read from the runtime environment.
- Integrated mode additionally needs the `INTEGRATED_*` variables listed in `.env.example`.

## Supplying configuration and keys

- Pass configuration with `--env-file` or individual `-e` values.
- Pass the Passport keys as `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY`, or mount `storage/oauth-private.key` and `storage/oauth-public.key`.
- Swagger documentation is generated at build time without a `.env`, so its `APP_URL` host is the default and may not match your deployment.

## Publishing

`.github/workflows/publish-ghcr.yml` runs on `v*` tags. It calls `ci.yml` first and builds and pushes only if CI passes. The `publish` job uses only the automatic `GITHUB_TOKEN` and must not be given other secrets. The `ci` job has read-only `contents` permission and, as on every push, resolves the `dev` environment's secrets (the OAuth keys the tests need) through its own `environment: dev`. That environment has no protection rules or branch policy, so tag runs are not blocked.
