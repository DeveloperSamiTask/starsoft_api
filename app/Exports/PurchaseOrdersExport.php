<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class PurchaseOrdersExport
{
    /**
     * Construye el XLSX con las columnas visibles en /ocs y datos de Starsoft.
     */
    public static function create(array $orders): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Ordenes');

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
            'Observacion',
            'Tipo de Unidad',
            'Precio sin IGV',
            'IGV',
            'Importe Total',
        ];

        $sheet->fromArray($headers, null, 'A1');

        $rows = array_map(static fn (array $order): array => [
            $order['company_name'] ?? $order['company'] ?? '',
            $order['module'] ?? '',
            $order['type'] ?? '',
            $order['code'] ?? '',
            $order['issue'] ?? '',
            $order['issue_date'] ?? '',
            $order['status'] ?? '',
            $order['area_name'] ?? '',
            $order['supplier_ruc'] ?? '',
            $order['observation'] ?? '',
            $order['unit_type'] ?? '',
            $order['amount_without_tax'] ?? null,
            $order['tax_amount'] ?? null,
            $order['total_amount'] ?? null,
        ], $orders);

        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
        }

        $lastRow = max(count($rows) + 1, 1);
        $headerStyle = $sheet->getStyle('A1:N1');
        $headerStyle->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F4E78');
        $headerStyle->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $headerStyle->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);

        $sheet->getStyle("A1:N{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getStyle("A1:N{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:N{$lastRow}");

        foreach (range('A', 'N') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $sheet->getColumnDimension('E')->setWidth(55);
        $sheet->getColumnDimension('H')->setWidth(30);
        $sheet->getColumnDimension('J')->setWidth(55);
        $sheet->getColumnDimension('K')->setWidth(18);
        $sheet->getColumnDimension('M')->setWidth(18);
        $sheet->getColumnDimension('N')->setWidth(18);

        if ($lastRow > 1) {
            $sheet->getStyle("L2:N{$lastRow}")
                ->getNumberFormat()
                ->setFormatCode('#,##0.00');
        }

        return $spreadsheet;
    }
}
