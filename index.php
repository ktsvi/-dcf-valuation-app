<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>DCF Valuation Model</title>
  <link rel="stylesheet" href="style.css">
</head>
<body>

<header>
  <div class="container">
    <div>
      <h1>&#x1F4C8; DCF Valuation Model</h1>
      <p>5-Year Free Cash Flow Analysis &mdash; WACC &middot; Terminal Value &middot; Sensitivity</p>
    </div>
  </div>
</header>

<main class="container">

  <!-- ── Step 1: Ticker Search ──────────────────────────────────────────── -->
  <section class="card" id="ticker-section">
    <h2>Stock Ticker</h2>
    <div class="ticker-row">
      <input type="text" id="ticker-input" placeholder="AAPL" maxlength="10" autocomplete="off" spellcheck="false">
      <button class="btn btn-primary" id="analyze-btn" onclick="fetchData()">Analyze</button>
    </div>
    <div id="company-info" class="hidden">
      <span class="company-name" id="company-name"></span>
      <span class="current-price" id="current-price-display"></span>
      <span class="company-meta" id="company-meta"></span>
    </div>
    <div id="error-msg" class="hidden"></div>
    <div id="loading" class="hidden">
      <div class="spinner"></div>
      <span>Fetching data from Yahoo Finance&hellip;</span>
    </div>
  </section>

  <!-- ── All model sections (hidden until data loads) ───────────────────── -->
  <div id="model-sections" class="hidden">

    <!-- ── Step 2: WACC ───────────────────────────────────────────────── -->
    <section class="card" id="wacc-section">
      <h2>WACC Inputs <span class="badge">Weighted Average Cost of Capital</span></h2>

      <p class="section-note" style="margin-bottom:16px;">
        All fields are pre-populated from live data. Edit any value to recalculate instantly.
      </p>

      <div style="font-weight:600;font-size:13px;color:var(--gray-600);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">
        Cost of Equity (CAPM)
      </div>
      <div class="inputs-grid" style="margin-bottom:20px;">
        <div class="input-group">
          <label>Beta (&beta;)</label>
          <input type="number" id="beta" step="0.01" min="0" oninput="calculateAll()">
          <span class="hint">From Yahoo Finance</span>
        </div>
        <div class="input-group">
          <label>Risk-Free Rate (R<sub>f</sub>) %</label>
          <input type="number" id="rf" step="0.1" min="0" value="4.3" oninput="calculateAll()">
          <span class="hint">US 10-yr Treasury approx.</span>
        </div>
        <div class="input-group">
          <label>Equity Risk Premium %</label>
          <input type="number" id="erp" step="0.1" min="0" value="5.5" oninput="calculateAll()">
          <span class="hint">Market premium over R<sub>f</sub></span>
        </div>
        <div class="input-group">
          <label>Cost of Equity (R<sub>e</sub>) %</label>
          <input type="number" id="re" step="0.01" readonly style="background:var(--gray-100);color:var(--blue);font-weight:700;">
          <span class="hint">= R<sub>f</sub> + &beta; &times; ERP</span>
        </div>
      </div>

      <div style="font-weight:600;font-size:13px;color:var(--gray-600);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px;">
        Cost of Debt &amp; Capital Structure
      </div>
      <div class="inputs-grid" style="margin-bottom:24px;">
        <div class="input-group">
          <label>Cost of Debt (R<sub>d</sub>) %</label>
          <input type="number" id="rd" step="0.01" min="0" oninput="calculateAll()">
          <span class="hint">Interest expense / Total debt</span>
        </div>
        <div class="input-group">
          <label>Tax Rate %</label>
          <input type="number" id="tax-rate" step="0.1" min="0" max="60" oninput="calculateAll()">
          <span class="hint">Effective tax rate</span>
        </div>
        <div class="input-group">
          <label>Equity Weight (E/V) %</label>
          <input type="number" id="equity-weight" step="0.1" min="0" max="100" oninput="calculateAll()">
          <span class="hint">Market cap / (Mkt cap + Debt)</span>
        </div>
        <div class="input-group">
          <label>Debt Weight (D/V) %</label>
          <input type="number" id="debt-weight" step="0.1" min="0" max="100" readonly style="background:var(--gray-100);">
          <span class="hint">= 1 &minus; Equity Weight</span>
        </div>
      </div>

      <div class="wacc-callout">
        <div>
          <div class="label">WACC = (E/V) &times; Re + (D/V) &times; Rd &times; (1 &minus; T)</div>
          <div class="wacc-breakdown">
            <div class="wacc-part">Cost of Equity: <span id="wacc-re-show">-</span></div>
            <div class="wacc-part">After-tax Cost of Debt: <span id="wacc-rd-show">-</span></div>
          </div>
        </div>
        <div>
          <div class="label">WACC</div>
          <div class="value" id="wacc-display">-</div>
        </div>
      </div>
    </section>

    <!-- ── Step 3: FCF Projections ────────────────────────────────────── -->
    <section class="card" id="fcf-section">
      <h2>FCF Projections <span class="badge">Free Cash Flow</span></h2>

      <div id="negative-fcf-warning" class="warning-banner hidden">
        &#9888; One or more historical FCF values are negative. Verify projections carefully.
      </div>

      <div class="inputs-grid" style="margin-bottom:20px;">
        <div class="input-group">
          <label>Base FCF Growth Rate %</label>
          <input type="number" id="fcf-growth" step="0.1" oninput="applyGrowthRate()">
          <span class="hint">Historical CAGR (auto-applied)</span>
        </div>
        <div class="input-group">
          <label>EBITDA Growth Rate % (for Exit Multiple)</label>
          <input type="number" id="ebitda-growth" step="0.1" value="5" oninput="calculateAll()">
          <span class="hint">Used for Year-5 EBITDA projection</span>
        </div>
      </div>

      <div class="table-wrap">
        <table id="fcf-table">
          <thead>
            <tr>
              <th>Period</th>
              <th>Revenue / EBITDA Ref.</th>
              <th>FCF ($)</th>
              <th>FCF Growth %</th>
              <th>PV of FCF ($)</th>
            </tr>
          </thead>
          <tbody id="historical-rows">
            <!-- Populated by JS -->
          </tbody>
          <tbody id="projection-rows">
            <!-- Populated by JS -->
          </tbody>
          <tfoot>
            <tr>
              <td colspan="4">Sum of PV(FCFs)</td>
              <td id="sum-pv-fcf">-</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

    <!-- ── Step 4: Terminal Value ──────────────────────────────────────── -->
    <section class="card" id="tv-section">
      <h2>Terminal Value</h2>
      <p class="section-note" style="margin-bottom:16px;">
        Select the method that feeds into the final valuation. Both are shown for reference.
      </p>

      <div class="tv-methods">

        <!-- Gordon Growth Model -->
        <div class="tv-card" id="tv-card-gordon">
          <label class="method-label">
            <input type="radio" name="tv-method" value="gordon" id="tv-gordon" checked onchange="calculateAll()">
            Gordon Growth Model
          </label>
          <div class="input-group" style="max-width:200px;">
            <label>Perpetuity Growth Rate (g) %</label>
            <input type="number" id="tv-growth-rate" step="0.1" value="2.5" oninput="calculateAll()">
            <span class="hint">Long-run nominal GDP growth</span>
          </div>
          <div class="tv-result">
            <div class="tv-label">TV = FCF<sub>5</sub> &times; (1+g) / (WACC &minus; g)</div>
            <div class="tv-value" id="tv-gordon-value">-</div>
          </div>
        </div>

        <!-- Exit EV/EBITDA Multiple -->
        <div class="tv-card" id="tv-card-exit">
          <label class="method-label">
            <input type="radio" name="tv-method" value="exit" id="tv-exit" onchange="calculateAll()">
            Exit EV/EBITDA Multiple
          </label>
          <div class="inputs-grid" style="grid-template-columns:1fr 1fr;">
            <div class="input-group">
              <label>EV/EBITDA Multiple</label>
              <input type="number" id="tv-multiple" step="0.5" value="12" oninput="calculateAll()">
              <span class="hint">Industry-specific multiple</span>
            </div>
            <div class="input-group">
              <label>Year-5 EBITDA ($)</label>
              <input type="number" id="tv-ebitda5" step="1" readonly style="background:var(--gray-100);color:var(--blue);font-weight:700;">
              <span class="hint">Base EBITDA &times; (1+g)&sup5;</span>
            </div>
          </div>
          <div class="tv-result">
            <div class="tv-label">TV = EBITDA<sub>5</sub> &times; Multiple</div>
            <div class="tv-value" id="tv-exit-value">-</div>
          </div>
        </div>

      </div>

      <!-- PV of terminal value -->
      <div style="margin-top:20px;display:flex;gap:24px;flex-wrap:wrap;">
        <div class="result-item">
          <div class="r-label">Terminal Value (Selected)</div>
          <div class="r-value" id="tv-selected-display">-</div>
        </div>
        <div class="result-item">
          <div class="r-label">PV of Terminal Value</div>
          <div class="r-value text-blue" id="pv-tv-display">-</div>
        </div>
      </div>
    </section>

    <!-- ── Step 5: Valuation Results ──────────────────────────────────── -->
    <section class="card" id="results-section">
      <h2>Valuation Results</h2>
      <div class="results-grid">
        <div class="result-item">
          <div class="r-label">Sum PV(FCFs)</div>
          <div class="r-value" id="r-sum-pv">-</div>
        </div>
        <div class="result-item">
          <div class="r-label">PV Terminal Value</div>
          <div class="r-value" id="r-pv-tv">-</div>
        </div>
        <div class="result-item">
          <div class="r-label">Enterprise Value</div>
          <div class="r-value" id="r-ev">-</div>
        </div>
        <div class="result-item">
          <div class="r-label">( &minus; ) Net Debt</div>
          <div class="r-value" id="r-net-debt">-</div>
        </div>
        <div class="result-item">
          <div class="r-label">Equity Value</div>
          <div class="r-value" id="r-equity-val">-</div>
        </div>
        <div class="result-item">
          <div class="r-label">Shares Outstanding</div>
          <div class="r-value" id="r-shares">-</div>
        </div>
        <div class="result-item highlight">
          <div class="r-label">Intrinsic Value / Share</div>
          <div class="r-value" id="r-intrinsic">-</div>
        </div>
        <div class="result-item">
          <div class="r-label">Current Market Price</div>
          <div class="r-value" id="r-market-price">-</div>
        </div>
      </div>
      <div id="upside-badge" class="upside-badge hidden"></div>
    </section>

    <!-- ── Step 6: Sensitivity Analysis ──────────────────────────────── -->
    <section class="card" id="sensitivity-section">
      <h2>Sensitivity Analysis <span class="badge">Intrinsic Value / Share</span></h2>
      <p class="section-note" style="margin-bottom:12px;">
        Rows = WACC &plusmn;2% &nbsp;|&nbsp; Columns = Terminal Growth Rate &plusmn;1.5%
        &nbsp;|&nbsp; Bold outline = base case
      </p>
      <div id="sensitivity-table-wrap">
        <!-- Table built by JS -->
      </div>
      <div class="sens-legend">
        <div class="sens-legend-item"><div class="swatch green"></div> Undervalued (intrinsic &gt; market price)</div>
        <div class="sens-legend-item"><div class="swatch red"></div> Overvalued (intrinsic &lt; market price)</div>
      </div>
    </section>

    <!-- ── Export Bar ─────────────────────────────────────────────────── -->
    <div class="export-bar">
      <button class="btn btn-success" onclick="exportModel('excel')">&#x1F4E5; Download Excel</button>
      <button class="btn btn-danger"  onclick="exportModel('pdf')">&#x1F4C4; Download PDF</button>
    </div>

  </div><!-- /#model-sections -->

</main>

<!-- Hidden export form -->
<form id="export-form" method="POST" action="export.php" target="_blank" style="display:none;">
  <input type="hidden" name="type"      id="export-type">
  <input type="hidden" name="modelData" id="export-data">
</form>

<script src="script.js"></script>
</body>
</html>
