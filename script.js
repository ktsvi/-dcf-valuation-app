'use strict';

// Global model state populated after a successful fetch
let modelData = null;

// ── Fetch & initialize ────────────────────────────────────────────────────────
async function fetchData() {
  const ticker = document.getElementById('ticker-input').value.trim().toUpperCase();
  if (!ticker) return;

  setLoading(true);
  clearError();
  document.getElementById('model-sections').classList.add('hidden');
  document.getElementById('company-info').classList.add('hidden');

  try {
    const res  = await fetch(`fetch.php?ticker=${encodeURIComponent(ticker)}`);
    const data = await res.json();
    if (!data.success) { showError(data.error || 'Failed to load data.'); return; }
    modelData = data;
    populateFields(data);
    document.getElementById('model-sections').classList.remove('hidden');
    calculateAll();
  } catch (e) {
    showError('Network error: ' + e.message);
  } finally {
    setLoading(false);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  document.getElementById('ticker-input').addEventListener('keydown', e => {
    if (e.key === 'Enter') fetchData();
  });
});

// ── UI helpers ────────────────────────────────────────────────────────────────
function setLoading(on) {
  document.getElementById('loading').classList.toggle('hidden', !on);
  document.getElementById('analyze-btn').disabled = on;
}
function showError(msg) {
  const el = document.getElementById('error-msg');
  el.textContent = msg;
  el.classList.remove('hidden');
}
function clearError() { document.getElementById('error-msg').classList.add('hidden'); }
function setVal(id, v) { const el = document.getElementById(id); if (el) el.value = v ?? ''; }

// ── Populate all form fields from fetched data ─────────────────────────────
function populateFields(data) {
  const { company, wacc_inputs, historical_fcf, fcf_cagr, latest_fcf } = data;

  // Company header
  document.getElementById('company-name').textContent        = company.name;
  document.getElementById('current-price-display').textContent = `$${fmtPrice(company.currentPrice)}`;
  document.getElementById('company-meta').textContent        = `(${company.ticker} · ${company.currency})`;
  document.getElementById('company-info').classList.remove('hidden');

  // WACC inputs (display percentages)
  setVal('beta',          wacc_inputs.beta);
  setVal('rd',            +(wacc_inputs.costOfDebt * 100).toFixed(2));
  setVal('tax-rate',      +(wacc_inputs.taxRate    * 100).toFixed(2));
  setVal('equity-weight', +(wacc_inputs.equityWeight * 100).toFixed(2));

  // FCF growth defaults
  setVal('fcf-growth',    +(fcf_cagr * 100).toFixed(2));
  setVal('ebitda-growth', +(fcf_cagr * 100).toFixed(2));

  // FCF table
  buildHistoricalRows(historical_fcf);
  buildProjectionRows(latest_fcf, fcf_cagr);

  // Warn on negative historical FCF
  const hasNeg = historical_fcf.some(h => h.fcf < 0);
  document.getElementById('negative-fcf-warning').classList.toggle('hidden', !hasNeg);
}

// ── FCF table: historical rows ─────────────────────────────────────────────
function buildHistoricalRows(hist) {
  const tbody = document.getElementById('historical-rows');
  tbody.innerHTML = '';
  hist.forEach(row => {
    const tr = document.createElement('tr');
    tr.className = 'historical-row';
    tr.innerHTML = `
      <td>${row.year} (Actual)</td>
      <td class="text-right">${fmtLarge(row.cfo)}</td>
      <td class="text-right">${fmtLarge(row.fcf)}</td>
      <td class="text-center">—</td>
      <td class="text-center">—</td>`;
    tbody.appendChild(tr);
  });
}

// ── FCF table: 5-year projection rows ────────────────────────────────────────
function buildProjectionRows(baseFCF, growthRate) {
  const tbody = document.getElementById('projection-rows');
  tbody.innerHTML = '';
  const yr0 = new Date().getFullYear();
  for (let i = 1; i <= 5; i++) {
    const projFCF = baseFCF * Math.pow(1 + growthRate, i);
    const tr = document.createElement('tr');
    tr.className = 'projection-row';
    tr.innerHTML = `
      <td>Year ${i} (${yr0 + i})</td>
      <td class="text-center">—</td>
      <td class="text-right">
        <input type="number" class="cell-input fcf-input" data-year="${i}"
               value="${Math.round(projFCF)}" step="1000000" oninput="calculateAll()">
      </td>
      <td class="text-right fcf-growth-cell" id="fcf-growth-${i}">—</td>
      <td class="text-right pv-cell" id="pv-fcf-${i}">—</td>`;
    tbody.appendChild(tr);
  }
}

// Re-fill projection cells when the uniform growth rate field changes
function applyGrowthRate() {
  if (!modelData) return;
  const g = (parseFloat(document.getElementById('fcf-growth').value) || 0) / 100;
  const base = modelData.latest_fcf;
  document.querySelectorAll('.fcf-input').forEach(input => {
    const yr = parseInt(input.dataset.year, 10);
    input.value = Math.round(base * Math.pow(1 + g, yr));
  });
  calculateAll();
}

