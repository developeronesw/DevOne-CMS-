import { StrictMode, useState } from "react";
import { createRoot } from "react-dom/client";
import "./styles.css";

type InstallMode = "local" | "cloudflare";

function LocalIcon() {
  return (
    <svg viewBox="0 0 48 48" aria-hidden="true" className="choice-icon-svg">
      <rect x="7" y="8" width="34" height="25" rx="3.5" />
      <path d="M18 40h12M24 33v7M17 19l4 4-4 4M25 27h7" />
    </svg>
  );
}

function CloudIcon() {
  return (
    <svg viewBox="0 0 48 48" aria-hidden="true" className="choice-icon-svg cloud-icon">
      <path d="M12 34h25a7 7 0 0 0 .7-14 13 13 0 0 0-24.9-2.4A8.4 8.4 0 0 0 12 34Z" />
      <path d="M9 39h27" />
    </svg>
  );
}

function App() {
  const [mode, setMode] = useState<InstallMode | null>(null);
  const [confirmed, setConfirmed] = useState(false);

  const chooseMode = (nextMode: InstallMode) => {
    setMode(nextMode);
    setConfirmed(false);
  };

  const reset = () => {
    setMode(null);
    setConfirmed(false);
  };

  return (
    <main className="installer-page">
      <div className="page-grid" aria-hidden="true" />
      <header className="brand-header" aria-label="DevOne CMS">
        <div className="brand-wordmark"><span>DevOne</span><span className="brand-muted">CMS</span></div>
        <div className="brand-version">VERSION 2.0</div>
      </header>

      <section className="setup-panel" aria-labelledby="setup-title">
        <div className="setup-content">
          <div className="eyebrow"><span className="status-dot" /> DEVONE SETUP</div>
          {!confirmed ? (
            <>
              <h1 id="setup-title">Choose where to install</h1>
              <p className="intro">Get DevOne CMS running in the environment that works<br className="desktop-break" /> best for your project.</p>

              <div className="choice-list" aria-label="Installation environment">
                <button
                  type="button"
                  className={`choice-card ${mode === "local" ? "is-selected" : ""}`}
                  aria-pressed={mode === "local"}
                  onClick={() => chooseMode("local")}
                >
                  <span className="choice-icon local-icon"><LocalIcon /></span>
                  <span className="choice-copy">
                    <span className="choice-title">Install locally</span>
                    <span className="choice-description">Run DevOne on your own machine or server</span>
                  </span>
                  <span className="choice-arrow" aria-hidden="true">{mode === "local" ? "✓" : "→"}</span>
                </button>

                <button
                  type="button"
                  className={`choice-card ${mode === "cloudflare" ? "is-selected" : ""}`}
                  aria-pressed={mode === "cloudflare"}
                  onClick={() => chooseMode("cloudflare")}
                >
                  <span className="choice-icon cloud-choice-icon"><CloudIcon /></span>
                  <span className="choice-copy">
                    <span className="choice-title">Deploy with Cloudflare</span>
                    <span className="choice-description">Launch globally on Cloudflare's network</span>
                  </span>
                  <span className="choice-arrow" aria-hidden="true">{mode === "cloudflare" ? "✓" : "→"}</span>
                </button>
              </div>

              <div className="choice-footer">
                <span className="selection-hint">{mode ? `${mode === "local" ? "Local installation" : "Cloudflare deployment"} selected` : "Select an environment to continue"}</span>
                <button className="primary-button" type="button" disabled={!mode} onClick={() => setConfirmed(true)}>
                  Continue <span aria-hidden="true">→</span>
                </button>
              </div>
            </>
          ) : (
            <div className="selected-view">
              <div className="eyebrow eyebrow-secondary">INSTALLATION METHOD</div>
              <h1 id="setup-title">{mode === "local" ? "Install locally" : "Deploy with Cloudflare"}</h1>
              <p className="intro selected-intro">
                {mode === "local"
                  ? "Run DevOne on a machine or server you control. The local setup path will guide you through runtime and storage configuration."
                  : "Prepare your DevOne deployment for Cloudflare. The cloud setup path will guide you through account and resource configuration."}
              </p>
              <div className="next-step-card">
                <span className="next-step-number">01</span>
                <div>
                  <strong>Next: environment setup</strong>
                  <p>{mode === "local" ? "We’ll check the local runtime and prepare the installation settings." : "We’ll confirm the Cloudflare prerequisites and prepare your deployment settings."}</p>
                </div>
              </div>
              <div className="choice-footer detail-footer">
                <button className="text-button" type="button" onClick={reset}>← Change installation method</button>
                <span className="coming-note">Guided setup screens are being connected next.</span>
              </div>
            </div>
          )}
        </div>
      </section>

      <footer className="page-footer">DevOne CMS <span>·</span> Simple content, built for developers</footer>
    </main>
  );
}

createRoot(document.getElementById("root")!).render(
  <StrictMode><App /></StrictMode>
);
