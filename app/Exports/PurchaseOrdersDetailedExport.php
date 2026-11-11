<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class PurchaseOrdersDetailedExport
{
    public static function create(array $orders): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('OC con detalle');

        $row = 1;

        foreach ($orders as $order) {
            $detail = $order['detail'] ?? [];
            $type = $order['type'] ?? 'OC';
            $code = $order['code'] ?? '';

            $sheet->mergeCells("A{$row}:D{$row}");
            $sheet->setCellValue("A{$row}", "{$type} {$code}");
            self::styleTitle($sheet, $row);
            $row++;

            self::writeHeaderBlock($sheet, $row, [
                ['Empresa', $order['company_name'] ?? $order['company'] ?? '', 'Módulo', $order['module'] ?? ''],
                ['Tipo', $type, 'Estado', $order['status'] ?? ''],
                ['Código', $code, 'Asunto', $order['issue'] ?? ''],
                ['Fecha envío', $order['issue_date'] ?? '', 'Fecha emisión', self::formatDate($detail['issue_date'] ?? '')],
                ['Proveedor', $detail['supplier_name'] ?? '', 'RUC', $detail['supplier_ruc'] ?? ''],
                ['Fecha entrega', self::formatDate($detail['delivery_date'] ?? ''), 'Moneda', $detail['currency'] ?? ''],
                ['Solicitado por', $detail['requested_by'] ?? '', 'Responsable', $detail['responsible'] ?? ''],
            ]);
            $row += 7;

            $sheet->setCellValue("A{$row}", 'Observación');
            $sheet->mergeCells("B{$row}:D{$row}");
            $sheet->setCellValue("B{$row}", $detail['observation'] ?? '');
            self::styleLabels($sheet, "A{$row}:A{$row}");
            self::styleValue($sheet, "B{$row}:D{$row}");
            $sheet->getStyle("B{$row}:D{$row}")->getAlignment()->setWrapText(true);
            $row++;

            $sheet->fromArray(['Descripción', 'UND', 'CANT', 'Total producto'], null, "A{$row}");
            self::styleTableHeader($sheet, $row);
            $row++;

            $products = $detail['products'] ?? [];
            if ($products === []) {
                $sheet->mergeCells("A{$row}:D{$row}");
                $sheet->setCellValue("A{$row}", 'No se encontraron productos');
                self::styleValue($sheet, "A{$row}:D{$row}");
                $row++;
            } else {
                foreach ($products as $product) {
                    $sheet->fromArray([
                        $product['description'] ?? '',
                        $product['unit'] ?? '',
                        $product['quantity'] ?? 0,
                        $product['total'] ?? 0,
                    ], null, "A{$row}");
                    $row++;
                }
            }

            $sheet->mergeCells("A{$row}:C{$row}");
            $sheet->setCellValue("A{$row}", 'Total orden');
            $sheet->setCellValue("D{$row}", $detail['total_amount'] ?? 0);
            self::styleTotal($sheet, $row);
            $row++;

            $sheet->mergeCells("A{$row}:C{$row}");
            $sheet->setCellValue("A{$row}", 'IGV');
            $sheet->setCellValue("D{$row}", $detail['tax_amount'] ?? 0);
            self::styleTotal($sheet, $row);
            $row += 2;
        }

        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(24);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(22);
        $sheet->getStyle("A1:D{$row}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A1:D{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("C1:D{$row}")->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getPageSetup()->setOrientation('landscape');
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);

        return $spreadsheet;
    }

    private static function writeHeaderBlock($sheet, int $row, array $rows): void
    {
        foreach ($rows as $values) {
            $sheet->fromArray($values, null, "A{$row}");
            self::styleLabels($sheet, "A{$row}:A{$row}");
            self::styleLabels($sheet, "C{$row}:C{$row}");
            self::styleValue($sheet, "B{$row}:B{$row}");
            self::styleValue($sheet, "D{$row}:D{$row}");
            $row++;
        }
    }

    private static function styleTitle($sheet, int $row): void
    {
        $style = $sheet->getStyle("A{$row}:D{$row}");
        $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F4E78');
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private static function styleLabels($sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9EAF7');
    }

    private static function styleValue($sheet, string $range): void
    {
        $sheet->getStyle($range)->getAlignment()->setWrapText(true);
    }

    private static function styleTableHeader($sheet, int $row): void
    {
        $style = $sheet->getStyle("A{$row}:D{$row}");
        $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF5B9BD5');
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    }

    private static function styleTotal($sheet, int $row): void
    {
        $style = $sheet->getStyle("A{$row}:D{$row}");
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEAF2F8');
    }

    private static function formatDate($value): string
    {
        if (!$value) {
            return '';
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? (string) $value : date('Y-m-d', $timestamp);
    }
}