// ── Main calculation ──────────────────────────────────────────────────────────
function calculateAll() {
  if (!modelData) return;

  // ── 1. WACC ────────────────────────────────────────────────────────────────
  const beta    = parseFloat(document.getElementById('beta').value)          || 1;
  const rf      = (parseFloat(document.getElementById('rf').value)           || 4.3)  / 100;
  const erp     = (parseFloat(document.getElementById('erp').value)          || 5.5)  / 100;
  const rd      = (parseFloat(document.getElementById('rd').value)           || 4.0)  / 100;
  const taxRate = (parseFloat(document.getElementById('tax-rate').value)     || 21)   / 100;
  const ew      = (parseFloat(document.getElementById('equity-weight').value)|| 80)   / 100;
  const dw      = 1 - ew;

  const re   = rf + beta * erp;
  const wacc = ew * re + dw * rd * (1 - taxRate);

  setVal('re', (re * 100).toFixed(2));
  setVal('debt-weight', (dw * 100).toFixed(2));
  document.getElementById('wacc-display').textContent  = pct(wacc);
  document.getElementById('wacc-re-show').textContent  = pct(re);
  document.getElementById('wacc-rd-show').textContent  = pct(rd * (1 - taxRate));

  // ── 2. FCF projections & discounting ──────────────────────────────────────
  const fcfs = [];
  document.querySelectorAll('.fcf-input').forEach(inp => fcfs.push(parseFloat(inp.value) || 0));

  let sumPV = 0;
  const pvFCFs = fcfs.map((fcf, i) => {
    const pv = fcf / Math.pow(1 + wacc, i + 1);
    sumPV += pv;

    // FCF growth display
    const prev = i === 0 ? modelData.latest_fcf : fcfs[i - 1];
    const gRow = prev !== 0 ? ((fcf / prev) - 1) * 100 : 0;
    const gCell = document.getElementById(`fcf-growth-${i + 1}`);
    if (gCell) {
      gCell.textContent = gRow.toFixed(1) + '%';
      gCell.className   = 'text-right fcf-growth-cell ' + (gRow >= 0 ? 'text-green' : 'text-red');
    }

    const pvCell = document.getElementById(`pv-fcf-${i + 1}`);
    if (pvCell) pvCell.textContent = fmtLarge(pv);
    return pv;
  });

  document.getElementById('sum-pv-fcf').textContent = fmtLarge(sumPV);

  // ── 3. Terminal Value ──────────────────────────────────────────────────────
  const fcf5        = fcfs[4] || 0;
  const tvMethod    = document.querySelector('input[name="tv-method"]:checked')?.value || 'gordon';
  const g           = (parseFloat(document.getElementById('tv-growth-rate').value) || 2.5) / 100;
  const evMultiple  = parseFloat(document.getElementById('tv-multiple').value)     || 12;
  const ebitdaG     = (parseFloat(document.getElementById('ebitda-growth').value)  || 5)  / 100;
  const baseEbitda  = modelData.wacc_inputs.ebitda || 0;
  const ebitda5     = baseEbitda * Math.pow(1 + ebitdaG, 5);

  const tvGordon = wacc > g ? fcf5 * (1 + g) / (wacc - g) : 0;
  const tvExit   = ebitda5 * evMultiple;

  document.getElementById('tv-gordon-value').textContent = fmtLarge(tvGordon);
  document.getElementById('tv-exit-value').textContent   = fmtLarge(tvExit);
  setVal('tv-ebitda5', Math.round(ebitda5));

  const tv   = tvMethod === 'gordon' ? tvGordon : tvExit;
  const pvTV = tv / Math.pow(1 + wacc, 5);

  document.getElementById('tv-selected-display').textContent = fmtLarge(tv);
  document.getElementById('pv-tv-display').textContent       = fmtLarge(pvTV);
  document.getElementById('tv-card-gordon').classList.toggle('selected', tvMethod === 'gordon');
  document.getElementById('tv-card-exit').classList.toggle('selected',   tvMethod !== 'gordon');

  // ── 4. Enterprise → Equity → Intrinsic Value ──────────────────────────────
  const ev         = sumPV + pvTV;
  const totalDebt  = modelData.wacc_inputs.totalDebt || 0;
  const cash       = modelData.wacc_inputs.cash      || 0;
  const netDebt    = totalDebt - cash;
  const equityVal  = ev - netDebt;
  const shares     = modelData.wacc_inputs.sharesOutstanding || 1;
  const intrinsic  = equityVal / shares;
  const mktPrice   = modelData.company.currentPrice;
  const upside     = mktPrice > 0 ? ((intrinsic - mktPrice) / mktPrice) * 100 : 0;

  document.getElementById('r-sum-pv').textContent      = fmtLarge(sumPV);
  document.getElementById('r-pv-tv').textContent       = fmtLarge(pvTV);
  document.getElementById('r-ev').textContent          = fmtLarge(ev);
  document.getElementById('r-net-debt').textContent    = fmtLarge(netDebt);
  document.getElementById('r-equity-val').textContent  = fmtLarge(equityVal);
  document.getElementById('r-shares').textContent      = fmtLarge(shares);
  document.getElementById('r-intrinsic').textContent   = `$${fmtPrice(intrinsic)}`;
  document.getElementById('r-market-price').textContent= `$${fmtPrice(mktPrice)}`;

  const badge = document.getElementById('upside-badge');
  badge.classList.remove('hidden', 'positive', 'negative');
  if (intrinsic >= mktPrice) {
    badge.className = 'upside-badge positive';
    badge.textContent = `▲ ${upside.toFixed(1)}% Upside — Potentially Undervalued at current price`;
  } else {
    badge.className = 'upside-badge negative';
    badge.textContent = `▼ ${Math.abs(upside).toFixed(1)}% Downside — Potentially Overvalued at current price`;
  }

  // ── 5. Sensitivity table ───────────────────────────────────────────────────
  buildSensitivityTable({ wacc, g, tvMethod, fcfs, ebitda5, evMultiple, netDebt, shares, mktPrice });

  // ── Store snapshot for export ──────────────────────────────────────────────
  modelData._calc = {
    wacc, re, rd, taxRate, ew, dw, beta, rf, erp,
    fcfs, pvFCFs, sumPV,
    tvGordon, tvExit, tv, pvTV, tvMethod, g, evMultiple, ebitda5,
    ev, netDebt, equityVal, shares, intrinsic, mktPrice, upside,
  };
}

