<?php
declare(strict_types=1);

/**
 * DOCX import. Reads a .docx (zip of XML) using PharData, parses
 * word/document.xml with DOMDocument, and extracts choreography entries
 * mirroring the Python backend's logic.
 */

function handle_import_docx(): void
{
    if (!isset($_FILES['file'])) {
        throw new ApiError('No file uploaded', 400);
    }
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new ApiError('Upload failed', 400);
    }

    $tmp = $file['tmp_name'];
    $entries = parse_docx_entries($tmp);
    json_out($entries, 200);
}

function parse_docx_entries(string $docxPath): array
{
    $documentXml = read_docx_part($docxPath, 'word/document.xml');
    if ($documentXml === null) {
        throw new ApiError('Not a valid .docx file (missing word/document.xml)', 400);
    }

    $dom = new DOMDocument();
    if (!@$dom->loadXML($documentXml)) {
        throw new ApiError('Could not parse document.xml', 400);
    }
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $entries = [];
    $currentElement = null;

    // ── Paragraphs (top-level only, matching python-docx doc.paragraphs) ──
    $paras = $xp->query('//w:body/w:p');
    foreach ($paras as $p) {
        $text = extract_paragraph_text($xp, $p);
        $text = trim($text);
        if ($text === '') {
            continue;
        }
        $lower = strtolower($text);
        if (strpos($lower, 'summary') === 0 || strpos($lower, 'design') === 0 || strpos($lower, 'animation total') === 0) {
            continue;
        }
        if (char_len($text) < 30 && !preg_match('/\bhr\b/i', $lower) && !preg_match('/\d/', $text)) {
            $currentElement = $text;
        }
        if (preg_match_all('/(\d+[\d.]*)\s*hr/i', $lower, $m) && $currentElement) {
            $totalH = 0.0;
            foreach ($m[1] as $h) {
                $totalH += (float)$h;
            }
            $animName = trim(preg_replace('/\s*:?\s*\d+[\d.]*\s*hr.*$/i', '', $text));
            if ($animName !== '' && strlen($animName) > 1 && strpos($animName, ' ') !== 0 && $currentElement) {
                $entries[] = [
                    'element_name' => $currentElement,
                    'animation_name' => $animName,
                    'looping' => (strpos($lower, 'loop') !== false || strpos($lower, 'idle') !== false),
                    'duration' => '',
                    'description' => '',
                    'artist' => '',
                    'projected_hours' => $totalH,
                    'actual_hours' => 0.0,
                ];
            }
        }
    }

    // ── Tables ──
    $tables = $xp->query('//w:tbl');
    foreach ($tables as $tbl) {
        $rows = $xp->query('./w:tr', $tbl);
        $rowArr = [];
        foreach ($rows as $r) {
            $cells = [];
            $tcNodes = $xp->query('./w:tc', $r);
            foreach ($tcNodes as $tc) {
                $cellText = '';
                $pNodes = $xp->query('./w:p', $tc);
                foreach ($pNodes as $pp) {
                    if ($cellText !== '') {
                        $cellText .= "\n";
                    }
                    $cellText .= extract_paragraph_text($xp, $pp);
                }
                $cells[] = trim($cellText);
            }
            $rowArr[] = $cells;
        }
        if (count($rowArr) < 4) {
            continue;
        }
        $label0 = strtolower(trim($rowArr[0][0] ?? ''));
        if (strpos($label0, 'symbol') === false && strpos($label0, 'name') === false) {
            continue;
        }
        $looping = '';
        $duration = '';
        $description = '';
        $artist = '';
        foreach ($rowArr as $cells) {
            $label = strtolower(trim($cells[0] ?? ''));
            $val = trim($cells[1] ?? '');
            $meta = count($cells) > 2 ? trim($cells[count($cells) - 1]) : '';
            if (strpos($label, 'loop') !== false) {
                $looping = $val;
            } elseif (strpos($label, 'dur') !== false) {
                $duration = $val;
            } elseif (strpos($label, 'desc') !== false) {
                $description = $val;
            } elseif (strpos($label, 'symbol') !== false || strpos($label, 'name') !== false) {
                $artist = $meta !== '' ? $meta : $val;
            }
        }
        $combined = strtolower($artist . ' ' . $description);
        $hrMatch = [];
        if (preg_match_all('/(\d+[\d.]*)\s*hr/i', $combined, $hrMatch)) {
            $projected = 0.0;
            foreach ($hrMatch[1] as $h) {
                $projected += (float)$h;
            }
        } else {
            $projected = 0.0;
        }
        if ($description !== '' || $duration !== '') {
            $entries[] = [
                'element_name' => $currentElement !== null ? $currentElement : '',
                'animation_name' => $currentElement !== null ? $currentElement : '',
                'looping' => in_array(strtolower($looping), ['yes', 'y', 'true'], true),
                'duration' => $duration,
                'description' => $description,
                'artist' => $artist !== '' ? trim(preg_replace('/\s*\d+[\d.]*\s*hr.*$/i', '', $artist)) : '',
                'projected_hours' => $projected,
                'actual_hours' => 0.0,
            ];
        }
    }

    return $entries;
}

