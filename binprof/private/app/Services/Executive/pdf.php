<?php
declare(strict_types=1);

require_once PROJECT . '/private/app/Services/exports.php';
require_once PROJECT . '/private/vendor/fpdf/fpdf.php';

const EXEC_PDF_COLORS = ['green'=>[27,119,94],'yellow'=>[177,109,15],'red'=>[170,50,56],'gray'=>[111,125,139]];

function executivePdfText(FPDF $pdf,string $value,float $width): string
{
    $oneLine = preg_replace('/[\x00-\x1F\x7F]/u',' ',$value);
    $plain = iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$oneLine) ?: '';
    if ($pdf->GetStringWidth($plain)<=($width-3)) {
        return $plain;
    }
    while ($plain!=='' && $pdf->GetStringWidth($plain . '...')>($width-3)) {
        $plain = substr($plain,0,-1);
    }
    return $plain . '...';
}

function executivePdfLine(FPDF $pdf,float $x,float $y,float $width,string $text,float $height=5): void
{
    $pdf->SetXY($x,$y);
    $pdf->Cell($width,$height,executivePdfText($pdf,$text,$width));
}

function executivePdfMap(FPDF $pdf,array $rows,string $layer): void
{
    $pdf->SetFillColor(228,239,241);
    $pdf->SetDrawColor(176,196,203);
    $pdf->Rect(10,79,159,78,'DF');
    $points = array_values(array_filter($rows,static fn(array $r): bool => $r['latitude']!==null && $r['longitude']!==null));
    if (!$points) {
        executivePdfLine($pdf,15,111,149,'Koordinat fasilitas dalam filter belum tersedia.');
        return;
    }
    $west = min(93.0,min(array_column($points,'longitude')));
    $east = max(144.0,max(array_column($points,'longitude')));
    $south = min(-13.0,min(array_column($points,'latitude')));
    $north = max(9.0,max(array_column($points,'latitude')));
    $scale = min(153/($east-$west),72/($north-$south));
    $centerX = ($east+$west)/2;
    $centerY = ($north+$south)/2;
    $project = static fn($x,$y): array => [89.5+($x-$centerX)*$scale,118-($y-$centerY)*$scale];
    $land = json_decode((string) file_get_contents(PROJECT . '/public/assets/data/land-110m.geojson'),true,flags:JSON_THROW_ON_ERROR);
    $pdf->SetDrawColor(123,157,155);
    $pdf->SetLineWidth(0.18);
    foreach ($land['features'] ?? [] as $feature) {
        $shape = $feature['geometry'];
        $polygons = match($shape['type']) {'Polygon'=>[$shape['coordinates']],'MultiPolygon'=>$shape['coordinates'],default=>[]};
        foreach ($polygons as $rings) {
            foreach ($rings as $ring) {
                $previous = null;
                foreach ($ring as [$lon,$lat]) {
                    $point = $lon >= $west && $lon <= $east && $lat >= $south && $lat <= $north ? $project($lon,$lat) : null;
                    if ($previous && $point) {
                        $pdf->Line(...[...$previous,...$point]);
                    }
                    $previous = $point;
                }
            }
        }
    }
    foreach ($points as $row) {
        [$x,$y] = $project((float) $row['longitude'],(float) $row['latitude']);
        $level = $layer==='situation' ? $row['condition'] : ($row['assessments'][$layer]['level'] ?? 'gray');
        $pdf->SetFillColor(...EXEC_PDF_COLORS[$level]);
        $pdf->Rect($x-0.9,$y-0.9,1.8,1.8,'F');
    }
}

