<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$ticker = isset($_GET['ticker'])
    ? preg_replace('/[^A-Z0-9\.\-\^]/', '', strtoupper(trim($_GET['ticker'])))
    : '';

if (empty($ticker)) {
    echo json_encode(['success' => false, 'error' => 'Please enter a valid ticker symbol.']);
    exit;
}

// ── Session & cookie setup ────────────────────────────────────────────────────
// One cookie jar per server session (refreshed every hour to keep crumb fresh)
$cookieFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yf_session.txt';
if (file_exists($cookieFile) && (time() - filemtime($cookieFile)) > 3600) {
    @unlink($cookieFile);
}

$UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
    . '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

function yfGet(string $url, string $cookieFile, string $UA, array $extraHeaders = []): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_USERAGENT      => $UA,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => array_merge([
            'Accept-Language: en-US,en;q=0.9',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
        ], $extraHeaders),
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => (string)($body ?: ''), 'code' => $code];
}

// ── Step 1: Visit the ticker's own quote page to establish session & get crumb ─
$quotePage = yfGet(
    "https://finance.yahoo.com/quote/{$ticker}/",
    $cookieFile,
    $UA,
    ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']
);

// Validate that the ticker exists (Yahoo 404s redirect but keep 200 with "lookup" in URL)
if ($quotePage['code'] !== 200) {
    echo json_encode(['success' => false, 'error' => "Ticker \"{$ticker}\" not found on Yahoo Finance."]);
    exit;
}

// ── Step 2: Extract crumb from page HTML (most reliable method) ───────────────
$crumb = '';
if (preg_match('/"crumb":"([^"]{5,30})"/', $quotePage['body'], $m)) {
    $crumb = stripcslashes($m[1]); // handle unicode escapes like /
}

// Fallback: hit the crumb API endpoint with the freshly acquired cookies
if (empty($crumb)) {
    $cr = yfGet(
        'https://query1.finance.yahoo.com/v1/test/getcrumb',
        $cookieFile,
        $UA,
        ['Accept: */*', "Referer: https://finance.yahoo.com/quote/{$ticker}/"]
    );
    $candidate = trim($cr['body']);
    if ($cr['code'] === 200 && strlen($candidate) >= 5 && strlen($candidate) <= 30
        && !str_contains($candidate, '<') && !str_contains($candidate, '{')) {
        $crumb = $candidate;
    }
}

if (empty($crumb)) {
    echo json_encode(['success' => false, 'error' => 'Could not authenticate with Yahoo Finance. Please try again in a few seconds.']);
    exit;
}

$crumbQ  = '&crumb=' . urlencode($crumb);
$apiBase = 'https://query1.finance.yahoo.com/v10/finance/quoteSummary/' . $ticker;
$apiHdrs = [
    'Accept: application/json',
    "Referer: https://finance.yahoo.com/quote/{$ticker}/",
];
$commonQ = '&formatted=false&lang=en-US&region=US';

// ── Step 3: Fetch fundamentals (two batched calls) ────────────────────────────
$modules1 = 'defaultKeyStatistics,financialData,summaryDetail,incomeStatementHistory,quoteType';
$modules2 = 'cashflowStatementHistory,balanceSheetHistory';

$res1 = yfGet("{$apiBase}?modules={$modules1}{$commonQ}{$crumbQ}", $cookieFile, $UA, $apiHdrs);
$res2 = yfGet("{$apiBase}?modules={$modules2}{$commonQ}{$crumbQ}", $cookieFile, $UA, $apiHdrs);

// Retry on query2 host if query1 gave non-200
if ($res1['code'] !== 200) {
    $alt = str_replace('query1', 'query2', $apiBase);
    $res1 = yfGet("{$alt}?modules={$modules1}{$commonQ}{$crumbQ}", $cookieFile, $UA, $apiHdrs);
}
if ($res2['code'] !== 200) {
    $alt = str_replace('query1', 'query2', $apiBase);
    $res2 = yfGet("{$alt}?modules={$modules2}{$commonQ}{$crumbQ}", $cookieFile, $UA, $apiHdrs);
}

$data1 = json_decode($res1['body'], true);
$data2 = json_decode($res2['body'], true);

if (empty($data1['quoteSummary']['result'][0])) {
    $errMsg = $data1['quoteSummary']['error']['description']
        ?? "No data found for \"{$ticker}\". It may be delisted or unsupported.";
    echo json_encode(['success' => false, 'error' => $errMsg]);
    exit;
}

$r1 = $data1['quoteSummary']['result'][0];
$r2 = $data2['quoteSummary']['result'][0] ?? [];

// ── Safe extractor for Yahoo Finance value objects ────────────────────────────
function val($node, ...$path) {
    $cur = $node;
    foreach ($path as $key) {
        if (!is_array($cur) || !array_key_exists($key, $cur)) return null;
        $cur = $cur[$key];
    }
    if (is_array($cur) && isset($cur['raw'])) return $cur['raw'];
    return is_numeric($cur) ? (float)$cur : null;
}

