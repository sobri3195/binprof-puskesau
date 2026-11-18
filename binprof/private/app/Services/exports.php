<?php
declare(strict_types=1);

function sendExport(string $format, string $title, array $columns, array $rows): never
{
    if (count($rows) > 1000) {
        fail(422, 'Persempit filter hingga paling banyak 1.000 hasil.');
    }
    $slug = strtolower($title);
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $slug . '.csv"');
        $stream = fopen('php://output', 'wb');
        fputcsv($stream, $columns, ',', '"', '');
        foreach ($rows as $row) {
            $safe = array_map(static function (mixed $value): mixed {
                if (is_string($value) && preg_match('/^(?:[\x00-\x20\p{Z}]*[=+@-]|[\t\r\n])/u', $value)) {
                    return "'" . $value;
                }
                return $value;
            }, $row);
            fputcsv($stream, $safe, ',', '"', '');
        }
        exit;
    }
    if ($format === 'xlsx') {
        require_once PROJECT . '/private/vendor/simplexlsxgen/SimpleXLSXGen.php';
        $rows = array_map(static fn (array $row): array => array_map(
            static fn (mixed $value): mixed => is_string($value) ? Shuchkin\SimpleXLSXGen::raw($value) : $value,
            array_values($row)
        ), $rows);
        if (!Shuchkin\SimpleXLSXGen::fromArray([$columns, ...$rows], $title)->downloadAs($slug . '.xlsx')) {
            throw new RuntimeException('Unable to stream spreadsheet');
        }
        exit;
    }
    require_once PROJECT . '/private/vendor/fpdf/fpdf.php';
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->SetTitle($title);
    $pdf->AddPage();
    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'BINPROFKES / ' . $title, 0, 1);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->Cell(0, 8, 'Privat - ' . day(), 0, 1);
    foreach ($rows as $row) {
        $pdf->Ln(2);
        foreach (array_values($row) as $i => $value) {
            $line = $columns[$i] . ': ' . preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value);
            $pdf->MultiCell(0, 5, iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $line) ?: '');
        }
    }
    sendPdfAttachment($pdf, $slug . '.pdf');
}

function sendPdfAttachment(FPDF $pdf, string $filename): never
{
    $bytes = $pdf->Output('S');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, private');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
}
