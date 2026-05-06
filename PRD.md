# Product Requirements Document
## DCF Stock Valuation Web Application

**Version:** 1.0  
**Date:** 2026-05-06  
**Author:** Development Team

---

## 1. Problem Statement

Equity valuation using Discounted Cash Flow analysis is a foundational skill in finance, but building a proper DCF model traditionally requires Excel expertise, manual data gathering from financial statements, and significant time. This application automates data collection and model construction so that a user can go from a ticker symbol to a complete intrinsic value estimate in under 60 seconds.

---

## 2. Target User

- Finance students and early-career analysts
- Individual investors performing fundamental analysis
- Professors or instructors demonstrating DCF methodology

---

## 3. Success Criteria

- Enter any valid US stock ticker and receive a populated DCF model within 5 seconds
- All auto-fetched inputs are editable; model recalculates instantly on any change
- Export to both Excel (multi-sheet) and PDF (formatted report)
- App runs locally on XAMPP with no external account or API key required

---

## 4. Functional Requirements

### FR-1: Ticker Input
- Single text input field accepting US stock tickers (e.g., AAPL, MSFT, TSLA)
- "Analyze" button triggers data fetch
- Display company name and current price upon successful fetch

### FR-2: Data Fetching
- Fetch from Yahoo Finance unofficial API via PHP cURL (no API key)
- Retrieve: current price, beta, shares outstanding, total debt, cash, operating cash flow (3 years), capex (3 years), EBITDA, interest expense, tax rate
- Display friendly error if ticker is invalid or data unavailable

### FR-3: WACC Module
Auto-populated fields (all editable):
- Beta (from Yahoo Finance)
- Risk-free rate (default: 4.3%, US 10-yr Treasury approximation)
- Equity risk premium (default: 5.5%)
- Cost of equity = Rf + Beta × ERP (CAPM)
- Cost of debt = Interest expense / Total debt × (1 − tax rate)
- Tax rate (from income statement)
- Equity weight = Market Cap / (Market Cap + Total Debt)
- Debt weight = Total Debt / (Market Cap + Total Debt)
- **WACC** = We × Re + Wd × Rd × (1 − T) [displayed prominently]

### FR-4: FCF Projections
- Show last 3 years of historical FCF (read-only reference)
- Calculate CAGR of historical FCF as default growth rate
- Project 5 years of FCF using growth rate (each year cell is editable)
- User can set a uniform growth rate or edit individual years

### FR-5: Terminal Value
- **Gordon Growth Model**: TV = FCF₅ × (1 + g) / (WACC − g); user sets perpetuity growth rate g
- **Exit EV/EBITDA Multiple**: TV = projected EBITDA₅ × user-defined multiple
- Toggle/radio to select which method feeds into valuation
- Both calculated and displayed side-by-side

### FR-6: DCF Valuation Output
- Present value of each year's FCF
- PV of terminal value
- Enterprise Value = Σ PV(FCF) + PV(TV)
- Net Debt = Total Debt − Cash
- Equity Value = EV − Net Debt
- Intrinsic Value per Share = Equity Value / Shares Outstanding
- Comparison: Intrinsic Value vs Current Price, % upside/downside

### FR-7: Sensitivity Analysis Table
- 7×7 grid: WACC (base ± 2% in 0.5% steps) vs Terminal Growth Rate (base ± 1.5% in 0.5% steps)
- Each cell shows intrinsic value per share
- Color coding: green if > current price (undervalued), red if < current price (overvalued)

### FR-8: Export
- **Excel**: 3 sheets — "DCF Model" (full model), "Sensitivity" (grid with color), "Raw Data" (all fetched figures)
- **PDF**: Single formatted report — header (ticker, date), WACC summary, FCF table, TV section, results, sensitivity grid
- Export buttons always visible after model is loaded

---

## 5. Non-Functional Requirements

- **Compatibility**: Runs on XAMPP (PHP 7.4+), tested on Windows
- **Responsiveness**: Usable on 1280px+ screens; mobile not required
- **Performance**: Yahoo Finance fetch completes in < 5 seconds; recalculation is instant (client-side JS)
- **No authentication**: No login, no user accounts
- **No database**: Stateless; all data lives in the browser session

---

## 6. Data Model

### Inputs (fetched + user-editable)
| Field | Source | Editable |
|-------|--------|---------|
| Beta | Yahoo Finance | Yes |
| Risk-free rate | Hardcoded default | Yes |
| Equity risk premium | Hardcoded default | Yes |
| Cost of debt | Calculated | Yes |
| Tax rate | Yahoo Finance | Yes |
| Total debt | Yahoo Finance | Yes |
| Cash | Yahoo Finance | Yes |
| Shares outstanding | Yahoo Finance | Yes |
| FCF Year 1–5 | Projected | Yes |
| Perpetuity growth rate | User input | Yes |
| EV/EBITDA multiple | User input | Yes |

### Outputs (calculated)
- Cost of equity, WACC
- PV of each FCF year
- Terminal value (both methods)
- Enterprise value, Equity value, Intrinsic value/share
- Sensitivity table (49 values)

---

## 7. Out of Scope (v1.0)

- Multi-ticker comparison
- Historical intrinsic value charts
- International stocks (non-US tickers)
- User accounts or saved models
- Real-time price streaming
- Mobile-optimized layout
- Monte Carlo simulation