/**
 * Extract paragraph text from a w:p element, replicating python-docx
 * behavior: w:t content concatenated, w:br -> "\n", w:tab -> "\t", w:cr -> "\r".
 */
function extract_paragraph_text(DOMXPath $xp, DOMElement $p): string
{
    // Iterate direct children only: w:r and w:hyperlink. This matches
    // python-docx's Paragraph.text, which includes hyperlink runs but does
    // not double-count them (descendant runs inside hyperlinks are reached
    // only via the hyperlink branch).
    $text = '';
    $nodes = $xp->query('./w:r | ./w:hyperlink', $p);
    foreach ($nodes as $node) {
        if ($node->localName === 'hyperlink') {
            foreach ($xp->query('./w:r', $node) as $hr) {
                $text .= run_text($xp, $hr);
            }
            continue;
        }
        $text .= run_text($xp, $node);
    }
    return $text;
}

function run_text(DOMXPath $xp, DOMElement $run): string
{
    $text = '';
    foreach ($xp->query('./w:t | ./w:tab | ./w:br | ./w:cr | ./w:noBreakHyphen', $run) as $child) {
        switch ($child->localName) {
            case 't':
                $text .= $child->textContent;
                break;
            case 'tab':
                $text .= "\t";
                break;
            case 'br':
                $text .= "\n";
                break;
            case 'cr':
                $text .= "\r";
                break;
            case 'noBreakHyphen':
                $text .= '-';
                break;
        }
    }
    return $text;
}

/**
 * Extract a named part from a .docx (zip) archive using manual binary zip reading.
 */
function read_docx_part(string $docxPath, string $part): ?string
{
    $zipData = file_get_contents($docxPath);
    if ($zipData === false) {
        return null;
    }

    $pos = 0;
    $len = strlen($zipData);

    while ($pos + 30 <= $len) {
        $sig = unpack('V', substr($zipData, $pos, 4))[1];
        if ($sig !== 0x04034b50) {
            break;
        }

        $compressed = unpack('v', substr($zipData, $pos + 8, 2))[1];
        $compSize   = unpack('V', substr($zipData, $pos + 18, 4))[1];
        $nameLen    = unpack('v', substr($zipData, $pos + 26, 2))[1];
        $extraLen   = unpack('v', substr($zipData, $pos + 28, 2))[1];
        $name       = substr($zipData, $pos + 30, $nameLen);
        $dataStart  = $pos + 30 + $nameLen + $extraLen;
        $content    = substr($zipData, $dataStart, $compSize);

        if ($name === $part) {
            if ($compressed === 0) {
                return $content;
            }
            $decompressed = @gzuncompress($content);
            return ($decompressed !== false) ? $decompressed : $content;
        }

        $pos = $dataStart + $compSize;
    }

    return null;
}