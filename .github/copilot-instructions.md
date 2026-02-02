<!-- copilot-pr-review.md v1.1 | Last updated: 2026-02-02 -->

# GitHub Copilot PR Review Guide

This file defines how **GitHub Copilot** assists in pull request reviews for this repository.
Copilot must follow our engineering standards, CI workflows, and commit conventions when reviewing code.

---

## 1. Review Objectives

Copilot should:

- Verify code quality, readability, and maintainability
- Confirm compliance with CI (PHPStan, PHPUnit, Linters)
- Ensure PRs follow commit, testing, and documentation guidelines
- Never auto-approve — only provide actionable feedback

---

## 2. Pull Request Format

All PRs must follow `.github/PULL_REQUEST_TEMPLATE.md`.

**Checklist:**

- PR title includes issue key (e.g., `CIVIMM-123: Fix summary bug`)
- All template sections (Overview, Before, After, Technical Details) are completed
- Linked to correct issue

If incomplete, Copilot should suggest precise edits or missing fields.

---

## 3. Review Checklist

| Category        | Requirement                          | Example Feedback                                                   |
|----------------|--------------------------------------|---------------------------------------------------------------------|
| **Code Quality** | Clear, maintainable logic             | "Consider extracting this logic into a helper."                     |
| **Testing**     | PHPUnit tests included and passing   | "Missing test for `InstalmentGenerationService::createInstalment()`."                |
| **Static Analysis** | PHPStan passes at CI level         | "Check for PHPStan level 9 compliance."                             |
| **Style**       | Follows PSR-12 & naming conventions  | "Rename `$obj` to `$contactData` for clarity."                      |
| **Docs**        | Public methods include PHPDoc        | "Add PHPDoc for `ContributionCompletionService::complete()`."              |

---

## 4. Critical Review Areas

### Security

- Detect hardcoded secrets or API keys
- Check for SQL injection and XSS
- Validate user input & sanitize output
- Review authentication/authorization logic

### Performance

- Identify N+1 query issues
- Detect inefficient loops or algorithms
- Spot memory leaks or unfreed resources
- Recommend caching for expensive ops

### Code Quality

- Functions should be focused and testable
- Use meaningful, descriptive names
- Handle errors properly

---

## 5. Review Style Tips

- Be specific and actionable
- Explain the "why" behind your suggestions
- Acknowledge good patterns
- Ask clarifying questions if intent is unclear
