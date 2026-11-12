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

        $headers = [
            'Empresa',
            'Modulo',
            'Tipo',
            'Codigo',
            'Asunto',
            'Fecha Envio',
            'Estado',
            'Area',
            'RUC Proveedor',
            'Codigo Item',
            'Descripcion',
            'Unidad',
            'Cantidad',
            'Precio Unitario',
            'Precio',
            '% Descuento',
            'Total',
        ];

        $sheet->fromArray($headers, null, 'A1');

        $rows = [];

        foreach ($orders as $order) {
            $detail = $order['detail'] ?? [];
            $products = $detail['products'] ?? [];

            // Conserva la OC en el reporte aunque no tenga líneas de detalle.
            if ($products === []) {
                $products = [[]];
            }

            foreach ($products as $product) {
                $rows[] = [
                    $order['company_name'] ?? $order['company'] ?? '',
                    $order['module'] ?? '',
                    $order['type'] ?? '',
                    $order['code'] ?? '',
                    $order['issue'] ?? '',
                    $order['issue_date'] ?? '',
                    $order['status'] ?? '',
                    $detail['area_name'] ?? '',
                    $detail['supplier_ruc'] ?? '',
                    $product['product_code'] ?? '',
                    $product['description'] ?? '',
                    $product['unit'] ?? '',
                    $product['quantity'] ?? null,
                    $product['unit_price'] ?? null,
                    $product['price'] ?? null,
                    $product['discount_percent'] ?? null,
                    $product['total'] ?? null,
                ];
            }
        }

        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
        }

        $lastRow = max(count($rows) + 1, 1);
        $headerStyle = $sheet->getStyle('A1:Q1');
        $headerStyle->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F4E78');
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->getStyle("A1:Q{$lastRow}")
            ->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A1:Q{$lastRow}")
            ->getBorders()
            ->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A1:Q{$lastRow}")
            ->getAlignment()
            ->setWrapText(true);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:Q{$lastRow}");

        foreach (range('A', 'Q') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $sheet->getColumnDimension('E')->setWidth(45);
        $sheet->getColumnDimension('H')->setWidth(28);
        $sheet->getColumnDimension('K')->setWidth(45);
        $sheet->getColumnDimension('L')->setWidth(14);
        $sheet->getColumnDimension('M')->setWidth(14);
        $sheet->getColumnDimension('N')->setWidth(18);
        $sheet->getColumnDimension('O')->setWidth(18);
        $sheet->getColumnDimension('P')->setWidth(16);
        $sheet->getColumnDimension('Q')->setWidth(18);

        if ($lastRow > 1) {
            $sheet->getStyle("M2:Q{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('#,##0.00');
        }

        $sheet->getPageSetup()->setOrientation('landscape');
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);

        return $spreadsheet;
    }
}
