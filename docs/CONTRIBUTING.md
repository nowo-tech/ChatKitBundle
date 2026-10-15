# Contributing Guide

Thank you for your interest in contributing to Chat Kit Bundle! This document provides guidelines for contributing to the project.

## Table of contents

- [Code of Conduct](#code-of-conduct)
- [How Can I Contribute?](#how-can-i-contribute)
  - [Reporting Bugs](#reporting-bugs)
  - [Suggesting Enhancements](#suggesting-enhancements)
  - [Contributing Code](#contributing-code)
- [Pull requests](#pull-requests)
- [Documentation](#documentation)
- [License](#license)
- [Git hooks (REQ-GIT-001)](#git-hooks-req-git-001)
- [Cursor Agent (REQ-IDE-006)](#cursor-agent-req-ide-006)

## Code of Conduct

This project adheres to a code of conduct. By participating, you are expected to uphold this code. Please report unacceptable behavior to hectorfranco@nowo.tech.

## How Can I Contribute?

### Reporting Bugs

1. **Check existing issues** at [GitHub Issues](https://github.com/nowo-tech/ChatKitBundle/issues)
2. **Create a new issue** with steps to reproduce, expected vs. actual behavior, and PHP/Symfony versions

### Suggesting Enhancements

1. **Search for duplicate suggestions** in the issue tracker
2. **Describe the use case**, benefits, and possible implementation

### Contributing Code

#### Development environment

```bash
git clone https://github.com/nowo-tech/ChatKitBundle.git
cd ChatKitBundle
make up
make install
```

#### Code standards

- PSR-12 via PHP-CS-Fixer
- PHP 8.3+ with `declare(strict_types=1);`
- PHPDoc in English for public classes and methods

```bash
make cs-check
make cs-fix
make phpstan
make igor
make rector-dry
```

#### Tests

```bash
make test
make test-coverage
make test-ts
make assets
```

Add or update tests under `tests/Unit/` (and `tests/Integration/` when applicable) for behavior changes.

#### Demos

```bash
make -C demo up-symfony8
make -C demo release-check
```

After editing bundle PHP code in a mounted demo, run `make update-bundle` inside the demo directory (or rely on `release-check`, which syncs the bundle first).

#### Release pipeline

Before opening a PR that targets a release:

```bash
make release-check
```

See [Release](RELEASE.md) for maintainer steps.

## Pull requests

1. Fork and create a feature branch
2. Update `docs/CHANGELOG.md` under `[Unreleased]` for user-facing changes
3. Update `docs/UPGRADING.md` when consumers must change configuration or code
4. Ensure `make release-check` passes locally (or document why not)

## Documentation

- Integrator docs: `docs/INSTALLATION.md`, `docs/CONFIGURATION.md`, `docs/USAGE.md`, `docs/ARCHITECTURE.md`
- Product spec and `REQ-*` traceability: `docs/SPEC-DRIVEN-DEVELOPMENT.md`

## License

By contributing, you agree that your contributions will be licensed under the MIT License.

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers, nor `Co-Authored-By: Claude …` / `noreply@anthropic.com` ones, to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.
If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.

## Cursor Agent (REQ-IDE-006)

Contributors using Cursor Agent on this repository should:

1. **Submit with Ctrl+Enter** (macOS: **⌘+Enter**). **Enter** inserts a newline; **Ctrl+Enter** / **⌘+Enter** submits the prompt. UI: **Cursor Settings → Agents / Chat → Submit with Ctrl + Enter**.
2. **Queue follow-ups** while the agent is running. In user `settings.json` (not a repo file):

```json
{
  "cursor.composer.queueMessageDefaultBehavior": "queue"
}
```

Do **not** use `"steer"` as the org default: steer interrupts the running agent as soon as a follow-up is sent.
