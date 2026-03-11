# Stability Playbook

This playbook defines how to apply the core project hardening steps without losing delivery speed.

## 1) Lock Domain Contracts

### What
Create one canonical value set for core states (`handling_mode`, statuses, event names, role names).

### Why
Contract drift creates regressions between backend, frontend, tests, and realtime consumers.

### How
1. Keep constants in one place (`backend/app/Support/ConversationHandlingMode.php`).
2. Normalize legacy values at model boundary (`Conversation` accessor/mutator).
3. Normalize persisted legacy rows with migration.
4. Update tests to assert canonical values.
5. Reject new aliases unless they are explicitly temporary.

### Done Criteria
- No feature test depends on deprecated domain values.
- Legacy rows read as canonical values in API responses.

## 2) Lock API Contracts

### What
Use one contract document and one canonical payload shape per endpoint.

### Why
Prevents frontend fallback sprawl and undefined behavior.

### How
1. Keep `docs/internal-chat-api-contract.md` as source of truth.
2. Keep frontend compatibility off by default (`VITE_INTERNAL_CHAT_ENABLE_LEGACY_ENDPOINTS=0`).
3. When compatibility is enabled, document deprecation and planned removal date.
4. Add/maintain integration tests that assert canonical response keys.

### Done Criteria
- Canonical endpoints work with legacy compatibility disabled.
- No new endpoint ships without contract update.

## 3) Remove UI Reload Workarounds

### What
Replace `window.location.reload()` with state updates.

### Why
Reloads hide state bugs and hurt UX; they also make race conditions harder to debug.

### How
1. Keep local list/detail state.
2. Patch state after successful writes.
3. Fall back to refetch only the affected resource.

### Done Criteria
- No hard page reload needed after create/update actions.

## 4) Enforce Quality Gates

### What
Run deterministic checks before merge.

### Why
Catches regressions early and makes AI-assisted changes safer.

### How
1. Local command: `powershell -ExecutionPolicy Bypass -File scripts/quality-gate.ps1`.
2. CI workflow: `.github/workflows/quality-gate.yml`.
3. Keep checks green before merge.

### Done Criteria
- Backend tests pass.
- Frontend and realtime checks pass.
- CI blocks failing pull requests.

## 5) Keep Tests Aligned With Current Product Rules

### What
Update tests whenever behavior intentionally changes.

### Why
Old test expectations produce false negatives and slow delivery.

### How
1. Assert stable outcomes (contract, security, ownership) instead of transient strings.
2. Skip tests that require unavailable platform capabilities (example: GD extension).
3. Keep tests deterministic and environment-aware.

### Done Criteria
- No brittle test depends on local machine-specific extensions unless explicitly required.

## 6) Rollout Sequence

Apply changes in this order:
1. Contract and migration
2. Tests
3. Frontend state fixes
4. Quality gate/CI
5. Legacy removal cleanup

Never invert this sequence in production branches.