// ── Sensitivity table (9 WACC steps × 7 g steps) ─────────────────────────────
function buildSensitivityTable({ wacc, g, tvMethod, fcfs, ebitda5, evMultiple, netDebt, shares, mktPrice }) {
  const wDelta = [-0.02, -0.015, -0.01, -0.005, 0, 0.005, 0.01, 0.015, 0.02];
  const gDelta = [-0.015, -0.01, -0.005, 0, 0.005, 0.01, 0.015];

  let html = '<table class="sens-table"><thead><tr><th>WACC \\ g</th>';
  gDelta.forEach(gd => { html += `<th>${((g + gd) * 100).toFixed(1)}%</th>`; });
  html += '</tr></thead><tbody>';

  wDelta.forEach(wd => {
    const w = wacc + wd;
    html += `<tr><th>${(w * 100).toFixed(1)}%</th>`;
    gDelta.forEach(gd => {
      const gi = g + gd;
      const sumPV = fcfs.reduce((s, fcf, i) => s + fcf / Math.pow(1 + w, i + 1), 0);
      const tv    = tvMethod === 'gordon'
        ? (w > gi ? fcfs[4] * (1 + gi) / (w - gi) : 0)
        : (ebitda5 * evMultiple);
      const pvTV  = tv / Math.pow(1 + w, 5);
      const intr  = (sumPV + pvTV - netDebt) / shares;

      const isBase  = wd === 0 && gd === 0;
      const isUnder = intr >= mktPrice;
      let cls = 'sens-cell ' + (isUnder ? 'undervalued' : 'overvalued');
      if (isBase) cls += ' base-cell';

      html += `<td class="${cls}">$${fmtPrice(intr)}</td>`;
    });
    html += '</tr>';
  });

  html += '</tbody></table>';
  document.getElementById('sensitivity-table-wrap').innerHTML = html;
}

// ── Export ────────────────────────────────────────────────────────────────────
function exportModel(type) {
  if (!modelData || !modelData._calc) { alert('Please analyze a ticker first.'); return; }
  document.getElementById('export-type').value = type;
  document.getElementById('export-data').value = JSON.stringify(modelData);
  document.getElementById('export-form').submit();
}

// ── Formatters ────────────────────────────────────────────────────────────────
function fmtLarge(n) {
  if (n === null || n === undefined || isNaN(n)) return '—';
  const abs  = Math.abs(n);
  const sign = n < 0 ? '-' : '';
  if (abs >= 1e12) return `${sign}$${(abs / 1e12).toFixed(2)}T`;
  if (abs >= 1e9)  return `${sign}$${(abs / 1e9 ).toFixed(2)}B`;
  if (abs >= 1e6)  return `${sign}$${(abs / 1e6 ).toFixed(2)}M`;
  if (abs >= 1e3)  return `${sign}$${(abs / 1e3 ).toFixed(1)}K`;
  return `${sign}$${abs.toFixed(0)}`;
}

function fmtPrice(n) {
  if (n === null || n === undefined || isNaN(n)) return '—';
  return (+n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function pct(n) { return (n * 100).toFixed(2) + '%'; }
