import './BotConfigStep.css';

/**
 * Bloco de um passo da configuração do bot: número, título, texto introdutório e regras.
 */
function BotConfigStep({ step, title, intro, rules = [], children }) {
  const titleId = `bot-config-step-title-${step}`;

  return (
    <section
      className="bot-config-section"
      aria-labelledby={titleId}
    >
      <header className="bot-config-step-head">
        <span className="bot-config-step-badge" aria-hidden="true">
          {step}
        </span>
        <div className="bot-config-step-head-text">
          <h2 id={titleId} className="bot-config-section-title bot-config-section-title--step">
            {title}
          </h2>
          {intro ? (
            <p className="bot-config-step-intro">{intro}</p>
          ) : null}
        </div>
      </header>

      {rules.length > 0 ? (
        <div className="bot-config-rules-wrap">
          <p className="bot-config-rules-label">Regras e boas práticas</p>
          <ul className="bot-config-rules">
            {rules.map((line, i) => (
              <li key={i}>{line}</li>
            ))}
          </ul>
        </div>
      ) : null}

      <div className="bot-config-step-body">{children}</div>
    </section>
  );
}

export default BotConfigStep;
