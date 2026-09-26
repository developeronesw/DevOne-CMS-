<?php
require __DIR__ . '/includes/admin_common.php';
verify_csrf();
if (function_exists('devone_network_enabled') && devone_network_enabled() && function_exists('devone_network_is_super_admin') && !devone_network_is_super_admin()) {
    http_response_code(403);
    devone_admin_header('Upgrade Locked - DevOneCMS');
    echo '<h1>Upgrade</h1><div class="card error-card"><strong>Upgrade and license management are only available to the Network Super Admin.</strong><br>Client Site Admins can manage their assigned site, but cannot change the DevOne license for this installation.</div>';
    devone_admin_footer();
    exit;
}
devone_require_permission('manage_settings');

$msg = $_GET['msg'] ?? '';
$error = '';
$result = null;
$status = function_exists('devone_license_status') ? devone_license_status() : array('plan'=>'free','plan_label'=>'DevOne Free','verified'=>false,'features'=>array());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'activate_license') {
        $result = devone_license_activate($_POST['license_key'] ?? '');
        if (!empty($result['ok'])) {
            $status = devone_license_status();
            $plan = devone_license_plan_label($status['plan']);
            header('Location: upgrade.php?activated=1&msg=' . rawurlencode('Thank you for upgrading to ' . $plan . '.'));
            exit;
        }
        $error = $result['message'] ?? 'License activation failed.';
    }
    if ($action === 'refresh_license') {
        $result = devone_license_refresh();
        if (!empty($result['ok'])) {
            header('Location: upgrade.php?msg=' . rawurlencode('License refreshed.'));
            exit;
        }
        $error = $result['message'] ?? 'License refresh failed.';
    }
    if ($action === 'clear_license') {
        devone_license_deactivate_local();
        header('Location: upgrade.php?msg=' . rawurlencode('Local license data cleared.'));
        exit;
    }
    $status = devone_license_status();
}

$activated = !empty($_GET['activated']);
$features = $status['features'] ?? array();
$serverUrl = function_exists('devone_license_server_url') ? devone_license_server_url() : '';
$publicKey = function_exists('devone_license_public_key') ? devone_license_public_key() : '';
$currentUser = function_exists('devone_current_user') ? devone_current_user() : array();
$purchaseEmail = is_array($currentUser) ? (string)($currentUser['email'] ?? '') : '';
$purchaseName = is_array($currentUser) ? trim((string)(($currentUser['display_name'] ?? '') ?: ($currentUser['username'] ?? ''))) : '';
$purchaseReturnUrl = rtrim(function_exists('devone_license_site_url') ? devone_license_site_url() : '', '/') . '/admin/upgrade.php?stripe_return=1&session_id={CHECKOUT_SESSION_ID}';
$purchaseConfig = array(
    'apiBase' => $serverUrl,
    'installId' => $status['install_id'] ?? '',
    'siteUrl' => $status['site_url'] ?? '',
    'domain' => $status['domain'] ?? '',
    'customerEmail' => $purchaseEmail,
    'customerName' => $purchaseName,
    'returnUrl' => $purchaseReturnUrl,
);

function devone_upgrade_feature_badge($name) {
    return '<span class="devone-feature-badge">' . e($name) . '</span>';
}

