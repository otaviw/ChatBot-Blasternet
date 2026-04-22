export function normalizeStepKey(flow, step) {
  const safeFlow = String(flow ?? '').trim();
  const safeStep = String(step ?? '').trim();

  if (!safeFlow || !safeStep) {
    return '';
  }

  return `${safeFlow}.${safeStep}`;
}

export function collectReachableStepKeys({
  steps = [],
  initialFlow = '',
  initialStep = '',
}) {
  const visited = new Set();
  const stepByKey = new Map(
    (steps ?? [])
      .map((step) => [normalizeStepKey(step?.flow, step?.step), step])
      .filter(([key]) => Boolean(key))
  );
  const initialKey = normalizeStepKey(initialFlow, initialStep);

  const visit = (stepKey, path = new Set()) => {
    if (!stepKey || visited.has(stepKey) || path.has(stepKey)) {
      return;
    }

    visited.add(stepKey);

    const step = stepByKey.get(stepKey);
    if (!step) {
      return;
    }

    const nextPath = new Set(path);
    nextPath.add(stepKey);

    if (step.type === 'numeric_menu') {
      for (const option of step.options ?? []) {
        if (option?.action?.kind !== 'go_to') {
          continue;
        }

        visit(normalizeStepKey(option?.action?.flow, option?.action?.step), nextPath);
      }
      return;
    }

    if (step?.on_text?.kind === 'go_to') {
      visit(normalizeStepKey(step?.on_text?.flow, step?.on_text?.step), nextPath);
    }
  };

  visit(initialKey);
  return visited;
}

export function getDisconnectedStepIds({
  steps = [],
  initialFlow = '',
  initialStep = '',
}) {
  const reachableKeys = collectReachableStepKeys({
    steps,
    initialFlow,
    initialStep,
  });

  return new Set(
    (steps ?? [])
      .filter((step) => {
        const stepKey = normalizeStepKey(step?.flow, step?.step);
        if (!stepKey) {
          return true;
        }

        return !reachableKeys.has(stepKey);
      })
      .map((step) => step.id)
  );
}
