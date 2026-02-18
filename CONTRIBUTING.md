# Contributing

Thanks for contributing to this project. This guide explains how to get set up,
make changes, and submit work.

## Quick Start
1. Fork and clone the repo.
2. Install dependencies:
   - `composer install`
   - `npm install`
3. Copy env and configure:
   - `cp .env.example .env`
   - update DB credentials as needed
4. Generate app key:
   - `php artisan key:generate`

## Running Tests
- `composer run test`

## Linting
- PHP: `composer run lint`
- Workflows: `npm run lint:workflows`

## Coding Standards
- Follow existing patterns and naming conventions.
- Keep changes focused and avoid unrelated refactors.
- Add or update tests for behavior changes.

## Commits and PRs
- Use clear, descriptive commits.
- Ensure CI passes before opening a PR.
- Include a concise summary and testing notes in the PR description.

## Security
- Do not commit secrets or credentials.
- Report vulnerabilities privately.
