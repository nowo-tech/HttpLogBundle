# Contributing Guide

Thank you for your interest in contributing to **Http Log Bundle**! This document provides guidelines for contributing to the project.

## Table of contents

- [Code of Conduct](#code-of-conduct)
- [How Can I Contribute?](#how-can-i-contribute)
  - [Reporting Bugs](#reporting-bugs)
  - [Suggesting Enhancements](#suggesting-enhancements)
  - [Submitting Pull Requests](#submitting-pull-requests)
- [Project Structure](#project-structure)
- [Demos](#demos)
- [Questions](#questions)
- [Acknowledgments](#acknowledgments)

## Code of Conduct

This project adheres to a code of conduct. By participating, you are expected to uphold this code. Please report unacceptable behavior to hectorfranco@nowo.tech.

## How Can I Contribute?

### Reporting Bugs

If you find a bug, please:

1. **Check that the bug hasn't already been reported** in the [issues](https://github.com/nowo-tech/HttpLogBundle/issues)
2. **Create a new issue** with:
   - A descriptive title
   - Steps to reproduce the problem
   - Expected behavior vs. actual behavior
   - PHP, Symfony, and bundle versions
   - Relevant `nowo_http_log` configuration

### Suggesting Enhancements

Enhancement suggestions are welcome:

1. **Check that the enhancement hasn't already been suggested** in the [issues](https://github.com/nowo-tech/HttpLogBundle/issues)
2. **Create a new issue** with:
   - A descriptive title
   - Detailed description of the proposed enhancement
   - Use cases and benefits
   - Possible implementations (if you have them)

### Contributing Code

#### Setting Up the Development Environment

1. **Fork the repository** on GitHub
2. **Clone your fork**:
   ```bash
   git clone https://github.com/your-username/HttpLogBundle.git
   cd HttpLogBundle
   ```
3. **Install dependencies**:
   ```bash
   # With Docker (recommended)
   make install

   # Without Docker
   composer install
   ```

#### Code Standards

The project follows these standards:

- **PSR-12**: PHP code style
- **PHP 8.2+**: Modern PHP features
- **Strict type hints**: `declare(strict_types=1);` in all files
- **PHP-CS-Fixer**: Used to maintain code consistency

**Before committing**:

```bash
make setup-hooks
make check-no-cursor-coauthor
make cs-check
make cs-fix
```

#### Tests

**The project requires 100% code coverage** on `src/`. All tests must pass before merging.

```bash
make test
make test-coverage
make test-coverage-100
```

#### Pull Request Process

1. Create a branch from `main`
2. Make your changes with tests and documentation updates
3. Run `make release-check` or at least `make qa phpstan test-coverage-100`
4. Update `specs/001-baseline/` when `src/` changes
5. Open a Pull Request on GitHub

#### Checklist Before PR

- [ ] Code follows PSR-12 standards
- [ ] Ran `make cs-fix`
- [ ] All tests pass (`make test`)
- [ ] Code coverage is 100% on `src/` (`make test-coverage-100`)
- [ ] `make phpstan` passes
- [ ] Added tests for new functionality
- [ ] Documentation updated (if necessary)
- [ ] CHANGELOG.md updated (if necessary)

## Project Structure

```
HttpLogBundle/
├── src/                    # Bundle source code
│   ├── Command/
│   ├── Controller/
│   ├── DependencyInjection/
│   ├── Entity/
│   ├── EventSubscriber/
│   ├── Message/
│   ├── Security/
│   ├── Service/
│   └── Resources/
├── tests/                  # Unit and integration tests
├── demo/                   # FrankenPHP demo (Symfony 8)
├── specs/                  # Spec Kit baseline
├── .github/                # GitHub configuration
└── docs/                   # Documentation
```

## Demos

Demo application under `demo/symfony8` (Symfony 8.x, FrankenPHP, SQLite, port **8091**):

```bash
make -C demo/symfony8 up
make demo-smoke
```

See [Demo with FrankenPHP](DEMO-FRANKENPHP.md).

## Questions

- Open an issue on GitHub
- Contact the maintainers at hectorfranco@nowo.tech

## Acknowledgments

Thank you for contributing to Http Log Bundle.

If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.
