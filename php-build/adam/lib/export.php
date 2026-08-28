<?php
declare(strict_types=1);

/**
 * Project exports: CSV, XLSX, DOCX.
 * DOCX built directly with manual zip construction.
 */

function handle_export(int $pid, string $format): void
{
    $project = db_row('SELECT * FROM projects WHERE id = ' . $pid);
    if (!$project) {
        throw new ApiError('Not found', 404);
    }
    $entries = db_rows("SELECT * FROM entries WHERE project_id = $pid ORDER BY element_name, id");
    $safeName = preg_replace('/[^\w\s-]/', '', $project['name']);
    $safeName = trim($safeName);
    $safeName = str_replace(' ', '_', $safeName);
    if ($safeName === '') {
        $safeName = 'project_' . $pid;
    }

    switch ($format) {
        case 'csv':
            export_csv($project, $entries, $safeName);
            break;
        case 'docx':
            export_docx($project, $entries, $safeName);
            break;
        case 'xlsx':
        default:
            export_xlsx($project, $entries, $safeName);
            break;
    }
}

// ══════════════ CSV ══════════════

function export_csv(array $project, array $entries, string $safeName): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $safeName . '_export.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Element', 'Animation', 'Looping', 'Duration', 'Description', 'Artist', 'Phase', 'Status', 'Priority', 'Projected Hours', 'Actual Hours', 'Flag']);
    $totalProjected = 0.0;
    $totalActual = 0.0;
    foreach ($entries as $e) {
        fputcsv($out, [
            $e['element_name'], $e['animation_name'], to_bool($e['looping']) ? 'Yes' : 'No',
            $e['duration'], $e['description'], $e['artist'], $e['phase'], $e['status'],
            $e['priority'], (float)$e['projected_hours'], (float)$e['actual_hours'],
            to_bool($e['alert_flag']) ? 'Flagged' : '',
        ]);
        $totalProjected += (float)$e['projected_hours'];
        $totalActual += (float)$e['actual_hours'];
    }
    fputcsv($out, ['TOTAL', '', '', '', '', '', '', '', '', number_format($totalProjected, 1, '.', ''), number_format($totalActual, 1, '.', ''), '']);
    fclose($out);
    exit;
}

// ══════════════ XLSX ══════════════

function export_xlsx(array $project, array $entries, string $safeName): void
{
    $totalHours = 0.0;
    foreach ($entries as $e) {
        $totalHours += (float)$e['actual_hours'];
    }

    $sheet1 = xlsx_summary_sheet($project, $entries, $totalHours);
    $sheet2 = xlsx_entries_sheet($entries);
    $sheet3 = xlsx_rollup_sheet($entries);

    $files = [
        '[Content_Types].xml' => xlsx_content_types(),
        '_rels/.rels' => xlsx_root_rels(),
        'xl/workbook.xml' => xlsx_workbook(),
        'xl/_rels/workbook.xml.rels' => xlsx_workbook_rels(),
        'xl/styles.xml' => xlsx_styles(),
        'xl/worksheets/sheet1.xml' => $sheet1,
        'xl/worksheets/sheet2.xml' => $sheet2,
        'xl/worksheets/sheet3.xml' => $sheet3,
    ];

    $data = build_zip($files);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $safeName . '_export.xlsx"');
    echo $data;
    exit;
}

function xlsx_content_types(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
}

function xlsx_root_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
}

function xlsx_workbook(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>'
        . '<sheet name="Summary" sheetId="1" r:id="rId1"/>'
        . '<sheet name="Entries" sheetId="2" r:id="rId2"/>'
        . '<sheet name="Rollup" sheetId="3" r:id="rId3"/>'
        . '</sheets></workbook>';
}

function xlsx_workbook_rels(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
        . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
}

