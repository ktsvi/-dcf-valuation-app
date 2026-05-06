<?php
// Validate request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

$type      = in_array($_POST['type'] ?? '', ['excel', 'pdf']) ? $_POST['type'] : null;
$modelJson = $_POST['modelData'] ?? '';
if (!$type || !$modelJson) { http_response_code(400); exit('Missing parameters'); }

$model = json_decode($modelJson, true);
if (!$model || !isset($model['company'], $model['_calc'])) { http_response_code(400); exit('Invalid data'); }

require_once __DIR__ . '/vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

$co      = $model['company'];
$inputs  = $model['wacc_inputs'];
$calc    = $model['_calc'];
$histFCF = $model['historical_fcf'] ?? [];
$ticker  = $co['ticker'];
$today   = date('Y-m-d');

// ── Shared helpers ────────────────────────────────────────────────────────────
function fmtB($n) {
    if ($n === null || !is_numeric($n)) return 'N/A';
    $a = abs($n); $s = $n < 0 ? '-' : '';
    if ($a >= 1e12) return $s . '$' . number_format($a / 1e12, 2) . 'T';
    if ($a >= 1e9)  return $s . '$' . number_format($a / 1e9,  2) . 'B';
    if ($a >= 1e6)  return $s . '$' . number_format($a / 1e6,  2) . 'M';
    return $s . '$' . number_format($a, 0);
}
function fmtP($n) { return is_numeric($n) ? '$' . number_format($n, 2) : 'N/A'; }
function fmtPct($n) { return is_numeric($n) ? number_format($n * 100, 2) . '%' : 'N/A'; }

function sensitivityIntrinsic($calc, $wDelta, $gDelta) {
    $w    = $calc['wacc'] + $wDelta;
    $gi   = $calc['g']    + $gDelta;
    $fcfs = $calc['fcfs'];
    $sumPV = array_reduce(array_keys($fcfs), function($s, $i) use ($fcfs, $w) {
        return $s + $fcfs[$i] / pow(1 + $w, $i + 1);
    }, 0);
    $tv = $calc['tvMethod'] === 'gordon'
        ? ($w > $gi ? $fcfs[4] * (1 + $gi) / ($w - $gi) : 0)
        : ($calc['ebitda5'] * $calc['evMultiple']);
    $pvTV = $tv / pow(1 + $w, 5);
    return ($sumPV + $pvTV - $calc['netDebt']) / $calc['shares'];
}

