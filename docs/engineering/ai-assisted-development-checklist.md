# AI-Assisted Development Checklist

Use this checklist for every AI-generated or AI-edited change before merge.

## Product and Contract
- [ ] Business rule is documented (not inferred only from prompt text).
- [ ] Domain values follow canonical constants (no ad-hoc strings).
- [ ] API shape matches contract docs.
- [ ] Backward compatibility is explicit and temporary.

## Safety and Security
- [ ] Authorization checks are preserved.
- [ ] Sensitive data is not leaked in logs/events/responses.
- [ ] Rate limits and input validation were not weakened.

## Test Coverage
- [ ] New behavior has feature/unit test coverage.
- [ ] Existing tests updated only when behavior changed intentionally.
- [ ] Tests do not rely on machine-specific capabilities unless guarded.

## Maintainability
- [ ] No unnecessary duplicate branches/fallbacks.
- [ ] Large functions/files were not expanded without clear reason.
- [ ] Comments explain non-obvious decisions, not obvious code.

## Operational Readiness
- [ ] `scripts/quality-gate.ps1` passes locally.
- [ ] CI checks are green.
- [ ] Rollback path is clear (especially for compatibility flags and migrations).

## Ownership
- [ ] A human owner is assigned for the changed module.
- [ ] Reviewer can explain the change without AI assistance.
