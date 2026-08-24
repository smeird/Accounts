<?php

/**
 * Small, dependency-free OOXML writer for the application's curated exports.
 * It deliberately supports only the workbook features used by the finance
 * export: styled cells, formulas with cached values, merged titles, frozen
 * panes, data bars and one structured table.
 */
class XlsxWorkbookWriter {
    private $sheets = [];
    private $metadata;

    private static $styleIds = [
        'default' => 0,
        'title' => 1,
        'subtitle' => 2,
        'section' => 3,
        'header' => 4,
        'text' => 5,
        'muted' => 6,
        'date' => 7,
        'month' => 8,
        'currency' => 9,
        'number' => 10,
        'percent' => 11,
        'kpi_label' => 12,
        'kpi_currency' => 13,
        'kpi_number' => 14,
        'positive' => 15,
        'negative' => 16,
        'total' => 17,
        'excluded' => 18,
        'boolean' => 19,
    ];

    public function __construct(array $metadata = []) {
        $this->metadata = $metadata;
    }

    public function addSheet(string $name, array $rows, array $options = []): void {
        if ($name === '' || strlen($name) > 31 || preg_match('~[\\\\/?*\[\]:]~', $name)) {
            throw new InvalidArgumentException('Invalid Excel worksheet name.');
        }
        foreach ($this->sheets as $sheet) {
            if (strcasecmp($sheet['name'], $name) === 0) {
                throw new InvalidArgumentException('Excel worksheet names must be unique.');
            }
        }
        $this->sheets[] = ['name' => $name, 'rows' => $rows, 'options' => $options];
    }

    public function save(string $path): void {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP zip extension is required to create Excel workbooks.');
        }
        if (!$this->sheets) {
            throw new RuntimeException('The workbook has no worksheets.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The Excel workbook could not be created.');
        }

        try {
            $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
            $zip->addFromString('_rels/.rels', $this->rootRelationshipsXml());
            $zip->addFromString('docProps/core.xml', $this->corePropertiesXml());
            $zip->addFromString('docProps/app.xml', $this->appPropertiesXml());
            $zip->addFromString('xl/workbook.xml', $this->workbookXml());
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml());
            $zip->addFromString('xl/styles.xml', $this->stylesXml());