function executiveReport(array $user,array $data): never
{
    $pdf = new FPDF('L','mm','A4');
    $pdf->SetMargins(10,10,10);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetTitle('BINPROFKES - Peta Kendali Pimpinan');
    $pdf->AddPage();
    $pdf->SetTextColor(21,49,60);
    $pdf->SetFont('Helvetica','B',17);
    executivePdfLine($pdf,10,8,277,'BINPROFKES | Peta Kendali Pimpinan');
    $pdf->SetFont('Helvetica','',9);
    $f = $data['filters'];
    $mode = ['now'=>'Saat ini','history'=>'Historis','projection'=>'Proyeksi'][$f['mode']];
    executivePdfLine($pdf,10,19,277,"Privat | $mode | Acuan " . ($data['reference'] ?? 'belum dipilih') . ' | ' . $data['scope']
        . ' | Sumber terbaru ' . ($data['source_at'] ? $data['source_at'] . ' UTC' : 'belum tersedia'));
    $parts = [EXEC_LAYERS[$f['layer']], $f['months'] . ' bulan', $f['metric']==='all' ? 'semua indikator' : $data['labels'][$f['metric']]];
    if ($f['q']!=='') {
        $parts[] = 'pencarian aktif';
    }
    foreach (['command_id'=>['Komando','commands'],'unit_id'=>['Satuan','units'],'type_id'=>['Jenis','facility_types'],
        'profession_id'=>['Profesi','professions'],'specialty_id'=>['Spesialisasi','specialties'],'facility_id'=>['Fasilitas','facilities']] as $key=>[$label,$table]) {
        if ($f[$key]!==null) {
            // Export names only when present in the authorized result or in the shared profession dictionaries.
            $name = match($key) {
                'command_id'=>$data['rows'][0]['command_name'] ?? null, 'unit_id'=>$data['rows'][0]['unit'] ?? null,
                'type_id'=>$data['rows'][0]['kind'] ?? null,'facility_id'=>$data['rows'][0]['name'] ?? null,
                default=>qu("SELECT name FROM $table WHERE id=?",[$f[$key]])->fetchColumn() ?: null,
            };
            $parts[] = $label . ' ' . ($name ?? 'terfilter');
        }
    }
    executivePdfLine($pdf,10,26,277,implode(' | ',$parts));
    foreach (array_keys($data['labels']) as $index=>$key) {
        $x = 10+$index*46.3;
        $pdf->SetFillColor(235,243,245);
        $pdf->Rect($x,37,44.3,29,'F');
        $pdf->SetFont('Helvetica','',8);
        $pdf->SetXY($x+1,39);
        $pdf->MultiCell(42,4,iconv('UTF-8','Windows-1252//TRANSLIT',$data['labels'][$key]));
        $pdf->SetFont('Helvetica','B',12);
        executivePdfLine($pdf,$x+1,52,42,(string) ($data['kpi'][$key] ?? 'Belum tersedia'));
        $pdf->SetFont('Helvetica','',7);
        executivePdfLine($pdf,$x+1,59,42,$data['covered'][$key] . ' fasilitas dengan data');
    }
    $pdf->SetFont('Helvetica','B',10);
    executivePdfLine($pdf,10,71,159,'Snapshot peta | ' . EXEC_LAYERS[$f['layer']]);
    executivePdfLine($pdf,175,71,112,'Lima prioritas utama');
    $pdf->SetFont('Helvetica','',9);
    executivePdfMap($pdf,$data['rows'],$f['layer']);
    $labels = ['green'=>'Memenuhi','yellow'=>'Perhatian','red'=>'Prioritas tinggi','gray'=>'Data belum cukup'];
    foreach (array_keys($labels) as $i=>$level) {
        $left = 10+$i*40;
        $pdf->SetFillColor(...EXEC_PDF_COLORS[$level]);
        $pdf->Rect($left,160,3,3,'F');
        executivePdfLine($pdf,$left+4,159,36,$labels[$level]);
    }
    if (!$data['priorities']) {
        executivePdfLine($pdf,175,81,112,'Belum ada prioritas tercatat dalam filter.');
    }
    foreach ($data['priorities'] as $i=>$issue) {
        $y = 79+$i*17;
        $pdf->SetTextColor(...EXEC_PDF_COLORS[$issue['level']]);
        $pdf->SetFont('Helvetica','B',9);
        executivePdfLine($pdf,175,$y,112,($i+1) . '. ' . $issue['facility'] . ' | ' . EXEC_ISSUES[$issue['kind']]);
        $pdf->SetTextColor(21,49,60);
        $pdf->SetFont('Helvetica','',8);
        executivePdfLine($pdf,175,$y+5,112,$issue['reason'] . ' | ' . $issue['state']);
        executivePdfLine($pdf,175,$y+10,112,'PIC ' . ($issue['pic'] ?? 'belum tersedia') . ' | Tenggat ' . ($issue['due'] ?? '-'));
    }
    $progress = [];
    foreach ($data['progress'] as $key=>$count) {
        $progress[] = EXEC_STATES[$key] . ' ' . ($count ?? '-');
    }
    $pdf->SetFont('Helvetica','B',9);
    executivePdfLine($pdf,10,172,277,'Progres | ' . implode('  /  ',$progress));
    $pdf->SetFont('Helvetica','',8);
    executivePdfLine($pdf,10,181,277,'Sumber indikator | Fasilitas, roster, target dan aturan terverifikasi, Diklat/Sertifikat, Pensiun/Petikan, kejadian peta dan arahan.');
    executivePdfLine($pdf,10,187,277,$data['status'] ?? 'Cakupan angka mengikuti fasilitas dengan catatan yang tersedia. Peta vektor memakai Natural Earth.');
    audit($user['id'],'export_pdf','executive_dashboard');
    sendPdfAttachment($pdf,'peta-kendali-pimpinan.pdf');
}
