<?php

namespace App\Services\Exports;

use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;
use XMLWriter;
use ZipArchive;

class WeeklyRecapWorkbook
{
    private const SHEET_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const DOC_REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const TYPE_NS = 'http://schemas.openxmlformats.org/package/2006/content-types';

    private const HEADER_ROW = 5;
    private const FIRST_PERIOD_COLUMN = 8;

    private const HEADING = 1;
    private const ETD_DATE = 2;
    private const IDENTITY = 3;
    private const SNAPSHOT_DATE = 4;
    private const QUANTITY = 5;
    private const ACTUAL = 7;
    private const UNCONFIRMED = 9;

    // Setiap entri berisi ID font, ID warna latar, dan ID format angka.
    private const STYLES = [
        [0, 0, 0],   // Teks biasa
        [1, 0, 0],   // Judul
        [1, 2, 166], // Tanggal ETD
        [0, 3, 0],   // Identitas produk
        [0, 0, 167], // Tanggal snapshot
        [0, 0, 164], // Angka bulat
        [0, 0, 165], // Angka desimal
        [0, 4, 164], // Actual, angka bulat
        [0, 4, 165], // Actual, angka desimal
        [0, 5, 164], // Batas belum diatur, angka bulat
        [0, 5, 165], // Batas belum diatur, angka desimal
    ];

    public function write(array $columnKeys, iterable $orders): string
    {
        if (count($columnKeys) + self::FIRST_PERIOD_COLUMN - 1 > 16_384) {
            throw new RuntimeException('Jumlah kolom rekap melebihi batas Excel.');
        }

        $sheetPath = tempnam(sys_get_temp_dir(), 'ppc-sheet-');

        if ($sheetPath === false) {
            throw new RuntimeException('File sementara worksheet tidak dapat dibuat.');
        }

        $outputPath = null;

        try {
            $this->writeSheet($sheetPath, $columnKeys, $orders);
            $outputPath = tempnam(sys_get_temp_dir(), 'ppc-rekap-');

            if ($outputPath === false) {
                throw new RuntimeException('File sementara rekap tidak dapat dibuat.');
            }

            $this->package($outputPath, $sheetPath);

            return $outputPath;
        } catch (Throwable $exception) {
            if (is_string($outputPath) && is_file($outputPath)) {
                unlink($outputPath);
            }

            throw $exception;
        } finally {
            unlink($sheetPath);
        }
    }

    private function writeSheet(string $path, array $columnKeys, iterable $orders): void
    {
        $columns = [];

        foreach ($columnKeys as $index => $key) {
            [$etd, $sr] = explode('|', $key, 2);
            $columns[] = [
                'key' => $key,
                'etd' => $etd,
                'sr' => $sr,
                'column' => self::FIRST_PERIOD_COLUMN + $index,
            ];
        }

        $xml = new XMLWriter();

        if (!$xml->openUri($path)) {
            throw new RuntimeException('Worksheet rekap tidak dapat ditulis.');
        }

        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElementNS(null, 'worksheet', self::SHEET_NS);

        $this->writeView($xml);
        $this->writeColumns($xml, count($columns));

        $xml->startElement('sheetData');
        $this->writeHeader($xml, $columns);

        $row = self::HEADER_ROW + 1;
        $previousProduct = null;
        $writtenOrders = 0;

        foreach ($orders as $order) {
            $product = [$order['department'], $order['product']];

            if ($previousProduct !== null && $previousProduct !== $product) {
                $row += 2;
            }

            if ($row + 1 > 1_048_576) {
                throw new RuntimeException('Jumlah baris rekap melebihi batas Excel.');
            }

            $this->writeOrderRow($xml, $row++, $order, 'Qty', $columns);
            $this->writeOrderRow($xml, $row++, $order, 'Cum', $columns);

            $previousProduct = $product;
            $writtenOrders++;

            if ($writtenOrders % 128 === 0) {
                $xml->flush();
            }
        }

        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();
        $xml->flush();
    }