function xlsx_styles(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="4">'
        . '<font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="14"/><name val="Calibri"/></font>'
        . '<font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="5">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF4472C4"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFD9E2F3"/><bgColor indexed="64"/></patternFill></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFB4C6E7"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="3" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="0" fontId="4" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

function xlsx_summary_sheet(array $project, array $entries, float $totalHours): string
{
    $totalProjected = 0.0;
    foreach ($entries as $e) {
        $totalProjected += (float)$e['projected_hours'];
    }
    $rows = [];
    $rows[] = '<row r="1"><c r="A1" t="inlineStr" s="1"><is><t>' . esc_xml($project['name']) . '</t></is></c></row>';
    $rows[] = '<row r="2"><c r="A2" t="inlineStr"><is><t>Choreography</t></is></c></row>';
    $rows[] = '<row r="3"><c r="A3" t="inlineStr"><is><t>Total Hours: ' . number_format($totalHours, 1, '.', '') . '</t></is></c></row>';
    $rows[] = '<row r="4"><c r="A4" t="inlineStr"><is><t>Projected Hours: ' . number_format($totalProjected, 1, '.', '') . '</t></is></c></row>';
    $info = [];
    if (!empty($project['game_type'])) {
        $info[] = 'Game Type: ' . esc_xml($project['game_type']);
    }
    if (!empty($project['customer'])) {
        $info[] = 'Customer: ' . esc_xml($project['customer']);
    }
    if (!empty($project['deadline'])) {
        $info[] = 'Deadline: ' . esc_xml($project['deadline']);
    }
    $r = 6;
    foreach ($info as $line) {
        $rows[] = '<row r="' . $r . '"><c r="B' . $r . '" t="inlineStr"><is><t>' . $line . '</t></is></c></row>';
        $r++;
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . implode('', $rows) . '</sheetData></worksheet>';
}

function xlsx_entries_sheet(array $entries): string
{
    $headers = ['Element', 'Animation', 'Looping', 'Duration', 'Description', 'Artist', 'Phase', 'Status', 'Priority', 'Projected Hours', 'Actual Hours', 'Flag'];
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData><row r="1">';
    foreach ($headers as $i => $h) {
        $col = chr(65 + $i);
        $xml .= '<c r="' . $col . '1" t="inlineStr" s="2"><is><t>' . esc_xml($h) . '</t></is></c>';
    }
    $xml .= '</row>';

    $rowIdx = 2;
    $totalProjected = 0.0;
    $totalActual = 0.0;
    foreach ($entries as $e) {
        $xml .= '<row r="' . $rowIdx . '">';
        $vals = [
            $e['element_name'], $e['animation_name'], to_bool($e['looping']) ? 'Yes' : 'No',
            $e['duration'], $e['description'], $e['artist'], $e['phase'], $e['status'],
            $e['priority'], (float)$e['projected_hours'], (float)$e['actual_hours'],
            to_bool($e['alert_flag']) ? 'Flagged' : '',
        ];
        foreach ($vals as $i => $v) {
            $col = chr(65 + $i);
            if (in_array($i, [9, 10], true)) {
                $xml .= '<c r="' . $col . $rowIdx . '"><v>' . (float)$v . '</v></c>';
            } else {
                $xml .= '<c r="' . $col . $rowIdx . '" t="inlineStr"><is><t>' . esc_xml((string)$v) . '</t></is></c>';
            }
        }
        $xml .= '</row>';
        $totalProjected += (float)$e['projected_hours'];
        $totalActual += (float)$e['actual_hours'];
        $rowIdx++;
    }

    $xml .= '<row r="' . $rowIdx . '">';
    $totalVals = ['TOTAL', '', '', '', '', '', '', '', '', $totalProjected, $totalActual, ''];
    foreach ($totalVals as $i => $v) {
        $col = chr(65 + $i);
        if (in_array($i, [9, 10], true)) {
            $xml .= '<c r="' . $col . $rowIdx . '" s="3"><v>' . (float)$v . '</v></c>';
        } else {
            $xml .= '<c r="' . $col . $rowIdx . '" t="inlineStr" s="3"><is><t>' . esc_xml((string)$v) . '</t></is></c>';
        }
    }
    $xml .= '</row></sheetData></worksheet>';
    return $xml;
}

function xlsx_rollup_sheet(array $entries): string
{
    $byElement = [];
    foreach ($entries as $e) {
        $name = (string)$e['element_name'];
        if (!isset($byElement[$name])) {
            $byElement[$name] = ['projected' => 0.0, 'actual' => 0.0, 'count' => 0];
        }
        $byElement[$name]['projected'] += (float)$e['projected_hours'];
        $byElement[$name]['actual'] += (float)$e['actual_hours'];
        $byElement[$name]['count'] += 1;
    }
    uasort($byElement, fn($a, $b) => $b['actual'] <=> $a['actual']);

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData><row r="1">';
    foreach (['Element', 'Projected', 'Actual', 'Entries'] as $i => $h) {
        $col = chr(65 + $i);
        $xml .= '<c r="' . $col . '1" t="inlineStr" s="2"><is><t>' . esc_xml($h) . '</t></is></c>';
    }
    $xml .= '</row>';
    $rowIdx = 2;
    foreach ($byElement as $name => $d) {
        $xml .= '<row r="' . $rowIdx . '">'
            . '<c r="A' . $rowIdx . '" t="inlineStr"><is><t>' . esc_xml($name) . '</t></is></c>'
            . '<c r="B' . $rowIdx . '"><v>' . $d['projected'] . '</v></c>'
            . '<c r="C' . $rowIdx . '"><v>' . $d['actual'] . '</v></c>'
            . '<c r="D' . $rowIdx . '"><v>' . $d['count'] . '</v></c>'
            . '</row>';
        $rowIdx++;
    }
    $xml .= '</sheetData></worksheet>';
    return $xml;
}

// ══════════════ DOCX ══════════════

function export_docx(array $project, array $entries, string $safeName): void
{
    $totalHours = 0.0;
    foreach ($entries as $e) {
        $totalHours += (float)$e['actual_hours'];
    }

    $elements = [];
    foreach ($entries as $e) {
        $elements[(string)$e['element_name']][] = $e;
    }

    // Collect all images for entries
    $imageFiles = [];   // zip path => raw data
    $imageRels = [];    // entry_id => ['rIdN' => 'imageN.ext']
    $imageTypes = [];   // ext => mime
    $imgIdx = 0;
    $entryIds = array_column($entries, 'id');

    if (!empty($entryIds)) {
        $idList = implode(',', array_map('intval', $entryIds));
        $imgRows = db_rows("SELECT * FROM entry_images WHERE entry_id IN ($idList) ORDER BY entry_id, sort_order");
        foreach ($imgRows as $img) {
            $imgIdx++;
            $path = UPLOAD_DIR . '/' . $img['image_path'];
            if (!is_file($path)) {
                continue;
            }
            $raw = file_get_contents($path);
            if ($raw === false) {
                continue;
            }
            $ext = strtolower(pathinfo($img['image_path'], PATHINFO_EXTENSION));
            if ($ext === 'jpg') {
                $ext = 'jpeg';
            }
            $zipName = "word/media/image{$imgIdx}.{$ext}";
            $imageFiles[$zipName] = $raw;

            $mimeMap = ['png' => 'image/png', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'bmp' => 'image/bmp'];
            $imageTypes[$ext] = $mimeMap[$ext] ?? 'image/png';

            $eid = (int)$img['entry_id'];
            $rId = 'rIdImg' . $imgIdx;
            if (!isset($imageRels[$eid])) {
                $imageRels[$eid] = [];
            }
            $imageRels[$eid][$rId] = $zipName;
        }
    }

    // Build document.xml body
    $body = '';
    $body .= '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>' . esc_xml($project['name']) . '</w:t></w:r></w:p>';
    $body .= '<w:p><w:r><w:t>Choreography</w:t></w:r></w:p>';
    $body .= '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>Animation list (total ' . round($totalHours) . ' hr)</w:t></w:r></w:p>';

    // Summary table — full width, 3 columns
    $body .= '<w:tbl><w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="9360" w:type="dxa"/>'
        . '<w:tblGrid><w:gridCol w:w="4000"/><w:gridCol w:w="3360"/><w:gridCol w:w="2000"/></w:tblGrid>'
        . '<w:tblBorders>'
        . '<w:top w:val="single" w:sz="4" w:color="auto"/><w:left w:val="single" w:sz="4" w:color="auto"/>'
        . '<w:bottom w:val="single" w:sz="4" w:color="auto"/><w:right w:val="single" w:sz="4" w:color="auto"/>'
        . '<w:insideH w:val="single" w:sz="4" w:color="auto"/><w:insideV w:val="single" w:sz="4" w:color="auto"/>'
        . '</w:tblBorders></w:tblPr>';

    $body .= '<w:tr>';
    foreach (['Element', 'Animation', 'Hours'] as $h) {
        $body .= '<w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="B4C6E7"/></w:tcPr>'
            . '<w:p><w:pPr><w:rPr><w:b/></w:rPr></w:pPr><w:r><w:rPr><w:b/></w:rPr><w:t>' . esc_xml($h) . '</w:t></w:r></w:p></w:tc>';
    }
    $body .= '</w:tr>';

    foreach ($elements as $elementName => $elemEntries) {
        $elemTotal = 0.0;
        foreach ($elemEntries as $i => $e) {
            $body .= '<w:tr>';
            $body .= '<w:tc><w:tcPr>' . ($i === 0 ? '<w:vMerge w:val="restart"/>' : '<w:vMerge/>') . '</w:tcPr><w:p><w:r><w:t>'
                . ($i === 0 ? esc_xml($elementName) : '') . '</w:t></w:r></w:p></w:tc>';
            $body .= '<w:tc><w:p><w:r><w:t>' . esc_xml((string)($e['animation_name'] ?: 'Untitled')) . '</w:t></w:r></w:p></w:tc>';
            $body .= '<w:tc><w:p><w:r><w:t>' . number_format((float)$e['actual_hours'], 1) . '</w:t></w:r></w:p></w:tc>';
            $body .= '</w:tr>';
            $elemTotal += (float)$e['actual_hours'];
        }
        $body .= '<w:tr>';
        $body .= '<w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p/></w:tc>';
        $body .= '<w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="D9E2F3"/></w:tcPr><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>' . esc_xml($elementName . ' Total') . '</w:t></w:r></w:p></w:tc>';
        $body .= '<w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="D9E2F3"/></w:tcPr><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>' . number_format($elemTotal, 1) . '</w:t></w:r></w:p></w:tc>';
        $body .= '</w:tr>';
    }
    $body .= '</w:tbl>';
    $body .= '<w:p/>';

    // Per-element detail sections
    foreach ($elements as $elementName => $elemEntries) {
        $body .= '<w:p><w:pPr><w:pStyle w:val="Heading2"/></w:pPr><w:r><w:t>' . esc_xml($elementName) . '</w:t></w:r></w:p>';
        foreach ($elemEntries as $entry) {
            $body .= '<w:p><w:pPr><w:pStyle w:val="Heading3"/></w:pPr><w:r><w:t>' . esc_xml((string)($entry['animation_name'] ?: 'Untitled')) . '</w:t></w:r></w:p>';

            // Table width: full page (9360 twips = 12240 - 1440*2 margins)
            $tblPr = '<w:tblPr><w:tblStyle w:val="TableGrid"/><w:tblW w:w="9360" w:type="dxa"/>'
                . '<w:tblBorders>'
                . '<w:top w:val="single" w:sz="4" w:color="auto"/><w:left w:val="single" w:sz="4" w:color="auto"/>'
                . '<w:bottom w:val="single" w:sz="4" w:color="auto"/><w:right w:val="single" w:sz="4" w:color="auto"/>'
                . '<w:insideH w:val="single" w:sz="4" w:color="auto"/><w:insideV w:val="single" w:sz="4" w:color="auto"/>'
                . '</w:tblBorders>';

            // Build image drawing if exists
            $hasImage = false;
            $drawingXml = '';
            $eid = (int)$entry['id'];
            if (isset($imageRels[$eid])) {
                foreach ($imageRels[$eid] as $rId => $zipName) {
                    $hasImage = true;
                    $cx = 400 * 9144;
                    $cy = 300 * 9144;
                    $imgPath = UPLOAD_DIR . '/' . $imgRows[array_search($rId, array_column($imgRows, 'id'))]['image_path'] ?? '';
                    if (is_file($imgPath) && function_exists('getimagesize')) {
                        $dims = @getimagesize($imgPath);
                        if ($dims) {
                            $cx = (int)round($dims[0] * 914400 / 96);
                            $cy = (int)round($dims[1] * 914400 / 96);
                            if ($cx > 5486400) {
                                $ratio = 5486400 / $cx;
                                $cx = 5486400;
                                $cy = (int)round($cy * $ratio);
                            }
                        }
                    }
                    $drawingXml = '<w:r><w:drawing>'
                        . '<wp:inline distT="0" distB="0" distL="0" distR="0" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
                        . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/>'
                        . '<wp:docPr id="' . $imgIdx . '" name="Image"/>'
                        . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
                        . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                        . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                        . '<pic:nvPicPr><pic:cNvPr id="1" name="Image"/><pic:cNvPicPr/></pic:nvPicPr>'
                        . '<pic:blipFill><a:blip r:embed="' . $rId . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></pic:blipFill>'
                        . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm><a:prstGeom prst="rect"/></pic:spPr>'
                        . '</pic:pic></a:graphicData></a:graphic>'
                        . '</wp:inline></w:drawing></w:r>';
                    break;
                }
            }

            // Column widths
            if ($hasImage) {
                $tblPr .= '<w:tblGrid><w:gridCol w:w="2500"/><w:gridCol w:w="4360"/><w:gridCol w:w="2500"/></w:tblGrid>';
            } else {
                $tblPr .= '<w:tblGrid><w:gridCol w:w="3000"/><w:gridCol w:w="6360"/></w:tblGrid>';
            }
            $tblPr .= '</w:tblPr>';
            $body .= '<w:tbl>' . $tblPr;

            // Symbol row (first row — image cell starts here if present)
            $body .= '<w:tr><w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="EDEDED"/></w:tcPr><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Symbol</w:t></w:r></w:p></w:tc>'
                . '<w:tc><w:p><w:r><w:t>' . esc_xml($entry['element_name']) . '</w:t></w:r></w:p></w:tc>';
            if ($hasImage) {
                $body .= '<w:tc><w:tcPr><w:vMerge w:val="restart"/></w:tcPr><w:p>' . $drawingXml . '</w:p></w:tc>';
            }
            $body .= '</w:tr>';

            $details = [
                ['Looping', to_bool($entry['looping']) ? 'yes' : 'no'],
                ['Duration', (string)$entry['duration']],
                ['Description', (string)$entry['description']],
                ['Artist', (string)$entry['artist']],
                ['Projected Hours', number_format((float)$entry['projected_hours'], 1)],
                ['Actual Hours', number_format((float)$entry['actual_hours'], 1)],
            ];
            $rowIdx = 0;
            $detailCount = count($details);
            foreach ($details as $d) {
                $rowIdx++;
                $body .= '<w:tr><w:tc><w:tcPr><w:shd w:val="clear" w:color="auto" w:fill="EDEDED"/></w:tcPr><w:p><w:r><w:rPr><w:b/></w:rPr><w:t>' . esc_xml($d[0]) . '</w:t></w:r></w:p></w:tc>';
                if ($d[0] === 'Description') {
                    $body .= '<w:tc><w:p>' . xml_runs_with_breaks($d[1]) . '</w:p></w:tc>';
                } else {
                    $body .= '<w:tc><w:p><w:r><w:t>' . esc_xml($d[1]) . '</w:t></w:r></w:p></w:tc>';
                }
                if ($hasImage) {
                    $body .= '<w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p/></w:tc>';
                }
                $body .= '</w:tr>';
            }

            $body .= '</w:tbl><w:p/>';
        }
    }

    $documentXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
        . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
        . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
        . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<w:body>' . $body
        . '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/>'
        . '<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="720" w:footer="720" w:gutter="0"/>'
        . '</w:sectPr></w:body></w:document>';

    // Build document.xml.rels with image relationships
    $docRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $allImageRels = [];
    foreach ($imageRels as $eidRels) {
        foreach ($eidRels as $rId => $zipName) {
            if (!isset($allImageRels[$rId])) {
                $allImageRels[$rId] = $zipName;
            }
        }
    }
    foreach ($allImageRels as $rId => $zipName) {
        $ext = pathinfo($zipName, PATHINFO_EXTENSION);
        $contentType = $imageTypes[$ext] ?? 'image/png';
        $docRels .= '<Relationship Id="' . $rId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . pathinfo($zipName, PATHINFO_BASENAME) . '"/>';
    }
    $docRels .= '</Relationships>';

    // Build [Content_Types].xml with image types
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>';
    foreach ($imageTypes as $ext => $mime) {
        $contentTypes .= '<Default Extension="' . $ext . '" ContentType="' . $mime . '"/>';
    }
    $contentTypes .= '</Types>';

    $files = [
        '[Content_Types].xml' => $contentTypes,
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        'word/_rels/document.xml.rels' => $docRels,
        'word/document.xml' => $documentXml,
        'word/styles.xml' => docx_styles(),
    ];

    // Add image files to zip
    foreach ($imageFiles as $zipName => $raw) {
        $files[$zipName] = $raw;
    }

    $data = build_zip($files);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $safeName . '_choreography.docx"');
    header('Content-Length: ' . strlen($data));
    echo $data;
    exit;
}

// ══════════════ ZIP BUILDER ══════════════

function int32le(int $n): string
{
    return chr($n & 0xFF) . chr(($n >> 8) & 0xFF) . chr(($n >> 16) & 0xFF) . chr(($n >> 24) & 0xFF);
}

function int16le(int $n): string
{
    return chr($n & 0xFF) . chr(($n >> 8) & 0xFF);
}

function build_zip(array $files): string
{
    $central = '';
    $data = '';
    $offset = 0;

    foreach ($files as $name => $content) {
        $nameLen = strlen($name);
        $contentLen = strlen($content);
        $crc = crc32($content);

        // Local file header (30 bytes)
        $data .= int32le(0x04034b50)   // signature
            . int16le(20)              // version needed
            . int16le(0)               // flags
            . int16le(0)               // compression method (stored)
            . int16le(0)               // mod time
            . int16le(0)               // mod date
            . int32le($crc)            // crc32
            . int32le($contentLen)     // compressed size
            . int32le($contentLen)     // uncompressed size
            . int16le($nameLen)        // filename length
            . int16le(0)               // extra field length
            . $name . $content;

        // Central directory entry (46 bytes)
        $central .= int32le(0x02014b50)   // signature
            . int16le(20)                  // version made by
            . int16le(20)                  // version needed
            . int16le(0)                   // flags
            . int16le(0)                   // compression method
            . int16le(0)                   // mod time
            . int16le(0)                   // mod date
            . int32le($crc)                // crc32
            . int32le($contentLen)         // compressed size
            . int32le($contentLen)         // uncompressed size
            . int16le($nameLen)            // filename length
            . int16le(0)                   // extra field length
            . int16le(0)                   // file comment length
            . int16le(0)                   // disk number start
            . int16le(0)                   // internal file attributes
            . int32le(0)                   // external file attributes
            . int32le($offset)             // relative offset of local header
            . $name;

        $offset += 30 + $nameLen + $contentLen;
    }

    // End of central directory (22 bytes)
    $data .= $central
        . int32le(0x06054b50)               // signature
        . int16le(0)                        // disk number
        . int16le(0)                        // disk with central directory
        . int16le(count($files))            // entries on this disk
        . int16le(count($files))            // total entries
        . int32le(strlen($central))         // size of central directory
        . int32le($offset)                  // offset of central directory
        . int16le(0);                       // comment length

    return $data;
}

function esc_xml(string $s): string
{
    $s = str_replace('&', '&#38;', $s);
    $s = str_replace('<', '&#60;', $s);
    $s = str_replace('>', '&#62;', $s);
    $s = str_replace('"', '&#34;', $s);
    $s = str_replace("'", '&#39;', $s);
    return $s;
}

function xml_runs_with_breaks(string $text): string
{
    if ($text === '') {
        return '<w:r><w:t></w:t></w:r>';
    }
    $lines = explode("\n", $text);
    $xml = '';
    foreach ($lines as $i => $line) {
        $xml .= '<w:r><w:t xml:space="preserve">' . esc_xml($line) . '</w:t></w:r>';
        if ($i < count($lines) - 1) {
            $xml .= '<w:r><w:br/></w:r>';
        }
    }
    return $xml;
}

function docx_styles(): string
{
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="240" w:after="120"/></w:pPr><w:rPr><w:b/><w:sz w:val="32"/><w:color w:val="1F4E79"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="200" w:after="80"/></w:pPr><w:rPr><w:b/><w:sz w:val="28"/><w:color w:val="2E74B5"/></w:rPr></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/><w:basedOn w:val="Normal"/><w:pPr><w:keepNext/><w:spacing w:before="160" w:after="60"/></w:pPr><w:rPr><w:b/><w:sz w:val="26"/></w:rPr></w:style>'
        . '<w:style w:type="table" w:styleId="TableGrid"><w:name w:val="Table Grid"/><w:tblPr><w:tblBorders><w:top w:val="single" w:sz="4" w:color="auto"/><w:left w:val="single" w:sz="4" w:color="auto"/><w:bottom w:val="single" w:sz="4" w:color="auto"/><w:right w:val="single" w:sz="4" w:color="auto"/><w:insideH w:val="single" w:sz="4" w:color="auto"/><w:insideV w:val="single" w:sz="4" w:color="auto"/></w:tblBorders></w:tblPr></w:style>'
        . '</w:styles>';
}
