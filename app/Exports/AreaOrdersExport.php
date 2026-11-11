<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class AreaOrdersExport
{
    /** Construye una tabla detallada y filtrable por proveedor y OC/OS. */
    public static function create(array $rows, string $area): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Detalle Area');

        $sheet->fromArray([["Área: {$area}"]], null, 'A1');
        $headers = [
            'Proveedor', 'RUC', 'Tipo', 'Nro OC/OS', 'Fecha Emision', 'Fecha Entrega',
            'Solicitado Por', 'Responsable', 'Observacion', 'Codigo', 'Descripcion',
            'Unidad', 'Cantidad', 'PU', 'IGV', 'Total Linea', 'Total Orden',
        ];
        $sheet->fromArray($headers, null, 'A3');

        $data = array_map(static fn ($row): array => [
            $row->supplier_name ?? '',
            $row->supplier_ruc ?? '',
            $row->order_type ?? '',
            $row->order_number ?? '',
            $row->issue_date ?? '',
            $row->delivery_date ?? '',
            $row->requested_by ?? '',
            $row->responsible ?? '',
            $row->observation ?? '',
            $row->product_code ?? '',
            $row->product_name ?? '',
            $row->unit ?? '',
            $row->quantity !== null ? (float) $row->quantity : null,
            $row->unit_price !== null ? (float) $row->unit_price : null,
            $row->detail_tax !== null ? (float) $row->detail_tax : null,
            $row->line_total !== null ? (float) $row->line_total : null,
            $row->order_total !== null ? (float) $row->order_total : null,
        ], $rows);

        if ($data !== []) {
            $sheet->fromArray($data, null, 'A4');
        }

        $lastRow = max(count($data) + 3, 3);
        $sheet->mergeCells('A1:Q1');
        $sheet->getStyle('A1:Q1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A3:Q3')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A3:Q3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F4E78');
        $sheet->getStyle("A3:Q{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A1:Q{$lastRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->freezePane('A4');
        $sheet->setAutoFilter("A3:Q{$lastRow}");

        foreach (range('A', 'Q') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $sheet->getColumnDimension('I')->setWidth(45);
        $sheet->getColumnDimension('K')->setWidth(40);
        $sheet->getStyle("M4:Q{$lastRow}")->getNumberFormat()->setFormatCode('#,##0.00');

        return $spreadsheet;
    }
}