    private function writeView(XMLWriter $xml): void
    {
        $xml->startElement('sheetViews');
        $xml->startElement('sheetView');
        $xml->writeAttribute('workbookViewId', '0');
        $xml->writeAttribute('zoomScale', '90');

        $xml->startElement('pane');
        $xml->writeAttribute('xSplit', '2');
        $xml->writeAttribute('ySplit', (string) self::HEADER_ROW);
        $xml->writeAttribute('topLeftCell', 'C6');
        $xml->writeAttribute('activePane', 'bottomRight');
        $xml->writeAttribute('state', 'frozen');
        $xml->endElement();

        $xml->endElement();
        $xml->endElement();
    }

    private function writeColumns(XMLWriter $xml, int $periodCount): void
    {
        $ranges = [
            [1, 1, 19],
            [2, 2, 17],
            [3, 3, 9],
            [4, 4, 12],
            [5, 5, 15],
            [6, 6, 32],
            [7, 7, 10],
        ];

        if ($periodCount > 0) {
            $ranges[] = [
                self::FIRST_PERIOD_COLUMN,
                self::FIRST_PERIOD_COLUMN + $periodCount - 1,
                11,
            ];
        }

        $xml->startElement('cols');

        foreach ($ranges as [$first, $last, $width]) {
            $xml->startElement('col');
            $xml->writeAttribute('min', (string) $first);
            $xml->writeAttribute('max', (string) $last);
            $xml->writeAttribute('width', (string) $width);
            $xml->writeAttribute('customWidth', '1');
            $xml->endElement();
        }

        $xml->endElement();
    }

    private function writeHeader(XMLWriter $xml, array $columns): void
    {
        $this->startRow($xml, 1);
        $this->cell($xml, 1, 1, 'Rekap Pesanan Mingguan', self::HEADING);
        $xml->endElement();

        $this->startRow($xml, 2);
        $this->cell(
            $xml,
            1,
            2,
            'Biru = ETD pada batas Actual; abu-abu = batas Actual belum ditetapkan'
        );
        $xml->endElement();

        $etdCounts = array_count_values(array_column($columns, 'etd'));
        $repeatedColumns = array_filter(
            $columns,
            fn (array $column) => $etdCounts[$column['etd']] > 1
        );

        if ($repeatedColumns !== []) {
            $this->startRow($xml, 3);
            $this->cell($xml, 6, 3, 'SR ISSUE untuk ETD yang sama');

            foreach ($repeatedColumns as $column) {
                $this->dateCell($xml, $column['column'], 3, $column['sr'], self::SNAPSHOT_DATE);
            }

            $xml->endElement();
        }

        $this->startRow($xml, 4);
        $this->cell($xml, self::FIRST_PERIOD_COLUMN, 4, 'ETD PORT');
        $xml->endElement();

        $this->startRow($xml, self::HEADER_ROW);

        $headings = ['CARNAME', 'Prod no', 'CV', 'FAMILY', 'S/R ISSUE', 'FILE NAME', 'Item'];

        foreach ($headings as $index => $heading) {
            $this->cell($xml, $index + 1, self::HEADER_ROW, $heading, self::HEADING);
        }

        foreach ($columns as $column) {
            $this->dateCell(
                $xml,
                $column['column'],
                self::HEADER_ROW,
                $column['etd'],
                self::ETD_DATE
            );
        }

        $xml->endElement();
    }

    private function writeOrderRow(
        XMLWriter $xml,
        int $row,
        array $order,
        string $item,
        array $columns
    ): void {
        $this->startRow($xml, $row);

        if ($item === 'Qty') {
            $this->cell($xml, 1, $row, $order['car_name'], self::IDENTITY);
            $this->cell($xml, 2, $row, $order['product']);
            $this->cell($xml, 3, $row, null, self::IDENTITY);
            $this->cell($xml, 4, $row, $order['family'], self::IDENTITY);
            $this->dateCell($xml, 5, $row, $order['snapshot'], self::SNAPSHOT_DATE);
            $this->cell($xml, 6, $row, $order['filename'], self::IDENTITY);
        }

        $this->cell($xml, 7, $row, $item);

        foreach ($columns as $column) {
            $value = $order['values'][$column['key']][$item] ?? null;

            if ($value === null) {
                continue;
            }

            $style = match (true) {
                $order['cutoff'] === null => self::UNCONFIRMED,
                $column['sr'] === $order['cutoff'] => self::ACTUAL,
                default => self::QUANTITY,
            };

            $quantity = (float) $value;

            if (floor($quantity) !== $quantity) {
                $style++;
            }

            $this->cell($xml, $column['column'], $row, (string) $value, $style, true);
        }

        $xml->endElement();
    }

