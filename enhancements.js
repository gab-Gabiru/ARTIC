/*
 * Artic final-project compliance enhancements.
 * Loaded after script.js so the original UI and application code remain intact.
 * Adds Reports, Analytics, and server-side role-management controls.
 */
(function () {
  if (typeof window === 'undefined') return;

  function escLocal(value) {
    return typeof esc === 'function' ? esc(value) : String(value ?? '').replace(/[&<>"']/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
  }

  function formatMoneyLocal(value) {
    if (typeof fmtMoney === 'function') return fmtMoney(value);
    return '$' + Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 2 });
  }

  function normalizeDate(value) {
    const time = new Date(value || '').getTime();
    return Number.isFinite(time) ? time : null;
  }

  async function adminChangeRole(userId, role) {
    try {
      await apiRequest('admin.php?action=change_role', {
        method: 'POST',
        body: csrfBody({ userId, role })
      });
      await loadDB();
      flash('User role updated.');
      render();
    } catch (error) {
      handleApiError(error);
      render();
    }
  }

  function renderAdminUsersEnhanced() {
    return `
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th>User</th>
              <th>Role</th>
              <th>Email</th>
              <th>Verification Status</th>
              <th>Role / Verification Actions</th>
            </tr>
          </thead>
          <tbody>
            ${APP.db.users.map(u => `
              <tr>
                <td><b>${escLocal(u.name)}</b></td>
                <td><span class="portal-pill">${escLocal(u.role)}</span></td>
                <td>${escLocal(u.email || 'Hidden')}</td>
                <td>${u.isVerified
                  ? `<span class="badge-verified">✓ Verified</span>`
                  : `<span class="badge-unverified">Unverified</span>`}</td>
                <td>
                  <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                    ${u.role === 'artist' && !u.isVerified ? `
                      <button class="btn btn-purple btn-sm" onclick="adminVerifyUser('${u.id}')">Manual Verify</button>
                    ` : ''}
                    ${u.id !== APP.me?.id ? `
                      <select onchange="adminChangeRole('${u.id}', this.value)" style="max-width:110px;">
                        <option value="client" ${u.role === 'client' ? 'selected' : ''}>client</option>
                        <option value="artist" ${u.role === 'artist' ? 'selected' : ''}>artist</option>
                        <option value="admin" ${u.role === 'admin' ? 'selected' : ''}>admin</option>
                      </select>
                    ` : `<span style="color:var(--text-faint);">Current account</span>`}
                  </div>
                </td>
              </tr>
            `).join('')}
          </tbody>
        </table>
      </div>`;
  }

  function renderAdminReportsEnhanced() {
    const users = APP.db.users || [];
    const commissions = APP.db.commissions || [];
    const listings = APP.db.listings || [];
    const statusCounts = commissions.reduce((acc, c) => {
      acc[c.status] = (acc[c.status] || 0) + 1;
      return acc;
    }, {});
    const paymentCounts = commissions.reduce((acc, c) => {
      acc[c.paymentStatus] = (acc[c.paymentStatus] || 0) + 1;
      return acc;
    }, {});
    const totalValue = commissions.reduce((sum, c) => sum + Number(c.price || 0), 0);
    const openSlots = listings.reduce((sum, l) => sum + Math.max(0, Number(l.slotsTotal) - Number(l.slotsUsed)), 0);

    return `
      <div class="kpi-grid">
        <div class="kpi-card"><div class="kpi-val">${users.length}</div><div class="kpi-label">Users</div></div>
        <div class="kpi-card"><div class="kpi-val">${listings.length}</div><div class="kpi-label">Listings</div></div>
        <div class="kpi-card"><div class="kpi-val">${commissions.length}</div><div class="kpi-label">Commissions</div></div>
        <div class="kpi-card"><div class="kpi-val">${formatMoneyLocal(totalValue)}</div><div class="kpi-label">Commission Value</div></div>
        <div class="kpi-card"><div class="kpi-val">${openSlots}</div><div class="kpi-label">Open Slots</div></div>
      </div>
      <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-top:20px;">
        <div class="card">
          <div style="font-weight:700; margin-bottom:12px;">Commission Status Summary</div>
          ${Object.entries(statusCounts).map(([key, value]) => `
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
              <span>${escLocal(key)}</span><b>${value}</b>
            </div>
          `).join('') || '<div class="empty-state">No commission data.</div>'}
        </div>
        <div class="card">
          <div style="font-weight:700; margin-bottom:12px;">Payment Status Summary</div>
          ${Object.entries(paymentCounts).map(([key, value]) => `
            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
              <span>${escLocal(key)}</span><b>${value}</b>
            </div>
          `).join('') || '<div class="empty-state">No payment data.</div>'}
        </div>
      </div>`;
  }

  function renderAnalyticsScatter() {
    const commissions = (APP.db.commissions || [])
      .map(c => ({
        ...c,
        priceNum: Number(c.price || 0),
        dateMs: normalizeDate(c.createdAt || c.created_at),
        revisionsNum: Number(c.revisions || 0)
      }))
      .filter(c => c.dateMs !== null && Number.isFinite(c.priceNum));

    const width = 900;
    const height = 420;
    const pad = { left: 72, right: 28, top: 34, bottom: 62 };
    const plotW = width - pad.left - pad.right;
    const plotH = height - pad.top - pad.bottom;

    if (!commissions.length) {
      return `
        <div class="card analytics-card">
          <div class="analytics-head">
            <div>
              <div class="analytics-title">Commission Analytics</div>
              <div class="analytics-sub">Scatter plot of commission value over time.</div>
            </div>
          </div>
          <div class="empty-state">No commission data is available for analytics yet.</div>
        </div>`;
    }

    const minDate = Math.min(...commissions.map(c => c.dateMs));
    const maxDate = Math.max(...commissions.map(c => c.dateMs));
    const dateSpan = Math.max(maxDate - minDate, 86400000);
    const maxPrice = Math.max(...commissions.map(c => c.priceNum), 1);
    const yMax = Math.ceil(maxPrice / 100) * 100 || 100;

    const x = ms => pad.left + ((ms - minDate) / dateSpan) * plotW;
    const y = price => pad.top + plotH - (price / yMax) * plotH;

    const statusClass = status => {
      const map = {
        requested: 'analytics-dot requested',
        accepted: 'analytics-dot accepted',
        declined: 'analytics-dot declined',
        cancelled: 'analytics-dot cancelled',
        inprogress: 'analytics-dot inprogress',
        inreview: 'analytics-dot inreview',
        delivered: 'analytics-dot delivered'
      };
      return map[status] || 'analytics-dot';
    };

    const horizontalTicks = 5;
    const verticalGrid = Array.from({ length: horizontalTicks + 1 }, (_, i) => {
      const value = (yMax / horizontalTicks) * i;
      const yy = y(value);
      return `
        <line x1="${pad.left}" y1="${yy.toFixed(1)}" x2="${width - pad.right}" y2="${yy.toFixed(1)}" class="analytics-grid-line"></line>
        <text x="${pad.left - 12}" y="${(yy + 4).toFixed(1)}" text-anchor="end" class="analytics-axis-text">${escLocal(formatMoneyLocal(value))}</text>`;
    }).join('');

    const xTicks = 5;
    const xAxis = Array.from({ length: xTicks + 1 }, (_, i) => {
      const ms = minDate + (dateSpan * i / xTicks);
      const xx = x(ms);
      const label = new Date(ms).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
      return `
        <line x1="${xx.toFixed(1)}" y1="${pad.top + plotH}" x2="${xx.toFixed(1)}" y2="${pad.top + plotH + 6}" class="analytics-axis-line"></line>
        <text x="${xx.toFixed(1)}" y="${height - 28}" text-anchor="middle" class="analytics-axis-text">${escLocal(label)}</text>`;
    }).join('');

    const points = commissions.map((c, idx) => {
      const cx = x(c.dateMs);
      const cy = y(c.priceNum);
      const radius = Math.min(12, 5 + c.revisionsNum * 1.4);
      const labelDate = new Date(c.dateMs).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
      const title = `${c.id ? `Commission #${c.id}` : 'Commission'} • ${labelDate} • ${formatMoneyLocal(c.priceNum)} • ${c.status || 'unknown'} • ${c.revisionsNum} revision${c.revisionsNum === 1 ? '' : 's'}`;
      return `<circle class="${statusClass(c.status)}" cx="${cx.toFixed(1)}" cy="${cy.toFixed(1)}" r="${radius.toFixed(1)}" tabindex="0" data-analytics-title="${escLocal(title)}" aria-label="${escLocal(title)}" onclick="showAnalyticsPoint(this, event)"></circle>`;
    }).join('');

    const latest = [...commissions].sort((a, b) => b.dateMs - a.dateMs);
    const total = commissions.reduce((sum, c) => sum + c.priceNum, 0);
    const paid = commissions.filter(c => c.paymentStatus === 'paid' || c.paymentStatus === 'released').reduce((sum, c) => sum + c.priceNum, 0);
    const avg = total / commissions.length;
    const revisions = commissions.reduce((sum, c) => sum + c.revisionsNum, 0);

    return `
      <div class="kpi-grid analytics-kpis">
        <div class="kpi-card"><div class="kpi-val">${formatMoneyLocal(total)}</div><div class="kpi-label">Total Commission Value</div></div>
        <div class="kpi-card"><div class="kpi-val">${formatMoneyLocal(avg)}</div><div class="kpi-label">Average Commission</div></div>
        <div class="kpi-card"><div class="kpi-val">${formatMoneyLocal(paid)}</div><div class="kpi-label">Paid / Released Value</div></div>
        <div class="kpi-card"><div class="kpi-val">${revisions}</div><div class="kpi-label">Total Revisions</div></div>
      </div>

      <div class="card analytics-card">
        <div class="analytics-head">
          <div>
            <div class="analytics-title">Commission Value Over Time</div>
            <div class="analytics-sub">Each point is one commission. Point size reflects revision count.</div>
          </div>
          <div class="analytics-count">${commissions.length} data point${commissions.length === 1 ? '' : 's'}</div>
        </div>
        <div class="analytics-chart-wrap">
          <svg class="analytics-chart" viewBox="0 0 ${width} ${height}" role="img" aria-label="Scatter plot of commission value over time">
            ${verticalGrid}
            <line x1="${pad.left}" y1="${pad.top + plotH}" x2="${width - pad.right}" y2="${pad.top + plotH}" class="analytics-axis-line"></line>
            <line x1="${pad.left}" y1="${pad.top}" x2="${pad.left}" y2="${pad.top + plotH}" class="analytics-axis-line"></line>
            ${xAxis}
            ${points}
            <text x="${pad.left + plotW / 2}" y="${height - 8}" text-anchor="middle" class="analytics-axis-title">Commission date</text>
            <text x="16" y="${pad.top + plotH / 2}" text-anchor="middle" class="analytics-axis-title" transform="rotate(-90 16 ${pad.top + plotH / 2})">Commission value</text>
          </svg>
          <div id="analytics-tooltip" class="analytics-tooltip" hidden></div>
        </div>
        <div class="analytics-legend">
          <span><i class="analytics-legend-dot requested"></i> Requested</span>
          <span><i class="analytics-legend-dot accepted"></i> Accepted</span>
          <span><i class="analytics-legend-dot inprogress"></i> In Progress</span>
          <span><i class="analytics-legend-dot inreview"></i> In Review</span>
          <span><i class="analytics-legend-dot delivered"></i> Delivered</span>
          <span><i class="analytics-legend-dot other"></i> Other</span>
        </div>
        <div class="analytics-note">Latest commission: ${latest[0] ? `#${escLocal(latest[0].id)} on ${escLocal(new Date(latest[0].dateMs).toLocaleDateString())}` : '—'}.</div>
      </div>`;
  }

  function showAnalyticsPoint(point, event) {
    const tooltip = document.getElementById('analytics-tooltip');
    if (!tooltip || !point) return;
    tooltip.textContent = point.getAttribute('data-analytics-title') || '';
    tooltip.hidden = false;
    const chart = point.closest('.analytics-chart-wrap');
    const rect = chart ? chart.getBoundingClientRect() : null;
    if (rect) {
      const svgRect = point.ownerSVGElement.getBoundingClientRect();
      const cx = Number(point.getAttribute('cx')) / point.ownerSVGElement.viewBox.baseVal.width * svgRect.width;
      const cy = Number(point.getAttribute('cy')) / point.ownerSVGElement.viewBox.baseVal.height * svgRect.height;
      tooltip.style.left = `${Math.min(Math.max(cx - 40, 8), rect.width - 220)}px`;
      tooltip.style.top = `${Math.max(cy - 52, 8)}px`;
    }
  }

  function hideAnalyticsTooltip() {
    const tooltip = document.getElementById('analytics-tooltip');
    if (tooltip) tooltip.hidden = true;
  }

  function renderAdminAnalyticsEnhanced() {
    return `
      <div class="analytics-toolbar">
        <div>
          <div class="section-title" style="font-size:20px;">Analytics Dashboard</div>
          <div class="section-sub">Use the scatter graph to inspect commission value, timing, workflow status, and revision activity.</div>
        </div>
        <button class="btn btn-primary btn-sm" onclick="hideAnalyticsTooltip(); loadDB().then(render).catch(handleApiError);">Refresh Data</button>
      </div>
      ${renderAnalyticsScatter()}`;
  }

  function viewAdminPortalEnhanced() {
    const u = currentUser();
    if (!u || u.role !== 'admin') {
      return '<div class="wrap section"><div class="empty-state">Admin system authorization required.</div></div>';
    }

    const totalUsers = APP.db.users.length;
    const verifiedArtists = APP.db.users.filter(x => x.role === 'artist' && x.isVerified).length;
    const totalVolume = APP.db.commissions
      .filter(c => c.paymentStatus === 'paid')
      .reduce((acc, c) => acc + Number(c.price || 0), 0);

    return `
      <div class="wrap section">
        <div class="section-head">
          <div>
            <div class="section-title">System Administration & Integration Monitor</div>
            <div class="section-sub">System-wide monitoring, verification governance, reports, analytics, and API audit logs.</div>
          </div>
        </div>

        <div class="kpi-grid">
          <div class="kpi-card"><div class="kpi-val">${totalUsers}</div><div class="kpi-label">Registered Users</div></div>
          <div class="kpi-card"><div class="kpi-val">${verifiedArtists}</div><div class="kpi-label">Verified Artists</div></div>
          <div class="kpi-card"><div class="kpi-val">${APP.db.commissions.length}</div><div class="kpi-label">Total Commissions</div></div>
          <div class="kpi-card"><div class="kpi-val">${formatMoneyLocal(totalVolume)}</div><div class="kpi-label">Locked Escrow</div></div>
        </div>

        <div class="tabs">
          <div class="tab ${APP.dashTab === 'overview' ? 'active' : ''}" onclick="APP.dashTab='overview'; render();">User & Role Management</div>
          <div class="tab ${APP.dashTab === 'codes' ? 'active' : ''}" onclick="APP.dashTab='codes'; render();">Invite Code Registry</div>
          <div class="tab ${APP.dashTab === 'audit' ? 'active' : ''}" onclick="APP.dashTab='audit'; render();">Audit Trail</div>
          <div class="tab ${APP.dashTab === 'apilogs' ? 'active' : ''}" onclick="APP.dashTab='apilogs'; render();">API / Integration Logs</div>
          <div class="tab ${APP.dashTab === 'analytics' ? 'active' : ''}" onclick="APP.dashTab='analytics'; render();">Analytics</div>
          <div class="tab ${APP.dashTab === 'reports' ? 'active' : ''}" onclick="APP.dashTab='reports'; render();">Reports</div>
        </div>

        ${APP.dashTab === 'overview' ? renderAdminUsersEnhanced() : ''}
        ${APP.dashTab === 'codes' ? renderAdminCodes() : ''}
        ${APP.dashTab === 'audit' ? renderAdminAudit() : ''}
        ${APP.dashTab === 'apilogs' ? renderAdminApiLogs() : ''}
        ${APP.dashTab === 'analytics' ? renderAdminAnalyticsEnhanced() : ''}
        ${APP.dashTab === 'reports' ? renderAdminReportsEnhanced() : ''}
      </div>`;
  }

  window.adminChangeRole = adminChangeRole;
  window.renderAdminUsers = renderAdminUsersEnhanced;
  window.renderAdminReports = renderAdminReportsEnhanced;
  window.renderAdminAnalytics = renderAdminAnalyticsEnhanced;
  window.viewAdminPortal = viewAdminPortalEnhanced;
  window.showAnalyticsPoint = showAnalyticsPoint;
  window.hideAnalyticsTooltip = hideAnalyticsTooltip;

  const originalApiRequest = window.apiRequest;
  if (typeof originalApiRequest === 'function') {
    window.apiRequest = async function (endpoint, options = {}) {
      try {
        return await originalApiRequest(endpoint, options);
      } catch (error) {
        throw error;
      }
    };
  }
})();
