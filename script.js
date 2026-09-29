/* =====================================================================
   ARTIC — E-Commerce Commission & Creative Asset Monitoring Portal
   ===================================================================== */

/* ------------------ Server-backed State ------------------ */

const API_BASE = new URL('api/', document.baseURI).toString().replace(/\/$/, '');

let PAYPAL_CONFIG_CACHE = null;
let PAYPAL_SDK_PROMISE = null;
let PAYPAL_RENDERED_COMMISSION = null;
let PAYPAL_RENDERED_ROOT = null;

const APP = {
  db: {
    users: [],
    listings: [],
    commissions: [],
    inviteCodes: [],
    notifications: [],
    auditlog: [],
    integrationLogs: [],
    messages: []
  },
  analytics: { artistId: null, data: null, loading: false, error: null },
  session: null,
  me: null,
  csrf: null,
  currentPortal: 'user',
  notifOpen: false,
  browseCat: 'All',
  browseQuery: '',
  dashTab: 'overview'
};

/* ------------------ Utilities ------------------ */

function nowISO() {
  return new Date().toISOString();
}

function fmtDate(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString(undefined, {
    month: 'short', day: 'numeric', year: 'numeric'
  });
}

function fmtDateTime(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString(undefined, {
    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
  });
}

function fmtMoney(n) {
  return '$' + Number(n || 0).toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2
  });
}

function esc(s) {
  return s == null ? '' : String(s).replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
  }[c]));
}

function initials(name) {
  return (name || '?').split(' ').map(p => p[0]).join('').toUpperCase().slice(0, 2);
}

function getUser(id) {
  return APP.db.users.find(u => String(u.id) === String(id)) || null;
}

function getListing(id) {
  return APP.db.listings.find(l => String(l.id) === String(id)) || null;
}

function getCommission(id) {
  return APP.db.commissions.find(c => String(c.id) === String(id)) || null;
}

function currentUser() {
  return APP.me || (APP.session ? getUser(APP.session) : null);
}

async function apiRequest(endpoint, options = {}) {
  const opts = { credentials: 'same-origin', ...options };
  const headers = new Headers(opts.headers || {});
  if (!headers.has('Accept')) headers.set('Accept', 'application/json');

  if (opts.body && typeof opts.body !== 'string' && !(opts.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json');
    opts.body = JSON.stringify(opts.body);
  }

  if (opts.body && !headers.has('Content-Type') && !(opts.body instanceof FormData)) {
    headers.set('Content-Type', 'application/json');
  }

  opts.headers = headers;

  let response;
  try {
    response = await fetch(`${API_BASE}/${endpoint}`, opts);
  } catch (err) {
    throw new Error('Unable to reach the Artic server. Confirm that Apache/PHP is running.');
  }

  let payload = null;
  try {
    payload = await response.json();
  } catch (err) {
    throw new Error(`The Artic server returned an invalid response (${response.status}).`);
  }

  if (!response.ok || !payload.success) {
    const error = new Error(payload.message || `Request failed with HTTP ${response.status}.`);
    error.status = response.status;
    error.data = payload.data || null;
    throw error;
  }

  return payload;
}

async function loadDB() {
  const response = await apiRequest('bootstrap.php');
  const data = response.data || {};

  APP.db = data.db || APP.db;
  APP.me = data.currentUser || null;
  APP.session = APP.me ? APP.me.id : null;
  APP.csrf = data.csrf || null;
}

async function loadMessages() {
  const response = await apiRequest('messages.php');
  APP.db.messages = response.data?.messages || [];
  return APP.db.messages;
}

function messagesForCommission(commissionId) {
  return (APP.db.messages || []).filter(m => String(m.commissionId) === String(commissionId));
}

function csrfBody(extra = {}) {
  return { ...extra, csrf: APP.csrf };
}

function handleApiError(error) {
  console.error(error);
  flash(error.message || 'Something went wrong.');
}

/* ------------------ UI Components & Helpers ------------------ */


function flash(msg) {
  const root = document.getElementById('toast-root');
  const toast = document.createElement('div');
  toast.className = 'toast';
  toast.textContent = msg;
  root.appendChild(toast);
  requestAnimationFrame(() => toast.classList.add('show'));
  setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => toast.remove(), 300);
  }, 2800);
}

function openModal(title, bodyHTML) {
  document.getElementById('modal-root').innerHTML = `
    <div class="modal-overlay" onclick="closeModal()">
      <div class="modal-box" onclick="event.stopPropagation()">
        <div class="modal-head">
          <h3>${esc(title)}</h3>
          <span class="modal-close" onclick="closeModal()">&times;</span>
        </div>
        <div class="modal-body">${bodyHTML}</div>
      </div>
    </div>`;
}

function closeModal() {
  document.getElementById('modal-root').innerHTML = '';
}

/* ------------------ Header Component ------------------ */

function renderHeader() {
  const u = currentUser();
  const unreadCount = u ? APP.db.notifications.filter(n => n.userId === u.id && !n.read).length : 0;

  document.getElementById('header').innerHTML = `
    <div class="wrap headbar">
      <div class="brand" onclick="navigate('#/')" aria-label="Artic home">
        <img class="brand-logo-image" src="assets/artic-logo.png" alt="Artic logo">
      </div>

      <nav class="nav-links">
        <a class="nav-link ${location.hash === '#/browse' ? 'active' : ''}" onclick="navigate('#/browse')">Explore</a>
        ${u ? `<a class="nav-link ${location.hash.includes('portal') ? 'active' : ''}" onclick="navigatePortal()">Dashboard</a><a class="nav-link ${location.hash.startsWith('#/messages') ? 'active' : ''}" onclick="navigate('#/messages')">Messages</a>` : ''}
      </nav>

      <div class="head-actions">
        ${u ? `
          <div style="position:relative;">
            <button class="btn btn-secondary btn-sm" onclick="toggleNotifications()">
              🔔 ${unreadCount > 0 ? `<span style="background:var(--accent-pink);color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;">${unreadCount}</span>` : ''}
            </button>
            ${APP.notifOpen ? renderNotificationDropdown(u) : ''}
          </div>

          <div class="portal-pill">${u.role.toUpperCase()}</div>
          <button class="btn btn-secondary btn-sm" onclick="navigate('#/account')" style="display:inline-flex;align-items:center;gap:7px;">${profileAvatarMarkup(u, 24)} Account</button>
          <button class="btn btn-secondary btn-sm" onclick="navigatePortal()">${esc(u.name)}</button>
          <button class="btn btn-danger btn-sm" onclick="logout()">Sign Out</button>
        ` : `
          <button class="btn btn-secondary btn-sm" onclick="navigate('#/account')">Sign In</button>
          <button class="btn btn-primary btn-sm" onclick="navigate('#/account?register=artist')">Join as Artist</button>
        `}
      </div>
    </div>`;
}

function renderNotificationDropdown(u) {
  const notifs = APP.db.notifications.filter(n => n.userId === u.id).slice(0, 8);
  return `
    <div class="notif-dropdown">
      <div style="padding:12px 16px; border-bottom:1px solid var(--border); font-weight:700; font-size:13px; display:flex; justify-content:space-between; align-items:center; gap:10px;">
        <span>Notifications</span>
        ${notifs.some(n => !n.read) ? `<button class="btn btn-secondary btn-sm" onclick="markAllNotificationsRead()">Mark all read</button>` : ''}
      </div>
      ${notifs.length === 0 ? `<div style="padding:16px; color:var(--text-muted); font-size:12px;">No notifications yet.</div>` : ''}
      ${notifs.map(n => `
        <div style="padding:12px 16px; border-bottom:1px solid var(--border); cursor:pointer; background:${n.read ? 'transparent' : 'rgba(6,182,212,0.05)'};"
             onclick="markNotifRead('${n.id}', '${n.commissionId}')">
          <div style="font-size:12.5px;">${esc(n.message)}</div>
          <div style="font-size:10.5px; color:var(--text-faint); margin-top:4px;">${fmtDateTime(n.at)}</div>
        </div>
      `).join('')}
    </div>`;
}

function toggleNotifications() {
  APP.notifOpen = !APP.notifOpen;
  renderHeader();
}

async function markAllNotificationsRead() {
  try {
    await apiRequest('notifications.php?action=mark_all_read', {
      method: 'POST',
      body: csrfBody()
    });
    await loadDB();
    APP.notifOpen = true;
    renderHeader();
    flash('All notifications marked as read.');
  } catch (error) {
    handleApiError(error);
  }
}

async function markNotifRead(id, commId) {
  try {
    await apiRequest('notifications.php?action=mark_read', {
      method: 'POST',
      body: csrfBody({ id })
    });

    await loadDB();
    APP.notifOpen = false;

    if (commId && commId !== 'null' && commId !== 'undefined') {
      navigate('#/commission/' + commId);
    } else {
      renderHeader();
    }
  } catch (error) {
    handleApiError(error);
  }
}

/* ------------------ Routing & View Controller ------------------ */

function navigate(hash) {
  location.hash = hash;
}

function navigatePortal() {
  const u = currentUser();
  if (!u) return navigate('#/account');
  if (u.role === 'client') navigate('#/user-portal');
  else if (u.role === 'artist') navigate('#/artist-portal');
  else if (u.role === 'admin') navigate('#/admin-portal');
}