    private function dateCell(
        XMLWriter $xml,
        int $column,
        int $row,
        string $date,
        int $style
    ): void {
        $serial = ExcelDate::PHPToExcel(new DateTimeImmutable($date));

        if ($serial === false) {
            throw new RuntimeException('Tanggal rekap tidak dapat dikonversi ke Excel.');
        }

        $this->cell($xml, $column, $row, (string) $serial, $style, true);
    }

    private function startRow(XMLWriter $xml, int $row): void
    {
        $xml->startElement('row');
        $xml->writeAttribute('r', (string) $row);
    }

    private function cell(
        XMLWriter $xml,
        int $column,
        int $row,
        ?string $value,
        int $style = 0,
        bool $numeric = false
    ): void {
        $xml->startElement('c');
        $xml->writeAttribute('r', Coordinate::stringFromColumnIndex($column) . $row);

        if ($style !== 0) {
            $xml->writeAttribute('s', (string) $style);
        }

        if ($value !== null && $numeric) {
            $xml->writeElement('v', $value);
        } elseif ($value !== null) {
            $xml->writeAttribute('t', 'inlineStr');
            $xml->startElement('is');
            $xml->writeElement('t', $value);
            $xml->endElement();
        }

        $xml->endElement();
    }

    private function package(string $path, string $sheetPath): void
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Workbook Excel tidak dapat dibuat.');
        }

        $entries = [
            '[Content_Types].xml' => '<Types xmlns="' . self::TYPE_NS . '">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '</Types>',
            '_rels/.rels' => '<Relationships xmlns="' . self::REL_NS . '">'
                . '<Relationship Id="rId1" Type="' . self::DOC_REL_NS . '/officeDocument" Target="xl/workbook.xml"/>'
                . '</Relationships>',
            'xl/workbook.xml' => '<workbook xmlns="' . self::SHEET_NS
                . '" xmlns:r="' . self::DOC_REL_NS . '">'
                . '<bookViews><workbookView activeTab="0"/></bookViews>'
                . '<sheets><sheet name="Rekap Mingguan" sheetId="1" r:id="rId1"/></sheets>'
                . '</workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="' . self::REL_NS . '">'
                . '<Relationship Id="rId1" Type="' . self::DOC_REL_NS . '/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="' . self::DOC_REL_NS . '/styles" Target="styles.xml"/>'
                . '</Relationships>',
            'xl/styles.xml' => $this->styles(),
        ];

        $written = true;

        foreach ($entries as $name => $contents) {
            $written = $zip->addFromString($name, $contents) && $written;
        }

        $written = $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml') && $written;
        $closed = $zip->close();

        if (!$written || !$closed) {
            throw new RuntimeException('Workbook Excel tidak dapat diselesaikan.');
        }
    }

    private function styles(): string
    {
        $cellStyles = '';

        foreach (self::STYLES as [$fontId, $fillId, $formatId]) {
            $cellStyles .= '<xf numFmtId="' . $formatId . '" fontId="' . $fontId
                . '" fillId="' . $fillId . '" borderId="0" xfId="0"'
                . ' applyNumberFormat="1" applyFont="1" applyFill="1"/>';
        }

        return '<styleSheet xmlns="' . self::SHEET_NS . '">'
            . '<numFmts count="4">'
            . '<numFmt numFmtId="164" formatCode="#,##0"/>'
            . '<numFmt numFmtId="165" formatCode="#,##0.####"/>'
            . '<numFmt numFmtId="166" formatCode="m/d"/>'
            . '<numFmt numFmtId="167" formatCode="dd/mm/yyyy"/>'
            . '</numFmts>'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="6">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . $this->solidFill('FFF8CBAD')
            . $this->solidFill('FFDDEBF7')
            . $this->solidFill('FF9DC3E6')
            . $this->solidFill('FFE7E6E6')
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count(self::STYLES) . '">' . $cellStyles . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private function solidFill(string $color): string
    {
        return '<fill><patternFill patternType="solid"><fgColor rgb="' . $color
            . '"/><bgColor indexed="64"/></patternFill></fill>';
    }
}