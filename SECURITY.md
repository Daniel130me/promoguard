# Security Policy

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability.

Use the repository's **Security** tab and select **Report a vulnerability** to
submit a private GitHub Security Advisory. Include:

- The affected version or commit
- Preconditions and required permissions
- Reproduction steps or a minimal proof of concept
- Expected and observed behavior
- Potential impact
- Any suggested mitigation

Do not include real customer data, credentials, payment details, private keys,
cookies, or production database exports. Use synthetic test data and redact
logs before attaching them.

## Scope

Security reports may cover authorization, stored or reflected injection,
cross-site request forgery, unsafe data exposure, identity conflicts,
transaction integrity, checkout bypasses, and dependency vulnerabilities in
the maintained codebase.

Reports that require unsupported WordPress, WooCommerce, or PHP versions may
be closed after the compatibility boundary is confirmed.

## Disclosure

Allow maintainers time to reproduce, fix, test, and release a correction before
public disclosure. Acknowledgement and release timing depend on severity,
reproducibility, and maintainer availability; this project does not promise a
fixed response-time SLA.