// ── Parse sections ────────────────────────────────────────────────────────────
$stats      = $r1['defaultKeyStatistics']  ?? [];
$finData    = $r1['financialData']         ?? [];
$summary    = $r1['summaryDetail']         ?? [];
$incomeHist = $r1['incomeStatementHistory']['incomeStatementHistory'] ?? [];
$quoteType  = $r1['quoteType']             ?? [];
$cfHistory  = $r2['cashflowStatementHistory']['cashflowStatements']  ?? [];

// ── Company ───────────────────────────────────────────────────────────────────
$companyName  = $quoteType['longName']  ?? $quoteType['shortName'] ?? $ticker;
$currentPrice = val($finData, 'currentPrice')
    ?? val($summary, 'regularMarketPrice')
    ?? val($summary, 'previousClose')
    ?? 0;
$currency     = $finData['financialCurrency'] ?? $quoteType['currency'] ?? 'USD';

// ── Core metrics ──────────────────────────────────────────────────────────────
$beta              = val($stats,   'beta')              ?? val($summary, 'beta') ?? 1.0;
$sharesOutstanding = val($stats,   'sharesOutstanding') ?? val($finData, 'sharesOutstanding') ?? 1;
$marketCap         = val($summary, 'marketCap')         ?? ($currentPrice * $sharesOutstanding);
$totalDebt         = val($finData, 'totalDebt')         ?? 0;
$cash              = val($finData, 'totalCash')         ?? 0;
$ebitda            = val($finData, 'ebitda')            ?? 0;

// ── Income statement (latest annual) ─────────────────────────────────────────
$latestInc       = $incomeHist[0] ?? [];
$interestExpense = abs(val($latestInc, 'interestExpense') ?? 0);
$incomeTax       = val($latestInc, 'incomeTaxExpense')   ?? 0;
$pretaxIncome    = val($latestInc, 'incomeBeforeTax')    ?? 0;
$totalRevenue    = val($latestInc, 'totalRevenue')       ?? 0;
$ebit            = val($latestInc, 'ebit')               ?? 0;

$taxRate = ($pretaxIncome > 0 && $incomeTax > 0) ? ($incomeTax / $pretaxIncome) : 0.21;
$taxRate = max(0.05, min(0.45, $taxRate));

$da = ($ebitda > 0 && $ebit > 0) ? ($ebitda - $ebit) : 0;

// ── WACC components ───────────────────────────────────────────────────────────
$costOfDebt   = ($totalDebt > 0 && $interestExpense > 0)
    ? max(0.01, min(0.20, $interestExpense / $totalDebt)) : 0.04;
$totalCapital = $marketCap + $totalDebt;
$equityWeight = $totalCapital > 0 ? $marketCap  / $totalCapital : 0.8;
$debtWeight   = $totalCapital > 0 ? $totalDebt  / $totalCapital : 0.2;

// ── Historical FCF ────────────────────────────────────────────────────────────
$historicalFCF = [];
foreach (array_reverse(array_slice($cfHistory, 0, 3)) as $cf) {
    $cfo   = val($cf, 'totalCashFromOperatingActivities') ?? 0;
    $capex = val($cf, 'capitalExpenditures') ?? 0;
    $historicalFCF[] = [
        'year'  => substr($cf['endDate']['fmt'] ?? '', 0, 4),
        'cfo'   => $cfo,
        'capex' => $capex,
        'fcf'   => $cfo + $capex,
    ];
}

// FCF CAGR
$fcfCagr   = 0.05;
$latestFCF = val($finData, 'freeCashflow') ?? 0;

if (count($historicalFCF) >= 2) {
    $first = $historicalFCF[0]['fcf'];
    $last  = end($historicalFCF)['fcf'];
    $n     = count($historicalFCF) - 1;
    if ($first > 0 && $last > 0 && $n > 0) {
        $fcfCagr = max(-0.20, min(0.50, pow($last / $first, 1 / $n) - 1));
    }
    $latestFCF = end($historicalFCF)['fcf'] ?: $latestFCF;
}

// ── Output ────────────────────────────────────────────────────────────────────
echo json_encode([
    'success' => true,
    'company' => [
        'name'         => $companyName,
        'ticker'       => $ticker,
        'currentPrice' => $currentPrice,
        'currency'     => $currency,
    ],
    'wacc_inputs' => [
        'beta'              => round($beta, 2),
        'sharesOutstanding' => $sharesOutstanding,
        'marketCap'         => $marketCap,
        'totalDebt'         => $totalDebt,
        'cash'              => $cash,
        'equityWeight'      => round($equityWeight, 4),
        'debtWeight'        => round($debtWeight, 4),
        'interestExpense'   => $interestExpense,
        'costOfDebt'        => round($costOfDebt, 4),
        'taxRate'           => round($taxRate, 4),
        'ebitda'            => $ebitda,
        'da'                => $da,
        'ebit'              => $ebit,
    ],
    'historical_fcf' => $historicalFCF,
    'fcf_cagr'       => round($fcfCagr, 4),
    'latest_fcf'     => $latestFCF,
    'income_statement' => [
        'revenue'          => $totalRevenue,
        'ebit'             => $ebit,
        'interestExpense'  => $interestExpense,
        'incomeTaxExpense' => $incomeTax,
        'pretaxIncome'     => $pretaxIncome,
        'taxRate'          => round($taxRate, 4),
        'da'               => $da,
    ],
], JSON_NUMERIC_CHECK);
