<?php

namespace App\Http\Controllers\Api;

use App\Exports\PurchaseOrdersExport;
use App\Exports\PurchaseOrdersDetailedExport;
use App\Http\Controllers\Controller;
use App\Models\OCModel;
use App\Models\OCSModel;
use App\Models\OrderAreaPreApproval;
use App\Models\OrderApprovalAreaMap;
use App\Models\Orders;
use App\Models\CompanyUserPivot;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class OrdersApi extends Controller
{
    private const ROLE_AREA_MANAGER = 'JEFE DE AREA';
    private const ROLE_MANAGER = 'GERENTE';
    private const ROLE_ADMINISTRATOR = 'ADMINISTRADOR';
    private const ACTION_PRE_APPROVE = 'pre_approve';
    private const ACTION_APPROVE = 'approve';
    private const ACTION_REJECT = 'reject';

    public function getOrders(Request $request)
    {
        try {
            $query = Orders::query();

            // Aplicar filtros
            $this->applyFilters($query, $request);

            // Aplicar ordenamiento
            $this->applySorting($query, $request);

            // Paginación
            $itemsPerPage = max((int) $request->input('itemsPerPage', 10), 1);
            $results = $query->paginate($itemsPerPage);

            // Formatear resultados
            $formatted = $this->formatOrders($results->items());

            Log::info($formatted);

            return response()->json([
                'ocs'   => $formatted,
                'total' => $results->total(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en getOrders', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Error inesperado al obtener órdenes',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function export(Request $request)
    {
        try {
            $query = Orders::query();

            // Exporta todos los registros filtrados, sin modificar la base de datos.
            $this->applyFilters($query, $request);
            $this->applySorting($query, $request);

            $orders = $this->formatOrders($query->get()->all());
            $orders = $this->addExportDetails($orders);
            $spreadsheet = PurchaseOrdersExport::create($orders);

            return response()->streamDownload(function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
            }, 'ordenes-starcheck.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en export', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Error inesperado al exportar órdenes',
            ], 500);
        }
    }

    public function exportWithDetails(Request $request)
    {
        try {
            $query = Orders::query();

            // Reutiliza los mismos filtros de la tabla; la consulta es solamente de lectura.
            $this->applyFilters($query, $request);
            $this->applySorting($query, $request);

            $orders = $this->formatOrders($query->get()->all());
            $orders = $this->addDetailedExportData($orders);
            $spreadsheet = PurchaseOrdersDetailedExport::create($orders);

            return response()->streamDownload(function () use ($spreadsheet) {
                (new Xlsx($spreadsheet))->save('php://output');
            }, 'ordenes-starcheck-con-detalle.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en exportWithDetails', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Error inesperado al exportar órdenes con detalle',
            ], 500);
        }
    }

    private function applyFilters($query, Request $request): void
    {
        $user = $request->user();
        $selectedStatus = $this->normalizePortalStatus($request->input('status'));

        if ($user) {
            $companyIds = $user->getCompanyIds();
            if (!empty($companyIds)) {
                $query->whereIn('codigoEmpresa', $companyIds);
            }
        }

        if ($request->filled('company')) {
            $query->where('codigoEmpresa', $request->input('company'));
        }

        if ($user && $this->isAreaManagerRole($this->normalizeRole($user->cargo ?? null))) {
            $areas = $user->getAreaPermissions();

            if (empty($areas)) {
                $query->whereRaw('1 = 0');
            } else {
                $allowedAreas = [];

                if ($request->filled('company')) {
                    $allowedAreas = $user->getAllowedAreasForCompany($request->input('company'));
                } else {
                    // Unir todas las áreas permitidas de todas las empresas
                    foreach ($areas as $companyAreas) {
                        $allowedAreas = array_merge($allowedAreas, (array) $companyAreas);
                    }
                }

                $allowedAreas = array_filter(array_map('trim', (array) $allowedAreas));

                if (!empty($allowedAreas)) {
                    $this->applyAreaExistsFilter($query, $allowedAreas);
                } else {
                    $query->whereRaw('1 = 0');
                }
            }
        }

        // Filtro explícito de área desde frontend (útil para JEFE DE AREA)
        if ($request->filled('area')) {
            $this->applyAreaExistsFilter($query, [(string) $request->input('area')]);
        }

        if ($request->filled('status') && $request->input('status') !== 'ALL') {
            $this->applyEffectiveStatusFilter($query, $request, $selectedStatus);
        }

        if ($request->filled('q')) {
            $search = $request->input('q');
            $query->where(function ($sub) use ($search) {
                $sub->where('identificador', 'like', "%$search%")
                    ->orWhere('asunto', 'like', "%$search%");
            });
        }

        if (!$request->boolean('ignoreDateFilter') && $request->filled('date')) {
            $dateRange = $request->input('date');

            if (strpos($dateRange, ' a ') !== false) {
                [$start, $end] = explode(' a ', $dateRange);
                $start = Carbon::parse(trim($start))->format('Y-m-d');
                $end = Carbon::parse(trim($end))->format('Y-m-d');

                if ($start > $end) {
                    [$start, $end] = [$end, $start];
                }

                // Evita CONVERT(DATE, fechaGeneracion), que impedía usar índices.
                $query->whereBetween('fechaGeneracion', [
                    Carbon::parse($start)->startOfDay(),
                    Carbon::parse($end)->endOfDay(),
                ]);
            } else {
                $date = Carbon::parse(trim($dateRange))->format('Y-m-d');
                $query->whereBetween('fechaGeneracion', [
                    Carbon::parse($date)->startOfDay(),
                    Carbon::parse($date)->endOfDay(),
                ]);
            }
        }
    }


    private function applySorting($query, Request $request): void
    {
        $sortBy = $request->input('sortBy');
        $orderBy = $request->input('orderBy', 'asc');

        $allowedSorts = [
            'company'      => 'codigoEmpresa',
            'company_name' => 'nombreEmpresa',
            'module'       => 'modulo',
            'type'         => 'tipo',
            'code'         => 'identificador',
            'issue'        => 'asunto',
            'issue_date'   => 'fechaGeneracion',
            'status'       => 'estado',
        ];

        if ($sortBy && isset($allowedSorts[$sortBy])) {
            $query->orderBy($allowedSorts[$sortBy], $orderBy === 'desc' ? 'desc' : 'asc');
        } else {
            $query->orderByDesc('fechaGeneracion'); // Orden por defecto
        }
    }

    private function formatOrders(array $orders): array
    {
        $preApprovals = $this->getPreApprovalsForOrders($orders);

        return collect($orders)->map(function ($oc) use ($preApprovals) {
            $preApproval = $preApprovals[$this->buildOrderKey(
                $this->normalizeCompanyCode($oc->codigoEmpresa),
                $this->normalizeOrderType($oc->tipo),
                $this->normalizeOrderCode($oc->identificador),
            )] ?? null;

            $normalizedStatus = $this->resolveEffectiveStatus($oc->estado ?? null, $preApproval);

            return [
                'company'       => $oc->codigoEmpresa,
                'company_name'  => $oc->nombreEmpresa,
                'module'        => $oc->modulo,
                'type'          => $oc->tipo,
                'code'          => $oc->identificador,
                'status'        => $normalizedStatus,
                'date'          => $oc->fechaGeneracion,
                'issue'         => $oc->asunto,
                'issue_date'    => $oc->fechaGeneracion
                    ? Carbon::parse($oc->fechaGeneracion)->format('Y-m-d')
                    : null,
                'read'          => (bool) $oc->leido, // <--- este campo
                'pre_approved_at' => $preApproval?->area_manager_approved_at?->toDateTimeString(),
                'pre_approved_by' => $preApproval?->area_manager_name,
            ];
        })->toArray();
    }

    /**
     * Agrega al Excel los datos de Starsoft sin modificar ninguna base de datos.
     */
    private function addExportDetails(array $orders): array
    {
        return array_map(function (array $order): array {
            $emptyDetails = [
                'area_code' => '',
                'area_name' => '',
                'supplier_ruc' => '',
                'observation' => '',
                'unit_type' => '',
                'amount_without_tax' => null,
                'tax_amount' => null,
                'total_amount' => null,
            ];

            try {
                $rawCompany = trim((string) ($order['company'] ?? ''));
                $company = $this->normalizeCompanyCode($rawCompany);
                $type = $this->normalizeOrderType($order['type'] ?? '');
                $code = trim((string) ($order['code'] ?? ''));

                if ($rawCompany === '' || $code === '' || !in_array($type, ['OC', 'OS'], true)) {
                    return [...$order, ...$emptyDetails];
                }

                $connection = 'sqlsrv_' . $company;
                $headerTable = $type === 'OS' ? 'COMOVC_S' : 'COMOVC';
                $detailTable = $type === 'OS' ? 'COMOVD_S' : 'COMOVD';
                $requirementType = $type === 'OS' ? 'RS' : 'RQ';

                // Consulta de cabecera, requisición y área; es solamente lectura.
                $header = DB::connection($connection)
                    ->table("{$headerTable} as H")
                    ->leftJoin('REQUISC as R', function ($join) use ($requirementType) {
                        $join->on('H.OC_CNRODOCREF', '=', 'R.NROREQUI')
                            ->where('R.TIPOREQUI', '=', $requirementType);
                    })
                    ->leftJoin('AREA as A', 'R.AREA', '=', 'A.AREA_CODIGO')
                    ->select([
                        'H.OC_CCODPRO as supplier_ruc',
                        'H.OC_COBSERV as observation',
                        'H.OC_NIMPORT as amount_without_tax',
                        'H.OC_NIGV as tax_amount',
                        'H.OC_NVENTA as total_amount',
                        'R.AREA as area_code',
                        'A.AREA_DESCRIPCION as area_name',
                    ])
                    ->where('H.OC_CNUMORD', $code)
                    ->first();

                if (!$header) {
                    return [...$order, ...$emptyDetails];
                }

                // Servicios usan UND en el sistema; las OC pueden tener varias unidades.
                $units = $type === 'OS'
                    ? 'UND'
                    : DB::connection($connection)
                        ->table($detailTable)
                        ->where('OC_CNUMORD', $code)
                        ->whereNotNull('OC_CUNIDAD')
                        ->pluck('OC_CUNIDAD')
                        ->map(fn ($unit) => trim((string) $unit))
                        ->filter()
                        ->unique()
                        ->values()
                        ->implode(', ');

                return [
                    ...$order,
                    'area_code' => trim((string) ($header->area_code ?? '')),
                    'area_name' => trim((string) ($header->area_name ?? '')),
                    'supplier_ruc' => trim((string) ($header->supplier_ruc ?? '')),
                    'observation' => trim((string) ($header->observation ?? '')),
                    'unit_type' => $units,
                    'amount_without_tax' => $header->amount_without_tax !== null
                        ? (float) $header->amount_without_tax
                        : null,
                    'tax_amount' => $header->tax_amount !== null
                        ? (float) $header->tax_amount
                        : null,
                    'total_amount' => $header->total_amount !== null
                        ? (float) $header->total_amount
                        : null,
                ];
            } catch (\Throwable $e) {
                // Un registro sin tablas relacionadas no debe cancelar todo el Excel.
                Log::warning('No se pudo enriquecer una orden para exportación', [
                    'company' => $order['company'] ?? null,
                    'type' => $order['type'] ?? null,
                    'code' => $order['code'] ?? null,
                    'message' => $e->getMessage(),
                ]);

                return [...$order, ...$emptyDetails];
            }
        }, $orders);
    }

    /**
     * Carga la cabecera y las líneas de cada orden para el reporte detallado.
     * No modifica ninguna BD: solo ejecuta consultas SELECT mediante los modelos.
     */
    private function addDetailedExportData(array $orders): array
    {
        return array_map(function (array $order): array {
            $emptyDetails = [
                'supplier_ruc' => '',
                'supplier_name' => '',
                'issue_date' => '',
                'delivery_date' => '',
                'requested_by' => '',
                'responsible' => '',
                'observation' => '',
                'currency' => '',
                'amount_without_tax' => null,
                'tax_amount' => null,
                'total_amount' => null,
                'products' => [],
            ];

            try {
                $company = $this->normalizeCompanyCode($order['company'] ?? '');
                $type = $this->normalizeOrderType($order['type'] ?? '');
                $code = trim((string) ($order['code'] ?? ''));

                if ($company === '' || $code === '' || !in_array($type, ['OC', 'OS'], true)) {
                    return [...$order, 'detail' => $emptyDetails];
                }

                // OC y OS usan tablas/modelos distintos, pero entregan la misma estructura al Excel.
                $starsoftOrder = $type === 'OS'
                    ? OCSModel::getOrderWithProducts('sqlsrv_' . $company, $code)
                    : OCModel::getOrderWithProducts('sqlsrv_' . $company, $code);

                if (!$starsoftOrder) {
                    return [...$order, 'detail' => $emptyDetails];
                }

                $products = collect($starsoftOrder->products ?? [])
                    ->values()
                    ->map(function ($product) use ($type): array {
                        $quantity = (float) ($product->OC_NCANTID ?? $product->OC_CANT ?? 0);
                        $unitPrice = (float) ($product->OC_NPREUNI ?? 0);
                        $price = (float) ($product->OC_NTOTVEN ?? $product->OC_NPRENET ?? 0);
                        $discountPercent = (float) ($product->OC_NDSCPOR ?? 0);
                        $total = (float) ($product->OC_NTOTNET ?? $product->OC_NPRENET ?? 0);

                        // Si la tabla no trae total neto, conserva la fórmula del modal como respaldo.
                        if ($total === 0.0 && $unitPrice !== 0.0) {
                            $total = $unitPrice * $quantity * (1 - ($discountPercent / 100));
                        }

                        return [
                            'product_code' => trim((string) ($product->OC_CCODIGO ?? $product->OC_CODSERVICIO ?? '')),
                            'description' => trim((string) ($product->OC_CDESREF ?? '')),
                            'unit' => $product->OC_CUNIDAD ?? ($type === 'OS' ? 'UND' : ''),
                            'quantity' => $quantity,
                            'unit_price' => $unitPrice,
                            'price' => $price,
                            'discount_percent' => $discountPercent,
                            'total' => round($total, 2),
                        ];
                    })
                    ->all();

                $requirementType = $type === 'OS' ? 'RS' : 'RQ';
                $area = DB::connection('sqlsrv_' . $company)
                    ->table('REQUISC as R')
                    ->leftJoin('AREA as A', 'R.AREA', '=', 'A.AREA_CODIGO')
                    ->select('A.AREA_DESCRIPCION as area_name')
                    ->where('R.NROREQUI', $starsoftOrder->OC_CNRODOCREF)
                    ->where('R.TIPOREQUI', $requirementType)
                    ->first();

                return [
                    ...$order,
                    'detail' => [
                        'area_name' => trim((string) ($area->area_name ?? '')),
                        'supplier_ruc' => trim((string) ($starsoftOrder->OC_CCODPRO ?? '')),
                        'supplier_name' => trim((string) ($starsoftOrder->OC_CRAZSOC ?? '')),
                        'issue_date' => $starsoftOrder->OC_DFECDOC ?? '',
                        'delivery_date' => $starsoftOrder->OC_DFECENT ?? '',
                        'requested_by' => trim((string) ($starsoftOrder->required?->TDESCRI ?? '')),
                        'responsible' => trim((string) ($starsoftOrder->responsible?->RESPONSABLE_NOMBRE ?? '')),
                        'observation' => trim((string) ($starsoftOrder->OC_COBSERV ?? '')),
                        'currency' => trim((string) ($starsoftOrder->OC_CCODMON ?? '')),
                        'amount_without_tax' => $starsoftOrder->OC_NIMPORT !== null
                            ? (float) $starsoftOrder->OC_NIMPORT
                            : null,
                        'tax_amount' => $starsoftOrder->OC_NIGV !== null
                            ? (float) $starsoftOrder->OC_NIGV
                            : null,
                        'total_amount' => $starsoftOrder->OC_NVENTA !== null
                            ? (float) $starsoftOrder->OC_NVENTA
                            : null,
                        'products' => $products,
                    ],
                ];
            } catch (\Throwable $e) {
                // Una orden con datos incompletos no debe cancelar las demás del reporte.
                Log::warning('No se pudo cargar detalle para exportación', [
                    'company' => $order['company'] ?? null,
                    'type' => $order['type'] ?? null,
                    'code' => $order['code'] ?? null,
                    'message' => $e->getMessage(),
                ]);

                return [...$order, 'detail' => $emptyDetails];
            }
        }, $orders);
    }

    public function getOrder(Request $request)
    {
        $company = $request->input('company');
        $type = $request->input('type');
        $code = $request->input('code');

        $connection = 'sqlsrv_' . $company;

        if ($type == 'OC') {
            $order = OCModel::getOrderWithProducts($connection, $code);
        } elseif ($type == 'OS') {
            $order = OCSModel::getOrderWithProducts($connection, $code);
        } else {
            return response()->json(['message' => 'Tipo de orden inválido'], 400);
        }

        if (!$order) {
            return response()->json(['message' => 'Orden no encontrada'], 404);
        }

        $portalOrder = Orders::where('tipo', $type)
            ->where('identificador', $code)
            ->where('codigoEmpresa', $company)
            ->first();

        $preApproval = $this->findPreApproval(
            $this->normalizeCompanyCode($company),
            $this->normalizeOrderType($type),
            $this->normalizeOrderCode($code),
        );

        $order->setAttribute('portal_status', $this->resolveEffectiveStatus($portalOrder?->estado ?? null, $preApproval));
        $order->setAttribute('usuarioAprobacion', $portalOrder?->usuarioAprobacion);
        $order->setAttribute('fechaAprobacion', $portalOrder?->fechaAprobacion);
        $order->setAttribute('pre_approved_by', $preApproval?->area_manager_name);
        $order->setAttribute('pre_approved_at', $preApproval?->area_manager_approved_at?->toDateTimeString());

        return response()->json($order);
    }

    public function markAsRead(Request $request)
    {
        $user = $request->user();
        $role = $this->normalizeRole($user?->cargo ?? null);

        if (!$this->canMarkAsReadRole($role)) {
            return response()->json([
                'success' => false,
                'message' => 'Solo gerencia puede marcar la orden como leída.',
            ], 403);
        }

        $oc = Orders::where('tipo', $request->type)
            ->where('identificador', $request->code)
            ->where('codigoEmpresa', $request->company)
            ->first();


        if (!$oc) {
            return response()->json(['error' => 'Orden no encontrada'], 404);
        }

        $oc->leido = 1;
        $oc->save();

        return response()->json(['success' => true]);
    }

    public function handleApproval(Request $request)
    {
        try {
            $user = $request->user();
            $action = $request->input('action');

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado',
                    'color'   => 'error',
                ], 401);
            }

            $role = $this->normalizeRole($user->cargo ?? null);

            // Paso 1: actualiza BDWENCO
            $oc = Orders::where('tipo', $request->type)
                ->where('identificador', $request->code)
                ->where('codigoEmpresa', $request->company)
                ->firstOrFail();

            $preApproval = $this->findPreApproval(
                $this->normalizeCompanyCode($request->company),
                $this->normalizeOrderType($request->type),
                $this->normalizeOrderCode($request->code),
            );

            $currentStatus = $this->resolveEffectiveStatus($oc->estado ?? null, $preApproval);

            if (!$this->canExecuteApprovalAction($action, $role, $currentStatus)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No tienes permisos para realizar esta acción en el estado actual.',
                    'color'   => 'error',
                ], 403);
            }

            [$estado, $erpStatus, $message] = $this->resolveApprovalTransition($action);

            if ($action === self::ACTION_PRE_APPROVE) {
                $preApproval = OrderAreaPreApproval::updateOrCreate(
                    [
                        'company_code' => $this->normalizeCompanyCode($request->company),
                        'order_type' => $this->normalizeOrderType($request->type),
                        'order_code' => $this->normalizeOrderCode($request->code),
                    ],
                    [
                        'area_manager_user_id' => $user->id,
                        'area_manager_name' => $user->name,
                        'area_manager_approved_at' => now(),
                    ],
                );
            } else {
                $oc->estado = $estado;
                $oc->usuarioAprobacion = $this->resolveApprovalUser($user->id, $request->company, $user->email ?? $user->name);
                $oc->fechaAprobacion = now();
                $oc->save();
            }

            if ($erpStatus !== null) {
                // Paso 2: actualiza COMOVC o COMOVC_S en la misma conexión solo en aprobación final o rechazo
                $connection = 'sqlsrv_' . $request->company;
                $table = $request->type === 'OC' ? 'dbo.COMOVC' : 'dbo.COMOVC_S';
                $fieldId = 'OC_CNUMORD';
                $fieldStatus = 'OC_CSITORD';

                DB::connection($connection)
                    ->table($table)
                    ->where($fieldId, $request->code)
                    ->update([
                        $fieldStatus              => $erpStatus,
                        'NOMBRE_USUARIO'          => $user->name,
                        'CARGO_USUARIO'           => $user->cargo ?? '',
                        'FECHAHORA_CAMBIOESTADO'  => now(),
                    ]);
            }

            return response()->json([
                'success' => true,
                'estado'  => $estado,
                'message' => $message,
                'color'   => 'primary',
            ]);
        } catch (\Throwable $e) {
            Log::error('Error en handleApproval', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No se pudo procesar la orden',
                'color'   => 'error',
            ], 500);
        }
    }

    private function applyAreaExistsFilter($query, array $areas): void
    {
        $ordersTable = $query->getModel()->getTable();

        $query->whereExists(function ($subQuery) use ($ordersTable, $areas) {
            $areaMap = new OrderApprovalAreaMap();

            $subQuery->selectRaw('1')
                ->from($areaMap->getTable() . ' as area_map')
                // Las columnas ya usan el mismo formato en ListadoAprobaciones y la vista.
                ->whereColumn('area_map.codigoEmpresa', "{$ordersTable}.codigoEmpresa")
                ->whereColumn('area_map.tipo', "{$ordersTable}.tipo")
                ->whereColumn('area_map.identificador', "{$ordersTable}.identificador")
                ->whereIn('area_map.AREA', $areas);
        });
    }

    private function applyEffectiveStatusFilter(Builder $query, Request $request, string $selectedStatus): void
    {
        if (in_array($selectedStatus, ['APROBADA', 'RECHAZADO'], true)) {
            if ($selectedStatus === 'RECHAZADO') {
                $query->whereIn('estado', ['RECHAZADO', 'RECHAZADA']);
            } else {
                $query->where('estado', $selectedStatus);
            }

            return;
        }

        if (!in_array($selectedStatus, ['EMITIDA', 'PREAPROBADA'], true)) {
            return;
        }

        $query->where('estado', 'EMITIDA');

        $preApprovedOrders = $this->getPreApprovedOrderKeysForRequest($request);

        if ($selectedStatus === 'PREAPROBADA') {
            if (empty($preApprovedOrders)) {
                $query->whereRaw('1 = 0');
                return;
            }

            $this->applyCompositeOrderKeyFilter($query, $preApprovedOrders, true);
            return;
        }

        if (!empty($preApprovedOrders)) {
            $this->applyCompositeOrderKeyFilter($query, $preApprovedOrders, false);
        }
    }

    private function getPreApprovedOrderKeysForRequest(Request $request): array
    {
        $preApprovalsQuery = OrderAreaPreApproval::query()->select([
            'company_code',
            'order_type',
            'order_code',
        ]);

        if ($request->filled('company')) {
            $preApprovalsQuery->where('company_code', $this->normalizeCompanyCode($request->input('company')));
        } elseif ($request->user()) {
            $companyIds = $request->user()->getCompanyIds();

            if (!empty($companyIds)) {
                $preApprovalsQuery->whereIn('company_code', array_map(
                    fn ($companyCode) => $this->normalizeCompanyCode($companyCode),
                    $companyIds,
                ));
            }
        }

        return $preApprovalsQuery
            ->get()
            ->map(fn ($preApproval) => [
                'company_code' => $this->normalizeCompanyCode($preApproval->company_code),
                'order_type' => $this->normalizeOrderType($preApproval->order_type),
                'order_code' => $this->normalizeOrderCode($preApproval->order_code),
            ])
            ->all();
    }

    private function applyCompositeOrderKeyFilter(Builder $query, array $orderKeys, bool $include): void
    {
        // Agrupar reduce drásticamente los parámetros: antes se generaban
        // varias condiciones por cada OC y SQL Server permite como máximo 2100.
        $groups = [];

        foreach ($orderKeys as $orderKey) {
            $groupKey = $orderKey['company_code'] . '|' . $orderKey['order_type'];
            $groups[$groupKey]['company_code'] = $orderKey['company_code'];
            $groups[$groupKey]['order_type'] = $orderKey['order_type'];
            $groups[$groupKey]['order_codes'][] = $orderKey['order_code'];
        }

        $applyGroupedKeys = function ($nestedQuery) use ($groups): void {
            foreach ($groups as $group) {
                $numericCodes = [];
                $textCodes = [];

                foreach (array_unique($group['order_codes']) as $orderCode) {
                    if (preg_match('/^\d+$/', $orderCode)) {
                        $numericCodes[] = (int) $orderCode;
                    } else {
                        $textCodes[] = $orderCode;
                    }
                }

                $nestedQuery->orWhere(function ($itemQuery) use ($group, $numericCodes, $textCodes): void {
                    $itemQuery->whereRaw(
                        "RIGHT('000' + CAST(codigoEmpresa AS VARCHAR(3)), 3) = ?",
                        [$group['company_code']],
                    )->whereRaw(
                        "UPPER(LTRIM(RTRIM(CAST(tipo AS VARCHAR(10))))) = ?",
                        [$group['order_type']],
                    )->where(function ($codeQuery) use ($numericCodes, $textCodes): void {
                        if (!empty($numericCodes)) {
                            // Los enteros se insertan como literales validados, sin bindings.
                            $codeQuery->whereIntegerInRaw(
                                DB::raw('TRY_CONVERT(BIGINT, LTRIM(RTRIM(CAST(identificador AS VARCHAR(50)))))'),
                                $numericCodes,
                            );
                        }

                        if (!empty($textCodes)) {
                            $method = !empty($numericCodes) ? 'orWhereIn' : 'whereIn';
                            $codeQuery->{$method}(
                                DB::raw('LTRIM(RTRIM(CAST(identificador AS VARCHAR(50))))'),
                                $textCodes,
                            );
                        }
                    });
                });
            }
        };

        if ($include) {
            $query->where($applyGroupedKeys);
        } else {
            $query->whereNot($applyGroupedKeys);
        }
    }

    private function getPreApprovalsForOrders(array $orders): array
    {
        if (empty($orders)) {
            return [];
        }

        $query = OrderAreaPreApproval::query();
        $companyCodes = collect($orders)
            ->map(fn ($order) => $this->normalizeCompanyCode($order->codigoEmpresa))
            ->unique()
            ->values()
            ->all();
        $orderTypes = collect($orders)
            ->map(fn ($order) => $this->normalizeOrderType($order->tipo))
            ->unique()
            ->values()
            ->all();

        $query->whereIn('company_code', $companyCodes)
            ->whereIn('order_type', $orderTypes);

        return $query->get()
            ->filter(function ($preApproval) use ($orders) {
                $preApprovalKey = $this->buildOrderKey(
                    $this->normalizeCompanyCode($preApproval->company_code),
                    $this->normalizeOrderType($preApproval->order_type),
                    $this->normalizeOrderCode($preApproval->order_code),
                );

                foreach ($orders as $order) {
                    $orderKey = $this->buildOrderKey(
                        $this->normalizeCompanyCode($order->codigoEmpresa),
                        $this->normalizeOrderType($order->tipo),
                        $this->normalizeOrderCode($order->identificador),
                    );

                    if ($preApprovalKey === $orderKey) {
                        return true;
                    }
                }

                return false;
            })
            ->keyBy(fn ($preApproval) => $this->buildOrderKey(
                $this->normalizeCompanyCode($preApproval->company_code),
                $this->normalizeOrderType($preApproval->order_type),
                $this->normalizeOrderCode($preApproval->order_code),
            ))
            ->all();
    }

    private function findPreApproval(string $companyCode, string $orderType, string $orderCode): ?OrderAreaPreApproval
    {
        return OrderAreaPreApproval::query()
            ->where('company_code', $companyCode)
            ->where('order_type', $orderType)
            ->get()
            ->first(fn ($preApproval) => $this->normalizeOrderCode($preApproval->order_code) === $orderCode);
    }

    private function resolveEffectiveStatus(?string $sqlStatus, ?OrderAreaPreApproval $preApproval): string
    {
        $normalizedStatus = $this->normalizePortalStatus($sqlStatus);

        if ($normalizedStatus === 'EMITIDA' && $preApproval) {
            return 'PREAPROBADA';
        }

        return $normalizedStatus;
    }

    private function buildOrderKey(string $companyCode, string $orderType, string $orderCode): string
    {
        return implode('|', [$companyCode, $orderType, $orderCode]);
    }

    private function normalizeCompanyCode($companyCode): string
    {
        return str_pad(trim((string) $companyCode), 3, '0', STR_PAD_LEFT);
    }

    private function normalizeOrderType($orderType): string
    {
        return strtoupper(trim((string) $orderType));
    }

    private function normalizeOrderCode($orderCode): string
    {
        $normalized = trim((string) $orderCode);

        if ($normalized !== '' && preg_match('/^\d+$/', $normalized)) {
            return ltrim($normalized, '0') ?: '0';
        }

        return strtoupper($normalized);
    }

    private function applyOrderCodeMatch(Builder $query, string $orderCode): void
    {
        $query->where(function ($orderCodeQuery) use ($orderCode) {
            $orderCodeQuery->whereRaw("LTRIM(RTRIM(CAST(identificador AS VARCHAR(50)))) = ?", [$orderCode]);

            if (preg_match('/^\d+$/', $orderCode)) {
                $orderCodeQuery->orWhereRaw("TRY_CONVERT(BIGINT, LTRIM(RTRIM(CAST(identificador AS VARCHAR(50))))) = ?", [(int) $orderCode]);
            }
        });
    }

    private function normalizePortalStatus(?string $status): string
    {
        $normalizedStatus = strtoupper(trim((string) $status));

        return $normalizedStatus === 'RECHAZADA'
            ? 'RECHAZADO'
            : $normalizedStatus;
    }

    private function normalizeRole(?string $role): string
    {
        return preg_replace('/\s+/', ' ', strtoupper(trim((string) $role))) ?? '';
    }

    private function isAreaManagerRole(string $role): bool
    {
        return $role === self::ROLE_AREA_MANAGER;
    }

    private function canFinalApproveRole(string $role): bool
    {
        return $role === self::ROLE_ADMINISTRATOR || str_starts_with($role, self::ROLE_MANAGER);
    }

    private function canMarkAsReadRole(string $role): bool
    {
        return str_starts_with($role, self::ROLE_MANAGER);
    }

    private function resolveApprovalUser(int $userId, string $companyId, string $fallback): string
    {
        $approvalEmail = CompanyUserPivot::query()
            ->where('user_id', $userId)
            ->where('company_id', $this->normalizeCompanyCode($companyId))
            ->value('approval_email');

        return filled($approvalEmail)
            ? trim((string) $approvalEmail)
            : $fallback;
    }

    private function canExecuteApprovalAction(string $action, string $role, string $currentStatus): bool
    {
        return match ($action) {
            self::ACTION_PRE_APPROVE =>
                $this->isAreaManagerRole($role) && $currentStatus === 'EMITIDA',
            self::ACTION_APPROVE =>
                $this->canFinalApproveRole($role) && $currentStatus === 'PREAPROBADA',
            self::ACTION_REJECT =>
                $this->canFinalApproveRole($role) && $currentStatus === 'PREAPROBADA',
            default => false,
        };
    }

    private function resolveApprovalTransition(string $action): array
    {
        return match ($action) {
            self::ACTION_PRE_APPROVE => ['PREAPROBADA', null, 'La orden fue enviada a aprobación gerencial.'],
            self::ACTION_APPROVE => ['APROBADA', '01', 'La orden fue aprobada correctamente.'],
            self::ACTION_REJECT => ['RECHAZADO', '06', 'La orden fue rechazada correctamente.'],
            default => throw new \InvalidArgumentException('Acción de aprobación inválida.'),
        };
    }
}