// ══════════════════════════════════════════════════════════════════════════════
// EXCEL EXPORT
// ══════════════════════════════════════════════════════════════════════════════
if ($type === 'excel') {

    $wb = new Spreadsheet();

    // ── Styles ────────────────────────────────────────────────────────────────
    $hdrStyle = [
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 11],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '0D1B2A']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ];
    $subHdrStyle = [
        'font' => ['bold' => true, 'size' => 10],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EAED']],
    ];
    $highlightStyle = [
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A73E8']],
    ];
    $labelStyle = [
        'font' => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '80868B']],
    ];
    $borderThin = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'E8EAED']]]];

    // ═══════════════════════════════════════════════════════════════════════
    // Sheet 1 – DCF Model
    // ═══════════════════════════════════════════════════════════════════════
    $ws1 = $wb->getActiveSheet()->setTitle('DCF Model');

    $ws1->getColumnDimension('A')->setWidth(32);
    foreach (['B','C','D','E','F','G'] as $col) $ws1->getColumnDimension($col)->setWidth(18);

    $row = 1;

    // Title
    $ws1->setCellValue("A{$row}", "DCF VALUATION REPORT — {$co['name']} ({$ticker})");
    $ws1->mergeCells("A{$row}:G{$row}");
    $ws1->getStyle("A{$row}")->applyFromArray($hdrStyle)->getFont()->setSize(13);
    $ws1->getRowDimension($row)->setRowHeight(28);
    $row++;

    $ws1->setCellValue("A{$row}", "Analysis Date: {$today}   |   Market Price: " . fmtP($co['currentPrice']) . "   |   Currency: {$co['currency']}");
    $ws1->mergeCells("A{$row}:G{$row}");
    $ws1->getStyle("A{$row}")->applyFromArray($subHdrStyle);
    $row += 2;

    // ── WACC Section ──────────────────────────────────────────────────────
    $ws1->setCellValue("A{$row}", 'WACC INPUTS');
    $ws1->mergeCells("A{$row}:G{$row}");
    $ws1->getStyle("A{$row}")->applyFromArray($hdrStyle);
    $row++;

    $waccRows = [
        ['Beta (β)',                   number_format($calc['beta'], 2)],
        ['Risk-Free Rate (Rf)',         fmtPct($calc['rf'])],
        ['Equity Risk Premium (ERP)',   fmtPct($calc['erp'])],
        ['Cost of Equity (Re = CAPM)', fmtPct($calc['re'])],
        ['Cost of Debt (Rd, pre-tax)', fmtPct($calc['rd'])],
        ['Tax Rate',                   fmtPct($calc['taxRate'])],
        ['Cost of Debt (after-tax)',   fmtPct($calc['rd'] * (1 - $calc['taxRate']))],
        ['Equity Weight (E/V)',        fmtPct($calc['ew'])],
        ['Debt Weight (D/V)',          fmtPct($calc['dw'])],
    ];
    foreach ($waccRows as $r) {
        $ws1->setCellValue("A{$row}", $r[0]);
        $ws1->setCellValue("B{$row}", $r[1]);
        $ws1->getStyle("A{$row}:G{$row}")->applyFromArray($borderThin);
        $row++;
    }

    $ws1->setCellValue("A{$row}", 'WACC');
    $ws1->setCellValue("B{$row}", fmtPct($calc['wacc']));
    $ws1->getStyle("A{$row}:G{$row}")->applyFromArray($highlightStyle);
    $ws1->getStyle("A{$row}:B{$row}")->getFont()->setSize(12);
    $row += 2;

    // ── Historical FCF ────────────────────────────────────────────────────
    $ws1->setCellValue("A{$row}", 'HISTORICAL FREE CASH FLOW');
    $ws1->mergeCells("A{$row}:G{$row}");
    $ws1->getStyle("A{$row}")->applyFromArray($hdrStyle);
    $row++;

    $ws1->setCellValue("A{$row}", 'Year');
    $ws1->setCellValue("B{$row}", 'Operating CF');
    $ws1->setCellValue("C{$row}", 'CapEx');
    $ws1->setCellValue("D{$row}", 'FCF');
    $ws1->getStyle("A{$row}:D{$row}")->applyFromArray($subHdrStyle);
    $row++;

    foreach ($histFCF as $h) {
        $ws1->setCellValue("A{$row}", $h['year'] . ' (Actual)');
        $ws1->setCellValue("B{$row}", $h['cfo']);
        $ws1->setCellValue("C{$row}", $h['capex']);
        $ws1->setCellValue("D{$row}", $h['fcf']);
        foreach (['B','C','D'] as $c) {
            $ws1->getStyle("{$c}{$row}")->getNumberFormat()->setFormatCode('"$"#,##0');
        }
        $ws1->getStyle("A{$row}:G{$row}")->applyFromArray($borderThin);
        $row++;
    }
    $row++;

    // ── FCF Projections ───────────────────────────────────────────────────
    $ws1->setCellValue("A{$row}", 'FCF PROJECTIONS (5-YEAR)');
    $ws1->mergeCells("A{$row}:G{$row}");
    $ws1->getStyle("A{$row}")->applyFromArray($hdrStyle);
    $row++;

    $ws1->setCellValue("A{$row}", 'Period');
    $ws1->setCellValue("B{$row}", 'Projected FCF');
    $ws1->setCellValue("C{$row}", 'Discount Factor');
    $ws1->setCellValue("D{$row}", 'PV of FCF');
    $ws1->getStyle("A{$row}:D{$row}")->applyFromArray($subHdrStyle);
    $row++;

    $yr0 = (int)date('Y');
    foreach ($calc['fcfs'] as $i => $fcf) {
        $t    = $i + 1;
        $df   = 1 / pow(1 + $calc['wacc'], $t);
        $pvF  = $fcf * $df;
        $ws1->setCellValue("A{$row}", "Year {$t} ({$yr0} + {$t})");
        $ws1->setCellValue("B{$row}", $fcf);
        $ws1->setCellValue("C{$row}", number_format($df, 4));
        $ws1->setCellValue("D{$row}", $pvF);
        $ws1->getStyle("B{$row}")->getNumberFormat()->setFormatCode('"$"#,##0');
        $ws1->getStyle("D{$row}")->getNumberFormat()->setFormatCode('"$"#,##0');
        $ws1->getStyle("A{$row}:G{$row}")->applyFromArray($borderThin);
        $row++;
    }

    $ws1->setCellValue("A{$row}", 'Sum of PV(FCFs)');
    $ws1->setCellValue("D{$row}", $calc['sumPV']);
    $ws1->getStyle("D{$row}")->getNumberFormat()->setFormatCode('"$"#,##0');
    $ws1->getStyle("A{$row}:G{$row}")->applyFromArray($highlightStyle);
    $row += 2;

    // ── Terminal Value ────────────────────────────────────────────────────
    $ws1->setCellValue("A{$row}", 'TERMINAL VALUE');
    $ws1->mergeCells("A{$row}:G{$row}");
    $ws1->getStyle("A{$row}")->applyFromArray($hdrStyle);
    $row++;

    $tvRows = [
        ['Method Selected',          $calc['tvMethod'] === 'gordon' ? 'Gordon Growth Model' : 'Exit EV/EBITDA Multiple'],
        ['Perpetuity Growth Rate (g)',fmtPct($calc['g'])],
        ['Terminal Value (Gordon)',   fmtB($calc['tvGordon'])],
        ['EV/EBITDA Multiple',        number_format($calc['evMultiple'], 1) . 'x'],
        ['Year-5 EBITDA',            fmtB($calc['ebitda5'])],
        ['Terminal Value (Exit)',     fmtB($calc['tvExit'])],
        ['Terminal Value Used',       fmtB($calc['tv'])],
        ['PV of Terminal Value',      fmtB($calc['pvTV'])],
    ];
    foreach ($tvRows as $r) {
        $ws1->setCellValue("A{$row}", $r[0]);
        $ws1->setCellValue("B{$row}", $r[1]);
        $ws1->getStyle("A{$row}:G{$row}")->applyFromArray($borderThin);
        $row++;
    }
    $row++;

    // ── Valuation Results ─────────────────────────────────────────────────
    $ws1->setCellValue("A{$row}", 'VALUATION RESULTS');
    $ws1->mergeCells("A{$row}:G{$row}");
    $ws1->getStyle("A{$row}")->applyFromArray($hdrStyle);
    $row++;

    $resultRows = [
        ['Sum of PV(FCFs)',             fmtB($calc['sumPV'])],
        ['PV of Terminal Value',        fmtB($calc['pvTV'])],
        ['Enterprise Value (EV)',       fmtB($calc['ev'])],
        ['(−) Net Debt',                fmtB($calc['netDebt'])],
        ['Equity Value',                fmtB($calc['equityVal'])],
        ['Shares Outstanding',          number_format($calc['shares'])],
        ['Current Market Price',        fmtP($calc['mktPrice'])],
        ['Intrinsic Value / Share',     fmtP($calc['intrinsic'])],
        ['Upside / (Downside)',         number_format($calc['upside'], 1) . '%'],
    ];
    foreach ($resultRows as $i => $r) {
        $ws1->setCellValue("A{$row}", $r[0]);
        $ws1->setCellValue("B{$row}", $r[1]);
        if ($i >= 6) {
            $ws1->getStyle("A{$row}:G{$row}")->applyFromArray($highlightStyle);
        } else {
            $ws1->getStyle("A{$row}:G{$row}")->applyFromArray($borderThin);
        }
        $row++;
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Sheet 2 – Sensitivity Analysis
    // ═══════════════════════════════════════════════════════════════════════
    $wb->createSheet()->setTitle('Sensitivity');
    $ws2 = $wb->getSheetByName('Sensitivity');

    $wSteps = [-0.02, -0.015, -0.01, -0.005, 0, 0.005, 0.01, 0.015, 0.02];
    $gSteps = [-0.015, -0.01, -0.005, 0, 0.005, 0.01, 0.015];

    $ws2->setCellValue('A1', "SENSITIVITY: Intrinsic Value/Share vs WACC & Terminal Growth Rate");
    $ws2->mergeCells('A1:J1');
    $ws2->getStyle('A1')->applyFromArray($hdrStyle);
    $ws2->getRowDimension(1)->setRowHeight(22);

    $ws2->setCellValue('A2', 'WACC \\ g →');
    $ws2->getStyle('A2')->applyFromArray($subHdrStyle);
    $ws2->getColumnDimension('A')->setWidth(12);

    foreach ($gSteps as $ci => $gd) {
        $col = chr(ord('B') + $ci);
        $ws2->setCellValue("{$col}2", number_format(($calc['g'] + $gd) * 100, 1) . '%');
        $ws2->getStyle("{$col}2")->applyFromArray($subHdrStyle);
        $ws2->getStyle("{$col}2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $ws2->getColumnDimension($col)->setWidth(12);
    }

    foreach ($wSteps as $ri => $wd) {
        $rowNum = $ri + 3;
        $ws2->setCellValue("A{$rowNum}", number_format(($calc['wacc'] + $wd) * 100, 1) . '%');
        $ws2->getStyle("A{$rowNum}")->applyFromArray($subHdrStyle);

        foreach ($gSteps as $ci => $gd) {
            $col   = chr(ord('B') + $ci);
            $intr  = sensitivityIntrinsic($calc, $wd, $gd);
            $cell  = "{$col}{$rowNum}";
            $ws2->setCellValue($cell, $intr);
            $ws2->getStyle($cell)->getNumberFormat()->setFormatCode('"$"#,##0.00');
            $ws2->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            $isBase  = $wd === 0 && $gd === 0;
            $isUnder = $intr >= $calc['mktPrice'];
            $bgColor = $isUnder ? 'C6EFCE' : 'FFC7CE';
            $fgColor = $isUnder ? '276221' : '9C0006';

            $ws2->getStyle($cell)->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bgColor]],
                'font'      => ['color' => ['rgb' => $fgColor], 'bold' => $isBase],
                'borders'   => $isBase
                    ? ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => '0D1B2A']]]
                    : ['allBorders' => ['borderStyle' => Border::BORDER_THIN,   'color' => ['rgb' => 'E8EAED']]],
            ]);
        }
    }

    // Legend
    $legRow = count($wSteps) + 4;
    $ws2->setCellValue("A{$legRow}", '■ Green = Undervalued (intrinsic > market price)   ■ Red = Overvalued   Bold outline = Base case');
    $ws2->mergeCells("A{$legRow}:J{$legRow}");

    // ═══════════════════════════════════════════════════════════════════════
    // Sheet 3 – Raw Data
    // ═══════════════════════════════════════════════════════════════════════
    $wb->createSheet()->setTitle('Raw Data');
    $ws3 = $wb->getSheetByName('Raw Data');
    $ws3->getColumnDimension('A')->setWidth(30);
    $ws3->getColumnDimension('B')->setWidth(22);

    $ws3->setCellValue('A1', 'RAW FINANCIAL DATA (from Yahoo Finance)');
    $ws3->mergeCells('A1:B1');
    $ws3->getStyle('A1')->applyFromArray($hdrStyle);

    $rawRows = [
        ['Company Name',       $co['name']],
        ['Ticker',             $co['ticker']],
        ['Current Price',      fmtP($co['currentPrice'])],
        ['Currency',           $co['currency']],
        ['Beta',               number_format($inputs['beta'], 2)],
        ['Market Cap',         fmtB($inputs['marketCap'])],
        ['Total Debt',         fmtB($inputs['totalDebt'])],
        ['Cash & Equivalents', fmtB($inputs['cash'])],
        ['Shares Outstanding', number_format($inputs['sharesOutstanding'])],
        ['Interest Expense',   fmtB($inputs['interestExpense'])],
        ['Tax Rate',           fmtPct($inputs['taxRate'])],
        ['EBITDA',             fmtB($inputs['ebitda'])],
        ['EBIT',               fmtB($inputs['ebit'])],
        ['D&A',                fmtB($inputs['da'])],
        ['Cost of Debt (raw)', fmtPct($inputs['costOfDebt'])],
        ['Data Fetch Date',    date('Y-m-d H:i:s')],
    ];
    foreach ($rawRows as $i => $r) {
        $r2 = $i + 2;
        $ws3->setCellValue("A{$r2}", $r[0]);
        $ws3->setCellValue("B{$r2}", $r[1]);
        $ws3->getStyle("A{$r2}")->getFont()->setBold(true);
        if ($i % 2 === 0) {
            $ws3->getStyle("A{$r2}:B{$r2}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8F9FA']],
            ]);
        }
    }

    // ── Stream file ───────────────────────────────────────────────────────
    $wb->setActiveSheetIndex(0);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header("Content-Disposition: attachment; filename=\"DCF_{$ticker}_{$today}.xlsx\"");
    header('Cache-Control: max-age=0');

    $writer = new Xlsx($wb);
    $writer->save('php://output');
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// PDF EXPORT (TCPDF)
// ══════════════════════════════════════════════════════════════════════════════
if ($type === 'pdf') {

    $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('DCF Valuation App');
    $pdf->SetAuthor('DCF Valuation');
    $pdf->SetTitle("DCF Analysis: {$ticker}");
    $pdf->SetSubject('Discounted Cash Flow Model');
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('helvetica', '', 9);

    $navy  = [13,  27,  42];
    $blue  = [26, 115, 232];
    $green = [30, 142,  62];
    $red   = [217, 48, 37];
    $lgray = [241, 243, 244];

    // ── Helper: section header ────────────────────────────────────────────
    $sectionHeader = function($title) use ($pdf, $navy) {
        $pdf->SetFillColor(...$navy);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 7, $title, 0, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Ln(1);
    };

    // ── Title block ───────────────────────────────────────────────────────
    $pdf->SetFillColor(...$navy);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, "DCF VALUATION REPORT — {$co['name']} ({$ticker})", 0, 1, 'C', true);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 6, "Analysis Date: {$today}   |   Market Price: " . fmtP($co['currentPrice']) . "   |   Currency: {$co['currency']}", 0, 1, 'C', true);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(4);

    // ── WACC + Results (two-column layout) ───────────────────────────────
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(...$navy);
    $pdf->SetTextColor(255,255,255);
    $leftW = 130; $rightW = 137;
    $pdf->Cell($leftW, 7, 'WACC INPUTS', 0, 0, 'L', true);
    $pdf->Cell(3, 7, '', 0, 0);
    $pdf->Cell($rightW, 7, 'VALUATION RESULTS', 0, 1, 'L', true);
    $pdf->SetTextColor(0,0,0);
    $pdf->SetFont('helvetica', '', 9);

    $yStart  = $pdf->GetY();
    $xLeft   = 12;
    $xRight  = 12 + $leftW + 3;
    $rowH    = 6;

    // Left: WACC table — drawn with absolute positioning
    $waccPairs = [
        ['Beta (β)',                number_format($calc['beta'],2)],
        ['Risk-Free Rate',          fmtPct($calc['rf'])],
        ['Equity Risk Premium',     fmtPct($calc['erp'])],
        ['Cost of Equity (Re)',     fmtPct($calc['re'])],
        ['Cost of Debt (Rd)',       fmtPct($calc['rd'])],
        ['Tax Rate',                fmtPct($calc['taxRate'])],
        ['Equity Weight',           fmtPct($calc['ew'])],
        ['Debt Weight',             fmtPct($calc['dw'])],
        ['WACC',                    fmtPct($calc['wacc'])],
    ];
    foreach ($waccPairs as $i => $p) {
        $isWacc = ($i === count($waccPairs) - 1);
        if ($isWacc) { $pdf->SetFillColor(...$blue); $pdf->SetTextColor(255,255,255); $pdf->SetFont('helvetica','B',9); }
        elseif ($i % 2 === 0) { $pdf->SetFillColor(...$lgray); $pdf->SetTextColor(0,0,0); $pdf->SetFont('helvetica','',9); }
        else { $pdf->SetFillColor(255,255,255); $pdf->SetTextColor(0,0,0); $pdf->SetFont('helvetica','',9); }
        $pdf->SetXY($xLeft, $yStart + $i * $rowH);
        $pdf->Cell($leftW * 0.6, $rowH, $p[0], 1, 0, 'L', true);
        $pdf->Cell($leftW * 0.4, $rowH, $p[1], 1, 0, 'R', true);
    }

    // Right: Results table — same absolute Y anchored to $yStart
    $resPairs = [
        ['Sum of PV(FCFs)',         fmtB($calc['sumPV'])],
        ['PV of Terminal Value',    fmtB($calc['pvTV'])],
        ['Enterprise Value',        fmtB($calc['ev'])],
        ['(−) Net Debt',            fmtB($calc['netDebt'])],
        ['Equity Value',            fmtB($calc['equityVal'])],
        ['Shares Outstanding',      number_format($calc['shares'])],
        ['Market Price / Share',    fmtP($calc['mktPrice'])],
        ['Intrinsic Value / Share', fmtP($calc['intrinsic'])],
        ['Upside / (Downside)',     number_format($calc['upside'],1).'%'],
    ];
    foreach ($resPairs as $i => $p) {
        if ($i === 7) { $pdf->SetFillColor(...$blue); $pdf->SetTextColor(255,255,255); $pdf->SetFont('helvetica','B',9); }
        elseif (in_array($i,[6,8])) { $pdf->SetFillColor(...$lgray); $pdf->SetTextColor(0,0,0); $pdf->SetFont('helvetica','B',9); }
        elseif ($i % 2 === 0) { $pdf->SetFillColor(...$lgray); $pdf->SetTextColor(0,0,0); $pdf->SetFont('helvetica','',9); }
        else { $pdf->SetFillColor(255,255,255); $pdf->SetTextColor(0,0,0); $pdf->SetFont('helvetica','',9); }
        $pdf->SetXY($xRight, $yStart + $i * $rowH);
        $pdf->Cell($rightW * 0.6, $rowH, $p[0], 1, 0, 'L', true);
        $pdf->Cell($rightW * 0.4, $rowH, $p[1], 1, 0, 'R', true);
    }

    $maxRows = max(count($waccPairs), count($resPairs));
    $pdf->SetXY($xLeft, $yStart + $maxRows * $rowH + 4);
    $pdf->SetTextColor(0,0,0);
    $pdf->SetFont('helvetica','',9);

    // ── FCF Projections table ─────────────────────────────────────────────
    $sectionHeader('FCF PROJECTIONS (5-YEAR)');
    $colW = [50, 35, 35, 35, 35];
    $hdrs = ['Period', 'Projected FCF', 'Discount Factor', 'PV of FCF', 'Cum. PV'];
    $pdf->SetFillColor(...$lgray); $pdf->SetFont('helvetica','B',9);
    foreach ($hdrs as $ci => $h) $pdf->Cell($colW[$ci], 6, $h, 1, 0, 'C', true);
    $pdf->Ln();
    $pdf->SetFont('helvetica','',9);
    $yr0 = (int)date('Y'); $cumPV = 0;
    foreach ($calc['fcfs'] as $i => $fcf) {
        $t   = $i + 1;
        $df  = 1 / pow(1 + $calc['wacc'], $t);
        $pv  = $fcf * $df; $cumPV += $pv;
        $pdf->SetFillColor($i%2===0 ? 248:255, $i%2===0 ? 249:255, $i%2===0 ? 250:255);
        $pdf->Cell($colW[0], 6, "Year {$t} ({$yr0}+{$t})", 1, 0, 'L', true);
        $pdf->Cell($colW[1], 6, fmtB($fcf), 1, 0, 'R', true);
        $pdf->Cell($colW[2], 6, number_format($df, 4), 1, 0, 'R', true);
        $pdf->Cell($colW[3], 6, fmtB($pv), 1, 0, 'R', true);
        $pdf->Cell($colW[4], 6, fmtB($cumPV), 1, 0, 'R', true);
        $pdf->Ln();
    }
    $pdf->SetFillColor(...$blue); $pdf->SetTextColor(255,255,255); $pdf->SetFont('helvetica','B',9);
    $pdf->Cell(array_sum(array_slice($colW,0,3)), 6, 'Sum of PV(FCFs)', 1, 0, 'L', true);
    $pdf->Cell($colW[3]+$colW[4], 6, fmtB($calc['sumPV']), 1, 0, 'R', true);
    $pdf->Ln();
    $pdf->SetTextColor(0,0,0); $pdf->SetFont('helvetica','',9);
    $pdf->Ln(3);

    // ── Terminal Value ────────────────────────────────────────────────────
    $sectionHeader('TERMINAL VALUE');
    $tvData = [
        ['Method',                $calc['tvMethod']==='gordon'?'Gordon Growth Model':'Exit EV/EBITDA Multiple'],
        ['Perp. Growth Rate (g)', fmtPct($calc['g'])],
        ['TV — Gordon',           fmtB($calc['tvGordon'])],
        ['EV/EBITDA Multiple',    number_format($calc['evMultiple'],1).'x'],
        ['Year-5 EBITDA',         fmtB($calc['ebitda5'])],
        ['TV — Exit Multiple',    fmtB($calc['tvExit'])],
        ['TV Selected',           fmtB($calc['tv'])],
        ['PV of TV',              fmtB($calc['pvTV'])],
    ];
    foreach ($tvData as $i => $p) {
        $pdf->SetFillColor($i%2===0?248:255,$i%2===0?249:255,$i%2===0?250:255);
        $pdf->Cell(130, 6, $p[0], 1, 0, 'L', true);
        $pdf->Cell(50,  6, $p[1], 1, 0, 'R', true);
        $pdf->Ln();
    }
    $pdf->Ln(3);

    // ── Sensitivity table (condensed 9×7) ────────────────────────────────
    $sectionHeader('SENSITIVITY ANALYSIS — Intrinsic Value/Share (WACC × Terminal Growth Rate)');
    $wSteps = [-0.02,-0.015,-0.01,-0.005,0,0.005,0.01,0.015,0.02];
    $gSteps = [-0.015,-0.01,-0.005,0,0.005,0.01,0.015];
    $cw     = 34; $rh = 5.5;

    // Header row
    $pdf->SetFillColor(...$navy); $pdf->SetTextColor(255,255,255); $pdf->SetFont('helvetica','B',8);
    $pdf->Cell($cw, $rh+1, 'WACC \\ g →', 1, 0, 'C', true);
    foreach ($gSteps as $gd)
        $pdf->Cell($cw, $rh+1, number_format(($calc['g']+$gd)*100,1).'%', 1, 0, 'C', true);
    $pdf->Ln();
    $pdf->SetFont('helvetica','',8);

    foreach ($wSteps as $wd) {
        $pdf->SetFillColor(...$lgray); $pdf->SetTextColor(0,0,0); $pdf->SetFont('helvetica','B',8);
        $pdf->Cell($cw, $rh, number_format(($calc['wacc']+$wd)*100,1).'%', 1, 0, 'C', true);
        $pdf->SetFont('helvetica','',8);

        foreach ($gSteps as $gd) {
            $intr   = sensitivityIntrinsic($calc, $wd, $gd);
            $isBase = ($wd===0 && $gd===0);
            $isU    = $intr >= $calc['mktPrice'];
            if ($isU) { $pdf->SetFillColor(198,239,206); $pdf->SetTextColor(39,98,33); }
            else       { $pdf->SetFillColor(255,199,206); $pdf->SetTextColor(156,0,6); }
            $style = $isBase ? 'B' : '';
            $pdf->SetFont('helvetica', $style, 8);
            $pdf->Cell($cw, $rh, fmtP($intr), 1, 0, 'C', true);
        }
        $pdf->Ln();
    }
    $pdf->SetTextColor(0,0,0); $pdf->SetFont('helvetica','',8);
    $pdf->Ln(2);
    $pdf->Cell(0, 5, '■ Green = Undervalued (intrinsic ≥ market price)   ■ Red = Overvalued   Bold = Base case', 0, 1, 'L');

    // Footer
    $pdf->SetY(-12);
    $pdf->SetFont('helvetica','I',7);
    $pdf->SetTextColor(128,134,139);
    $pdf->Cell(0,5,"DCF Valuation Model — {$co['name']} ({$ticker}) — Generated {$today} — For informational purposes only.",0,0,'C');

    $pdf->Output("DCF_{$ticker}_{$today}.pdf", 'D');
    exit;
}