devone_admin_header('Upgrade - DevOneCMS');
?>
<style>
.devone-upgrade-grid{display:grid;grid-template-columns:1.1fr .9fr;gap:18px;align-items:start}.devone-upgrade-hero{border-radius:30px;padding:30px;background:radial-gradient(circle at top right,rgba(40,184,255,.18),transparent 35%),linear-gradient(145deg,rgba(255,255,255,.08),rgba(255,255,255,.035));border:1px solid rgba(255,255,255,.12)}.devone-upgrade-plan{display:inline-flex;align-items:center;gap:10px;padding:10px 14px;border-radius:999px;background:rgba(255,255,255,.08);font-weight:900}.devone-upgrade-plan strong{color:#ffd35a}.devone-feature-list{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}.devone-feature-badge{display:inline-flex;padding:8px 11px;border-radius:999px;background:rgba(40,184,255,.12);border:1px solid rgba(40,184,255,.25);font-size:.84rem;font-weight:800}.devone-license-meta{display:grid;grid-template-columns:1fr 1fr;gap:10px}.devone-license-meta div{border-radius:16px;background:rgba(255,255,255,.06);padding:13px}.devone-license-meta span{display:block;color:#aab6d8;font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.devone-license-meta strong{display:block;margin-top:5px;word-break:break-word}.devone-thankyou{border:1px solid rgba(66,255,155,.25);background:rgba(66,255,155,.08);border-radius:24px;padding:20px;margin:16px 0}.devone-license-key-input{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.04em;text-transform:uppercase}@media(max-width:900px){.devone-upgrade-grid{grid-template-columns:1fr}.devone-license-meta{grid-template-columns:1fr}}
.devone-purchase-table{width:100%;border-collapse:separate;border-spacing:0 10px}.devone-purchase-table th{text-align:left;color:#aab6d8;text-transform:uppercase;letter-spacing:.06em;font-size:.78rem}.devone-purchase-table td{background:rgba(255,255,255,.055);border-top:1px solid rgba(255,255,255,.08);border-bottom:1px solid rgba(255,255,255,.08);padding:14px;vertical-align:middle}.devone-purchase-table td:first-child{border-left:1px solid rgba(255,255,255,.08);border-radius:18px 0 0 18px}.devone-purchase-table td:last-child{border-right:1px solid rgba(255,255,255,.08);border-radius:0 18px 18px 0}.devone-price-stack{display:flex;flex-direction:column;gap:4px}.devone-price-stack strong{font-size:1.15rem;color:#ffd35a}.devone-price-stack small s{opacity:.72}.devone-launch-badge{display:inline-flex;width:max-content;padding:5px 9px;border-radius:999px;background:rgba(66,255,155,.12);border:1px solid rgba(66,255,155,.28);color:#b9ffd8;font-size:.72rem;font-weight:900;text-transform:uppercase;letter-spacing:.06em}.devone-policy-warning{border:1px solid rgba(255,211,90,.35);background:rgba(255,211,90,.08);color:#fff3c2;border-radius:18px;padding:14px 16px;margin:14px 0;line-height:1.45}.devone-policy-warning strong{color:#ffd35a}.devone-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.72);display:none;align-items:center;justify-content:center;z-index:99999;padding:18px}.devone-modal-backdrop.open{display:flex}.devone-modal{width:min(960px,100%);max-height:92vh;overflow:auto;background:#0d1324;border:1px solid rgba(255,255,255,.14);border-radius:28px;box-shadow:0 24px 80px rgba(0,0,0,.45);padding:22px}.devone-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:center}.devone-modal-close{width:auto;background:rgba(255,255,255,.08);color:#fff}.devone-purchase-form{display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;margin:16px 0}.devone-purchase-message{border-radius:16px;padding:12px;margin:12px 0;display:none}.devone-purchase-message.ok{display:block;background:rgba(66,255,155,.1);border:1px solid rgba(66,255,155,.28)}.devone-purchase-message.err{display:block;background:rgba(255,92,120,.1);border:1px solid rgba(255,92,120,.3)}#devone-stripe-checkout{min-height:420px;background:#fff;border-radius:18px;overflow:hidden}.devone-hidden{display:none!important}@media(max-width:760px){.devone-purchase-table,.devone-purchase-table thead,.devone-purchase-table tbody,.devone-purchase-table tr,.devone-purchase-table td{display:block}.devone-purchase-table thead{display:none}.devone-purchase-table tr{margin:12px 0}.devone-purchase-table td{border:0;border-radius:0!important}.devone-purchase-table td:first-child{border-radius:18px 18px 0 0!important}.devone-purchase-table td:last-child{border-radius:0 0 18px 18px!important}.devone-purchase-form{grid-template-columns:1fr}}
</style>
<section class="page-manager-hero">
  <div>
    <p class="admin-kicker"><span></span> License & Upgrade</p>
    <h1>Upgrade DevOne</h1>
    <p class="muted">Enter a DevOne Pro or Enterprise license key to unlock paid features on this installation. DevOne validates through the official license server, then stores a signed yearly certificate locally until expiration.</p>
  </div>
  <div class="admin-hero-actions"><a class="btn secondary" href="dashboard.php">Dashboard</a></div>
</section>
<?php devone_flash($msg); devone_flash($error, 'card error-card'); ?>
<?php if ($activated): ?>
<section class="devone-thankyou">
  <h2>Thank you for your purchase.</h2>
  <p>Your <?= e($status['plan_label'] ?? 'DevOne') ?> license is active. Paid features are now unlocked for this installation until the certificate expires.</p>
</section>
<?php endif; ?>
<div class="devone-upgrade-grid">
  <section class="devone-upgrade-hero">
    <div class="devone-upgrade-plan">Current Plan: <strong><?= e($status['plan_label'] ?? 'DevOne Free') ?></strong></div>
    <h2><?= !empty($status['verified']) ? 'License active' : 'DevOne Free active' ?></h2>
    <p class="muted"><?= !empty($status['verified']) ? 'This installation has a signed DevOne license certificate.' : 'This installation is running the free feature set until a Pro or Enterprise license is activated.' ?></p>
    <div class="devone-license-meta">
      <div><span>Install ID</span><strong><?= e($status['install_id'] ?? '') ?></strong></div>
      <div><span>Domain</span><strong><?= e($status['domain'] ?? '') ?></strong></div>
      <div><span>Expires</span><strong><?= e($status['expires_at'] ?: 'Not activated') ?></strong></div>
      <div><span>Grace Until</span><strong><?= e($status['grace_until'] ?: 'N/A') ?></strong></div>
    </div>
    <h3>Unlocked Features</h3>
    <div class="devone-feature-list">
      <?php if ($features): foreach ($features as $feature): ?><?= devone_upgrade_feature_badge($feature) ?><?php endforeach; else: ?><?= devone_upgrade_feature_badge('free_core') ?><?= devone_upgrade_feature_badge('pages') ?><?= devone_upgrade_feature_badge('media') ?><?= devone_upgrade_feature_badge('themes') ?><?php endif; ?>
    </div>
  </section>

  <section class="card">
    <div class="section-head"><h2>Activate License</h2><span>Pro / Enterprise</span></div>
    <form method="post" class="settings-grid">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="activate_license">
      <label>License Key
        <input class="devone-license-key-input" name="license_key" placeholder="D1PRO-XXXX-XXXX-XXXX-XXXX" autocomplete="off" required>
        <small>Your key will be validated through the official DevOne license server.</small>
      </label>
      <button>Activate License</button>
    </form>
  </section>
</div>

<section class="card" id="devone-purchase-section">
  <div class="section-head"><h2>Purchase a License</h2><span>Founder launch pricing</span></div>
  <p class="muted">Buy a yearly DevOne Pro or Enterprise license without leaving this admin screen. Founder launch pricing is available for early customers. After successful payment, your license key is generated by the official DevOne license server and emailed to you.</p>
  <p class="devone-policy-warning"><strong>Non-refundable license notice:</strong> DevOne Pro and Enterprise license purchases are final and non-refundable once the license key has been issued, delivered, or activated, except where required by law.</p>
  <table class="devone-purchase-table">
    <thead><tr><th>Plan</th><th>Includes</th><th>Price</th><th>Action</th></tr></thead>
    <tbody>
      <tr>
        <td><strong>DevOne Pro</strong><br><small class="muted">For businesses and agencies starting with multisite.</small></td>
        <td><small>Multisite, custom domains, private site themes, premium updates.</small></td>
        <td><div class="devone-price-stack"><span class="devone-launch-badge">Founder launch</span><strong data-devone-price="pro" data-default-price="$99/year">$99/year</strong><small class="muted">Regular: <s>$149/year</s></small></div></td>
        <td><button type="button" class="devone-buy-plan" data-plan="pro">Buy Pro</button></td>
      </tr>
      <tr>
        <td><strong>DevOne Enterprise</strong><br><small class="muted">For agencies, teams, and high-control client networks.</small></td>
        <td><small>Everything in Pro plus white label, audit logs, agency dashboard, and advanced roles.</small></td>
        <td><div class="devone-price-stack"><span class="devone-launch-badge">Founder launch</span><strong data-devone-price="enterprise" data-default-price="$399/year">$399/year</strong><small class="muted">Regular: <s>$499/year</s></small></div></td>
        <td><button type="button" class="devone-buy-plan" data-plan="enterprise">Buy Enterprise</button></td>
      </tr>
    </tbody>
  </table>
  <p class="muted"><small>Payments are processed by Stripe. DevOne does not store card numbers in this CMS installation.</small></p>
  <p class="muted"><small><span id="devoneStripeApiStatus">Checking Stripe purchase API…</span></small></p>
</section>

<div class="devone-modal-backdrop" id="devonePurchaseModal" aria-hidden="true">
  <div class="devone-modal" role="dialog" aria-modal="true" aria-labelledby="devonePurchaseTitle">
    <div class="devone-modal-head"><div><h2 id="devonePurchaseTitle">Purchase DevOne License</h2><p class="muted" id="devonePurchasePlan">Secure embedded Stripe checkout.</p></div><button type="button" class="devone-modal-close" id="devonePurchaseClose">Close</button></div>
    <div class="devone-purchase-message" id="devonePurchaseMessage"></div>
    <div class="devone-purchase-form" id="devonePurchaseForm">
      <label>Your Name<input id="devoneBuyerName" value="<?= e($purchaseName) ?>" autocomplete="name"></label>
      <label>Email for License Key<input id="devoneBuyerEmail" type="email" value="<?= e($purchaseEmail) ?>" autocomplete="email" required></label>
      <button type="button" id="devoneStartPayment">Continue to Payment</button>
    </div>
    <div id="devone-stripe-checkout" class="devone-hidden"></div>
  </div>
</div>

<section class="card">
  <div class="section-head"><h2>License Tools</h2><span>Yearly certificate</span></div>
  <div class="inline-actions">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="refresh_license"><button class="secondary">Refresh License Now</button></form>
    <form method="post" onsubmit="return confirm('Clear the local license data from this installation?');"><?= csrf_field() ?><input type="hidden" name="action" value="clear_license"><button class="danger">Clear Local License</button></form>
  </div>
  <p class="muted">DevOne does not need to call the license server on every page load. The signed certificate is checked locally until its expiration date. Premium downloads and updates should still perform a live check later.</p>
</section>

<script>
(function(){
  const purchase = <?= json_encode($purchaseConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const modal = document.getElementById('devonePurchaseModal');
  const msg = document.getElementById('devonePurchaseMessage');
  const checkoutBox = document.getElementById('devone-stripe-checkout');
  const form = document.getElementById('devonePurchaseForm');
  const startBtn = document.getElementById('devoneStartPayment');
  const closeBtn = document.getElementById('devonePurchaseClose');
  const planLabel = document.getElementById('devonePurchasePlan');
  let selectedPlan = 'pro';
  let stripe = null;
  let checkout = null;
  let currentSession = '';
  let poller = null;
  let paid = false;

  function showMessage(text, type){
    msg.textContent = text || '';
    msg.className = 'devone-purchase-message ' + (type || '');
  }
  function api(path){ return String(purchase.apiBase || '').replace(/\/$/,'') + path; }
  async function fetchJson(url, options){
    let res;
    try {
      res = await fetch(url, options || {});
    } catch (networkError) {
      throw new Error('Could not reach the official DevOne payment API. Check that the license server is online, SSL is valid, and the latest license server patch is installed.');
    }
    let json;
    try { json = await res.json(); }
    catch (parseError) { throw new Error('The official DevOne payment API returned an invalid response. Check the endpoint file, PHP errors, or Cloudflare/SSL settings.'); }
    if (!res.ok && !json.message) { json.message = 'License server payment API returned HTTP ' + res.status; }
    return json;
  }
  function loadStripe(){
    return new Promise((resolve,reject)=>{
      if (window.Stripe) return resolve();
      const s=document.createElement('script');
      s.src='https://js.stripe.com/v3/';
      s.onload=()=>resolve();
      s.onerror=()=>reject(new Error('Could not load Stripe.js'));
      document.head.appendChild(s);
    });
  }
  async function loadConfig(){
    const json = await fetchJson(api('/api/stripe-config.php'), {headers:{'Accept':'application/json'}});
    if (!json.ok && !json.publishable_key) throw new Error(json.message || 'Stripe purchases are not available yet.');
    if (!json.publishable_key) throw new Error('Stripe publishable key is missing on the license server.');
    if (json.plans) {
      Object.keys(json.plans).forEach(plan=>{
        const el = document.querySelector('[data-devone-price="'+plan+'"]');
        if (el && json.plans[plan].price_label) el.textContent = json.plans[plan].price_label;
      });
    }
    return json;
  }
  async function refreshPrices(){
    const statusEl = document.getElementById('devoneStripeApiStatus');
    try {
      const cfg = await loadConfig();
      if (statusEl) statusEl.textContent = cfg.publishable_key ? 'Ready.' : 'Stripe is not configured on the license server yet.';
    } catch(e){
      document.querySelectorAll('[data-devone-price]').forEach(el=>{ if (el.dataset.defaultPrice) el.textContent = el.dataset.defaultPrice; });
      if (statusEl) statusEl.textContent = e.message || 'Payment API unavailable.';
    }
  }
  async function startPayment(){
    try {
      showMessage('', '');
      paid = false;
      startBtn.disabled = true;
      startBtn.textContent = 'Loading Stripe...';
      const email = document.getElementById('devoneBuyerEmail').value.trim();
      const name = document.getElementById('devoneBuyerName').value.trim();
      if (!email || !/^\S+@\S+\.\S+$/.test(email)) throw new Error('Enter a valid email so we can send your license key.');
      const config = await loadConfig();
      await loadStripe();
      stripe = window.Stripe(config.publishable_key);
      startBtn.textContent = 'Creating checkout...';
      const data = await fetchJson(api('/api/stripe-create-session.php'), {method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify({plan:selectedPlan, customer_email:email, customer_name:name, install_id:purchase.installId, site_url:purchase.siteUrl, domain:purchase.domain, return_url:purchase.returnUrl})});
      if (!data.ok) throw new Error(data.message || 'Could not create Stripe checkout.');
      currentSession = data.session_id || '';
      form.classList.add('devone-hidden');
      checkoutBox.classList.remove('devone-hidden');
      checkoutBox.innerHTML = '';
      if (checkout && checkout.destroy) { try { checkout.destroy(); } catch(e){} }
      checkout = await stripe.initEmbeddedCheckout({
        fetchClientSecret: async () => data.client_secret,
        onComplete: function(){ handleComplete(); }
      });
      checkout.mount('#devone-stripe-checkout');
      startPolling();
      showMessage('Complete your payment securely below. If successful, your license key will be emailed automatically.', 'ok');
    } catch(e) {
      showMessage(e.message || 'Payment could not be started. Please try again.', 'err');
      startBtn.disabled = false;
      startBtn.textContent = 'Continue to Payment';
    }
  }
  async function checkSession(){
    if (!currentSession) return;
    try {
      const json = await fetchJson(api('/api/stripe-session-status.php?session_id='+encodeURIComponent(currentSession)), {headers:{'Accept':'application/json'}});
      if (json.payment_status === 'paid' || json.status === 'complete') {
        paid = true;
        if (poller) clearInterval(poller);
        showMessage('Payment successful, please check your email for your license key.', 'ok');
        checkoutBox.classList.add('devone-hidden');
        form.classList.add('devone-hidden');
      } else if (json.status === 'expired') {
        if (poller) clearInterval(poller);
        showMessage('Payment was declined or expired. Please try another form of payment.', 'err');
        form.classList.remove('devone-hidden');
        checkoutBox.classList.add('devone-hidden');
      }
    } catch(e) { /* keep polling quietly */ }
  }
  function startPolling(){ if (poller) clearInterval(poller); poller=setInterval(checkSession, 3500); }
  function handleComplete(){ checkSession(); }
  function openModal(plan){
    selectedPlan = plan || 'pro';
    paid = false;
    currentSession = '';
    planLabel.textContent = selectedPlan === 'enterprise' ? 'DevOne Enterprise yearly license' : 'DevOne Pro yearly license';
    modal.classList.add('open');
    modal.setAttribute('aria-hidden','false');
    form.classList.remove('devone-hidden');
    checkoutBox.classList.add('devone-hidden');
    checkoutBox.innerHTML='';
    showMessage('', '');
    startBtn.disabled=false;
    startBtn.textContent='Continue to Payment';
  }
  function closeModal(){
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden','true');
    if (poller) clearInterval(poller);
    if (checkout && checkout.destroy) { try { checkout.destroy(); } catch(e){} }
    if (currentSession && !paid) { showMessage('Payment was not completed. Please try another form of payment when ready.', 'err'); }
  }
  document.querySelectorAll('.devone-buy-plan').forEach(btn=>btn.addEventListener('click',()=>openModal(btn.dataset.plan || 'pro')));
  if (startBtn) startBtn.addEventListener('click', startPayment);
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  refreshPrices();
})();
</script>


<?php devone_admin_footer();
