<?php

use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$company = '006';
$fullCodes = ['0000000000082', '0000000000083', '0000000000084'];
$shortCodes = ['82', '83', '84'];

function printSection(string $title, array $rows): void
{
    echo PHP_EOL . '==== ' . $title . ' ====' . PHP_EOL;
    echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

$starsoft = DB::connection('sqlsrv_' . $company)->select(
    "select OC_CNUMORD, OC_DFECDOC, OC_CCODPRO, OC_CRAZSOC, OC_CSITORD, OC_COBSERV, OC_CUSUARI
     from COMOVC
     where OC_CNUMORD in (?, ?, ?)",
    $fullCodes,
);

printSection('Starsoft COMOVC empresa 006', $starsoft);

$listado = DB::connection('bdwenco')->select(
    "select idAprobaciones, codigoEmpresa, nombreEmpresa, modulo, tipo, identificador, asunto,
            usuarioGeneracion, fechaGeneracion, estado, leido, usuarioAprobacion, fechaAprobacion
     from ListadoAprobaciones
     where codigoEmpresa = ?
       and tipo = 'OC'
       and identificador in (?, ?, ?)",
    array_merge([$company], $fullCodes),
);

printSection('BDWENCO ListadoAprobaciones empresa 006', $listado);

$areaMap = DB::connection('bdwenco')->select(
    "select *
     from vw_ListadoAprobacionesAreaMap
     where codigoEmpresa = ?
       and tipo = 'OC'
       and identificador in (?, ?, ?)",
    array_merge([$company], $fullCodes),
);

printSection('BDWENCO vw_ListadoAprobacionesAreaMap empresa 006', $areaMap);

$preApprovals = DB::connection('auth_db')->select(
    "select company_code, order_type, order_code, area_manager_name, area_manager_approved_at
     from order_area_pre_approvals
     where company_code = ?
       and order_type = 'OC'
       and order_code in (?, ?, ?, ?, ?, ?)",
    array_merge([$company], $shortCodes, $fullCodes),
);

printSection('auth_db order_area_pre_approvals empresa 006', $preApprovals);

