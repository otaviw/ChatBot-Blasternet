# Stability Playbook

## Objective

Keep the system reliable under normal load, partial failures, and deploy churn.

## Core rules

1. Prefer small, reversible changes.
2. Add or update tests with each behavior change.
3. Fail closed for security-sensitive flows.
4. Keep observability on by default (structured logs + actionable metadata).
5. Never ship with known critical regressions.

## Pre-merge checklist

1. Run local quality gate (`scripts/quality-gate.ps1`).
2. Verify changed paths have automated coverage.
3. Confirm no new lint warnings.
4. Validate error paths and fallback behavior.
5. Review migrations for rollback and data safety.

## Runtime guards

1. Use bounded retries and explicit timeouts for external calls.
2. Keep rate limits enabled for write and auth endpoints.
3. Ensure secrets are required in production paths.
4. Avoid logging secrets, tokens, or PII.
5. Emit machine-readable logs for incident triage.

## Release safety

1. Deploy backend, frontend, and realtime independently.
2. Run smoke checks after deploy:
   - login
   - inbox load
   - realtime connect/join
   - message send/receive
3. Monitor error rate and latency for first 30 minutes.
4. Roll back quickly on user-facing regression.
