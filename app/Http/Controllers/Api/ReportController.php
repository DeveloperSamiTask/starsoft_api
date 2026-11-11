<?php

namespace App\Http\Controllers\Api;

use App\Exports\AreaOrdersExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\SupplierProductByAreaRequest;
use App\Models\Area;
use App\Models\OCModel;
use App\Services\SupplierReportService;
use App\Services\UserPermissionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportController extends Controller
{
    public function __construct(
        private SupplierReportService $reportService,
        private UserPermissionService $permissionService
    ) {}

    public function reportSuppliers(Request $request)
    {
        try {
            $context = $this->resolveReportContext($request);

            $result = OCModel::getOrdersSummary(
                $context['connection'],
                $context['start_date'],
                $context['end_date'],
                $context['responsible'],
                $context['type'],
                $context['area_filter'],
            );

            $areas = Area::getAvailableAreas(
                $context['connection'],
                $context['start_date'],
                $context['end_date'],
                $context['responsible'],
                $context['type'],
                $context['area_filter'],
            );

            // Calcular monto máximo
            $maxMonto = $result->max(fn($item) => (float) $item->MONTO_TOTAL);

            return response()->json([
                'data' => $result,
                'maxMonto' => $maxMonto,
                'areas' => $areas,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en reportSuppliers', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Error inesperado al obtener proveedores',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function reportAreas(Request $request)
    {
        try {
            $context = $this->resolveReportContext($request);

            $result = OCModel::reportAreas(
                $context['connection'],
                $context['start_date'],
                $context['end_date'],
                $context['responsible'],
                $context['type'],
                $context['area_filter'],
            );

            $maxMonto = $result->max(fn($item) => (float) $item->MONTO_TOTAL);

            return response()->json([
                'data' => $result,
                'maxMonto' => $maxMonto,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en reportAreas', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Error inesperado al obtener reporte por áreas',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

        public function reportSupplierProductsAreas(
        SupplierProductByAreaRequest $request
    ){
        try {
            $company = $this->permissionService->resolveAuthorizedCompany($request->getCompany());
            $areaFilter = $this->permissionService->resolveAreaFilter($company, $request->input('area'));

            if (is_array($areaFilter) && empty($areaFilter)) {
                return response()->json($this->reportService->formatResponse(collect(), [
                    'company' => $company,
                    'area' => null,
                    'date' => $request->input('date'),
                    'type' => $request->getType(),
                ]));
            }

            $resolvedArea = is_array($areaFilter) && !empty($areaFilter)
                ? $areaFilter[0]
                : $request->input('area');

            $suppliers = $this->reportService->getSupplierProducts(
                company: $company,
                area: $resolvedArea,
                dateRange: $request->input('date'),
                type: $request->getType()
            );

            $orderCounts = $this->reportService->getAreaOrderCounts(
                company: $company,
                area: $resolvedArea,
                dateRange: $request->input('date'),
                type: $request->getType()
            );

            $response = $this->reportService->formatResponse(
                $suppliers,
                [
                    'company' => $company,
                    'area' => $resolvedArea,
                    'date' => $request->input('date'),
                    'type' => $request->getType(),
                ]
            );
            $response['order_counts'] = $orderCounts;

            return response()->json($response);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Datos inválidos',
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Throwable $e) {
            Log::error('Error en reportSupplierProductsAreas', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'error' => 'Error al generar el reporte',
                'message' => config('app.debug') ? $e->getMessage() : null,
            ], 500);
        }
    }

    public function exportAreaOrders(Request $request)
    {
        try {
            $company = $this->permissionService->resolveAuthorizedCompany($request->input('company'));
            $area = trim((string) $request->input('area'));
            $date = (string) $request->input('date');
            $type = $request->input('type');

            if ($area === '' || $date === '') {
                return response()->json(['error' => 'Área y rango de fechas son obligatorios.'], 422);
            }

            $rows = $this->reportService->getAreaOrdersForExport($company, $area, $date, $type);
            $spreadsheet = AreaOrdersExport::create($rows->all(), $area);

            return response()->streamDownload(function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
            }, "reporte-area-{$company}-{$area}.xlsx", [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en exportAreaOrders', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return response()->json(['error' => 'No se pudo exportar el reporte del área.'], 500);
        }
    }

    public function reportAreasByOrders(Request $request)
    {
        try {
            $context = $this->resolveReportContext($request);

            $result = OCModel::reportAreasByOrders(
                $context['connection'],
                $context['start_date'],
                $context['end_date'],
                $context['responsible'],
                $context['type'],
                $context['area_filter'],
            );

            $maxCantidad = $result->max(fn($item) => (int) $item->MONTO_TOTAL);

            return response()->json([
                'data' => $result,
                'maxCantidad' => $maxCantidad,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en reportAreasCount', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Error inesperado al obtener reporte por áreas (conteo)',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function reportMonthOrders(Request $request)
    {
        try {

            $conexion = 'sqlsrv_' . $request->input('company', '003');
            $dateRange = $request->input('date');
            $type = $request->input('type', 'OC');
            $staff = $request->input('staff');
            $months = 4;

            $responsible = null;
            if (auth()->check()) {
                $user = auth()->user();

                if (in_array(strtoupper($user->cargo), ['GERENTE', 'ADMINISTRADOR'])) {
                    $responsible = $staff;
                } else {
                    $userCode = CompanyUserPivot::where('user_id', $user->id)
                        ->where('company_id', $request->input('company', '003'))
                        ->value('user_code');

                    $responsible = $userCode;
                }
            }

            $endDate = Carbon::now()->endOfMonth();
            $startDate = Carbon::now()->subMonths($months - 1)->startOfMonth();
        } catch (\Throwable $e) {
        }
    }

    public function reportMonthlyExpenses(Request $request)
    {
        try {
            $context = $this->resolveReportContext($request);
            $monthsBack = $request->input('months', 5); // cantidad de meses atrás

            $result = OCModel::reportMonthlyExpenses(
                $context['connection'],
                $context['type'],
                $context['responsible'],
                $context['area_filter'],
                $monthsBack,
            );

            // 5️⃣ Calcular total general
            $totalGeneral = collect($result)->sum('total');

            return response()->json([
                'data' => $result,
                'totalGeneral' => $totalGeneral,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en reportMonthlyExpenses', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Error inesperado al obtener reporte mensual',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Centraliza los permisos de reportes:
     * empresa permitida, responsable aplicable y áreas visibles para el usuario.
     */
    private function resolveReportContext(Request $request): array
    {
        $company = $this->permissionService->resolveAuthorizedCompany($request->input('company'));
        [$startDate, $endDate] = $this->parseDateRange($request->input('date'));

        return [
            'company' => $company,
            'connection' => 'sqlsrv_' . $company,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'type' => $request->input('type', 'OC'),
            'responsible' => $this->permissionService->resolveResponsibleUser($company, $request->input('staff')),
            'area_filter' => $this->permissionService->resolveAreaFilter($company, $request->input('area')),
        ];
    }

    private function parseDateRange(?string $dateRange): array
    {
        if (strpos((string) $dateRange, ' a ') !== false) {
            [$start, $end] = explode(' a ', (string) $dateRange);
            $start = Carbon::parse(trim($start))->format('Y-m-d');
            $end = Carbon::parse(trim($end))->format('Y-m-d');

            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }

            return [$start, $end];
        }

        $date = Carbon::parse(trim((string) $dateRange))->format('Y-m-d');

        return [$date, $date];
    }
}