function routeParts() {
  const h = location.hash || '#/';
  const clean = h.split('?')[0];
  return clean.replace(/^#\/?/, '').split('/').filter(Boolean);
}

function routeQuery() {
  const q = location.hash.split('?')[1] || '';
  const params = new URLSearchParams(q);
  return Object.fromEntries(params.entries());
}

function render() {
  renderHeader();
  const parts = routeParts();
  const page = parts[0] || 'home';
  const param = parts[1] || null;

  let html = '';
  if (page === 'home') html = viewHome();
  else if (page === 'browse') html = viewBrowse();
  else if (page === 'artist') html = viewArtistStorefront(param);
  else if (page === 'profile') html = viewPublicProfile(param);
  else if (page === 'commission') html = viewCommissionDetail(param);
  else if (page === 'user-portal') html = viewUserPortal();
  else if (page === 'artist-portal') html = viewArtistPortal();
  else if (page === 'admin-portal') html = viewAdminPortal();
  else if (page === 'messages') html = viewMessages();
  else if (page === 'account') html = viewAccount();
  else html = viewHome();

  document.getElementById('app').innerHTML = html;
  window.scrollTo(0, 0);
  queueMicrotask(() => renderPayPalButtons().catch(() => {}));
}

function profileAvatarMarkup(user, size = 56) {
  if (user && user.profileImageUrl) {
    return `<img src="${esc(user.profileImageUrl)}" alt="${esc(user.name || 'Profile')}" style="width:${size}px;height:${size}px;border-radius:50%;object-fit:cover;border:1px solid var(--border-light);background:var(--bg-input);" loading="lazy">`;
  }
  return `<div style="width:${size}px;height:${size}px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg, rgba(124,58,237,.35), rgba(6,182,212,.25));border:1px solid var(--border-light);font-weight:800;color:#fff;font-size:${Math.max(11, Math.round(size*.28))}px;">${esc(initials(user?.name || '?'))}</div>`;
}

function profileImagePanelMarkup(user, size = 112) {
  return `<div style="display:flex;align-items:center;gap:14px;">${profileAvatarMarkup(user, size)}<div><div style="font-size:18px;font-weight:800;">${esc(user?.name || 'Profile')}</div><div style="font-size:12px;color:var(--text-faint);text-transform:capitalize;">${esc(user?.role || '')}</div></div></div>`;
}

function viewPublicProfile(userId) {
  const profile = getUser(userId);
  if (!profile) return `<div class="wrap section"><div class="empty-state">Profile not found.</div></div>`;
  const listings = profile.role === 'artist' ? APP.db.listings.filter(l => String(l.artistId) === String(profile.id)) : [];
  return `
    <div class="wrap section">
      <div class="card" style="padding:28px;margin-bottom:24px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:18px;flex-wrap:wrap;">
          ${profileImagePanelMarkup(profile, 96)}
          ${profile.isVerified ? `<span class="badge-verified">✓ Verified Artist</span>` : ''}
        </div>
        <div style="margin-top:18px;color:var(--text-muted);line-height:1.7;max-width:760px;">${esc(profile.bio || 'No bio provided.')}</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">
          <span class="badge-unverified">${esc(profile.role)}</span>
          <span style="font-size:11px;color:var(--text-faint);padding-top:4px;">Member since ${fmtDate(profile.joined)}</span>
        </div>
      </div>
      ${profile.role === 'artist' ? `
        <div class="section-title" style="margin-bottom:14px;">Commission Tiers</div>
        ${listings.length ? `<div class="grid">${listings.map(renderListingCard).join('')}</div>` : `<div class="empty-state"><h4>No commission tiers yet</h4></div>`}
        <div class="section-title" style="margin:32px 0 14px;">Portfolio</div>
        ${(profile.portfolio && profile.portfolio.length) ? `<div class="grid">${profile.portfolio.map(renderPortfolioCard).join('')}</div>` : `<div class="empty-state"><h4>No portfolio pieces yet</h4><p>This artist has not published portfolio work.</p></div>`}
      ` : ''}
    </div>`;
}

/* ------------------ Views: Home Page ------------------ */

function viewHome() {
  const featured = APP.db.listings.slice(0, 3);
  return `
    <section class="hero">
      <div class="wrap hero-grid">
        <div>
          <h1>An Integrated Marketplace for <span>Digital Artists & Studios</span></h1>
          <p>Artic integrates briefs, approvals, single-use verification codes, and real-time monitoring logs into a unified creative platform.</p>
          <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <button class="btn btn-primary" onclick="navigate('#/browse')">Explore Commissions</button>
            <button class="btn btn-purple" onclick="navigate('#/account?register=artist')">Become Verified Artist</button>
          </div>
        </div>
        <div class="card" style="background:var(--bg-card); border-color:var(--border-light);">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <span style="font-size:12px; font-weight:700; color:var(--accent-cyan);">LIVE WORKFLOW MONITORING</span>
            <span class="stamp inreview">In Review</span>
          </div>
          <div style="font-weight:700; font-size:16px; margin-bottom:10px;">Commission Pipeline #c-101</div>
          <div style="font-size:12.5px; color:var(--text-muted); display:flex; flex-direction:column; gap:8px;">
            <div>✓ Client Brief & Escrow Locked ($650)</div>
            <div>✓ Layered PSD Files & WIP Uploaded</div>
            <div>⚡ Pending Client Approval & Delivery</div>
          </div>
        </div>
      </div>
    </section>

    <section class="section wrap">
      <div class="section-head">
        <div>
          <div class="section-title">Open for Commission</div>
          <div class="section-sub">Verified artists accepting new client requests</div>
        </div>
        <button class="btn btn-secondary btn-sm" onclick="navigate('#/browse')">View All</button>
      </div>
      <div class="grid">
        ${featured.map(renderListingCard).join('')}
      </div>
    </section>`;
}

/* ------------------ Views: Browse Marketplace ------------------ */

function viewBrowse() {
  const cats = ['All', 'Live2D', 'Illustration', '3D Asset', 'Animation'];
  let list = APP.db.listings.slice();

  if (APP.browseCat !== 'All') list = list.filter(l => l.category === APP.browseCat);
  if (APP.browseQuery.trim()) {
    const q = APP.browseQuery.toLowerCase();
    list = list.filter(l => l.title.toLowerCase().includes(q) || l.description.toLowerCase().includes(q));
  }

  return `
    <div class="wrap section">
      <div class="section-head">
        <div>
          <div class="section-title">Explore Open Slots</div>
          <div class="section-sub">Find verified creators across Live2D, 3D, and Illustration</div>
        </div>
      </div>

      <div style="display:flex; gap:12px; margin-bottom:24px; flex-wrap:wrap;">
        <input type="text" placeholder="Search commission titles, tags..." value="${esc(APP.browseQuery)}"
               style="max-width:320px; background:var(--bg-card); border:1px solid var(--border); padding:8px 14px; border-radius:6px; color:#fff;"
               oninput="APP.browseQuery = this.value; render();">
        <div style="display:flex; gap:6px;">
          ${cats.map(c => `
            <button class="btn btn-sm ${APP.browseCat === c ? 'btn-primary' : 'btn-secondary'}"
                    onclick="APP.browseCat='${c}'; render();">${c}</button>
          `).join('')}
        </div>
      </div>

      ${list.length ? `<div class="grid">${list.map(renderListingCard).join('')}</div>` : `
        <div class="empty-state">
          <h4>No commissions found</h4>
          <p>Try switching categories or clearing your search term.</p>
        </div>`}
    </div>`;
}

function renderListingCard(l) {
  const artist = getUser(l.artistId);
  const openSlots = l.slotsTotal - l.slotsUsed;
  return `
    <div class="card">
      <div>
        ${l.coverImageUrl ? (l.coverIsImage ? `<a href="${esc(l.coverImageUrl)}" target="_blank" rel="noopener noreferrer"><img src="${esc(l.coverImageUrl)}" alt="${esc(l.title)}" style="width:100%;height:190px;object-fit:cover;border-radius:8px;margin-bottom:12px;border:1px solid var(--border);" loading="lazy"></a>` : `<div class="card-banner" style="display:flex;align-items:center;justify-content:center;font-weight:800;letter-spacing:1px;">PSD</div>`) : `<div class="card-banner"></div>`}
        <div class="card-category">${esc(l.category)}</div>
        <div class="card-title">${esc(l.title)}</div>
        <div class="card-artist" onclick="navigate('#/artist/${l.artistId}')" style="cursor:pointer;">
          by ${esc(artist ? artist.name : 'Unknown')}
          ${artist && artist.isVerified ? `<span class="badge-verified">✓ Verified</span>` : ''}
        </div>
        <div class="card-desc">${esc(l.description)}</div>
      </div>
      <div>
        <div class="card-footer">
          <div class="card-price">${fmtMoney(l.price)}</div>
          <div style="font-size:12px; color:${openSlots > 0 ? 'var(--accent-green)' : 'var(--accent-red)'}; font-weight:600;">
            ${openSlots > 0 ? `${openSlots} slots open` : 'Queue Full'}
          </div>
        </div>
        <button class="btn btn-primary btn-block btn-sm" style="margin-top:12px;"
                onclick="openRequestModal('${l.id}')" ${openSlots <= 0 ? 'disabled' : ''}>
          ${openSlots > 0 ? 'Request Commission' : 'Queue Full'}
        </button>
      </div>
    </div>`;
}

function renderPortfolioCard(item) {
  const owner = getUser(item.artistId);
  return `<div class="card">
    ${item.url && item.isImage ? `<a href="${esc(item.url)}" target="_blank" rel="noopener noreferrer"><img src="${esc(item.url)}" alt="${esc(item.title)}" style="width:100%;height:190px;object-fit:cover;border-radius:8px;margin-bottom:12px;border:1px solid var(--border);" loading="lazy"></a>` : `<div style="height:190px;border-radius:8px;background:var(--bg-input);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-weight:900;color:var(--accent-purple);font-size:24px;margin-bottom:12px;">${item.isPsd ? 'PSD' : 'ART'}</div>`}
    <div style="font-weight:800;">${esc(item.title)}</div>
    ${owner ? `<button class="btn btn-secondary btn-sm" style="margin-top:10px;" onclick="navigate('#/profile/${owner.id}')">View Artist Profile</button>` : ''}
    ${item.description ? `<div class="card-desc">${esc(item.description)}</div>` : ''}
    <div style="font-size:10.5px;color:var(--text-faint);margin-top:8px;">${esc(item.fileName || 'Portfolio artwork')}</div>
  </div>`;
}

/* ------------------ Views: Artist Public Storefront ------------------ */

function viewArtistStorefront(artistId) {
  return viewPublicProfile(artistId);
}

/* ------------------ Views: Account Sign In / Sign Up ------------------ */

function viewAccount() {
  const u = currentUser();

  if (u) {
    return `
      <div class="wrap section" style="max-width:900px;">
        <div class="section-head">
          <div>
            <div class="section-title">Account</div>
            <div class="section-sub">Your Artic account is connected to the SQL Server database.</div>
          </div>
          <button class="btn btn-secondary btn-sm" onclick="navigatePortal()">Back to Dashboard</button>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px;">
          <div class="card">
            <div style="font-weight:700; font-size:18px; margin-bottom:16px;">Account Profile</div>
            <form enctype="multipart/form-data" onsubmit="handleUpdateProfile(event)">
              <div style="display:flex;align-items:center;gap:16px;margin-bottom:18px;">
                ${profileAvatarMarkup(u, 92)}
                <div><div style="font-weight:700;">Public profile picture</div><div style="font-size:11px;color:var(--text-faint);margin-top:4px;">JPG, PNG, GIF, or WEBP · max 50 MB.</div></div>
              </div>
              <div class="field">
                <label>Profile Picture</label>
                <input type="file" name="profileImage" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp">
              </div>
              <div class="field">
                <label>Display Name</label>
                <input type="text" name="name" value="${esc(u.name)}" required minlength="2" maxlength="100">
              </div>
              <div class="field">
                <label>Bio</label>
                <textarea name="bio" maxlength="2000" placeholder="Tell people about your creative work...">${esc(u.bio || '')}</textarea>
              </div>
              <div class="field">
                <label>Email</label>
                <input type="email" value="${esc(u.email || '')}" readonly>
              </div>
              <div class="field">
                <label>Role</label>
                <input type="text" value="${esc(u.role)}" readonly>
              </div>
              <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <button class="btn btn-primary" type="submit">Save Profile</button>
                <button class="btn btn-secondary" type="button" onclick="navigate('#/profile/${u.id}')">View Public Profile</button>
              </div>
            </form>
          </div>

          <div class="card">
            <div style="font-weight:700; font-size:18px; margin-bottom:16px;">Change Password</div>
            <form onsubmit="handleChangePassword(event)">
              <div class="field">
                <label>Current Password</label>
                <input type="password" name="currentPassword" required autocomplete="current-password">
              </div>
              <div class="field">
                <label>New Password</label>
                <input type="password" name="newPassword" required minlength="8" maxlength="72" autocomplete="new-password">
              </div>
              <div class="field">
                <label>Confirm New Password</label>
                <input type="password" name="confirmPassword" required minlength="8" maxlength="72" autocomplete="new-password">
              </div>
              <button class="btn btn-primary btn-block" type="submit">Update Password</button>
            </form>
          </div>
        </div>
      </div>`;
  }

  const q = routeQuery();
  const isArtistReg = q.register === 'artist';

  return `
    <div class="wrap section" style="max-width:800px;">
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:32px; align-items:start;">
        <div class="card">
          <div style="font-weight:700; font-size:18px; margin-bottom:16px;">Sign In</div>
          <p style="color:var(--text-muted); font-size:13px; margin-bottom:16px;">
            Sign in with the email and password registered in Artic.
          </p>
          <form onsubmit="handleLogin(event)">
            <div class="field">
              <label>Email Address</label>
              <input type="email" name="email" required autocomplete="email" placeholder="you@example.com">
            </div>
            <div class="field">
              <label>Password</label>
              <input type="password" name="password" required minlength="8" autocomplete="current-password" placeholder="Enter your password">
            </div>
            <button class="btn btn-primary btn-block" type="submit">Sign In</button>
          </form>
        </div>

        <div class="card">
          <div style="font-weight:700; font-size:18px; margin-bottom:16px;">Create New Account</div>
          <form onsubmit="handleRegister(event)">
            <div class="field">
              <label>Full Name</label>
              <input type="text" name="name" required minlength="2" maxlength="100" placeholder="e.g. Alex Rivera">
            </div>
            <div class="field">
              <label>Email Address</label>
              <input type="email" name="email" required maxlength="190" placeholder="alex@example.com">
            </div>
            <div class="field">
              <label>Password</label>
              <input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="At least 8 characters">
            </div>
            <div class="field">
              <label>Confirm Password</label>
              <input type="password" name="confirmPassword" required minlength="8" maxlength="72" autocomplete="new-password" placeholder="Repeat your password">
            </div>
            <div class="field">
              <label>Role</label>
              <select name="role" onchange="toggleArtistCodeField(this.value)">
                <option value="client" ${!isArtistReg ? 'selected' : ''}>Client (Request Commissions)</option>
                <option value="artist" ${isArtistReg ? 'selected' : ''}>Artist (Sell & Manage Queue)</option>
              </select>
            </div>
            <div id="verification-code-field" class="field" style="${isArtistReg ? '' : 'display:none;'}">
              <label>VGen Invite / Verification Code</label>
              <input type="text" name="inviteCode" maxlength="30" placeholder="ARTIC-XXXX-XXXX" style="font-family:var(--font-mono); text-transform:uppercase;">
              <div class="hint">A valid unused code activates verified status. Leave blank to register as an unverified artist.</div>
            </div>
            <button class="btn btn-primary btn-block" type="submit">Create Account</button>
          </form>
        </div>
      </div>
    </div>`;
}

async function handleUpdateProfile(e) {
  e.preventDefault();
  const form = e.target;
  const fd = new FormData(form);
  fd.set('csrf', APP.csrf || '');
  const image = fd.get('profileImage');
  if (image instanceof File && image.size > 0 && image.size > 50 * 1024 * 1024) {
    flash('Profile pictures are limited to 50 MB.');
    return;
  }
  const button = form.querySelector('button[type="submit"]');
  if (button) button.disabled = true;
  try {
    const response = await apiRequest('auth.php?action=update_profile', { method:'POST', body:fd });
    APP.me = response.data?.user || APP.me;
    await loadDB();
    render();
    flash('Account profile updated successfully.');
  } catch (error) {
    handleApiError(error);
    if (button) button.disabled = false;
  }
}

function toggleArtistCodeField(role) {
  const field = document.getElementById('verification-code-field');
  if (field) field.style.display = (role === 'artist') ? 'block' : 'none';
}

async function handleChangePassword(e) {
  e.preventDefault();

  const form = e.currentTarget || e.target;
  const currentPassword = String(form.elements.namedItem('currentPassword')?.value || '');
  const newPassword = String(form.elements.namedItem('newPassword')?.value || '');
  const confirmPassword = String(form.elements.namedItem('confirmPassword')?.value || '');

  if (newPassword !== confirmPassword) {
    flash('New passwords do not match.');
    return;
  }

  const submitButton = form.querySelector('button[type="submit"]');
  if (submitButton) submitButton.disabled = true;

  try {
    const response = await apiRequest('auth.php?action=change_password', {
      method: 'POST',
      body: csrfBody({ currentPassword, newPassword, confirmPassword })
    });

    APP.csrf = response.data?.csrf || APP.csrf;
    flash('Password changed successfully.');
    form.reset();
  } catch (error) {
    handleApiError(error);
  } finally {
    if (submitButton) submitButton.disabled = false;
  }
}

async function handleLogin(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  const email = String(fd.get('email') || '').trim().toLowerCase();
  const password = String(fd.get('password') || '');

  if (!email || !password) {
    flash('Email and password are required.');
    return;
  }

  try {
    const response = await apiRequest('auth.php?action=login', {
      method: 'POST',
      body: { email, password }
    });

    const loggedInUser = response.data.user;
    APP.me = loggedInUser;
    APP.session = loggedInUser.id;
    APP.csrf = response.data.csrf;

    if (!Array.isArray(APP.db.users)) APP.db.users = [];
    const existingIndex = APP.db.users.findIndex(u => String(u.id) === String(loggedInUser.id));
    if (existingIndex >= 0) APP.db.users[existingIndex] = loggedInUser;
    else APP.db.users.push(loggedInUser);

    try {
      await loadDB();
      if (!APP.me) {
        APP.me = loggedInUser;
        APP.session = loggedInUser.id;
        APP.csrf = response.data.csrf;
      }
    } catch (refreshError) {
      console.warn('Login succeeded; bootstrap refresh failed:', refreshError);
    }

    // Preserve the authenticated user even if the refresh returned no session.
    APP.me = loggedInUser;
    APP.session = loggedInUser.id;
    APP.csrf = response.data.csrf;

    flash(`Welcome back, ${loggedInUser.name}!`);
    navigatePortal();
  } catch (error) {
    handleApiError(error);
  }
}

async function handleRegister(e) {
  e.preventDefault();

  const form = e.currentTarget || e.target;
  const nameInput = form.elements.namedItem('name');
  const emailInput = form.elements.namedItem('email');
  const passwordInput = form.elements.namedItem('password');
  const confirmPasswordInput = form.elements.namedItem('confirmPassword');
  const roleInput = form.elements.namedItem('role');
  const inviteCodeInput = form.elements.namedItem('inviteCode');

  if (!nameInput || !emailInput || !passwordInput || !confirmPasswordInput || !roleInput) {
    flash('Registration form could not be read. Please reload the page.');
    return;
  }

  const name = nameInput.value.trim();
  const email = emailInput.value.trim().toLowerCase();
  const password = passwordInput.value;
  const confirmPassword = confirmPasswordInput.value;
  const role = roleInput.value;
  const inviteCode = inviteCodeInput ? inviteCodeInput.value.trim().toUpperCase() : '';

  // Read both password fields directly from the form controls instead of FormData.
  // This also makes the comparison reliable when browsers/autofill modify a field.
  if (password !== confirmPassword) {
    confirmPasswordInput.setCustomValidity('Passwords do not match.');
    confirmPasswordInput.reportValidity();
    flash('Passwords do not match.');
    return;
  }

  confirmPasswordInput.setCustomValidity('');

  try {
    const response = await apiRequest('auth.php?action=register', {
      method: 'POST',
      body: {
        name,
        email,
        password,
        confirmPassword,
        role,
        inviteCode: role === 'artist' ? inviteCode : ''
      }
    });

    const createdUser = response.data.user;
    APP.me = createdUser;
    APP.session = createdUser.id;
    APP.csrf = response.data.csrf;

    if (!Array.isArray(APP.db.users)) APP.db.users = [];
    const existingIndex = APP.db.users.findIndex(u => String(u.id) === String(createdUser.id));
    if (existingIndex >= 0) APP.db.users[existingIndex] = createdUser;
    else APP.db.users.push(createdUser);

    try {
      await loadDB();
      if (!APP.me) {
        APP.me = createdUser;
        APP.session = createdUser.id;
        APP.csrf = response.data.csrf;
      }
    } catch (refreshError) {
      console.warn('Registration succeeded; bootstrap refresh failed:', refreshError);
    }

    // Never let a failed background refresh turn a newly-created account
    // back into an anonymous visitor.
    APP.me = createdUser;
    APP.session = createdUser.id;
    APP.csrf = response.data.csrf;

    flash(`Welcome to Artic, ${createdUser.name}! You are now signed in.`);
    navigatePortal();
  } catch (error) {
    handleApiError(error);
  }
}

async function logout() {
  try {
    if (APP.session && APP.csrf) {
      await apiRequest('auth.php?action=logout', {
        method: 'POST',
        body: csrfBody()
      });
    }
  } catch (error) {
    console.warn(error);
  } finally {
    APP.session = null;
    APP.me = null;
    APP.csrf = null;
    APP.notifOpen = false;
    flash('Signed out.');
    navigate('#/');
  }
}

/* =====================================================================
   PORTAL 1: USER / CLIENT MONITORING SYSTEM
   ===================================================================== */

function viewUserPortal() {
  const u = currentUser();
  if (!u || u.role !== 'client') return `<div class="wrap section"><div class="empty-state">Client access required.</div></div>`;

  const myCommissions = APP.db.commissions.filter(c => c.clientId === u.id);

  return `
    <div class="wrap section">
      <div class="section-head">
        <div>
          <div class="section-title">Client Monitoring Portal</div>
          <div class="section-sub">Track active orders, review draft deliverables, and release payments.</div>
        </div>
      </div>

      <div class="kpi-grid">
        <div class="kpi-card">
          <div class="kpi-val">${myCommissions.length}</div>
          <div class="kpi-label">Total Orders</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-val">${myCommissions.filter(c => ['accepted', 'inprogress', 'inreview'].includes(c.status)).length}</div>
          <div class="kpi-label">Active Queue</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-val">${myCommissions.filter(c => c.status === 'delivered').length}</div>
          <div class="kpi-label">Completed</div>
        </div>
      </div>

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>Commission</th>
              <th>Artist</th>
              <th>Price</th>
              <th>Status</th>
              <th>Payment</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            ${myCommissions.length === 0 ? `<tr><td colspan="6" style="text-align:center; color:var(--text-faint);">No active or past commissions found.</td></tr>` : ''}
            ${myCommissions.map(c => {
              const listing = getListing(c.listingId);
              const artist = getUser(c.artistId);
              return `
                <tr>
                  <td><b>${esc(listing ? listing.title : 'Commission')}</b></td>
                  <td>${esc(artist ? artist.name : 'Unknown')}</td>
                  <td>${fmtMoney(c.price)}</td>
                  <td><span class="stamp ${c.status}">${c.status}</span></td>
                  <td>${c.paymentStatus === 'paid' ? '<span style="color:var(--accent-green);font-weight:700;">Escrow Locked</span>' : c.paymentStatus === 'released' ? '<span style="color:var(--accent-green);font-weight:700;">Payment Released</span>' : '<span style="color:var(--accent-amber);">Unpaid</span>'}</td>
                  <td>
                    <button class="btn btn-secondary btn-sm" onclick="navigate('#/commission/${c.id}')">View Brief & Assets</button>
                    <button class="btn btn-secondary btn-sm" onclick="navigate('#/messages?commission=${c.id}'); markMessageThreadRead('${c.id}')">Message</button>
                  </td>
                </tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>
    </div>`;
}

/* =====================================================================
   PORTAL 2: ARTIST MONITORING & VERIFICATION SYSTEM
   ===================================================================== */

function viewArtistPortal() {
  const u = currentUser();
  if (!u || u.role !== 'artist') return `<div class="wrap section"><div class="empty-state">Artist access required.</div></div>`;

  const myCommissions = APP.db.commissions.filter(c => c.artistId === u.id);
  const myListings = APP.db.listings.filter(l => l.artistId === u.id);
  const totalEarnings = myCommissions.filter(c => ['paid', 'released'].includes(c.paymentStatus)).reduce((acc, c) => acc + c.price, 0);
  ensureArtistAnalytics();

  return `
    <div class="wrap section">
      <div class="section-head">
        <div>
          <div style="display:flex; align-items:center; gap:12px;">
            <div class="section-title">Artist Workspace & Verification System</div>
            ${u.isVerified ? `<span class="badge-verified">✓ Verified Creator</span>` : `<span class="badge-unverified">Unverified Account</span>`}
          </div>
          <div class="section-sub">Manage queue, upload assets, and invite authentic creators via single-use verification codes.</div>
        </div>
        <div>
          ${!u.isVerified ? `<button class="btn btn-purple btn-sm" onclick="openArtistVerifyModal()">Verify Account</button>` : ''}
          <button class="btn btn-primary btn-sm" onclick="openListingModal()">+ New Tier Listing</button>
        </div>
      </div>

      <!-- Verification Invite Code System Block -->
      <div class="invite-box">
        <div style="font-weight:700; color:var(--text-main); margin-bottom:4px;">Artist Verification & Single-Use Invites</div>
        <div style="font-size:12.5px; color:var(--text-muted); margin-bottom:12px;">
          ${u.isVerified
            ? 'You possess 2 single-use invite codes to bring genuine creators onto Artic. Once used by an artist during registration, the code expires permanently.'
            : 'Your account is unverified. Enter a VGen invite code below or request verification from an Admin.'}
        </div>

        ${u.isVerified ? `
          <div style="display:flex; gap:16px; flex-wrap:wrap;">
            ${u.inviteCodes.map((inv, idx) => `
              <div style="background:var(--bg-card); border:1px solid var(--border); padding:10px 14px; border-radius:6px; flex:1; min-width:220px;">
                <div style="font-size:11px; color:var(--text-faint); margin-bottom:4px;">INVITE CODE #${idx + 1}</div>
                <div style="display:flex; justify-content:space-between; align-items:center;">
                  <span class="code-badge">${inv.code}</span>
                  <span style="font-size:11px; font-weight:700; color:${inv.isUsed ? 'var(--accent-red)' : 'var(--accent-green)'};">
                    ${inv.isUsed ? 'EXPIRED / USED' : 'ACTIVE'}
                  </span>
                </div>
              </div>
            `).join('')}
          </div>
        ` : `
          <button class="btn btn-purple btn-sm" onclick="openArtistVerifyModal()">Enter Verification Code</button>
        `}
      </div>

      <div class="kpi-grid">
        <div class="kpi-card">
          <div class="kpi-val">${myCommissions.filter(c => c.status === 'requested').length}</div>
          <div class="kpi-label">New Brief Requests</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-val">${myCommissions.filter(c => ['accepted', 'inprogress', 'inreview'].includes(c.status)).length}</div>
          <div class="kpi-label">In Progress</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-val">${fmtMoney(totalEarnings)}</div>
          <div class="kpi-label">Gross Revenue</div>
        </div>
      </div>

      <div class="section-title" style="font-size:18px; margin-bottom:12px;">Active Queue</div>
      <div class="table-wrap" style="margin-bottom:32px;">
        <table class="table">
          <thead>
            <tr>
              <th>Client</th>
              <th>Tier Listing</th>
              <th>Price</th>
              <th>Status</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            ${myCommissions.length === 0 ? `<tr><td colspan="5" style="text-align:center; color:var(--text-faint);">Your queue is clear.</td></tr>` : ''}
            ${myCommissions.map(c => {
              const client = getUser(c.clientId);
              const listing = getListing(c.listingId);
              return `
                <tr>
                  <td>${esc(client ? client.name : 'Unknown')}</td>
                  <td>${esc(listing ? listing.title : 'Commission')}</td>
                  <td>${fmtMoney(c.price)}</td>
                  <td><span class="stamp ${c.status}">${c.status}</span></td>
                  <td>
                    <button class="btn btn-secondary btn-sm" onclick="navigate('#/commission/${c.id}')">Manage Order</button>
                    <button class="btn btn-secondary btn-sm" onclick="navigate('#/messages?commission=${c.id}')">Message</button>
                  </td>
                </tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
        <div class="section-title" style="font-size:18px; margin-bottom:12px;">Published Listings</div>
      </div>
      <div class="grid">
        ${myListings.map(renderListingCard).join('')}
      </div>

      <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-top:32px;">
        <div class="section-title" style="font-size:18px; margin:0 0 12px;">Portfolio</div>
        <button class="btn btn-purple btn-sm" onclick="openPortfolioModal()">+ Add Portfolio Piece</button>
      </div>
      ${(u.portfolio && u.portfolio.length) ? `<div class="grid">${u.portfolio.map(renderPortfolioCard).join('')}</div>` : `<div class="empty-state"><h4>Your portfolio is empty</h4><p>Upload artwork or layered PSD files to showcase your work.</p></div>`}

      <div class="section-title" style="font-size:18px; margin:32px 0 12px;">Artist Analytics</div>
      <div class="card" id="artist-analytics">${artistAnalyticsMarkup()}</div>
    </div>`;
}


function artistAnalyticsMarkup() {
  const state = APP.analytics || {};
  if (state.loading && !state.data) return '<div class="empty-state" style="border:0;">Loading artist analytics…</div>';
  if (state.error) return `<div class="empty-state" style="border:0;">${esc(state.error)}<br><button class="btn btn-secondary btn-sm" style="margin-top:10px;" onclick="loadArtistAnalytics(true)">Retry</button></div>`;
  if (!state.data) return '<div class="empty-state" style="border:0;">No analytics data yet.</div>';

  const s = state.data.summary || {};
  return `
    <div class="kpi-grid" style="margin-bottom:22px;">
      <div class="kpi-card"><div class="kpi-val">${s.totalCommissions || 0}</div><div class="kpi-label">Total Commissions</div></div>
      <div class="kpi-card"><div class="kpi-val">${s.completedCommissions || 0}</div><div class="kpi-label">Completed</div></div>
      <div class="kpi-card"><div class="kpi-val">${Number(s.completionRate || 0).toFixed(1)}%</div><div class="kpi-label">Completion Rate</div></div>
      <div class="kpi-card"><div class="kpi-val">${fmtMoney(s.revenue || 0)}</div><div class="kpi-label">Completed Revenue</div></div>
      <div class="kpi-card"><div class="kpi-val">${fmtMoney(s.averageOrderValue || 0)}</div><div class="kpi-label">Average Order</div></div>
      <div class="kpi-card"><div class="kpi-val">${Number(s.averageTurnaroundDays || 0).toFixed(1)}d</div><div class="kpi-label">Avg Turnaround</div></div>
    </div>
    <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; margin-bottom:10px; flex-wrap:wrap;">
      <div><div style="font-weight:800;">Commission Value vs Elapsed Workflow Time</div><div style="font-size:11.5px;color:var(--text-faint);">Each point is one commission. X = elapsed workflow time in days; Y = commission value.</div></div>
      <button class="btn btn-secondary btn-sm" onclick="loadArtistAnalytics(true)">↻ Refresh</button>
    </div>
    <div id="artist-scatter-wrap" style="overflow:auto;">${artistScatterSvg(state.data.scatter)}</div>
  `;
}

function artistScatterSvg(scatter) {
  const points = Array.isArray(scatter?.points) ? scatter.points : [];
  const width = 760, height = 360, padL = 62, padR = 24, padT = 28, padB = 52;
  const innerW = width - padL - padR, innerH = height - padT - padB;
  const maxX = Math.max(1, ...points.map(p => Number(p.x) || 0));
  const maxY = Math.max(1, ...points.map(p => Number(p.y) || 0));
  const x = v => padL + (Math.max(0, Number(v) || 0) / maxX) * innerW;
  const y = v => padT + innerH - (Math.max(0, Number(v) || 0) / maxY) * innerH;
  const xTicks = 5, yTicks = 5;
  const grid = [];
  for (let i = 0; i <= xTicks; i++) {
    const xv = (maxX / xTicks) * i;
    const px = x(xv);
    grid.push(`<line x1="${px}" y1="${padT}" x2="${px}" y2="${padT+innerH}" stroke="currentColor" opacity=".10"/><text x="${px}" y="${padT+innerH+22}" text-anchor="middle" fill="currentColor" opacity=".65" font-size="10">${xv.toFixed(xv < 10 ? 1 : 0)}</text>`);
  }
  for (let i = 0; i <= yTicks; i++) {
    const yv = (maxY / yTicks) * i;
    const py = y(yv);
    grid.push(`<line x1="${padL}" y1="${py}" x2="${padL+innerW}" y2="${py}" stroke="currentColor" opacity=".10"/><text x="${padL-10}" y="${py+3}" text-anchor="end" fill="currentColor" opacity=".65" font-size="10">${fmtMoney(yv)}</text>`);
  }
  const dots = points.map(p => {
    const cls = p.status === 'delivered' ? 'var(--accent-green)' : p.status === 'declined' ? 'var(--accent-red)' : 'var(--accent-cyan)';
    const title = `${esc(p.title)} · #${p.id} · ${Number(p.x).toFixed(1)} days · ${fmtMoney(p.y)} · ${esc(p.status)}`;
    return `<circle cx="${x(p.x)}" cy="${y(p.y)}" r="5.5" fill="${cls}" stroke="var(--bg-card)" stroke-width="2"><title>${title}</title></circle>`;
  }).join('');
  if (!points.length) return '<div class="empty-state" style="border:0;">No commissions yet, so there are no points to plot.</div>';
  return `<svg viewBox="0 0 ${width} ${height}" width="100%" role="img" aria-label="Artist commission scatter graph" style="min-width:620px;height:auto;color:var(--text-muted);background:var(--bg-input);border:1px solid var(--border);border-radius:10px;padding:4px;box-sizing:border-box;">
    ${grid.join('')}
    <line x1="${padL}" y1="${padT+innerH}" x2="${padL+innerW}" y2="${padT+innerH}" stroke="currentColor" opacity=".35"/>
    <line x1="${padL}" y1="${padT}" x2="${padL}" y2="${padT+innerH}" stroke="currentColor" opacity=".35"/>
    ${dots}
    <text x="${padL + innerW/2}" y="${height-10}" text-anchor="middle" fill="currentColor" opacity=".8" font-size="11">Elapsed workflow time (days)</text>
    <text x="15" y="${padT + innerH/2}" text-anchor="middle" transform="rotate(-90 15 ${padT + innerH/2})" fill="currentColor" opacity=".8" font-size="11">Commission value</text>
  </svg>`;
}

async function loadArtistAnalytics(force = false) {
  const u = currentUser();
  if (!u || u.role !== 'artist') return;
  if (!force && APP.analytics.artistId === u.id && APP.analytics.data) return;
  if (APP.analytics.loading) return;
  APP.analytics.loading = true;
  APP.analytics.error = null;
  APP.analytics.artistId = u.id;
  const target = document.getElementById('artist-analytics');
  if (target && !APP.analytics.data) target.innerHTML = artistAnalyticsMarkup();
  try {
    const response = await apiRequest('analytics.php');
    APP.analytics.data = response.data || null;
    APP.analytics.error = null;
  } catch (error) {
    APP.analytics.error = error?.message || 'Analytics could not be loaded.';
  } finally {
    APP.analytics.loading = false;
    const box = document.getElementById('artist-analytics');
    if (box) box.innerHTML = artistAnalyticsMarkup();
  }
}

function ensureArtistAnalytics() {
  const u = currentUser();
  if (!u || u.role !== 'artist') return;
  if (APP.analytics.artistId !== u.id || !APP.analytics.data) {
    loadArtistAnalytics();
  }
}

function openArtistVerifyModal() {
  openModal('Verify Artist Account', `
    <form onsubmit="handleVerifyAccount(event)">
      <div class="field">
        <label>Enter VGen Invite Code</label>
        <input type="text" name="code" required placeholder="ARTIC-XXXX-XXXX" style="font-family:var(--font-mono); text-transform:uppercase;">
      </div>
      <button class="btn btn-purple btn-block" type="submit">Verify Now</button>
    </form>
  `);
}

async function handleVerifyAccount(e) {
  e.preventDefault();
  const code = String(new FormData(e.target).get('code') || '').trim().toUpperCase();

  try {
    await apiRequest('auth.php?action=verify_artist', {
      method: 'POST',
      body: csrfBody({ code })
    });

    await loadDB();
    closeModal();
    flash('Congratulations! Your artist account is now verified.');
    render();
  } catch (error) {
    handleApiError(error);
  }
}

/* =====================================================================
   PORTAL 3: ADMIN SYSTEM & INTEGRATION MONITORING
   ===================================================================== */

function viewAdminPortal() {
  const u = currentUser();
  if (!u || u.role !== 'admin') return `<div class="wrap section"><div class="empty-state">Admin system authorization required.</div></div>`;

  const totalUsers = APP.db.users.length;
  const verifiedArtists = APP.db.users.filter(x => x.role === 'artist' && x.isVerified).length;
  const totalVolume = APP.db.commissions.filter(c => c.paymentStatus === 'paid').reduce((acc, c) => acc + c.price, 0);

  return `
    <div class="wrap section">
      <div class="section-head">
        <div>
          <div class="section-title">System Administration & Integration Monitor</div>
          <div class="section-sub">System-wide monitoring, verification governance, and API audit logs.</div>
        </div>
      </div>

      <div class="kpi-grid">
        <div class="kpi-card">
          <div class="kpi-val">${totalUsers}</div>
          <div class="kpi-label">Registered Users</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-val">${verifiedArtists}</div>
          <div class="kpi-label">Verified Artists</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-val">${APP.db.commissions.length}</div>
          <div class="kpi-label">Total Commissions</div>
        </div>
        <div class="kpi-card">
          <div class="kpi-val">${fmtMoney(totalVolume)}</div>
          <div class="kpi-label">Total Locked Escrow</div>
        </div>
      </div>

      <div class="tabs">
        <div class="tab ${APP.dashTab === 'overview' ? 'active' : ''}" onclick="APP.dashTab='overview'; render();">User Verification & Governance</div>
        <div class="tab ${APP.dashTab === 'codes' ? 'active' : ''}" onclick="APP.dashTab='codes'; render();">Invite Code Master Registry</div>
        <div class="tab ${APP.dashTab === 'audit' ? 'active' : ''}" onclick="APP.dashTab='audit'; render();">Audit Trail</div>
        <div class="tab ${APP.dashTab === 'apilogs' ? 'active' : ''}" onclick="APP.dashTab='apilogs'; render();">API Integration & Webhook Logs</div>
      </div>

      ${APP.dashTab === 'overview' ? renderAdminUsers() : ''}
      ${APP.dashTab === 'codes' ? renderAdminCodes() : ''}
      ${APP.dashTab === 'audit' ? renderAdminAudit() : ''}
      ${APP.dashTab === 'apilogs' ? renderAdminApiLogs() : ''}
    </div>`;
}

function renderAdminUsers() {
  return `
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>User</th>
            <th>Role</th>
            <th>Email</th>
            <th>Verification Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          ${APP.db.users.map(u => `
            <tr>
              <td><b>${esc(u.name)}</b></td>
              <td><span class="portal-pill">${u.role}</span></td>
              <td>${esc(u.email)}</td>
              <td>${u.isVerified ? `<span class="badge-verified">✓ Verified</span>` : `<span class="badge-unverified">Unverified</span>`}</td>
              <td>
                ${u.role === 'artist' && !u.isVerified ? `
                  <button class="btn btn-purple btn-sm" onclick="adminVerifyUser('${u.id}')">Manual Verify</button>
                ` : '—'}
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>`;
}

async function adminVerifyUser(userId) {
  try {
    const response = await apiRequest('admin.php?action=verify_artist', {
      method: 'POST',
      body: csrfBody({ userId })
    });

    await loadDB();
    const name = response.data?.user?.name || 'Artist';
    flash(`${name} has been verified.`);
    render();
  } catch (error) {
    handleApiError(error);
  }
}

function renderAdminCodes() {
  return `
    <div style="margin-bottom:16px; display:flex; justify-content:flex-end;">
      <button class="btn btn-primary btn-sm" onclick="adminGenerateMasterCode()">+ Generate Master Verification Code</button>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Invite / Verification Code</th>
            <th>Generated By</th>
            <th>Status</th>
            <th>Used By</th>
            <th>Created</th>
          </tr>
        </thead>
        <tbody>
          ${APP.db.inviteCodes.map(c => {
            const creator = getUser(c.createdBy);
            const user = getUser(c.usedBy);
            return `
              <tr>
                <td><span class="code-badge">${c.code}</span></td>
                <td>${esc(creator ? creator.name : 'System Admin')}</td>
                <td>${c.isUsed ? '<span style="color:var(--accent-red);font-weight:700;">EXPIRED</span>' : '<span style="color:var(--accent-green);font-weight:700;">ACTIVE</span>'}</td>
                <td>${user ? esc(user.name) : '—'}</td>
                <td>${fmtDate(c.createdAt)}</td>
              </tr>`;
          }).join('')}
        </tbody>
      </table>
    </div>`;
}

async function adminGenerateMasterCode() {
  try {
    const response = await apiRequest('admin.php?action=generate_code', {
      method: 'POST',
      body: csrfBody()
    });

    await loadDB();
    flash(`Master code created: ${response.data.code}`);
    render();
  } catch (error) {
    handleApiError(error);
  }
}

function renderAdminAudit() {
  return `
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>Timestamp</th>
            <th>Actor</th>
            <th>Action</th>
            <th>Target / Details</th>
          </tr>
        </thead>
        <tbody>
          ${APP.db.auditlog.map(a => `
            <tr>
              <td>${fmtDateTime(a.at)}</td>
              <td><b>${esc(a.actor)}</b></td>
              <td>${esc(a.action)}</td>
              <td>${esc(a.target)}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    </div>`;
}

function renderAdminApiLogs() {
  return `
    <div class="log-box">
      ${APP.db.integrationLogs.map(l => `
        <div class="log-line">
          <span class="log-time">[${fmtDateTime(l.at)}]</span>
          <span style="color:var(--accent-cyan); font-weight:700;">${l.endpoint}</span>
          <span style="color:var(--accent-green); font-weight:700;">${l.status}</span>
          <span>Payload: ${esc(l.payload)}</span>
        </div>
      `).join('')}
    </div>`;
}

/* =====================================================================
   DIRECT MESSAGING PLATFORM
   ===================================================================== */

function viewMessages() {
  const u = currentUser();
  if (!u || !['client', 'artist'].includes(u.role)) {
    return `<div class="wrap section"><div class="empty-state">Messaging is available to Clients and Artists.</div></div>`;
  }

  const params = routeQuery();
  const requestedCommission = params.commission ? String(params.commission) : null;
  const myCommissions = APP.db.commissions.filter(c => c.clientId === u.id || c.artistId === u.id);
  const selected = requestedCommission
    ? myCommissions.find(c => String(c.id) === requestedCommission)
    : myCommissions[0];

  if (!selected) {
    return `
      <div class="wrap section">
        <div class="section-title">Messages</div>
        <div class="section-sub" style="margin-bottom:24px;">Direct conversations between clients and artists are linked to their commission.</div>
        <div class="empty-state">No commission conversations are available yet.</div>
      </div>`;
  }

  const counterpartId = u.id === selected.clientId ? selected.artistId : selected.clientId;
  const counterpart = getUser(counterpartId);
  const listing = getListing(selected.listingId);
  const thread = messagesForCommission(selected.id);

  return `
    <div class="wrap section">
      <div class="section-head">
        <div>
          <div class="section-title">Messages</div>
          <div class="section-sub">Private client ↔ artist conversation for each commission.</div>
        </div>
        <button class="btn btn-secondary btn-sm" onclick="refreshMessages()">↻ Refresh</button>
      </div>

      <div style="display:grid; grid-template-columns:280px 1fr; gap:20px; align-items:start;">
        <div class="card">
          <div style="font-weight:700; margin-bottom:10px;">Conversations</div>
          <div style="display:flex; flex-direction:column; gap:8px;">
            ${myCommissions.map(c => {
              const otherId = u.id === c.clientId ? c.artistId : c.clientId;
              const other = getUser(otherId);
              const itemMessages = messagesForCommission(c.id);
              const unread = itemMessages.filter(m => m.recipientId === u.id && !m.readAt).length;
              return `
                <button class="btn ${String(c.id) === String(selected.id) ? 'btn-primary' : 'btn-secondary'}" style="text-align:left;"
                        onclick="navigate('#/messages?commission=${c.id}')">
                  <div style="font-weight:700;">${esc(other ? other.name : 'User')}</div>
                  <div style="font-size:11px; opacity:.8; margin-top:3px;">${esc(getListing(c.listingId)?.title || 'Commission')} · #${c.id}</div>
                  ${unread ? `<div style="font-size:10px; margin-top:5px;">${unread} unread</div>` : ''}
                </button>`;
            }).join('')}
          </div>
        </div>

        <div class="card">
          <div style="display:flex; justify-content:space-between; gap:12px; align-items:center; border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:14px;">
            <div>
              <div style="font-weight:800;">${esc(counterpart ? counterpart.name : 'Conversation')}</div>
              <div style="font-size:11.5px; color:var(--text-faint);">${esc(listing ? listing.title : 'Commission')} · #${selected.id}</div>
            </div>
            <span class="stamp ${selected.status}">${selected.status}</span>
          </div>

          <div id="message-thread" style="display:flex; flex-direction:column; gap:10px; min-height:280px; max-height:460px; overflow:auto; padding:4px 2px 12px;">
            ${thread.length ? thread.map(renderMessageBubble).join('') : `<div class="empty-state" style="margin:auto; border:0;">No messages yet. Start the conversation.</div>`}
          </div>

          <form enctype="multipart/form-data" onsubmit="handleSendMessage(event, '${selected.id}')" style="margin-top:10px;">
            <div class="field" style="margin-bottom:8px;">
              <textarea name="message" maxlength="5000" placeholder="Write a message to ${esc(counterpart ? counterpart.name : 'the other participant')}..." style="min-height:90px;"></textarea>
            </div>
            <div style="display:flex; gap:10px; align-items:end; flex-wrap:wrap; margin-bottom:8px;">
              <div class="field" style="margin:0; flex:1; min-width:240px;">
                <label>Attach image / PSD</label>
                <input type="file" name="attachment" accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.psd,image/*">
                <div style="font-size:10.5px;color:var(--text-faint);margin-top:4px;">JPG, PNG, GIF, WEBP, SVG or layered PSD · max 50 MB</div>
              </div>
              <button class="btn btn-primary" type="submit">Send Message</button>
            </div>
            <div style="font-size:11px; color:var(--text-faint);">Messages are stored with the commission and notify the other participant.</div>
          </form>
        </div>
      </div>
    </div>`;
}

function renderMessageBubble(m) {
  const u = currentUser();
  const mine = u && String(m.senderId) === String(u.id);
  const sender = getUser(m.senderId);
  return `
    <div style="display:flex; justify-content:${mine ? 'flex-end' : 'flex-start'};">
      <div style="max-width:78%; background:${mine ? 'rgba(124,58,237,.20)' : 'var(--bg-input)'}; border:1px solid ${mine ? 'rgba(124,58,237,.45)' : 'var(--border)'}; border-radius:10px; padding:10px 12px;">
        <div style="font-size:10.5px; color:var(--text-faint); margin-bottom:4px;">${esc(sender ? sender.name : 'User')} · ${fmtDateTime(m.sentAt)}</div>
        ${m.attachmentUrl ? (m.attachmentIsImage ? `<a href="${esc(m.attachmentUrl)}" target="_blank" rel="noopener noreferrer" title="Open attached image"><img src="${esc(m.attachmentUrl)}" alt="${esc(m.attachmentName || 'Attached image')}" style="display:block;max-width:280px;max-height:220px;object-fit:contain;border-radius:8px;border:1px solid var(--border);margin:6px 0 8px;"></a>` : `<a href="${esc(m.attachmentUrl)}" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm" style="display:inline-flex;margin:6px 0 8px;">${m.attachmentIsPsd ? 'PSD Attachment ↗' : 'Attachment ↗'}</a>` ) : ''}
        ${m.message ? `<div style="font-size:13px; white-space:pre-wrap; word-break:break-word;">${esc(m.message)}</div>` : ''}
      </div>
    </div>`;
}

async function markMessageThreadRead(commissionId) {
  try {
    await apiRequest('messages.php?action=mark_thread_read', {
      method: 'POST',
      body: csrfBody({ commissionId })
    });

    if (currentUser()) {
      await loadDB();
      // Keep the messages page and notification badges in sync with SQL Server.
      if (routeParts()[0] === 'messages' || routeParts()[0] === 'user-portal' || routeParts()[0] === 'artist-portal') {
        render();
      }
    }
  } catch (error) {
    console.warn(error);
  }
}

async function refreshMessages() {
  try {
    await loadDB();
    await loadMessages();
    render();
    flash('Messages refreshed.');
  } catch (error) {
    handleApiError(error);
  }
}

async function handleSendMessage(e, commissionId) {
  e.preventDefault();
  const form = e.target;
  const fd = new FormData(form);
  const message = String(fd.get('message') || '').trim();
  const attachment = fd.get('attachment');
  const hasAttachment = attachment instanceof File && attachment.size > 0;

  if (!message && !hasAttachment) {
    flash('Write a message or attach an image/PSD file.');
    return;
  }
  if (hasAttachment && attachment.size > 50 * 1024 * 1024) {
    flash('Attachments are limited to 50 MB.');
    return;
  }

  fd.set('commissionId', String(commissionId));
  fd.set('csrf', APP.csrf || '');
  const submitButton = form.querySelector('button[type="submit"]');
  if (submitButton) submitButton.disabled = true;

  try {
    await apiRequest('messages.php?action=send', { method: 'POST', body: fd });
    form.reset();
    await loadMessages();
    render();
    await apiRequest('messages.php?action=mark_thread_read', {
      method: 'POST',
      body: csrfBody({ commissionId })
    });
    flash('Message sent.');
  } catch (error) {
    handleApiError(error);
    if (submitButton) submitButton.disabled = false;
  }
}

/* =====================================================================
   COMMISSION DETAILED PIPELINE & FILE VERSIONING
   ===================================================================== */

function viewCommissionDetail(id) {
  const c = getCommission(id);
  const u = currentUser();
  if (!c) return `<div class="wrap section"><div class="empty-state">Commission record not found.</div></div>`;

  const listing = getListing(c.listingId);
  const client = getUser(c.clientId);
  const artist = getUser(c.artistId);

  const isClient = u && u.id === c.clientId;
  const isArtist = u && u.id === c.artistId;
  const isAdmin = u && u.role === 'admin';

  if (!isClient && !isArtist && !isAdmin) {
    return `<div class="wrap section"><div class="empty-state">Access restricted to assigned participants.</div></div>`;
  }

  return `
    <div class="wrap section">
      <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:24px; flex-wrap:wrap; gap:16px;">
        <div>
          <div style="font-size:12px; color:var(--text-faint); margin-bottom:4px;">COMMISSION TRACKER ID #${c.id}</div>
          <h1 style="font-size:24px; font-weight:800;">${esc(listing ? listing.title : 'Commission')}</h1>
          <div style="color:var(--text-muted); font-size:13.5px; margin-top:4px;">
            Artist: <b>${esc(artist ? artist.name : 'Unknown')}</b> | Client: <b>${esc(client ? client.name : 'Unknown')}</b>
          </div>
        </div>
        <div>
          <span class="stamp ${c.status}">${c.status}</span>
        </div>
      </div>

      <!-- Action Control Strip -->
      <div class="card" style="margin-bottom:24px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
          <div>
            <div style="font-size:12px; color:var(--text-faint);">TOTAL AGREED PRICE</div>
            <div style="font-size:22px; font-weight:800;">${fmtMoney(c.price)}</div>
          </div>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            ${renderPipelineActions(c, isClient, isArtist)}
          </div>
        </div>
      </div>

      <div class="card" style="margin-bottom:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
          <div style="font-weight:800;">Artist Profile & Portfolio</div>
          ${artist ? `<button class="btn btn-secondary btn-sm" onclick="navigate('#/profile/${artist.id}')">View Full Profile</button>` : ''}
        </div>
        ${artist ? `<div style="display:flex;align-items:center;gap:14px;margin-bottom:16px;">${profileAvatarMarkup(artist, 64)}<div><div style="font-weight:800;">${esc(artist.name)} ${artist.isVerified ? '<span class="badge-verified">✓ Verified</span>' : ''}</div><div style="font-size:12px;color:var(--text-muted);margin-top:4px;max-width:700px;">${esc(artist.bio || 'No bio provided.')}</div></div></div>` : ''}
        ${(artist?.portfolio && artist.portfolio.length) ? `<div class="grid">${artist.portfolio.map(renderPortfolioCard).join('')}</div>` : `<div class="empty-state" style="border:1px dashed var(--border);"><h4>No portfolio pieces yet</h4><p>The artist has not published portfolio work.</p></div>`}
      </div>

      ${c.invoice ? `<div class="card" style="margin-bottom:24px;border-color:rgba(34,211,238,.35);">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;">
          <div><div style="font-size:11px;color:var(--text-faint);">DEMO INVOICE · ${esc(c.invoice.invoiceNumber)}</div><div style="font-weight:800;font-size:18px;">Invoice ${esc(c.invoice.invoiceNumber)}</div><div style="font-size:12px;color:var(--text-muted);">Status: ${esc(c.invoice.status.toUpperCase())} · ${fmtDateTime(c.invoice.paidAt || c.invoice.issuedAt)}</div></div>
          <button class="btn btn-secondary btn-sm" onclick="printDemoInvoice('${c.id}')">Print / Save Invoice</button>
        </div>
        <div style="margin-top:12px;display:flex;justify-content:space-between;gap:10px;"><span>Commission total</span><b>${fmtMoney(c.invoice.total)}</b></div>
        <div style="font-size:10.5px;color:var(--text-faint);margin-top:8px;">Demo invoice only. No real payment processor or money transfer is connected.</div>
      </div>` : ''}

      ${c.payout ? `<div class="card" style="margin-bottom:24px;"><div style="font-weight:800;">Artist Payout</div><div style="display:flex;justify-content:space-between;gap:10px;margin-top:10px;"><span>${esc(c.payout.reference)}</span><b>${fmtMoney(c.payout.amount)}</b></div><div style="font-size:11px;color:var(--text-faint);margin-top:5px;">Status: ${esc(c.payout.status.toUpperCase())} · Demo payout</div>${isArtist && c.payout.status === 'pending' && c.paymentStatus === 'released' ? `<button class="btn btn-purple btn-sm" style="margin-top:10px;" onclick="simulateDemoPayout('${c.id}')">Simulate Payout</button>` : ''}</div>` : ''}

      <div style="display:grid; grid-template-columns:1.5fr 1fr; gap:24px;">
        <div>
          <!-- Brief -->
          <div class="card" style="margin-bottom:20px;">
            <div style="font-weight:700; margin-bottom:8px;">Client Brief Specification</div>
            <div style="color:var(--text-muted); font-size:13.5px;">${esc(c.brief)}</div>
          </div>

          <!-- Versioned File Submission Module -->
          <div class="card" style="margin-bottom:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
              <div style="font-weight:700;">Deliverables & Versioned WIP Assets (${c.files.length})</div>
              ${isArtist && ['accepted', 'inprogress', 'inreview'].includes(c.status) ? `
                <button class="btn btn-primary btn-sm" onclick="openFileUploadModal('${c.id}')">+ Submit File Link</button>
              ` : ''}
            </div>

            ${c.files.length === 0 ? `<div style="color:var(--text-faint); font-size:12.5px;">No files or WIP links submitted yet.</div>` : ''}
            <div style="display:flex; flex-direction:column; gap:10px;">
              ${c.files.map(f => `
                <div style="background:var(--bg-input); border:1px solid var(--border); padding:10px 14px; border-radius:6px; display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap;">
                  <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                    ${f.isImage ? `<a href="${esc(f.url)}" target="_blank" rel="noopener noreferrer" title="Open image"><img src="${esc(f.url)}" alt="${esc(f.label)}" style="width:68px;height:68px;object-fit:cover;border-radius:8px;border:1px solid var(--border);background:var(--bg-card);" loading="lazy"></a>` : `<div style="width:68px;height:68px;display:flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid var(--border);background:var(--bg-card);font-size:11px;font-weight:800;color:${f.isPsd ? 'var(--accent-purple)' : 'var(--text-muted)'};">${f.isPsd ? 'PSD' : 'FILE'}</div>`}
                    <div style="min-width:0;">
                      <div style="margin-bottom:4px;">
                        <span style="font-size:10px; font-weight:700; text-transform:uppercase; padding:2px 6px; border-radius:4px; margin-right:8px; background:${f.type === 'final' ? 'rgba(16,185,129,0.2)' : 'rgba(6,182,212,0.2)'}; color:${f.type === 'final' ? 'var(--accent-green)' : 'var(--accent-cyan)'};">${f.type}</span>
                        <span style="font-weight:600;">${esc(f.label)}</span>
                      </div>
                      <div style="font-size:10.5px;color:var(--text-faint);word-break:break-all;">${esc(f.fileName || (f.isPsd ? 'Layered PSD' : 'External file'))}${f.fileSize ? ` · ${(Number(f.fileSize)/1024/1024).toFixed(2)} MB` : ''}</div>
                    </div>
                  </div>
                  <a href="${esc(f.url)}" target="_blank" rel="noopener noreferrer" class="btn btn-secondary btn-sm">${f.isImage ? 'Open Image ↗' : f.isPsd ? 'Open / Download PSD ↗' : 'Open File ↗'}</a>
                </div>
              `).join('')}
            </div>
          </div>

          <!-- Direct Client ↔ Artist Messaging -->
          <div class="card" style="margin-bottom:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
              <div>
                <div style="font-weight:700;">Direct Messages</div>
                <div style="font-size:11px; color:var(--text-faint); margin-top:3px;">Private conversation between the client and artist.</div>
              </div>
              <button class="btn btn-secondary btn-sm" onclick="navigate('#/messages?commission=${c.id}')">Open Messages</button>
            </div>
            ${messagesForCommission(c.id).slice(-3).map(renderMessageBubble).join('') || `<div style="color:var(--text-faint); font-size:12.5px; padding:10px 0;">No direct messages yet.</div>`}
          </div>

          <!-- Feedback & Discussion -->
          <div class="card">
            <div style="font-weight:700; margin-bottom:12px;">Feedback & Discussion Log</div>
            <div style="display:flex; flex-direction:column; gap:10px; margin-bottom:16px;">
              ${c.comments.map(cm => {
                const author = getUser(cm.authorId);
                return `
                  <div style="background:var(--bg-input); padding:10px 14px; border-radius:6px;">
                    <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
                      <span style="font-weight:700; font-size:12.5px;">${esc(author ? author.name : 'User')}</span>
                      <span style="font-size:10px; color:var(--text-faint);">${fmtDateTime(cm.at)}</span>
                    </div>
                    <div style="font-size:13px; color:var(--text-muted);">${esc(cm.text)}</div>
                  </div>`;
              }).join('')}
            </div>

            <form onsubmit="handlePostComment(event, '${c.id}')">
              <div class="field" style="margin-bottom:8px;">
                <textarea name="text" required placeholder="Add feedback or clear revision instructions..." style="min-height:70px;"></textarea>
              </div>
              <button class="btn btn-secondary btn-sm" type="submit">Post Comment</button>
            </form>
          </div>
        </div>

        <div>
          <div class="card">
            <div style="font-weight:700; margin-bottom:12px;">Workflow State History</div>
            <div style="display:flex; flex-direction:column; gap:12px; font-size:12.5px;">
              <div style="color:${c.status === 'requested' ? 'var(--accent-amber)' : 'var(--accent-green)'};">1. Brief Submitted</div>
              <div style="color:${['accepted', 'inprogress', 'inreview', 'delivered'].includes(c.status) ? 'var(--accent-green)' : 'var(--text-faint)'};">2. Accepted by Artist</div>
              <div style="color:${c.paymentStatus === 'paid' ? 'var(--accent-green)' : 'var(--text-faint)'};">3. Escrow Locked (${c.paymentStatus})</div>
              <div style="color:${['inprogress', 'inreview', 'delivered'].includes(c.status) ? 'var(--accent-green)' : 'var(--text-faint)'};">4. Work In Progress</div>
              <div style="color:${['inreview', 'delivered'].includes(c.status) ? 'var(--accent-green)' : 'var(--text-faint)'};">5. Deliverables In Review</div>
              <div style="color:${c.status === 'delivered' ? 'var(--accent-green)' : 'var(--text-faint)'};">6. Approved & Closed</div>
            </div>
          </div>
        </div>
      </div>
    </div>`;
}

function renderPipelineActions(c, isClient, isArtist) {
  const btns = [];
  if (isArtist && c.status === 'requested') {
    btns.push(`<button class="btn btn-primary btn-sm" onclick="updateCommissionStatus('${c.id}', 'accepted')">Accept Brief</button>`);
    btns.push(`<button class="btn btn-danger btn-sm" onclick="updateCommissionStatus('${c.id}', 'declined')">Decline</button>`);
  }
  if (isClient && c.status === 'accepted' && c.paymentStatus !== 'paid') {
    btns.push(`<div id="paypal-button-${c.id}" style="min-width:240px;max-width:420px;"></div><button class="btn btn-secondary btn-sm" style="margin-top:8px;" onclick="payEscrow('${c.id}')">Use Demo Payment Instead</button>`);
  }
  if (isArtist && c.status === 'accepted' && c.paymentStatus === 'paid') {
    btns.push(`<button class="btn btn-primary btn-sm" onclick="updateCommissionStatus('${c.id}', 'inprogress')">Start Production</button>`);
  }
  if (isArtist && c.status === 'inprogress') {
    btns.push(`<button class="btn btn-purple btn-sm" onclick="updateCommissionStatus('${c.id}', 'inreview')">Submit Draft for Client Review</button>`);
  }
  if (isClient && c.status === 'inreview') {
    btns.push(`<button class="btn btn-primary btn-sm" onclick="updateCommissionStatus('${c.id}', 'delivered')">Approve Deliverables & Complete</button>`);
    btns.push(`<button class="btn btn-secondary btn-sm" onclick="updateCommissionStatus('${c.id}', 'inprogress', true)">Request Revision</button>`);
  }
  if (isClient && c.status === 'delivered' && c.paymentStatus === 'paid') {
    btns.push(`<button class="btn btn-purple btn-sm" onclick="releaseEscrow('${c.id}')">Release Demo Payment</button>`);
  }
  return btns.join('') || `<span style="font-size:12px; color:var(--text-faint);">No pending actions for your role.</span>`;
}

async function updateCommissionStatus(id, newStatus, isRevision = false) {
  try {
    await apiRequest('commissions.php?action=status', {
      method: 'POST',
      body: csrfBody({ id, status: newStatus, revision: Boolean(isRevision) })
    });

    await loadDB();
    flash(isRevision ? 'Revision requested.' : `Status updated to ${newStatus}.`);
    render();
  } catch (error) {
    handleApiError(error);
  }
}

async function getPayPalConfig() {
  if (PAYPAL_CONFIG_CACHE) return PAYPAL_CONFIG_CACHE;
  const response = await apiRequest('paypal.php?action=config');
  PAYPAL_CONFIG_CACHE = response.data || { enabled: false };
  return PAYPAL_CONFIG_CACHE;
}

function loadPayPalSdk(clientId, currency) {
  if (window.paypal && typeof window.paypal.Buttons === 'function') return Promise.resolve(window.paypal);
  if (PAYPAL_SDK_PROMISE) return PAYPAL_SDK_PROMISE;
  PAYPAL_SDK_PROMISE = new Promise((resolve, reject) => {
    const existing = document.querySelector('script[data-artic-paypal-sdk="1"]');
    if (existing) {
      existing.addEventListener('load', () => resolve(window.paypal));
      existing.addEventListener('error', () => reject(new Error('PayPal JavaScript SDK could not be loaded.')));
      return;
    }
    const script = document.createElement('script');
    script.src = `https://www.paypal.com/sdk/js?client-id=${encodeURIComponent(clientId)}&currency=${encodeURIComponent(currency)}&intent=capture&components=buttons`;
    script.async = true;
    script.dataset.articPaypalSdk = '1';
    script.onload = () => window.paypal ? resolve(window.paypal) : reject(new Error('PayPal SDK loaded without the PayPal object.'));
    script.onerror = () => reject(new Error('PayPal JavaScript SDK could not be loaded.'));
    document.head.appendChild(script);
  });
  return PAYPAL_SDK_PROMISE;
}

async function renderPayPalButtons() {
  const parts = routeParts();
  if (parts[0] !== 'commission') return;
  const c = getCommission(parts[1]);
  const root = c ? document.getElementById(`paypal-button-${c.id}`) : null;
  if (!root || !currentUser() || currentUser().role !== 'client' || c.status !== 'accepted' || c.paymentStatus !== 'pending') return;
  if (PAYPAL_RENDERED_ROOT === root) return;

  const cfg = await getPayPalConfig();
  if (!cfg.enabled) {
    root.innerHTML = '<div style="font-size:12px;color:var(--text-faint);padding:10px 0;">PayPal is not configured. Use the demo payment button for now.</div>';
    return;
  }

  const paypal = await loadPayPalSdk(cfg.clientId, cfg.currency || 'USD');
  if (!paypal || typeof paypal.Buttons !== 'function') throw new Error('PayPal Buttons is unavailable.');
  PAYPAL_RENDERED_COMMISSION = String(c.id);
  PAYPAL_RENDERED_ROOT = root;
  root.innerHTML = '';
  await paypal.Buttons({
    style: { layout: 'vertical', shape: 'rect', label: 'paypal', height: 42 },
    createOrder: async () => {
      const r = await apiRequest('paypal.php?action=create_order', { method: 'POST', body: csrfBody({ id: Number(c.id) }) });
      return r.data.paypalOrderId;
    },
    onApprove: async (data) => {
      try {
        await apiRequest('paypal.php?action=capture_order', { method: 'POST', body: csrfBody({ id: Number(c.id), orderId: data.orderID }) });
        PAYPAL_CONFIG_CACHE = null;
        PAYPAL_RENDERED_COMMISSION = null;
        PAYPAL_RENDERED_ROOT = null;
        await loadDB();
        flash('PayPal payment completed and recorded.');
        render();
      } catch (error) {
        PAYPAL_RENDERED_COMMISSION = null;
        handleApiError(error);
      }
    },
    onCancel: () => flash('PayPal payment was cancelled.'),
    onError: (err) => {
      console.error('PayPal error', err);
      PAYPAL_RENDERED_COMMISSION = null;
      PAYPAL_RENDERED_ROOT = null;
      flash('PayPal could not complete the payment. You can try again or use the demo payment option.');
    }
  }).render(root);
}

async function payEscrow(id) {
  try {
    await apiRequest('commissions.php?action=pay_escrow', {
      method: 'POST',
      body: csrfBody({ id })
    });

    await loadDB();
    flash('Payment locked in escrow!');
    render();
  } catch (error) {
    handleApiError(error);
  }
}

async function releaseEscrow(id) {
  try {
    await apiRequest('commissions.php?action=release_escrow', {
      method: 'POST',
      body: csrfBody({ id })
    });

    await loadDB();
    flash('Escrow payment released to the artist.');
    render();
  } catch (error) {
    handleApiError(error);
  }
}

function printDemoInvoice(id) {
  const c=getCommission(id); if(!c || !c.invoice) return;
  const client=getUser(c.clientId), artist=getUser(c.artistId), listing=getListing(c.listingId);
  const w=window.open('','_blank','width=760,height=800'); if(!w) return flash('Allow pop-ups to print the invoice.');
  w.document.write(`<html><head><title>${esc(c.invoice.invoiceNumber)}</title><style>body{font-family:Arial,sans-serif;padding:40px;color:#172033}h1{margin-bottom:4px}.muted{color:#64748b;font-size:13px}.row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #e2e8f0}.note{margin-top:24px;padding:12px;background:#f1f5f9;font-size:12px}</style></head><body><h1>Artic Demo Invoice</h1><div class="muted">${esc(c.invoice.invoiceNumber)} · ${esc(fmtDateTime(c.invoice.paidAt||c.invoice.issuedAt))}</div><p><b>Client:</b> ${esc(client?.name||'Client')}<br><b>Artist:</b> ${esc(artist?.name||'Artist')}<br><b>Commission:</b> ${esc(listing?.title||'Commission')}</p><div class="row"><span>Subtotal</span><b>${fmtMoney(c.invoice.subtotal)}</b></div><div class="row"><span>Platform fee</span><b>${fmtMoney(c.invoice.platformFee)}</b></div><div class="row"><span>Total paid</span><b>${fmtMoney(c.invoice.total)}</b></div><div class="note">DEMO ONLY — This invoice represents a simulated payment for the Artic prototype. No real payment was processed.</div><script>window.onload=()=>window.print()<\/script></body></html>`); w.document.close();
}
async function simulateDemoPayout(id) {
  try { const r=await apiRequest('finance.php?action=simulate_payout',{method:'POST',body:csrfBody({id:Number(id)})}); await loadDB(); flash(r.message||'Demo payout completed.'); render(); } catch(error){ handleApiError(error); }
}

/* ------------------ Modals: New Request & File Upload ------------------ */

function openRequestModal(listingId) {
  const u = currentUser();
  if (!u) {
    flash('Please sign in to request a commission.');
    return navigate('#/account');
  }
  if (u.role !== 'client') {
    flash('Please sign in with a Client account.');
    return;
  }

  const listing = getListing(listingId);
  openModal(`Request: ${esc(listing.title)}`, `
    <form onsubmit="handleSendRequest(event, '${listing.id}')">
      <div style="font-size:13px; color:var(--text-muted); margin-bottom:12px;">
        Price: <b>${fmtMoney(listing.price)}</b> | Estimated Delivery: <b>${listing.deliveryDays} Days</b>
      </div>
      <div class="field">
        <label>Brief Specification</label>
        <textarea name="brief" required placeholder="Describe your character, references, and exact delivery requirements..." style="min-height:100px;"></textarea>
      </div>
      <button class="btn btn-primary btn-block" type="submit">Submit Commission Request</button>
    </form>
  `);
}

async function handleSendRequest(e, listingId) {
  e.preventDefault();
  const brief = String(new FormData(e.target).get('brief') || '').trim();

  if (!brief) {
    flash('Please enter a brief specification.');
    return;
  }

  const submitButton = e.target.querySelector('button[type="submit"]');
  if (submitButton) submitButton.disabled = true;

  try {
    const response = await apiRequest('commissions.php?action=create', {
      method: 'POST',
      body: csrfBody({ listingId: Number(listingId), brief })
    });

    const created = response.data?.commission;
    if (created) {
      const existingIndex = APP.db.commissions.findIndex(c => String(c.id) === String(created.id));
      if (existingIndex >= 0) APP.db.commissions[existingIndex] = created;
      else APP.db.commissions.unshift(created);
    }

    closeModal();
    flash('Request submitted to artist. Both you and the artist were notified.');
    navigate('#/commission/' + response.data.id);
  } catch (error) {
    handleApiError(error);
    if (submitButton) submitButton.disabled = false;
  }
}

function openFileUploadModal(commId) {
  openModal('Submit Deliverable Asset', `
    <form enctype="multipart/form-data" onsubmit="handleAddFile(event, '${commId}')">
      <div class="field">
        <label>Asset Type</label>
        <select name="type">
          <option value="wip">Work In Progress (WIP)</option>
          <option value="final">Final Deliverable</option>
        </select>
      </div>
      <div class="field">
        <label>File Title / Version Label</label>
        <input type="text" name="label" required placeholder="e.g. Rough Sketch v2 / Final Character PSD">
      </div>
      <div class="field">
        <label>Choose Image / PSD</label>
        <input type="file" name="file" accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.psd,image/*" required>
        <div style="font-size:11px;color:var(--text-faint);margin-top:6px;">JPG, PNG, GIF, WEBP, SVG, or layered PSD. Maximum 50 MB.</div>
      </div>
      <div style="text-align:center;color:var(--text-faint);font-size:11px;margin:4px 0 10px;">— or submit an external link —</div>
      <div class="field">
        <label>Drive / Dropbox Link (optional)</label>
        <input type="url" name="url" placeholder="https://drive.google.com/your-file">
      </div>
      <div style="font-size:11px;color:var(--text-faint);margin:8px 0 12px;">Select a file or provide an HTTPS link.</div>
      <button class="btn btn-primary btn-block" type="submit">Upload Deliverable</button>
    </form>
  `);
}

async function handleAddFile(e, commId) {
  e.preventDefault();
  const form = e.target;
  const fd = new FormData(form);
  const file = fd.get('file');
  const url = String(fd.get('url') || '').trim();
  const label = String(fd.get('label') || '').trim();
  const submitButton = form.querySelector('button[type="submit"]');
  if (!file || !(file instanceof File) || file.size === 0) {
    fd.delete('file');
    if (!url) {
      flash('Choose an image/PSD file or enter an external link.');
      return;
    }
  } else if (file.size > 50 * 1024 * 1024) {
    flash('Files are limited to 50 MB.');
    return;
  }

  fd.set('commissionId', String(commId));
  fd.set('mode', (file && file instanceof File && file.size > 0) ? 'upload' : 'link');
  fd.set('csrf', APP.csrf || '');
  if (submitButton) submitButton.disabled = true;

  try {
    await apiRequest('commissions.php?action=add_file', {
      method: 'POST',
      body: fd
    });

    await loadDB();
    closeModal();
    flash('Deliverable uploaded successfully.');
    render();
  } catch (error) {
    handleApiError(error);
    if (submitButton) submitButton.disabled = false;
  }
}

async function handlePostComment(e, commId) {
  e.preventDefault();
  const text = String(new FormData(e.target).get('text') || '').trim();

  if (!text) {
    flash('Comment cannot be empty.');
    return;
  }

  try {
    await apiRequest('commissions.php?action=add_comment', {
      method: 'POST',
      body: csrfBody({ commissionId: commId, text })
    });

    await loadDB();
    flash('Comment posted.');
    render();
  } catch (error) {
    handleApiError(error);
  }
}

function openListingModal() {
  openModal('Create Tier Listing', `
    <form onsubmit="handleCreateListing(event)">
      <div class="field">
        <label>Tier Cover Image / PSD</label>
        <input type="file" name="cover" accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.psd,image/*">
        <div style="font-size:11px;color:var(--text-faint);margin-top:5px;">Optional. JPG, PNG, GIF, WEBP, SVG or PSD · max 50 MB.</div>
      </div>
      <div class="field">
        <label>Title</label>
        <input type="text" name="title" required placeholder="e.g. Full-Body Character Concept">
      </div>
      <div class="field-row">
        <div class="field">
          <label>Category</label>
          <select name="category">
            <option value="Illustration">Illustration</option>
            <option value="Live2D">Live2D</option>
            <option value="3D Asset">3D Asset</option>
            <option value="Animation">Animation</option>
          </select>
        </div>
        <div class="field">
          <label>Price ($ USD)</label>
          <input type="number" name="price" min="5" required placeholder="100">
        </div>
      </div>
      <div class="field-row">
        <div class="field">
          <label>Delivery Days</label>
          <input type="number" name="deliveryDays" min="1" required value="7">
        </div>
        <div class="field">
          <label>Total Open Slots</label>
          <input type="number" name="slotsTotal" min="1" required value="3">
        </div>
      </div>
      <div class="field">
        <label>Description</label>
        <textarea name="description" required placeholder="Specify deliverables, revisions, and format specifications..."></textarea>
      </div>
      <button class="btn btn-primary btn-block" type="submit">Publish Tier Listing</button>
    </form>
  `);
}

async function handleCreateListing(e) {
  e.preventDefault();
  const fd = new FormData(e.target);
  const payload = new FormData();
  payload.set('title', String(fd.get('title') || '').trim());
  payload.set('category', String(fd.get('category') || ''));
  payload.set('price', String(fd.get('price') || ''));
  payload.set('deliveryDays', String(fd.get('deliveryDays') || ''));
  payload.set('slotsTotal', String(fd.get('slotsTotal') || ''));
  payload.set('description', String(fd.get('description') || '').trim());
  const cover = fd.get('cover'); if (cover instanceof File && cover.size > 0) payload.set('cover', cover);
  payload.set('csrf', APP.csrf || '');
  const submitButton = e.target.querySelector('button[type="submit"]');
  if (submitButton) submitButton.disabled = true;
  try {
    const response = await apiRequest('listings.php?action=create', { method:'POST', body:payload });
    const created=response.data?.listing;
    if (created) APP.db.listings.unshift(created);
    closeModal(); flash('Tier listing published successfully.'); render();
  } catch(error) { handleApiError(error); if (submitButton) submitButton.disabled=false; }
}

function openPortfolioModal() {
  openModal('Add Portfolio Piece', `<form enctype="multipart/form-data" onsubmit="handlePortfolioUpload(event)">
    <div class="field"><label>Title</label><input name="title" required maxlength="160" placeholder="Character Illustration / 3D Render"></div>
    <div class="field"><label>Description</label><textarea name="description" maxlength="1000" placeholder="Short description of the work..."></textarea></div>
    <div class="field"><label>Artwork / Layered PSD</label><input type="file" name="file" accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.psd,image/*" required><div style="font-size:11px;color:var(--text-faint);margin-top:5px;">Maximum 50 MB. PSD files are preserved as PSD attachments.</div></div>
    <button class="btn btn-primary btn-block" type="submit">Add to Portfolio</button>
  </form>`);
}

async function handlePortfolioUpload(e) {
  e.preventDefault(); const fd=new FormData(e.target); fd.set('csrf',APP.csrf||'');
  try { await apiRequest('artist_media.php?action=add_portfolio',{method:'POST',body:fd}); await loadDB(); closeModal(); flash('Portfolio piece added.'); render(); } catch(error){ handleApiError(error); }
}


/* ------------------ Global event bridge ------------------ */
/*
 * The UI is rendered dynamically. Exposing event handlers explicitly on
 * window makes inline handlers deterministic in Apache/XAMPP and avoids
 * scope surprises when the page is served from localhost or a subfolder.
 */
Object.assign(window, {
  APP,
  apiRequest,
  loadDB,
  loadMessages,
  csrfBody,
  openPortfolioModal,
  handlePortfolioUpload,
  printDemoInvoice,
  simulateDemoPayout,
  flash,
  openModal,
  closeModal,
  navigate,
  navigatePortal,
  render,
  toggleNotifications,
  markNotifRead,
  markAllNotificationsRead,
  toggleArtistCodeField,
  handleLogin,
  handleRegister,
  handleChangePassword,
  handleUpdateProfile,
  logout,
  openArtistVerifyModal,
  handleVerifyAccount,
  adminVerifyUser,
  adminGenerateMasterCode,
  refreshMessages,
  markMessageThreadRead,
  handleSendMessage,
  loadArtistAnalytics,
  updateCommissionStatus,
  renderPayPalButtons,
  getPayPalConfig,
  payEscrow,
  releaseEscrow,
  openRequestModal,
  handleSendRequest,
  openFileUploadModal,
  handleAddFile,
  handlePostComment,
  openListingModal,
  handleCreateListing
});

/* ------------------ App Bootstrapper ------------------ */

window.addEventListener('hashchange', async () => {
  render();
  const page = routeParts()[0] || 'home';
  if (page === 'messages' && currentUser()) {
    try {
      await loadMessages();
      render();
      const q = routeQuery();
      if (q.commission) {
        await markMessageThreadRead(q.commission);
      }
    } catch (error) {
      handleApiError(error);
    }
  }
});

(async function boot() {
  try {
    await loadDB();
    if (currentUser()) {
      try { await loadMessages(); } catch (messageError) { console.warn('Message preload failed:', messageError); }
    }
    render();
  } catch (error) {
    console.error(error);
    const detail = error?.message || 'The database bootstrap request failed.';
    const safeDetail = esc(detail);
    document.getElementById('app').innerHTML = `
      <div class="wrap section">
        <div class="empty-state">
          <h4>Artic could not connect to the database</h4>
          <p>The PHP page is running, but the SQL Server bootstrap request failed.</p>
          <div style="text-align:left; max-width:760px; margin:18px auto 0; padding:14px; border:1px solid rgba(255,255,255,.12); border-radius:10px; background:rgba(0,0,0,.15);">
            <strong>Actual error:</strong>
            <pre style="white-space:pre-wrap; word-break:break-word; margin:8px 0 0; font:12px/1.5 monospace;">${safeDetail}</pre>
          </div>
          <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap; margin-top:16px;">
            <a class="btn btn-secondary" href="health.php" target="_blank" rel="noopener">Open Health Check</a>
            <a class="btn btn-primary" href="setup.php">Configure / Repair MSSQL</a>
          </div>
        </div>
      </div>`;
  }
})();
