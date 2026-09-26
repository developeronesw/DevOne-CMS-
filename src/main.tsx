import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import "./styles.css";

function App() {
  return (
    <main className="devone-shell">
      <section className="devone-card">
        <div className="devone-kicker">DEVONE CMS • SERVERLESS 2.0</div>
        <h1>Developer One</h1>
        <p className="tagline">Build. Manage. Evolve.</p>
        <p>Performance, security, and design — built for Cloudflare.</p>
      </section>
    </main>
  );
}

createRoot(document.getElementById("root")!).render(
  <StrictMode><App /></StrictMode>
);
