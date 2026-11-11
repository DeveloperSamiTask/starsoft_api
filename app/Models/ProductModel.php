<?php

namespace App\Models;

use App\Models\Product\FamilyModel;
use App\Models\Product\TypeModel;
use App\Traits\HasDynamicConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductModel extends Model
{
    use HasFactory, HasDynamicConnection;

    protected $table = 'MAEART';
    protected $primaryKey = 'ACODIGO';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'ACODIGO',
        'ADESCRI',
        'AUNIDAD',
        'AFSERIE',
        'AFLOTE',
        'AFAMILIA',
        'AMODELO',
        'AGRUPO',
        'ATIPO',
        'AUSER',
        'AFECHA',
    ];

    // ============================================
    // RELACIONES
    // ============================================

    public function type()
    {
        return $this->belongsTo(TypeModel::class, 'ATIPO', 'COD_TIPO');
    }

    public function family()
    {
        return $this->belongsTo(FamilyModel::class, 'AFAMILIA', 'FAM_CODIGO');
    }

    public function oc()
    {
        return $this->hasMany(OCDModel::class, 'OC_CCODIGO', 'ACODIGO');
    }

    public function os()
    {
        return $this->hasMany(OCSDModel::class, 'OC_CODSERVICIO', 'ACODIGO');
    }

    // ============================================
    // CONFIGURACIÓN DE TIPOS DE ORDEN
    // ============================================

    private const ORDER_TYPES = [
        'OC' => [
            'header' => 'COMOVC',
            'detail' => 'COMOVD',
            'tiporequi' => 'RQ',
            'is_service' => false,
            'order_type' => 'OC',
        ],
        'OS' => [
            'header' => 'COMOVC_S',
            'detail' => 'COMOVD_S',
            'tiporequi' => 'RS',
            'is_service' => true,
            'order_type' => 'OS',
        ],
    ];

    // ============================================
    // MÉTODO PRINCIPAL (PÚBLICO)
    // ============================================

    /**
     * Obtiene órdenes con productos agrupados por proveedor
     * 
     * @param string $connectionName Nombre de la conexión (ej: 'sqlsrv_003')
     * @param string $start Fecha inicio (Y-m-d)
     * @param string $end Fecha fin (Y-m-d)
     * @param string $area Código de área
     * @param string|null $responsible Código del responsable (opcional)
     * @param string|null $type Tipo de orden: 'OC', 'OS', 'ALL' o null
     * @return Collection
     */
    public static function getOrdersByAreaWithProducts(
        string $connectionName,
        string $start,
        string $end,
        string $area,
        ?string $responsible = null,
        ?string $type = null
    ): Collection {
        $tables = self::getTablesByType($type);
        $results = collect();

        foreach ($tables as $config) {
            $query = self::buildOrderQuery(
                $connectionName,
                $config,
                $start,
                $end,
                $area,
                $responsible
            );

            $results = $results->concat($query->get());
        }

        return self::aggregateResults($results);
    }

    /**
     * Obtiene las líneas sin agrupar para exportar cada OC/OS con su detalle.
     */
    public static function getOrdersByAreaForExport(
        string $connectionName,
        string $start,
        string $end,
        string $area,
        ?string $responsible = null,
        ?string $type = null
    ): Collection {
        $results = collect();

        foreach (self::getTablesByType($type) as $config) {
            $header = $config['header'];
            $detail = $config['detail'];
            $isService = $config['is_service'];

            $query = DB::connection($connectionName)
                ->table("{$header} as H")
                ->join("{$detail} as D", 'H.OC_CNUMORD', '=', 'D.OC_CNUMORD')
                ->join('MAEPROV as P', 'H.OC_CCODPRO', '=', 'P.PRVCCODIGO')
                ->join('REQUISC as R', function ($join) use ($config) {
                    $join->on('H.OC_CNRODOCREF', '=', 'R.NROREQUI')
                        ->where('R.TIPOREQUI', '=', $config['tiporequi']);
                })
                ->leftJoin('AREA as A', 'R.AREA', '=', 'A.AREA_CODIGO')
                ->leftJoin('RESPONSABLECMP as Resp', 'H.OC_CSOLICT', '=', 'Resp.RESPONSABLE_CODIGO')
                ->leftJoin('TABAYU as Sol', 'H.OC_SOLICITA', '=', 'Sol.TCLAVE')
                ->select([
                    DB::raw("'{$config['order_type']}' as order_type"),
                    'P.PRVCCODIGO as supplier_ruc',
                    'P.PRVCNOMBRE as supplier_name',
                    'H.OC_CNUMORD as order_number',
                    'H.OC_DFECDOC as issue_date',
                    'H.OC_DFECENT as delivery_date',
                    'Sol.TDESCRI as requested_by',
                    'Resp.RESPONSABLE_NOMBRE as responsible',
                    'H.OC_COBSERV as observation',
                    'A.AREA_DESCRIPCION as area_name',
                    'H.OC_NVENTA as order_total',
                    'H.OC_NIGV as order_tax',
                    $isService ? DB::raw('D.OC_CODSERVICIO as product_code') : 'D.OC_CCODIGO as product_code',
                    $isService ? 'D.OC_CDESREF as product_name' : 'D.OC_CDESREF as product_name',
                    $isService ? DB::raw("'UND' as unit") : 'D.OC_CUNIDAD as unit',
                    $isService ? 'D.OC_CANT as quantity' : 'D.OC_NCANTID as quantity',
                    $isService ? 'D.OC_NPREUNI as unit_price' : 'D.OC_NPREUNI as unit_price',
                    'D.OC_NIGV as detail_tax',
                    $isService ? 'D.OC_NPRENET as line_total' : 'D.OC_NTOTNET as line_total',
                ])
                ->whereIn('H.OC_CSITORD', ['01', '03', '04'])
                ->whereBetween('H.OC_DFECDOC', [$start, $end])
                ->whereRaw('CAST(R.AREA AS INT) = ?', [(int) $area]);

            if ($responsible) {
                $query->where('H.OC_CSOLICT', $responsible);
            }

            $results = $results->concat($query
                ->orderBy('P.PRVCNOMBRE')
                ->orderBy('H.OC_DFECDOC')
                ->orderBy('H.OC_CNUMORD')
                ->get());
        }

        return $results;
    }

    // ============================================
    // MÉTODOS PRIVADOS (LÓGICA INTERNA)
    // ============================================

    /**
     * Determina qué tablas consultar según el tipo
     */
    private static function getTablesByType(?string $type): array
    {
        // Si es null o 'ALL', traer ambos tipos
        if (!$type || $type === 'ALL') {
            return array_values(self::ORDER_TYPES);
        }

        // Si el tipo existe en la configuración, devolverlo
        if (isset(self::ORDER_TYPES[$type])) {
            return [self::ORDER_TYPES[$type]];
        }

        // Fallback: traer ambos (por si acaso)
        return array_values(self::ORDER_TYPES);
    }

    /**
     * Construye la query para un tipo de orden específico
     */
    private static function buildOrderQuery(
        string $connectionName,
        array $config,
        string $start,
        string $end,
        string $area,
        ?string $responsible
    ) {
        $header = $config['header'];
        $detail = $config['detail'];

        $query = DB::connection($connectionName)
            ->table("{$header} as H")
            ->join("{$detail} as D", 'H.OC_CNUMORD', '=', 'D.OC_CNUMORD')
            ->join('MAEPROV as P', 'H.OC_CCODPRO', '=', 'P.PRVCCODIGO')
            ->join('REQUISC as R', 'H.OC_CNRODOCREF', '=', 'R.NROREQUI')
            ->select(self::getSelectFields($config['is_service'], $config['order_type']))
            ->whereIn('H.OC_CSITORD', ['01', '03', '04'])
            ->whereBetween('H.OC_DFECDOC', [$start, $end])
            ->whereRaw('CAST(R.AREA AS INT) = ?', [(int)$area])
            ->where('R.TIPOREQUI', $config['tiporequi']);

        if ($responsible) {
            $query->where('H.OC_CSOLICT', $responsible);
        }

        return $query;
    }

    /**
     * Define los campos SELECT según si es servicio o producto
     */
    private static function getSelectFields(bool $isService, string $orderType): array
    {
        $orderTotal = self::amountInSolesExpression('H', 'H.OC_NVENTA');

        $baseFields = [
            'P.PRVCCODIGO as proveedor_id',
            'P.PRVCNOMBRE as proveedor_name',
            'H.OC_CNUMORD as order_number',
            DB::raw("{$orderTotal} as order_total"),
            DB::raw("'{$orderType}' as order_type"),
        ];

        if ($isService) {
            $unitPrice = self::amountInSolesExpression('H', 'D.OC_NPREUNI');
            $total = self::amountInSolesExpression('H', 'D.OC_NPRENET');

            return array_merge($baseFields, [
                'D.OC_CODSERVICIO as product_id',
                'D.OC_CDESREF as product_name',
                DB::raw("'UND' as unidad"),
                'D.OC_CANT as cantidad',
                DB::raw("{$unitPrice} as precio_unitario"),
                DB::raw("{$total} as total"),
                'D.OC_NIGV as igv',
            ]);
        }

        $unitPrice = self::amountInSolesExpression('H', 'D.OC_NPREUNI');
        $total = self::amountInSolesExpression('H', 'D.OC_NTOTNET');

        return array_merge($baseFields, [
            'D.OC_CCODIGO as product_id',
            'D.OC_CDESREF as product_name',
            'D.OC_CUNIDAD as unidad',
            'D.OC_NCANTID as cantidad',
            DB::raw("{$unitPrice} as precio_unitario"),
            DB::raw("{$total} as total"),
            'D.OC_NIGV as igv',
        ]);
    }

    private static function amountInSolesExpression(string $headerAlias, string $amountField): string
    {
        return "CASE
            WHEN {$headerAlias}.OC_CCODMON = 'ME'
                THEN {$amountField} * COALESCE(NULLIF({$headerAlias}.OC_NTIPCAM, 0), 1)
            ELSE {$amountField}
        END";
    }

    /**
     * Agrupa y suma los resultados por proveedor y producto
     */
    private static function aggregateResults(Collection $results): Collection
    {
        $supplierTotals = self::getSupplierTotals($results);

        return $results
            ->groupBy('proveedor_id')
            ->flatMap(function ($providerItems) use ($supplierTotals) {
                $providerId = $providerItems->first()->proveedor_id;
                $providerName = $providerItems->first()->proveedor_name;
                $supplierTotal = $supplierTotals[$providerId] ?? $providerItems->sum('total');

                return $providerItems
                    ->groupBy('product_id')
                    ->map(function ($products) use ($providerId, $providerName, $supplierTotal) {
                        return self::buildProductSummary($products, $providerId, $providerName, $supplierTotal);
                    })
                    ->values();
            })
            ->values();
    }

    private static function getSupplierTotals(Collection $results): array
    {
        return $results
            ->groupBy('proveedor_id')
            ->map(fn ($items) => $items
                ->unique(fn ($item) => $item->order_type . '|' . $item->order_number)
                ->sum('order_total'))
            ->toArray();
    }

    /**
     * Construye el resumen de un producto agrupado
     */
    private static function buildProductSummary(
        Collection $products,
        string $providerId,
        string $providerName,
        float $supplierTotal
    ): array {
        $first = $products->first();
        $totalCantidad = $products->sum('cantidad');
        $totalMonto = $products->sum('total');
        $precioPromedio = $products->avg('precio_unitario');

        // ✅ Calcular IGV solo si el producto lo lleva
        $igvPromedio = $products->avg('igv');
        $precioConIgv = ($igvPromedio > 0)
            ? round($precioPromedio * 1.18, 2)
            : 0;

        return [
            'proveedor_id' => $providerId,
            'proveedor_name' => $providerName,
            'product_id' => $first->product_id,
            'product_name' => $first->product_name,
            'unidad' => $first->unidad,
            'cantidad' => round($totalCantidad, 2),
            'precio_unitario' => round($precioPromedio, 2),
            'precio_igv' => $precioConIgv,
            'total' => round($totalMonto, 2),
            'supplier_total' => round($supplierTotal, 2),
        ];
    }
}