            $tableId = 1;
            foreach ($this->sheets as $index => $sheet) {
                $sheetNumber = $index + 1;
                $table = $sheet['options']['table'] ?? null;
                $zip->addFromString(
                    'xl/worksheets/sheet' . $sheetNumber . '.xml',
                    $this->worksheetXml($sheet, $table ? $tableId : null)
                );
                if ($table) {
                    $zip->addFromString(
                        'xl/worksheets/_rels/sheet' . $sheetNumber . '.xml.rels',
                        $this->worksheetRelationshipsXml($tableId)
                    );
                    $zip->addFromString('xl/tables/table' . $tableId . '.xml', $this->tableXml($table, $tableId));
                    $tableId++;
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function contentTypesXml(): string {
        $overrides = '';
        $tableId = 1;
        foreach ($this->sheets as $index => $sheet) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . ($index + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            if (!empty($sheet['options']['table'])) {
                $overrides .= '<Override PartName="/xl/tables/table' . $tableId . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.table+xml"/>';
                $tableId++;
            }
        }
        return $this->xmlHeader()
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . $overrides . '</Types>';
    }

    private function rootRelationshipsXml(): string {
        return $this->xmlHeader()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string {
        $sheets = '';
        foreach ($this->sheets as $index => $sheet) {
            $sheets .= '<sheet name="' . $this->escape($sheet['name']) . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }
        return $this->xmlHeader()
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $sheets . '</sheets>'
            . '<calcPr calcId="191029" calcMode="auto" fullCalcOnLoad="1" forceFullCalc="1"/>'
            . '</workbook>';
    }

    private function workbookRelationshipsXml(): string {
        $relationships = '';
        foreach ($this->sheets as $index => $sheet) {
            $relationships .= '<Relationship Id="rId' . ($index + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($index + 1) . '.xml"/>';
        }
        $stylesId = count($this->sheets) + 1;
        $relationships .= '<Relationship Id="rId' . $stylesId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        return $this->xmlHeader() . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relationships . '</Relationships>';
    }

    private function worksheetXml(array $sheet, ?int $tableId): string {
        $rows = $sheet['rows'];
        $options = $sheet['options'];
        $columnCount = $this->maximumColumnCount($rows, $options);
        $rowCount = max(1, count($rows));
        $dimension = 'A1:' . $this->columnName($columnCount) . $rowCount;

        $views = '<sheetView workbookViewId="0" showGridLines="0">';
        $freezeRows = (int)($options['freeze']['rows'] ?? 0);
        $freezeColumns = (int)($options['freeze']['columns'] ?? 0);
        if ($freezeRows > 0 || $freezeColumns > 0) {
            $topLeft = $this->columnName($freezeColumns + 1) . ($freezeRows + 1);
            $pane = $freezeRows > 0 && $freezeColumns > 0 ? 'bottomRight' : ($freezeRows > 0 ? 'bottomLeft' : 'topRight');
            $views .= '<pane'
                . ($freezeColumns > 0 ? ' xSplit="' . $freezeColumns . '"' : '')
                . ($freezeRows > 0 ? ' ySplit="' . $freezeRows . '"' : '')
                . ' topLeftCell="' . $topLeft . '" activePane="' . $pane . '" state="frozen"/>'
                . '<selection pane="' . $pane . '" activeCell="' . $topLeft . '" sqref="' . $topLeft . '"/>';
        }
        $views .= '</sheetView>';

        $columns = '';
        if (!empty($options['columns'])) {
            $columns = '<cols>';
            foreach (array_values($options['columns']) as $index => $width) {
                $columns .= '<col min="' . ($index + 1) . '" max="' . ($index + 1) . '" width="' . $this->numeric($width) . '" customWidth="1"/>';
            }
            $columns .= '</cols>';
        }

        $sheetData = '<sheetData>';
        foreach ($rows as $rowIndex => $rowDefinition) {
            $rowNumber = $rowIndex + 1;
            $cells = isset($rowDefinition['cells']) ? $rowDefinition['cells'] : $rowDefinition;
            $height = isset($rowDefinition['height']) ? ' ht="' . $this->numeric($rowDefinition['height']) . '" customHeight="1"' : '';
            $sheetData .= '<row r="' . $rowNumber . '"' . $height . '>';
            foreach (array_values($cells) as $columnIndex => $cell) {
                if ($cell === null) continue;
                $sheetData .= $this->cellXml($this->columnName($columnIndex + 1) . $rowNumber, $cell);
            }
            $sheetData .= '</row>';
        }
        $sheetData .= '</sheetData>';

        $merges = '';
        if (!empty($options['merges'])) {
            $merges = '<mergeCells count="' . count($options['merges']) . '">';
            foreach ($options['merges'] as $range) $merges .= '<mergeCell ref="' . $this->escape($range) . '"/>';
            $merges .= '</mergeCells>';
        }

        $conditional = '';
        $priority = 1;
        foreach (($options['data_bars'] ?? []) as $bar) {
            $conditional .= '<conditionalFormatting sqref="' . $this->escape($bar['range']) . '"><cfRule type="dataBar" priority="' . $priority++ . '"><dataBar showValue="1"><cfvo type="min" val="0"/><cfvo type="max" val="0"/><color rgb="FF' . $this->colour($bar['color'] ?? '6366F1') . '"/></dataBar></cfRule></conditionalFormatting>';
        }

        $autoFilter = !$tableId && !empty($options['auto_filter']) ? '<autoFilter ref="' . $this->escape($options['auto_filter']) . '"/>' : '';
        $tableParts = $tableId ? '<tableParts count="1"><tablePart r:id="rId1"/></tableParts>' : '';
        $orientation = !empty($options['landscape']) ? ' orientation="landscape"' : '';
        $tabColour = !empty($options['tab_color']) ? '<sheetPr><tabColor rgb="FF' . $this->colour($options['tab_color']) . '"/></sheetPr>' : '';

        return $this->xmlHeader()
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . $tabColour . '<dimension ref="' . $dimension . '"/><sheetViews>' . $views . '</sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>' . $columns . $sheetData . $autoFilter . $merges . $conditional
            . '<pageMargins left="0.3" right="0.3" top="0.5" bottom="0.5" header="0.2" footer="0.2"/>'
            . '<pageSetup paperSize="9" fitToWidth="1" fitToHeight="0"' . $orientation . '/>'
            . $tableParts . '</worksheet>';
    }

    private function worksheetRelationshipsXml(int $tableId): string {
        return $this->xmlHeader()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/table" Target="../tables/table' . $tableId . '.xml"/>'
            . '</Relationships>';
    }

    private function tableXml(array $table, int $tableId): string {
        $headers = $table['headers'] ?? [];
        $columns = '';
        foreach ($headers as $index => $header) {
            $columns .= '<tableColumn id="' . ($index + 1) . '" name="' . $this->escape($header) . '"/>';
        }
        $name = preg_replace('/[^A-Za-z0-9_]/', '', $table['name'] ?? ('Table' . $tableId));
        if ($name === '' || ctype_digit(substr($name, 0, 1))) $name = 'Table' . $tableId;
        return $this->xmlHeader()
            . '<table xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" id="' . $tableId . '" name="' . $name . '" displayName="' . $name . '" ref="' . $this->escape($table['range']) . '" totalsRowShown="0">'
            . '<autoFilter ref="' . $this->escape($table['range']) . '"/><tableColumns count="' . count($headers) . '">' . $columns . '</tableColumns>'
            . '<tableStyleInfo name="TableStyleMedium2" showFirstColumn="0" showLastColumn="0" showRowStripes="1" showColumnStripes="0"/>'
            . '</table>';
    }

    private function cellXml(string $reference, $cell): string {
        if (!is_array($cell)) $cell = ['value' => $cell];
        $styleName = $cell['style'] ?? 'default';
        $styleId = self::$styleIds[$styleName] ?? 0;
        $attributes = ' r="' . $reference . '" s="' . $styleId . '"';

        if (array_key_exists('formula', $cell)) {
            $formula = ltrim((string)$cell['formula'], '=');
            $value = $cell['value'] ?? 0;
            if (($cell['type'] ?? '') === 'string') {
                return '<c' . $attributes . ' t="str"><f>' . $this->escape($formula) . '</f><v>' . $this->escape((string)$value) . '</v></c>';
            }
            return '<c' . $attributes . '><f>' . $this->escape($formula) . '</f><v>' . $this->numeric($value) . '</v></c>';
        }

        if (($cell['type'] ?? '') === 'date') {
            return '<c' . $attributes . '><v>' . $this->numeric($this->excelDate((string)($cell['value'] ?? ''))) . '</v></c>';
        }
        $value = $cell['value'] ?? '';
        if (is_bool($value)) {
            return '<c' . $attributes . ' t="b"><v>' . ($value ? '1' : '0') . '</v></c>';
        }
        if (is_int($value) || is_float($value)) {
            return '<c' . $attributes . '><v>' . $this->numeric($value) . '</v></c>';
        }
        return '<c' . $attributes . ' t="inlineStr"><is><t xml:space="preserve">' . $this->escape((string)$value) . '</t></is></c>';
    }

    private function maximumColumnCount(array $rows, array $options): int {
        $maximum = count($options['columns'] ?? []);
        foreach ($rows as $row) {
            $cells = isset($row['cells']) ? $row['cells'] : $row;
            $maximum = max($maximum, count($cells));
        }
        return max(1, $maximum);
    }

    private function stylesXml(): string {
        $fonts = [
            '<font><sz val="10"/><color rgb="FF172033"/><name val="Aptos"/><family val="2"/><scheme val="minor"/></font>',
            '<font><b/><sz val="20"/><color rgb="FFFFFFFF"/><name val="Aptos Display"/><family val="2"/></font>',
            '<font><sz val="10"/><color rgb="FFDDE4F5"/><name val="Aptos"/><family val="2"/></font>',
            '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Aptos"/><family val="2"/></font>',
            '<font><b/><sz val="10"/><color rgb="FF172033"/><name val="Aptos"/><family val="2"/></font>',
            '<font><sz val="9"/><color rgb="FF667085"/><name val="Aptos"/><family val="2"/></font>',
            '<font><b/><sz val="18"/><color rgb="FF172033"/><name val="Aptos Display"/><family val="2"/></font>',
            '<font><sz val="10"/><color rgb="FF067647"/><name val="Aptos"/><family val="2"/></font>',
            '<font><sz val="10"/><color rgb="FFB42318"/><name val="Aptos"/><family val="2"/></font>',
        ];
        $fills = [
            '<fill><patternFill patternType="none"/></fill>',
            '<fill><patternFill patternType="gray125"/></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FF172033"/><bgColor indexed="64"/></patternFill></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FF6366F1"/><bgColor indexed="64"/></patternFill></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FFEEF2FF"/><bgColor indexed="64"/></patternFill></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/><bgColor indexed="64"/></patternFill></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FFECFDF3"/><bgColor indexed="64"/></patternFill></fill>',
            '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF1F0"/><bgColor indexed="64"/></patternFill></fill>',
        ];
        $borders = [
            '<border><left/><right/><top/><bottom/><diagonal/></border>',
            '<border><left/><right/><top/><bottom style="thin"><color rgb="FFDCE3EF"/></bottom><diagonal/></border>',
            '<border><left/><right/><top style="thin"><color rgb="FF98A2B3"/></top><bottom style="double"><color rgb="FF98A2B3"/></bottom><diagonal/></border>',
        ];

        $xfs = [
            $this->xf(0, 0, 0),
            $this->xf(1, 2, 0, 0, 'left', 'center'),
            $this->xf(2, 2, 0, 0, 'left', 'center'),
            $this->xf(3, 3, 0, 0, 'left', 'center'),
            $this->xf(3, 2, 0, 0, 'left', 'center'),
            $this->xf(0, 0, 1, 0, 'left', 'center'),
            $this->xf(5, 0, 0, 0, 'left', 'center'),
            $this->xf(0, 0, 1, 14, 'left', 'center'),
            $this->xf(0, 0, 1, 165, 'left', 'center'),
            $this->xf(0, 0, 1, 164, 'right', 'center'),
            $this->xf(0, 0, 1, 3, 'right', 'center'),
            $this->xf(0, 0, 1, 10, 'right', 'center'),
            $this->xf(4, 4, 0, 0, 'left', 'center'),
            $this->xf(6, 4, 0, 164, 'right', 'center'),
            $this->xf(6, 4, 0, 3, 'right', 'center'),
            $this->xf(7, 6, 1, 164, 'right', 'center'),
            $this->xf(8, 7, 1, 164, 'right', 'center'),
            $this->xf(4, 0, 2, 164, 'right', 'center'),
            $this->xf(5, 5, 1, 0, 'left', 'center'),
            $this->xf(0, 0, 1, 0, 'center', 'center'),
        ];

        return $this->xmlHeader()
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="&quot;£&quot;#,##0.00;[Red](&quot;£&quot;#,##0.00);-"/><numFmt numFmtId="165" formatCode="mmm yyyy"/></numFmts>'
            . '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>'
            . '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>'
            . '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '<dxfs count="0"/><tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleMedium9"/>'
            . '</styleSheet>';
    }

    private function xf(int $fontId, int $fillId, int $borderId, int $numFmtId = 0, string $horizontal = 'general', string $vertical = 'bottom'): string {
        return '<xf numFmtId="' . $numFmtId . '" fontId="' . $fontId . '" fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"'
            . ($numFmtId ? ' applyNumberFormat="1"' : '')
            . '><alignment horizontal="' . $horizontal . '" vertical="' . $vertical . '" wrapText="1"/></xf>';
    }

    private function corePropertiesXml(): string {
        $created = gmdate('Y-m-d\TH:i:s\Z');
        return $this->xmlHeader()
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $this->escape($this->metadata['title'] ?? 'Financial workbook') . '</dc:title>'
            . '<dc:creator>' . $this->escape($this->metadata['creator'] ?? 'Accounts') . '</dc:creator>'
            . '<cp:lastModifiedBy>' . $this->escape($this->metadata['creator'] ?? 'Accounts') . '</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $created . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function appPropertiesXml(): string {
        $titles = '';
        foreach ($this->sheets as $sheet) $titles .= '<vt:lpstr>' . $this->escape($sheet['name']) . '</vt:lpstr>';
        return $this->xmlHeader()
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Accounts</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>' . count($this->sheets) . '</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="' . count($this->sheets) . '" baseType="lpstr">' . $titles . '</vt:vector></TitlesOfParts>'
            . '<Company></Company><LinksUpToDate>false</LinksUpToDate><SharedDoc>false</SharedDoc><HyperlinksChanged>false</HyperlinksChanged><AppVersion>1.0</AppVersion>'
            . '</Properties>';
    }

    private function columnName(int $number): string {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = (int)floor($number / 26);
        }
        return $name;
    }

    private function excelDate(string $date): float {
        $value = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$value || $value->format('Y-m-d') !== $date) return 0;
        return ((float)$value->format('U') / 86400) + 25569;
    }

    private function numeric($value): string {
        $number = (float)$value;
        if (is_nan($number) || is_infinite($number)) $number = 0;
        $formatted = rtrim(rtrim(sprintf('%.10F', $number), '0'), '.');
        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    private function colour(string $value): string {
        $value = strtoupper(ltrim($value, '#'));
        return preg_match('/^[0-9A-F]{6}$/', $value) ? $value : '6366F1';
    }

    private function escape(string $value): string {
        $clean = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value);
        return htmlspecialchars($clean === null ? '' : $clean, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function xmlHeader(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    }
}
