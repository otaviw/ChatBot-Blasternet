# AI Assisted Development Checklist

## Goal

Use AI to speed up delivery without lowering correctness, security, or maintainability.

## Before coding

1. Define scope and acceptance criteria.
2. Identify impacted services (`backend`, `frontend`, `realtime`).
3. List high-risk areas (auth, permissions, data integrity, realtime events).

## During coding

1. Keep changes minimal and focused.
2. Preserve existing architecture and domain boundaries.
3. Add tests for new behavior and edge cases.
4. Avoid introducing broad refactors without explicit need.
5. Do not expose secrets in code, logs, or examples.

## Validation

1. Run lint, tests, and build for affected projects.
2. Verify negative paths (invalid payload, unauthorized access, missing config).
3. Check for race conditions and stale state in UI hooks.
4. Confirm no new warnings in CI.

## Review quality bar

1. Clear naming and low-complexity control flow.
2. No dead code or commented-out blocks left behind.
3. Observability preserved (logs and trace context where needed).
4. Documentation updated when behavior or workflow changes.

## Final handoff

1. Summarize what changed and why.
2. List exact files touched.
3. Mention residual risks and recommended next steps.
