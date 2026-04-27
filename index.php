<?php
declare(strict_types=1);

date_default_timezone_set('America/Bogota');

function ensureDirectory(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0777, true);
    }
}

function readJsonFile(string $path, mixed $default): mixed
{
    if (!is_file($path)) {
        return $default;
    }

    $content = file_get_contents($path);
    if ($content === false || trim($content) === '') {
        return $default;
    }

    $decoded = json_decode($content, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
}

function writeJsonFile(string $path, mixed $data): bool
{
    $payload = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $payload !== false && file_put_contents($path, $payload, LOCK_EX) !== false;
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function sanitizeText(?string $value): string
{
    return trim(strip_tags((string) $value));
}

function sanitizeFloat(?string $value): float
{
    $normalized = str_replace([' ', ','], ['', '.'], trim((string) $value));
    return is_numeric($normalized) ? (float) $normalized : 0.0;
}

function sanitizeInt(?string $value): int
{
    return max(0, (int) round(sanitizeFloat($value)));
}

function clamp(float $value, float $min, float $max): float
{
    return max($min, min($value, $max));
}

function formatNumber(float $value, int $decimals = 2, string $suffix = ''): string
{
    return number_format($value, $decimals, ',', '.') . $suffix;
}

function formatDateTime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'Sin registro';
    }

    $timestamp = strtotime($value);
    return $timestamp ? date('d/m/Y H:i', $timestamp) : $value;
}

function calculateMean(array $values): float
{
    if ($values === []) {
        return 0.0;
    }

    return array_sum($values) / count($values);
}

function calculateStdDev(array $values): float
{
    $count = count($values);
    if ($count < 2) {
        return 0.0;
    }

    $mean = calculateMean($values);
    $sum = 0.0;
    foreach ($values as $value) {
        $sum += ($value - $mean) ** 2;
    }

    return sqrt($sum / ($count - 1));
}

function inverseNormalCdf(float $p): float
{
    $a = [-39.6968302866538, 220.946098424521, -275.928510446969, 138.357751867269, -30.6647980661472, 2.50662827745924];
    $b = [-54.4760987982241, 161.585836858041, -155.698979859887, 66.8013118877197, -13.2806815528857];
    $c = [-0.00778489400243029, -0.322396458041136, -2.40075827716184, -2.54973253934373, 4.37466414146497, 2.93816398269878];
    $d = [0.00778469570904146, 0.32246712907004, 2.445134137143, 3.75440866190742];

    $plow = 0.02425;
    $phigh = 1 - $plow;

    if ($p <= 0.0) {
        return -INF;
    }

    if ($p >= 1.0) {
        return INF;
    }

    if ($p < $plow) {
        $q = sqrt(-2 * log($p));
        return (((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) /
            ((((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q) + 1));
    }

    if ($p > $phigh) {
        $q = sqrt(-2 * log(1 - $p));
        return -(((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5]) /
            ((((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q) + 1));
    }

    $q = $p - 0.5;
    $r = $q * $q;
    return (((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q) /
        (((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r) + 1);
}

function estimateSigma(float $defectRate): float
{
    $yield = clamp(1 - $defectRate, 0.000001, 0.999999);
    return clamp(inverseNormalCdf($yield) + 1.5, 0.0, 6.0);
}

function buildControlStats(array $values): array
{
    $mean = calculateMean($values);
    $std = calculateStdDev($values);

    return [
        'mean' => $mean,
        'std' => $std,
        'ucl' => $mean + (3 * $std),
        'lcl' => max(0.0, $mean - (3 * $std)),
    ];
}

function calculateCp(float $lsl, float $usl, float $std): ?float
{
    if ($std <= 0) {
        return null;
    }

    return ($usl - $lsl) / (6 * $std);
}

function calculateCpk(float $lsl, float $usl, float $mean, float $std): ?float
{
    if ($std <= 0) {
        return null;
    }

    $upper = ($usl - $mean) / (3 * $std);
    $lower = ($mean - $lsl) / (3 * $std);
    return min($upper, $lower);
}

function statusMeta(string $status): array
{
    return match ($status) {
        'critical' => ['label' => 'Rojo', 'class' => 'danger'],
        'attention' => ['label' => 'Amarillo', 'class' => 'warning'],
        'good' => ['label' => 'Verde', 'class' => 'success'],
        default => ['label' => 'Sin datos', 'class' => 'neutral'],
    };
}

function classifySigma(float $sigma): string
{
    return match (true) {
        $sigma >= 5 => 'Muy robusto',
        $sigma >= 4 => 'Controlado',
        $sigma >= 3 => 'Aceptable',
        $sigma >= 2 => 'Vulnerable',
        default => 'Critico',
    };
}

function classifyFiveS(float $average): array
{
    return match (true) {
        $average >= 4.5 => ['status' => 'success', 'label' => 'Area ejemplar'],
        $average >= 3.5 => ['status' => 'warning', 'label' => 'Cumple con ajustes'],
        $average >= 2.5 => ['status' => 'warning', 'label' => 'Requiere accion'],
        default => ['status' => 'danger', 'label' => 'Zona critica'],
    };
}

function renderFiveSIcon(string $pillar): string
{
    return match ($pillar) {
        'seiri' => '<svg viewBox="0 0 64 64" aria-hidden="true"><path fill="currentColor" d="M32 8 12 18l20 10 20-10L32 8Zm-16 16v18l16 10V34L16 24Zm32 0L32 34v18l16-10V24Z"/><path fill="currentColor" d="M38 36h10v10H38z"/></svg>',
        'seiton' => '<svg viewBox="0 0 64 64" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><path d="M18 12v40"/><path d="M46 12v40"/><path d="M18 16h28"/><path d="M18 30h28"/><path d="M18 44h28"/><rect x="23" y="19" width="8" height="7" rx="1.5" fill="currentColor" stroke="none"/><rect x="34" y="19" width="8" height="7" rx="1.5" fill="currentColor" stroke="none"/><rect x="23" y="33" width="8" height="7" rx="1.5" fill="currentColor" stroke="none"/><rect x="34" y="33" width="8" height="7" rx="1.5" fill="currentColor" stroke="none"/></svg>',
        'seiso' => '<svg viewBox="0 0 64 64" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><path d="M40 12 29 39"/><path fill="currentColor" stroke="none" d="M21 35c5 3 9 8 11 16-8 0-16-4-22-12 4 0 8-1 11-4Z"/><path d="m45 22 3 6 6 3-6 3-3 6-3-6-6-3 6-3 3-6Z"/><path d="m52 13 1.8 3.8L58 18.6l-4.2 1.8L52 24.2l-1.8-3.8L46 18.6l4.2-1.8L52 13Z"/></svg>',
        'seiketsu' => '<svg viewBox="0 0 64 64" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><rect x="18" y="14" width="28" height="36" rx="4"/><path d="M26 14h12a4 4 0 0 1 4 4v2H22v-2a4 4 0 0 1 4-4Z"/><path d="m25 27 3 3 5-6"/><path d="M36 28h5"/><path d="m25 36 3 3 5-6"/><path d="M36 37h5"/><path d="m25 45 3 3 5-6"/><path d="M36 46h5"/></svg>',
        'shitsuke' => '<svg viewBox="0 0 64 64" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 29c0-7.5 5.4-13 12-13s12 5.5 12 13"/><path d="M22 28h20"/><path d="M17 49c2.5-8.5 9.8-13 15-13s12.5 4.5 15 13"/><circle cx="47" cy="44" r="9"/><path d="m43 44 2.8 2.8L51 41.5"/></svg>',
        default => '',
    };
}

function capabilityMeta(?float $value): array
{
    if ($value === null) {
        return [
            'status' => 'neutral',
            'label' => 'Sin base',
            'message' => 'Registra al menos dos lotes para medir capacidad.',
        ];
    }

    return match (true) {
        $value >= 1.67 => [
            'status' => 'success',
            'label' => 'Excelente',
            'message' => 'El proceso tiene un margen amplio frente a la especificacion.',
        ],
        $value >= 1.33 => [
            'status' => 'success',
            'label' => 'Capaz',
            'message' => 'El proceso puede sostener el estandar con buena estabilidad.',
        ],
        $value >= 1.00 => [
            'status' => 'warning',
            'label' => 'Marginal',
            'message' => 'Cumple, pero cualquier variacion adicional lo puede sacar del rango.',
        ],
        default => [
            'status' => 'danger',
            'label' => 'No capaz',
            'message' => 'La variabilidad actual compromete el cumplimiento del estandar.',
        ],
    };
}

function metricRangeMeta(?float $value, float $min, float $max, string $unit): array
{
    if ($value === null) {
        return [
            'status' => 'neutral',
            'label' => 'Sin lectura',
            'message' => 'Aun no hay una medicion guardada.',
        ];
    }

    $range = max(0.1, $max - $min);
    $warningBand = $range * 0.12;

    if ($value < $min) {
        return [
            'status' => 'danger',
            'label' => 'Bajo',
            'message' => 'El valor esta ' . formatNumber($min - $value, 2, ' ' . $unit) . ' por debajo del minimo.',
        ];
    }

    if ($value > $max) {
        return [
            'status' => 'danger',
            'label' => 'Alto',
            'message' => 'El valor esta ' . formatNumber($value - $max, 2, ' ' . $unit) . ' por encima del maximo.',
        ];
    }

    if (($value - $min) < $warningBand || ($max - $value) < $warningBand) {
        return [
            'status' => 'warning',
            'label' => 'Cerca del limite',
            'message' => 'Sigue dentro del estandar, pero esta muy cerca del borde permitido.',
        ];
    }

    return [
        'status' => 'success',
        'label' => 'Controlado',
        'message' => 'El valor cae dentro de la zona segura definida por el CTQ.',
    ];
}

function productionTargetMeta(?int $value, int $target): array
{
    if ($value === null) {
        return [
            'status' => 'neutral',
            'label' => 'Sin dato',
            'message' => 'Aun no hay lote para comparar contra la meta.',
        ];
    }

    $compliance = $target > 0 ? ($value / $target) * 100 : 0.0;

    return match (true) {
        $compliance >= 100 => [
            'status' => 'success',
            'label' => 'Meta lograda',
            'message' => 'El lote alcanzo o supero la meta de produccion.',
        ],
        $compliance >= 85 => [
            'status' => 'warning',
            'label' => 'Meta cercana',
            'message' => 'La meta esta cerca, pero aun faltan unidades por cerrar.',
        ],
        default => [
            'status' => 'danger',
            'label' => 'Meta baja',
            'message' => 'La produccion del lote esta lejos de la meta esperada.',
        ],
    };
}

function trendMeta(array $values, bool $smallerIsBetter = false): array
{
    if (count($values) < 2) {
        return [
            'status' => 'neutral',
            'label' => 'Sin tendencia',
            'message' => 'Aun no hay suficientes datos para detectar una direccion.',
        ];
    }

    $latest = (float) $values[count($values) - 1];
    $previous = array_slice($values, 0, -1);
    $reference = calculateMean($previous);

    if (abs($latest - $reference) < 0.0001) {
        return [
            'status' => 'neutral',
            'label' => 'Estable',
            'message' => 'El ultimo valor esta muy cerca del comportamiento promedio.',
        ];
    }

    $isImproving = $smallerIsBetter ? $latest < $reference : $latest > $reference;

    return [
        'status' => $isImproving ? 'success' : 'warning',
        'label' => $isImproving ? 'Mejorando' : 'Moviendose',
        'message' => $isImproving
            ? 'El ultimo dato muestra una direccion favorable frente al promedio previo.'
            : 'El ultimo dato se movio en una direccion que requiere seguimiento.',
    ];
}

function buildProcessScore(
    ?array $latestRecord,
    array $standards,
    float $overallSigma,
    float $overallDefectPercent,
    ?float $tempCpk,
    ?float $timeCpk,
    float $overallFiveS
): int {
    if ($latestRecord === null) {
        return 0;
    }

    $score = 0.0;
    $score += clamp(($overallSigma / 6) * 28, 0, 28);

    $defectScore = $standards['max_defect_rate'] > 0
        ? clamp((1 - ($overallDefectPercent / max($standards['max_defect_rate'] * 2.5, 0.1))) * 20, 0, 20)
        : 0;
    $score += $defectScore;

    $tempInside = ($latestRecord['temperature'] >= $standards['temp_min'] && $latestRecord['temperature'] <= $standards['temp_max']) ? 12 : 0;
    $timeInside = ($latestRecord['time_minutes'] >= $standards['time_min'] && $latestRecord['time_minutes'] <= $standards['time_max']) ? 12 : 0;
    $score += $tempInside + $timeInside;

    $score += $tempCpk !== null ? clamp(($tempCpk / 1.67) * 10, 0, 10) : 0;
    $score += $timeCpk !== null ? clamp(($timeCpk / 1.67) * 10, 0, 10) : 0;
    $score += $overallFiveS > 0 ? clamp(($overallFiveS / 5) * 8, 0, 8) : 0;

    return (int) round(clamp($score, 0, 100));
}

function complianceScore(?float $value, float $min, float $max): float
{
    if ($value === null) {
        return 0.0;
    }

    $range = max(0.1, $max - $min);
    $center = ($min + $max) / 2;
    $halfRange = max(0.1, $range / 2);

    if ($value >= $min && $value <= $max) {
        $distance = abs($value - $center);
        return clamp(100 - (($distance / $halfRange) * 25), 72, 100);
    }

    $outsideDistance = $value < $min ? ($min - $value) : ($value - $max);
    return clamp(72 - (($outsideDistance / $range) * 80), 0, 71.9);
}

function healthMeta(int $score): array
{
    return match (true) {
        $score >= 85 => [
            'status' => 'success',
            'label' => 'Salud excelente',
            'message' => 'El proceso luce fuerte, ordenado y con bajo riesgo operativo.',
        ],
        $score >= 70 => [
            'status' => 'success',
            'label' => 'Salud buena',
            'message' => 'El proceso esta bien encaminado y solo necesita vigilancia rutinaria.',
        ],
        $score >= 50 => [
            'status' => 'warning',
            'label' => 'Salud en vigilancia',
            'message' => 'Hay base operativa, pero aun se observan puntos que pueden fallar.',
        ],
        $score > 0 => [
            'status' => 'danger',
            'label' => 'Salud critica',
            'message' => 'La operacion necesita correcciones visibles para ganar estabilidad.',
        ],
        default => [
            'status' => 'neutral',
            'label' => 'Sin base de evaluacion',
            'message' => 'Primero registra lotes y evidencias para que el sistema pueda calificar.',
        ],
    };
}

function buildProcessAssessment(
    array $standards,
    ?array $latestRecord,
    array $tempStats,
    array $timeStats,
    float $overallDefectPercent,
    float $overallSigma
): array {
    if ($latestRecord === null) {
        return [
            'status' => 'neutral',
            'headline' => 'Todavia no hay capturas de proceso',
            'plain_message' => 'El sistema aun no puede interpretar la planta porque no hay ningun lote guardado.',
            'alerts' => ['Carga el primer lote para activar el analisis automatico.'],
            'actions' => ['Define tu CTQ y registra una corrida para ver alertas, sigma y capacidad.'],
            'requiredData' => ['Operario responsable', 'Temperatura', 'Tiempo', 'Cantidad producida', 'Defectos', 'Observacion del lote'],
        ];
    }

    $alerts = [];
    $actions = [];
    $requiredData = [];
    $severity = 0;

    $latestDefectPercent = $latestRecord['produced_qty'] > 0
        ? ($latestRecord['defective_qty'] / $latestRecord['produced_qty']) * 100
        : 0.0;

    if ($latestRecord['temperature'] < $standards['temp_min'] || $latestRecord['temperature'] > $standards['temp_max']) {
        $alerts[] = 'La temperatura del ultimo lote quedo fuera del estandar.';
        $actions[] = 'Revisa calentamiento o enfriamiento y confirma calibracion del sensor.';
        $requiredData[] = 'Registro del equipo termico';
        $requiredData[] = 'Confirmacion de calibracion del sensor';
        $severity = 2;
    }

    if ($latestRecord['time_minutes'] < $standards['time_min'] || $latestRecord['time_minutes'] > $standards['time_max']) {
        $alerts[] = 'El tiempo de proceso no coincide con el rango esperado.';
        $actions[] = 'Verifica arranque, retencion y descarga del lote.';
        $requiredData[] = 'Hora de inicio y fin del lote';
        $requiredData[] = 'Motivo del retraso o aceleracion';
        $severity = max($severity, 2);
    }

    if ($latestDefectPercent > $standards['max_defect_rate']) {
        $alerts[] = 'La tasa de defectos del ultimo lote supero el limite permitido.';
        $actions[] = 'Separa producto no conforme, revisa materia prima y adjunta evidencia.';
        $requiredData[] = 'Foto del defecto';
        $requiredData[] = 'Codigo de lote afectado';
        $severity = 2;
    }

    $tempTolerance = max(0.1, $standards['temp_max'] - $standards['temp_min']);
    $timeTolerance = max(0.1, $standards['time_max'] - $standards['time_min']);

    if ($tempStats['std'] > ($tempTolerance / 6) || $timeStats['std'] > ($timeTolerance / 6)) {
        $alerts[] = 'El proceso muestra alta variabilidad frente a los limites definidos.';
        $actions[] = 'Compara turnos, equipo y operario para detectar causas especiales.';
        $requiredData[] = 'Turno de trabajo';
        $requiredData[] = 'Equipo utilizado';
        $severity = max($severity, 1);
    }

    if ($tempStats['std'] > 0 && ($latestRecord['temperature'] > $tempStats['ucl'] || $latestRecord['temperature'] < $tempStats['lcl'])) {
        $alerts[] = 'La temperatura actual esta fuera de control estadistico.';
        $actions[] = 'Investiga si hubo una causa especial antes de continuar la produccion.';
        $requiredData[] = 'Evento o cambio anormal en el equipo';
        $severity = 2;
    }

    if ($timeStats['std'] > 0 && ($latestRecord['time_minutes'] > $timeStats['ucl'] || $latestRecord['time_minutes'] < $timeStats['lcl'])) {
        $alerts[] = 'El tiempo del lote se salio del comportamiento historico esperado.';
        $actions[] = 'Verifica paradas, abastecimiento y forma de operacion del turno.';
        $requiredData[] = 'Detalle de paradas o microparadas';
        $severity = max($severity, 2);
    }

    if ($overallSigma < 3) {
        $alerts[] = 'El nivel sigma global sigue bajo para operar con tranquilidad.';
        $actions[] = 'Prioriza reduccion de defectos y disciplina en estandares.';
        $severity = max($severity, 1);
    }

    if ($overallDefectPercent <= $standards['max_defect_rate'] && $severity === 0) {
        $alerts[] = 'El proceso esta dentro del estandar y con comportamiento estable.';
        $actions[] = 'Mantener monitoreo y seguir cargando evidencia 5S del area.';
        $requiredData[] = 'Foto de cierre del area';
    }

    $plainMessage = match (true) {
        $severity >= 2 => 'Hay senales claras de que el lote requiere correccion inmediata antes de seguir produciendo con normalidad.',
        $severity === 1 => 'El proceso no esta perdido, pero si necesita seguimiento cercano para evitar que se salga del control.',
        default => 'La operacion se ve estable y el sistema no detecta fallas graves en este momento.',
    };

    return [
        'status' => $severity >= 2 ? 'critical' : ($severity === 1 ? 'attention' : 'good'),
        'headline' => $severity >= 2 ? 'Proceso fuera de control' : ($severity === 1 ? 'Proceso con vigilancia' : 'Proceso estable'),
        'plain_message' => $plainMessage,
        'alerts' => array_values(array_unique($alerts)),
        'actions' => array_values(array_unique($actions)),
        'requiredData' => array_values(array_unique($requiredData)),
    ];
}

function recordInDateRange(?string $timestamp, ?string $dateFrom, ?string $dateTo): bool
{
    if ($timestamp === null || trim($timestamp) === '') {
        return false;
    }

    $time = strtotime($timestamp);
    if ($time === false) {
        return false;
    }

    if ($dateFrom !== null && $dateFrom !== '') {
        $fromTime = strtotime($dateFrom . ' 00:00:00');
        if ($fromTime !== false && $time < $fromTime) {
            return false;
        }
    }

    if ($dateTo !== null && $dateTo !== '') {
        $toTime = strtotime($dateTo . ' 23:59:59');
        if ($toTime !== false && $time > $toTime) {
            return false;
        }
    }

    return true;
}

function processRecordInsights(array $record, array $standards): array
{
    $issues = [];
    $temperature = (float) ($record['temperature'] ?? 0);
    $timeMinutes = (float) ($record['time_minutes'] ?? 0);
    $producedQty = (int) ($record['produced_qty'] ?? 0);
    $defectiveQty = (int) ($record['defective_qty'] ?? 0);
    $defectPercent = $producedQty > 0 ? ($defectiveQty / $producedQty) * 100 : 0.0;

    if ($temperature < (float) $standards['temp_min'] || $temperature > (float) $standards['temp_max']) {
        $issues[] = 'Temperatura fuera de rango';
    }

    if ($timeMinutes < (float) $standards['time_min'] || $timeMinutes > (float) $standards['time_max']) {
        $issues[] = 'Tiempo fuera de rango';
    }

    if ($defectPercent > (float) $standards['max_defect_rate']) {
        $issues[] = 'Defectos por encima del limite';
    }

    if ($producedQty > 0 && $producedQty < (int) $standards['target_output']) {
        $issues[] = 'Produccion por debajo de la meta';
    }

    $status = match (true) {
        count($issues) >= 2 => ['class' => 'danger', 'label' => 'Critico'],
        count($issues) === 1 => ['class' => 'warning', 'label' => 'Vigilar'],
        default => ['class' => 'success', 'label' => 'Estable'],
    };

    return [
        'issues' => $issues,
        'status' => $status['class'],
        'label' => $status['label'],
        'defect_percent' => $defectPercent,
    ];
}

function buildViewUrl(string $view, array $overrides = []): string
{
    $carryKeys = ['date_from', 'date_to', 'range_filter', 'shift_filter', 'operator_filter', 'area_filter'];
    $query = ['view' => $view];

    foreach ($carryKeys as $key) {
        $value = $_GET[$key] ?? null;
        if ($value !== null && $value !== '') {
            $query[$key] = (string) $value;
        }
    }

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($query[$key]);
            continue;
        }

        $query[$key] = (string) $value;
    }

    return '?' . http_build_query($query);
}

$allowedViews = ['home', 'standards', 'process', 'capability', 'five-s', 'history'];
$requestedView = sanitizeText($_GET['view'] ?? ($_POST['return_view'] ?? 'home'));
$currentView = in_array($requestedView, $allowedViews, true) ? $requestedView : 'home';

$baseDir = __DIR__;
$dataDir = $baseDir . DIRECTORY_SEPARATOR . 'data';
$uploadDir = $baseDir . DIRECTORY_SEPARATOR . 'uploads';
ensureDirectory($dataDir);
ensureDirectory($uploadDir);

$standardsFile = $dataDir . DIRECTORY_SEPARATOR . 'standards.json';
$processFile = $dataDir . DIRECTORY_SEPARATOR . 'process_records.json';
$fiveSFile = $dataDir . DIRECTORY_SEPARATOR . 'five_s_records.json';

$defaultStandards = [
    'process_name' => 'Pasteurizacion agroindustrial',
    'area' => 'Linea principal',
    'temp_min' => 72.0,
    'temp_max' => 75.0,
    'time_min' => 15.0,
    'time_max' => 20.0,
    'target_output' => 1000,
    'max_defect_rate' => 2.0,
    'notes' => 'Ajusta estos datos a tu proceso real.',
    'updated_at' => null,
];

$standards = readJsonFile($standardsFile, $defaultStandards);
$processRecords = readJsonFile($processFile, []);
$fiveSRecords = readJsonFile($fiveSFile, []);

if (!is_array($standards)) {
    $standards = $defaultStandards;
}

if (!is_array($processRecords)) {
    $processRecords = [];
}

if (!is_array($fiveSRecords)) {
    $fiveSRecords = [];
}

$messages = [];
$errors = [];
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'POST') {
    $action = sanitizeText($_POST['action'] ?? '');
    $postedView = sanitizeText($_POST['return_view'] ?? $currentView);
    if (in_array($postedView, $allowedViews, true)) {
        $currentView = $postedView;
    }

    if ($action === 'save_standards') {
        $newStandards = [
            'process_name' => sanitizeText($_POST['process_name'] ?? ''),
            'area' => sanitizeText($_POST['area'] ?? ''),
            'temp_min' => sanitizeFloat($_POST['temp_min'] ?? null),
            'temp_max' => sanitizeFloat($_POST['temp_max'] ?? null),
            'time_min' => sanitizeFloat($_POST['time_min'] ?? null),
            'time_max' => sanitizeFloat($_POST['time_max'] ?? null),
            'target_output' => sanitizeInt($_POST['target_output'] ?? null),
            'max_defect_rate' => sanitizeFloat($_POST['max_defect_rate'] ?? null),
            'notes' => sanitizeText($_POST['notes'] ?? ''),
            'updated_at' => date('c'),
        ];

        if ($newStandards['process_name'] === '') {
            $errors[] = 'Debes indicar el nombre del proceso.';
        }

        if ($newStandards['area'] === '') {
            $errors[] = 'Debes indicar el area de trabajo.';
        }

        if ($newStandards['temp_min'] >= $newStandards['temp_max']) {
            $errors[] = 'La temperatura minima debe ser menor que la maxima.';
        }

        if ($newStandards['time_min'] >= $newStandards['time_max']) {
            $errors[] = 'El tiempo minimo debe ser menor que el maximo.';
        }

        if ($newStandards['target_output'] <= 0) {
            $errors[] = 'La meta de produccion debe ser mayor que cero.';
        }

        if ($newStandards['max_defect_rate'] < 0) {
            $errors[] = 'El limite de defectos no puede ser negativo.';
        }

        if ($errors === []) {
            $standards = $newStandards;
            if (writeJsonFile($standardsFile, $standards)) {
                $messages[] = 'CTQ y estandares guardados correctamente.';
            } else {
                $errors[] = 'No se pudieron guardar los estandares.';
            }
        }
    }

    if ($action === 'save_process') {
        $record = [
            'timestamp' => date('c'),
            'area' => sanitizeText($_POST['process_area'] ?? (string) ($standards['area'] ?? '')),
            'operator' => sanitizeText($_POST['operator'] ?? ''),
            'shift' => sanitizeText($_POST['shift'] ?? ''),
            'temperature' => sanitizeFloat($_POST['temperature'] ?? null),
            'time_minutes' => sanitizeFloat($_POST['time_minutes'] ?? null),
            'produced_qty' => sanitizeInt($_POST['produced_qty'] ?? null),
            'defective_qty' => sanitizeInt($_POST['defective_qty'] ?? null),
            'observations' => sanitizeText($_POST['observations'] ?? ''),
        ];

        if ($record['operator'] === '') {
            $errors[] = 'Debes registrar el nombre del operario.';
        }

        if ($record['area'] === '') {
            $errors[] = 'Debes indicar el area del lote.';
        }

        if ($record['shift'] === '') {
            $errors[] = 'Debes indicar el turno del lote.';
        }

        if ($record['produced_qty'] <= 0) {
            $errors[] = 'La cantidad producida debe ser mayor que cero.';
        }

        if ($record['defective_qty'] > $record['produced_qty']) {
            $errors[] = 'Los defectos no pueden ser mayores que la cantidad producida.';
        }

        if ($record['temperature'] <= 0) {
            $errors[] = 'La temperatura debe ser mayor que cero.';
        }

        if ($record['time_minutes'] <= 0) {
            $errors[] = 'El tiempo del proceso debe ser mayor que cero.';
        }

        if ($errors === []) {
            $processRecords[] = $record;
            if (writeJsonFile($processFile, $processRecords)) {
                $messages[] = 'Lote guardado. El tablero ya actualizo sigma, alertas y capacidad.';
            } else {
                array_pop($processRecords);
                $errors[] = 'No se pudo guardar el lote.';
            }
        }
    }

    if ($action === 'save_fives') {
        $scores = [
            'seiri' => clamp(sanitizeFloat($_POST['seiri'] ?? null), 1, 5),
            'seiton' => clamp(sanitizeFloat($_POST['seiton'] ?? null), 1, 5),
            'seiso' => clamp(sanitizeFloat($_POST['seiso'] ?? null), 1, 5),
            'seiketsu' => clamp(sanitizeFloat($_POST['seiketsu'] ?? null), 1, 5),
            'shitsuke' => clamp(sanitizeFloat($_POST['shitsuke'] ?? null), 1, 5),
        ];

        $fiveSRecord = [
            'timestamp' => date('c'),
            'area' => sanitizeText($_POST['five_s_area'] ?? ''),
            'responsible' => sanitizeText($_POST['responsible'] ?? ''),
            'notes' => sanitizeText($_POST['five_s_notes'] ?? ''),
            'scores' => $scores,
            'average' => array_sum($scores) / count($scores),
            'photos' => [],
        ];
        $savedPhotoPaths = [];

        if ($fiveSRecord['area'] === '') {
            $errors[] = 'Debes indicar el area evaluada en 5S.';
        }

        if ($fiveSRecord['responsible'] === '') {
            $errors[] = 'Debes indicar el responsable de la evaluacion 5S.';
        }

        $uploaded = $_FILES['five_s_photos'] ?? null;
        if ($uploaded && is_array($uploaded['name'])) {
            $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;
            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
            ];

            foreach ($uploaded['name'] as $index => $name) {
                if (($uploaded['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                    continue;
                }

                if (($uploaded['error'][$index] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                    $errors[] = 'Una de las fotos no pudo cargarse.';
                    continue;
                }

                $tmpName = $uploaded['tmp_name'][$index] ?? '';
                $size = (int) ($uploaded['size'][$index] ?? 0);
                $mime = $finfo && $tmpName !== '' ? finfo_file($finfo, $tmpName) : null;

                if ($size > 5 * 1024 * 1024) {
                    $errors[] = 'Cada foto debe pesar menos de 5 MB.';
                    continue;
                }

                if ($mime === false || !isset($allowedTypes[$mime])) {
                    $errors[] = 'Solo se permiten imagenes JPG, PNG, WEBP o GIF.';
                    continue;
                }

                try {
                    $random = bin2hex(random_bytes(4));
                } catch (Throwable) {
                    $random = (string) mt_rand(1000, 9999);
                }

                $targetName = date('Ymd_His') . '_' . $random . '.' . $allowedTypes[$mime];
                $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $targetName;
                if (move_uploaded_file($tmpName, $targetPath)) {
                    $fiveSRecord['photos'][] = 'uploads/' . $targetName;
                    $savedPhotoPaths[] = $targetPath;
                } else {
                    $errors[] = 'No se pudo mover una de las fotos al almacenamiento local.';
                }
            }

            if ($finfo) {
                finfo_close($finfo);
            }
        }

        if ($errors !== []) {
            foreach ($savedPhotoPaths as $savedPhotoPath) {
                if (is_file($savedPhotoPath)) {
                    unlink($savedPhotoPath);
                }
            }
        }

        if ($errors === []) {
            $fiveSRecords[] = $fiveSRecord;
            if (writeJsonFile($fiveSFile, $fiveSRecords)) {
                $messages[] = 'Evaluacion 5S guardada con evidencia visual.';
            } else {
                foreach ($savedPhotoPaths as $savedPhotoPath) {
                    if (is_file($savedPhotoPath)) {
                        unlink($savedPhotoPath);
                    }
                }
                $errors[] = 'No se pudo guardar la evaluacion 5S.';
            }
        }
    }
}

usort($processRecords, static fn(array $a, array $b): int => strcmp($a['timestamp'] ?? '', $b['timestamp'] ?? ''));
usort($fiveSRecords, static fn(array $a, array $b): int => strcmp($a['timestamp'] ?? '', $b['timestamp'] ?? ''));

$allProcessRecords = $processRecords;
$allFiveSRecords = $fiveSRecords;

$rangeFilter = sanitizeText($_GET['range_filter'] ?? '30d');
$allowedRanges = ['7d', '30d', '90d', 'all'];
if (!in_array($rangeFilter, $allowedRanges, true)) {
    $rangeFilter = '30d';
}

$dateFrom = sanitizeText($_GET['date_from'] ?? '');
$dateTo = sanitizeText($_GET['date_to'] ?? '');
$shiftFilter = sanitizeText($_GET['shift_filter'] ?? '');
$operatorFilter = sanitizeText($_GET['operator_filter'] ?? '');
$areaFilter = sanitizeText($_GET['area_filter'] ?? '');

if ($dateFrom === '' && $dateTo === '' && $rangeFilter !== 'all') {
    $daysByRange = ['7d' => 7, '30d' => 30, '90d' => 90];
    $days = $daysByRange[$rangeFilter] ?? 30;
    $dateFrom = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $dateTo = date('Y-m-d');
}

$operatorOptions = array_values(array_unique(array_filter(array_merge(
    array_map(static fn(array $record): string => trim((string) ($record['operator'] ?? '')), $allProcessRecords),
    array_map(static fn(array $record): string => trim((string) ($record['responsible'] ?? '')), $allFiveSRecords)
))));
sort($operatorOptions);

$shiftOptions = array_values(array_unique(array_filter(array_map(
    static fn(array $record): string => trim((string) ($record['shift'] ?? '')),
    $allProcessRecords
))));
sort($shiftOptions);

$areaOptions = array_values(array_unique(array_filter(array_merge(
    array_map(static fn(array $record): string => trim((string) ($record['area'] ?? '')), $allProcessRecords),
    array_map(static fn(array $record): string => trim((string) ($record['area'] ?? '')), $allFiveSRecords),
    [trim((string) ($standards['area'] ?? ''))]
))));
sort($areaOptions);

$processRecords = array_values(array_filter($allProcessRecords, static function (array $record) use ($dateFrom, $dateTo, $shiftFilter, $operatorFilter, $areaFilter, $standards): bool {
    if (!recordInDateRange((string) ($record['timestamp'] ?? ''), $dateFrom !== '' ? $dateFrom : null, $dateTo !== '' ? $dateTo : null)) {
        return false;
    }

    if ($shiftFilter !== '' && strcasecmp((string) ($record['shift'] ?? ''), $shiftFilter) !== 0) {
        return false;
    }

    if ($operatorFilter !== '' && stripos((string) ($record['operator'] ?? ''), $operatorFilter) === false) {
        return false;
    }

    $recordArea = trim((string) ($record['area'] ?? ($standards['area'] ?? '')));
    if ($areaFilter !== '' && strcasecmp($recordArea, $areaFilter) !== 0) {
        return false;
    }

    return true;
}));

$fiveSRecords = array_values(array_filter($allFiveSRecords, static function (array $record) use ($dateFrom, $dateTo, $operatorFilter, $areaFilter): bool {
    if (!recordInDateRange((string) ($record['timestamp'] ?? ''), $dateFrom !== '' ? $dateFrom : null, $dateTo !== '' ? $dateTo : null)) {
        return false;
    }

    if ($operatorFilter !== '' && stripos((string) ($record['responsible'] ?? ''), $operatorFilter) === false) {
        return false;
    }

    if ($areaFilter !== '' && strcasecmp((string) ($record['area'] ?? ''), $areaFilter) !== 0) {
        return false;
    }

    return true;
}));

$activeFilterBadges = [];
if ($dateFrom !== '' || $dateTo !== '') {
    $activeFilterBadges[] = 'Fecha: ' . ($dateFrom !== '' ? $dateFrom : 'inicio') . ' a ' . ($dateTo !== '' ? $dateTo : 'hoy');
}
if ($shiftFilter !== '') {
    $activeFilterBadges[] = 'Turno: ' . $shiftFilter;
}
if ($operatorFilter !== '') {
    $activeFilterBadges[] = 'Operario: ' . $operatorFilter;
}
if ($areaFilter !== '') {
    $activeFilterBadges[] = 'Area: ' . $areaFilter;
}
$hasCustomFilters = $shiftFilter !== '' || $operatorFilter !== '' || $areaFilter !== '' || $rangeFilter !== '30d' || isset($_GET['date_from']) || isset($_GET['date_to']);

$latestRecord = $processRecords !== [] ? end($processRecords) : null;
if ($latestRecord === false) {
    $latestRecord = null;
}

$latestFiveS = $fiveSRecords !== [] ? end($fiveSRecords) : null;
if ($latestFiveS === false) {
    $latestFiveS = null;
}

$temperatureValues = array_values(array_map(static fn(array $record): float => (float) ($record['temperature'] ?? 0), $processRecords));
$timeValues = array_values(array_map(static fn(array $record): float => (float) ($record['time_minutes'] ?? 0), $processRecords));
$defectPercentages = array_values(array_map(
    static fn(array $record): float => ($record['produced_qty'] ?? 0) > 0
        ? ((float) $record['defective_qty'] / (float) $record['produced_qty']) * 100
        : 0.0,
    $processRecords
));

$totalProduced = array_sum(array_map(static fn(array $record): int => (int) ($record['produced_qty'] ?? 0), $processRecords));
$totalDefects = array_sum(array_map(static fn(array $record): int => (int) ($record['defective_qty'] ?? 0), $processRecords));
$overallDefectRate = $totalProduced > 0 ? ($totalDefects / $totalProduced) : 0.0;
$overallDefectPercent = $overallDefectRate * 100;
$overallSigma = $totalProduced > 0 ? estimateSigma($overallDefectRate) : 0.0;
$overallYield = $totalProduced > 0 ? 100 - $overallDefectPercent : 0.0;

$tempStats = buildControlStats($temperatureValues);
$timeStats = buildControlStats($timeValues);

$tempCp = calculateCp((float) $standards['temp_min'], (float) $standards['temp_max'], $tempStats['std']);
$tempCpk = calculateCpk((float) $standards['temp_min'], (float) $standards['temp_max'], $tempStats['mean'], $tempStats['std']);
$timeCp = calculateCp((float) $standards['time_min'], (float) $standards['time_max'], $timeStats['std']);
$timeCpk = calculateCpk((float) $standards['time_min'], (float) $standards['time_max'], $timeStats['mean'], $timeStats['std']);

$assessment = buildProcessAssessment($standards, $latestRecord, $tempStats, $timeStats, $overallDefectPercent, $overallSigma);
$status = statusMeta($assessment['status']);

$latestDefectPercent = $latestRecord && $latestRecord['produced_qty'] > 0
    ? (($latestRecord['defective_qty'] / $latestRecord['produced_qty']) * 100)
    : 0.0;
$latestProduced = $latestRecord ? (int) $latestRecord['produced_qty'] : null;
$latestTemperature = $latestRecord ? (float) $latestRecord['temperature'] : null;
$latestTime = $latestRecord ? (float) $latestRecord['time_minutes'] : null;

$targetCompliance = $latestRecord && $standards['target_output'] > 0
    ? min(100, ($latestRecord['produced_qty'] / $standards['target_output']) * 100)
    : 0.0;
$productionGap = $latestRecord
    ? max(0, (int) $standards['target_output'] - (int) $latestRecord['produced_qty'])
    : 0;

$fiveSAverages = array_values(array_map(static fn(array $record): float => (float) ($record['average'] ?? 0), $fiveSRecords));
$overallFiveS = $fiveSAverages !== [] ? calculateMean($fiveSAverages) : 0.0;
$latestFiveSStatus = $latestFiveS ? classifyFiveS((float) $latestFiveS['average']) : ['status' => 'neutral', 'label' => 'Sin evaluacion'];
$overallFiveSStatus = $overallFiveS > 0 ? classifyFiveS($overallFiveS) : ['status' => 'neutral', 'label' => 'Sin historial'];

$tempCapability = capabilityMeta($tempCpk);
$timeCapability = capabilityMeta($timeCpk);

$latestTempMeta = metricRangeMeta($latestTemperature, (float) $standards['temp_min'], (float) $standards['temp_max'], 'C');
$latestTimeMeta = metricRangeMeta($latestTime, (float) $standards['time_min'], (float) $standards['time_max'], 'min');
$productionMeta = productionTargetMeta($latestProduced, (int) $standards['target_output']);

$recentProcessRecords = array_slice(array_reverse($processRecords), 0, 10);
$recentFiveSRecords = array_slice(array_reverse($fiveSRecords), 0, 6);

$chartRecords = array_slice($processRecords, -10);
$chartLabels = array_map(static fn(array $record): string => date('d/m', strtotime((string) ($record['timestamp'] ?? 'now'))), $chartRecords);
$chartTemps = array_map(static fn(array $record): float => (float) ($record['temperature'] ?? 0), $chartRecords);
$chartTimes = array_map(static fn(array $record): float => (float) ($record['time_minutes'] ?? 0), $chartRecords);
$chartDefects = array_map(static fn(array $record): float => ($record['produced_qty'] ?? 0) > 0 ? ((float) $record['defective_qty'] / (float) $record['produced_qty']) * 100 : 0.0, $chartRecords);
$chartTempCompliance = array_map(
    static fn(array $record): float => complianceScore(
        isset($record['temperature']) ? (float) $record['temperature'] : null,
        (float) $standards['temp_min'],
        (float) $standards['temp_max']
    ),
    $chartRecords
);
$chartTimeCompliance = array_map(
    static fn(array $record): float => complianceScore(
        isset($record['time_minutes']) ? (float) $record['time_minutes'] : null,
        (float) $standards['time_min'],
        (float) $standards['time_max']
    ),
    $chartRecords
);

$hasProcessData = $totalProduced > 0;
$sampleSize = count($processRecords);
$lastObservation = $latestRecord['observations'] ?? 'Sin observaciones.';
$lastFiveSNotes = $latestFiveS['notes'] ?? 'Sin observaciones.';
$latestOperator = $latestRecord['operator'] ?? 'Sin operario';
$latestShift = $latestRecord['shift'] ?? 'Sin turno';

$processScore = buildProcessScore($latestRecord, $standards, $overallSigma, $overallDefectPercent, $tempCpk, $timeCpk, $overallFiveS);
$scoreMeta = healthMeta($processScore);

$tempTrend = trendMeta($temperatureValues, false);
$timeTrend = trendMeta($timeValues, false);
$defectTrend = trendMeta($defectPercentages, true);
$fiveSTrend = trendMeta($fiveSAverages, false);

$latestFiveSScores = $latestFiveS['scores'] ?? [
    'seiri' => 0,
    'seiton' => 0,
    'seiso' => 0,
    'seiketsu' => 0,
    'shitsuke' => 0,
];

$latestFiveSSeries = [
    (float) ($latestFiveSScores['seiri'] ?? 0),
    (float) ($latestFiveSScores['seiton'] ?? 0),
    (float) ($latestFiveSScores['seiso'] ?? 0),
    (float) ($latestFiveSScores['seiketsu'] ?? 0),
    (float) ($latestFiveSScores['shitsuke'] ?? 0),
];

$fiveSPillars = [
    [
        'key' => 'seiri',
        'step' => '1',
        'title' => 'Clasificar',
        'description' => 'Separa lo necesario de lo que estorba para liberar espacio y evitar errores.',
        'color' => '#0b3e91',
        'soft' => 'rgba(11, 62, 145, 0.10)',
        'score' => (float) ($latestFiveSScores['seiri'] ?? 0),
    ],
    [
        'key' => 'seiton',
        'step' => '2',
        'title' => 'Ordenar',
        'description' => 'Ubica herramientas y materiales en un lugar fijo para trabajar sin perdida de tiempo.',
        'color' => '#1e73be',
        'soft' => 'rgba(30, 115, 190, 0.10)',
        'score' => (float) ($latestFiveSScores['seiton'] ?? 0),
    ],
    [
        'key' => 'seiso',
        'step' => '3',
        'title' => 'Limpiar',
        'description' => 'Mantiene el area lista, segura y visualmente controlada al cierre de cada turno.',
        'color' => '#3f8a2a',
        'soft' => 'rgba(63, 138, 42, 0.10)',
        'score' => (float) ($latestFiveSScores['seiso'] ?? 0),
    ],
    [
        'key' => 'seiketsu',
        'step' => '4',
        'title' => 'Estandarizar',
        'description' => 'Convierte las buenas practicas en reglas simples para repetirlas sin confusion.',
        'color' => '#e2ad05',
        'soft' => 'rgba(226, 173, 5, 0.11)',
        'score' => (float) ($latestFiveSScores['seiketsu'] ?? 0),
    ],
    [
        'key' => 'shitsuke',
        'step' => '5',
        'title' => 'Disciplina',
        'description' => 'Sostiene el orden con habitos, seguimiento y compromiso continuo del personal.',
        'color' => '#1b8d95',
        'soft' => 'rgba(27, 141, 149, 0.11)',
        'score' => (float) ($latestFiveSScores['shitsuke'] ?? 0),
    ],
];

$criticalLotsCount = 0;
$issueCounts = [];
$worstProcessLot = null;
$worstProcessLotMeta = null;
$shiftBreakdown = [];

foreach ($processRecords as $record) {
    $insight = processRecordInsights($record, $standards);
    $shiftName = trim((string) ($record['shift'] ?? '')) ?: 'Sin turno';

    if (!isset($shiftBreakdown[$shiftName])) {
        $shiftBreakdown[$shiftName] = [
            'shift' => $shiftName,
            'count' => 0,
            'produced' => 0,
            'defects' => 0,
        ];
    }

    $shiftBreakdown[$shiftName]['count']++;
    $shiftBreakdown[$shiftName]['produced'] += (int) ($record['produced_qty'] ?? 0);
    $shiftBreakdown[$shiftName]['defects'] += (int) ($record['defective_qty'] ?? 0);

    if ($insight['issues'] !== []) {
        $criticalLotsCount++;
        foreach ($insight['issues'] as $issue) {
            $issueCounts[$issue] = ($issueCounts[$issue] ?? 0) + 1;
        }
    }

    if ($worstProcessLot === null) {
        $worstProcessLot = $record;
        $worstProcessLotMeta = $insight;
        continue;
    }

    $currentIssueCount = count($insight['issues']);
    $worstIssueCount = count($worstProcessLotMeta['issues'] ?? []);
    if (
        $currentIssueCount > $worstIssueCount ||
        ($currentIssueCount === $worstIssueCount && $insight['defect_percent'] > (float) ($worstProcessLotMeta['defect_percent'] ?? 0))
    ) {
        $worstProcessLot = $record;
        $worstProcessLotMeta = $insight;
    }
}

$shiftBreakdown = array_values($shiftBreakdown);
usort($shiftBreakdown, static function (array $a, array $b): int {
    return [$b['count'], $b['produced']] <=> [$a['count'], $a['produced']];
});

arsort($issueCounts);
$topIssueName = $issueCounts !== [] ? (string) array_key_first($issueCounts) : 'Sin alerta dominante';
$topIssueCount = $issueCounts !== [] ? (int) current($issueCounts) : 0;
$leadShift = $shiftBreakdown[0] ?? null;
$leadShiftLabel = $leadShift['shift'] ?? 'Sin datos';
$leadShiftCount = $leadShift['count'] ?? 0;
$leadShiftProduced = $leadShift['produced'] ?? 0;
$leadShiftDefectPercent = ($leadShift !== null && (int) $leadShift['produced'] > 0)
    ? ((int) $leadShift['defects'] / (int) $leadShift['produced']) * 100
    : 0.0;

$executiveCards = [
    [
        'title' => 'Lotes filtrados',
        'value' => (string) count($processRecords),
        'text' => $processRecords !== [] ? 'Registros que cumplen con los filtros activos.' : 'No hay lotes para el filtro actual.',
    ],
    [
        'title' => 'Produccion filtrada',
        'value' => $totalProduced > 0 ? number_format($totalProduced, 0, ',', '.') . ' u' : 'Sin dato',
        'text' => 'Suma total de unidades producidas en la vista actual.',
    ],
    [
        'title' => 'Lotes criticos',
        'value' => (string) $criticalLotsCount,
        'text' => $criticalLotsCount > 0 ? 'Lotes con desvio visible en CTQ, defectos o meta.' : 'Sin lotes criticos en el filtro actual.',
    ],
    [
        'title' => 'Turno dominante',
        'value' => $leadShiftLabel,
        'text' => $leadShiftCount > 0 ? $leadShiftCount . ' lotes y ' . number_format($leadShiftProduced, 0, ',', '.') . ' u en esta seleccion.' : 'Aun no hay turnos dentro del filtro.',
    ],
];

$onboardingSteps = [
    ['step' => '01', 'title' => 'Define tu CTQ', 'text' => 'Configura limites de temperatura, tiempo, defectos y meta de produccion.'],
    ['step' => '02', 'title' => 'Captura el lote', 'text' => 'El operario solo llena un formulario simple con pocos campos.'],
    ['step' => '03', 'title' => 'Lee los colores', 'text' => 'El sistema traduce los datos a semaforos, alertas y acciones directas.'],
    ['step' => '04', 'title' => 'Cierra con 5S', 'text' => 'Sube fotos y deja evidencia visual del orden y limpieza del area.'],
];

$moduleCards = [
    [
        'view' => 'standards',
        'title' => 'Definir CTQ',
        'description' => 'Configura limites de temperatura, tiempo, meta y defectos aceptables por proceso.',
        'accent' => 'teal',
        'status' => $standards['updated_at'] ? 'Listo' : 'Pendiente',
    ],
    [
        'view' => 'process',
        'title' => 'Capturar lote',
        'description' => 'El operario registra datos simples y el sistema responde con alertas y lectura inmediata.',
        'accent' => 'blue',
        'status' => $sampleSize > 0 ? 'Con datos' : 'Sin datos',
    ],
    [
        'view' => 'capability',
        'title' => 'Estudio de capacidad',
        'description' => 'Revisa sigma, Cp, Cpk, limites de control y tendencia de defectos sin usar Minitab.',
        'accent' => 'gold',
        'status' => $sampleSize > 1 ? 'Activo' : 'Esperando muestra',
    ],
    [
        'view' => 'five-s',
        'title' => '5S visual',
        'description' => 'Evalua orden y limpieza con checklist, historial y fotos del area de trabajo.',
        'accent' => 'slate',
        'status' => $latestFiveS ? 'Con evidencias' : 'Sin evaluacion',
    ],
];

$ctqGuide = [
    ['title' => 'Temperatura', 'text' => 'Define el rango donde el producto sale bien y con seguridad.'],
    ['title' => 'Tiempo', 'text' => 'Marca el tiempo esperado de proceso para evitar subproceso o sobreproceso.'],
    ['title' => 'Defectos', 'text' => 'Es el limite maximo de producto no conforme que la planta quiere aceptar.'],
    ['title' => 'Meta', 'text' => 'Es la cantidad que el lote deberia producir para cumplir con la jornada.'],
];

$coachCards = [
    [
        'title' => 'Lo que esta pasando',
        'text' => $assessment['plain_message'],
    ],
    [
        'title' => 'Primera accion recomendada',
        'text' => $assessment['actions'][0] ?? 'Aun no hay accion sugerida hasta registrar el primer lote.',
    ],
    [
        'title' => 'Dato adicional que conviene pedir',
        'text' => $assessment['requiredData'][0] ?? 'Aun no hay requerimientos extra hasta que el sistema detecte variaciones.',
    ],
];

$glossary = [
    ['term' => 'Sigma', 'meaning' => 'Resume que tan bien esta funcionando el proceso. Mientras mas alto, menos defectos.'],
    ['term' => 'Cp', 'meaning' => 'Mide si el rango del proceso cabe dentro del rango permitido por el CTQ.'],
    ['term' => 'Cpk', 'meaning' => 'Mide si el proceso cabe y ademas esta centrado dentro del rango esperado.'],
    ['term' => 'LCL / UCL', 'meaning' => 'Son limites de control estadistico que avisan cuando aparece una causa rara.'],
    ['term' => '5S', 'meaning' => 'Metodo para ordenar, limpiar, estandarizar y mantener disciplina en el area.'],
];

$comparisonScores = [
    $latestTemperature !== null ? complianceScore($latestTemperature, (float) $standards['temp_min'], (float) $standards['temp_max']) : 0.0,
    $latestTime !== null ? complianceScore($latestTime, (float) $standards['time_min'], (float) $standards['time_max']) : 0.0,
    $latestProduced !== null && (int) $standards['target_output'] > 0 ? clamp(((float) $latestProduced / (float) $standards['target_output']) * 100, 0, 125) : 0.0,
    $latestDefectPercent > 0 || $latestRecord ? clamp(100 - (($latestDefectPercent / max((float) $standards['max_defect_rate'], 0.1)) * 40), 0, 120) : 0.0,
];

$pageTitles = [
    'home' => ['title' => 'Inicio', 'description' => 'Panel general del sistema Sigma Facil Agro.'],
    'standards' => ['title' => 'Definir CTQ', 'description' => 'Configura los estandares clave del proceso agroindustrial.'],
    'process' => ['title' => 'Captura de lote', 'description' => 'Registro simple para operario con analisis automatico.'],
    'capability' => ['title' => 'Estudio de capacidad', 'description' => 'Visualizacion y capacidad del proceso en lenguaje simple.'],
    'five-s' => ['title' => '5S visual', 'description' => 'Checklist, evidencia fotografica e historial del area.'],
    'history' => ['title' => 'Historial', 'description' => 'Consulta los lotes y evaluaciones guardadas.'],
];

$pageMeta = $pageTitles[$currentView];
$brandName = 'InduSigma 5S';
$brandTagline = 'Control visual para agroindustria';
$brandSupportLine = 'Six Sigma + 5S para operarios y supervisores';
$logoFile = __DIR__ . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'logo.png';
$brandLogoUrl = is_file($logoFile) ? 'img/logo.png?v=' . rawurlencode((string) filemtime($logoFile)) : null;
$exportType = sanitizeText($_GET['export'] ?? '');

if (in_array($exportType, ['excel', 'pdf'], true)) {
    $reportTitle = $brandName . ' - Reporte ejecutivo';
    $filterText = $activeFilterBadges !== [] ? implode(' | ', $activeFilterBadges) : 'Vista base de los ultimos 30 dias';
    $reportGeneratedAt = formatDateTime(date('c'));
    $reportFileBase = 'reporte_indusigma_' . date('Ymd_His');

    if ($exportType === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $reportFileBase . '.xls"');
        echo "<html><head><meta charset=\"UTF-8\"><style>
            body{font-family:Segoe UI,Arial,sans-serif;font-size:12px;color:#122033}
            h1,h2{color:#0b3e91}
            table{width:100%;border-collapse:collapse;margin-top:14px}
            th,td{border:1px solid #cfd9e6;padding:8px;text-align:left;vertical-align:top}
            th{background:#edf4fb;color:#17324f}
            .summary td{width:25%}
            .small{color:#5f7288}
        </style></head><body>";
        echo '<h1>' . e($reportTitle) . '</h1>';
        echo '<p class="small">Generado: ' . e($reportGeneratedAt) . '</p>';
        echo '<p class="small">Filtros: ' . e($filterText) . '</p>';
        echo '<table class="summary"><tr>';
        foreach ($executiveCards as $card) {
            echo '<td><strong>' . e($card['title']) . '</strong><br>' . e($card['value']) . '<br><span class="small">' . e($card['text']) . '</span></td>';
        }
        echo '</tr></table>';
        echo '<table><tr><th>Indicador</th><th>Valor</th><th>Lectura</th></tr>';
        echo '<tr><td>Salud del proceso</td><td>' . e((string) $processScore) . '/100</td><td>' . e($scoreMeta['message']) . '</td></tr>';
        echo '<tr><td>Nivel sigma</td><td>' . e($hasProcessData ? formatNumber($overallSigma, 2) : 'Sin dato') . '</td><td>' . e($hasProcessData ? classifySigma($overallSigma) : 'Sin datos suficientes') . '</td></tr>';
        echo '<tr><td>Defectos globales</td><td>' . e($hasProcessData ? formatNumber($overallDefectPercent, 2, '%') : 'Sin dato') . '</td><td>' . e($assessment['headline']) . '</td></tr>';
        echo '<tr><td>5S promedio</td><td>' . e($overallFiveS > 0 ? formatNumber($overallFiveS, 2) . '/5' : 'Sin dato') . '</td><td>' . e($overallFiveSStatus['label']) . '</td></tr>';
        echo '</table>';

        echo '<h2>Lotes filtrados</h2>';
        echo '<table><tr><th>Fecha</th><th>Area</th><th>Operario</th><th>Turno</th><th>Temp</th><th>Tiempo</th><th>Produccion</th><th>Defectos</th><th>Estado</th><th>Observaciones</th></tr>';
        if ($processRecords === []) {
            echo '<tr><td colspan="10">No hay lotes en esta seleccion.</td></tr>';
        } else {
            foreach ($processRecords as $record) {
                $meta = processRecordInsights($record, $standards);
                echo '<tr>';
                echo '<td>' . e(formatDateTime((string) ($record['timestamp'] ?? ''))) . '</td>';
                echo '<td>' . e((string) ($record['area'] ?? $standards['area'])) . '</td>';
                echo '<td>' . e((string) ($record['operator'] ?? '')) . '</td>';
                echo '<td>' . e((string) ($record['shift'] ?? '')) . '</td>';
                echo '<td>' . e(formatNumber((float) ($record['temperature'] ?? 0), 2, ' C')) . '</td>';
                echo '<td>' . e(formatNumber((float) ($record['time_minutes'] ?? 0), 2, ' min')) . '</td>';
                echo '<td>' . e(number_format((int) ($record['produced_qty'] ?? 0), 0, ',', '.')) . '</td>';
                echo '<td>' . e(formatNumber($meta['defect_percent'], 2, '%')) . '</td>';
                echo '<td>' . e($meta['label']) . '</td>';
                echo '<td>' . e((string) ($record['observations'] ?? 'Sin observaciones.')) . '</td>';
                echo '</tr>';
            }
        }
        echo '</table>';

        echo '<h2>Evaluaciones 5S filtradas</h2>';
        echo '<table><tr><th>Fecha</th><th>Area</th><th>Responsable</th><th>Promedio</th><th>Estado</th><th>Notas</th></tr>';
        if ($fiveSRecords === []) {
            echo '<tr><td colspan="6">No hay evaluaciones 5S en esta seleccion.</td></tr>';
        } else {
            foreach ($fiveSRecords as $record) {
                $meta = classifyFiveS((float) ($record['average'] ?? 0));
                echo '<tr>';
                echo '<td>' . e(formatDateTime((string) ($record['timestamp'] ?? ''))) . '</td>';
                echo '<td>' . e((string) ($record['area'] ?? '')) . '</td>';
                echo '<td>' . e((string) ($record['responsible'] ?? '')) . '</td>';
                echo '<td>' . e(formatNumber((float) ($record['average'] ?? 0), 2) . '/5') . '</td>';
                echo '<td>' . e($meta['label']) . '</td>';
                echo '<td>' . e((string) ($record['notes'] ?? 'Sin observaciones.')) . '</td>';
                echo '</tr>';
            }
        }
        echo '</table></body></html>';
        exit;
    }

    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>' . e($reportTitle) . '</title><style>
        body{font-family:Segoe UI,Arial,sans-serif;background:#f4f8fc;color:#122033;margin:0;padding:28px}
        .sheet{max-width:1040px;margin:0 auto;background:#fff;border:1px solid #d7e2ef;border-radius:22px;box-shadow:0 18px 42px rgba(11,35,73,.08);padding:28px}
        h1,h2,h3{margin:0;color:#0b3e91}
        p{line-height:1.6}
        .muted{color:#5f7288}
        .summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:18px}
        .card{border:1px solid #d7e2ef;border-radius:18px;padding:16px;background:#f8fbff}
        .card strong{display:block;font-size:1.4rem;margin-top:8px}
        .meta{display:flex;justify-content:space-between;gap:18px;flex-wrap:wrap;margin-bottom:18px}
        table{width:100%;border-collapse:collapse;margin-top:16px}
        th,td{border:1px solid #d7e2ef;padding:9px;text-align:left;vertical-align:top}
        th{background:#eef5fb;font-size:.84rem;text-transform:uppercase;letter-spacing:.04em}
        .actions{display:flex;gap:10px;justify-content:flex-end;margin-bottom:16px}
        .btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:999px;background:#1368d3;color:#fff;text-decoration:none;font-weight:700}
        .btn.alt{background:#eef5fb;color:#17324f}
        @media print{body{background:#fff;padding:0}.sheet{box-shadow:none;border:0;border-radius:0;max-width:none;padding:0}.actions{display:none}}
        @media (max-width:760px){body{padding:12px}.sheet{padding:16px;border-radius:16px}.summary{grid-template-columns:1fr 1fr}}
    </style></head><body><div class="sheet">';
    echo '<div class="actions"><a class="btn alt" href="' . e(buildViewUrl('home', ['export' => null])) . '">Volver</a><button class="btn" onclick="window.print()">Guardar como PDF</button></div>';
    echo '<div class="meta"><div><h1>' . e($reportTitle) . '</h1><p class="muted">Generado: ' . e($reportGeneratedAt) . '<br>Filtros: ' . e($filterText) . '</p></div><div><h3>' . e((string) $standards['process_name']) . '</h3><p class="muted">Area base: ' . e((string) $standards['area']) . '</p></div></div>';
    echo '<div class="summary">';
    foreach ($executiveCards as $card) {
        echo '<div class="card"><span class="muted">' . e($card['title']) . '</span><strong>' . e($card['value']) . '</strong><p class="muted">' . e($card['text']) . '</p></div>';
    }
    echo '</div>';
    echo '<div style="margin-top:20px"><h2>Lectura ejecutiva</h2><p class="muted">Estado actual: ' . e($assessment['headline']) . '. Salud ' . e((string) $processScore) . '/100. Sigma ' . e($hasProcessData ? formatNumber($overallSigma, 2) : 'Sin dato') . '. 5S promedio ' . e($overallFiveS > 0 ? formatNumber($overallFiveS, 2) . '/5' : 'Sin dato') . '.</p></div>';
    if ($worstProcessLot !== null && $worstProcessLotMeta !== null) {
        echo '<div class="card" style="margin-top:18px"><span class="muted">Lote con mayor atencion</span><strong>' . e((string) ($worstProcessLot['operator'] ?? 'Sin operario')) . ' - ' . e(formatDateTime((string) ($worstProcessLot['timestamp'] ?? ''))) . '</strong><p class="muted">' . e($worstProcessLotMeta['issues'] !== [] ? implode(', ', $worstProcessLotMeta['issues']) : 'Sin desvios mayores en la seleccion actual.') . '</p></div>';
    }
    echo '<h2 style="margin-top:22px">Lotes filtrados</h2><table><tr><th>Fecha</th><th>Area</th><th>Operario</th><th>Turno</th><th>Temp</th><th>Tiempo</th><th>Produccion</th><th>Defectos</th><th>Estado</th></tr>';
    if ($processRecords === []) {
        echo '<tr><td colspan="9">No hay lotes en esta seleccion.</td></tr>';
    } else {
        foreach ($processRecords as $record) {
            $meta = processRecordInsights($record, $standards);
            echo '<tr>';
            echo '<td>' . e(formatDateTime((string) ($record['timestamp'] ?? ''))) . '</td>';
            echo '<td>' . e((string) ($record['area'] ?? $standards['area'])) . '</td>';
            echo '<td>' . e((string) ($record['operator'] ?? '')) . '</td>';
            echo '<td>' . e((string) ($record['shift'] ?? '')) . '</td>';
            echo '<td>' . e(formatNumber((float) ($record['temperature'] ?? 0), 2, ' C')) . '</td>';
            echo '<td>' . e(formatNumber((float) ($record['time_minutes'] ?? 0), 2, ' min')) . '</td>';
            echo '<td>' . e(number_format((int) ($record['produced_qty'] ?? 0), 0, ',', '.')) . '</td>';
            echo '<td>' . e(formatNumber($meta['defect_percent'], 2, '%')) . '</td>';
            echo '<td>' . e($meta['label']) . '</td>';
            echo '</tr>';
        }
    }
    echo '</table>';
    echo '<h2 style="margin-top:22px">Evaluaciones 5S filtradas</h2><table><tr><th>Fecha</th><th>Area</th><th>Responsable</th><th>Promedio</th><th>Estado</th><th>Notas</th></tr>';
    if ($fiveSRecords === []) {
        echo '<tr><td colspan="6">No hay evaluaciones 5S en esta seleccion.</td></tr>';
    } else {
        foreach ($fiveSRecords as $record) {
            $meta = classifyFiveS((float) ($record['average'] ?? 0));
            echo '<tr>';
            echo '<td>' . e(formatDateTime((string) ($record['timestamp'] ?? ''))) . '</td>';
            echo '<td>' . e((string) ($record['area'] ?? '')) . '</td>';
            echo '<td>' . e((string) ($record['responsible'] ?? '')) . '</td>';
            echo '<td>' . e(formatNumber((float) ($record['average'] ?? 0), 2) . '/5') . '</td>';
            echo '<td>' . e($meta['label']) . '</td>';
            echo '<td>' . e((string) ($record['notes'] ?? 'Sin observaciones.')) . '</td>';
            echo '</tr>';
        }
    }
    echo '</table><script>window.addEventListener("load",()=>window.print());</script></div></body></html>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($brandName) ?> | Control Agroindustrial</title>
    <?php if ($brandLogoUrl !== null): ?>
        <link rel="icon" type="image/png" href="<?= e($brandLogoUrl) ?>">
    <?php endif; ?>
    <style>
        :root {
            --bg: #edf4fb;
            --bg-soft: #f6fbff;
            --surface: rgba(255, 255, 255, 0.98);
            --surface-deep: #ffffff;
            --surface-muted: #f5f8fc;
            --ink: #122033;
            --muted: #5f7288;
            --line: #d7e2ef;
            --line-strong: #c4d3e4;
            --blue: #1368d3;
            --blue-deep: #0b3e91;
            --cyan: #0ea5c6;
            --teal: #0b7766;
            --green: #17876b;
            --gold: #dd7a0e;
            --red: #d93a3a;
            --slate: #58697e;
            --purple: #4051bf;
            --shadow-soft: 0 18px 42px rgba(11, 35, 73, 0.08);
            --shadow-strong: 0 24px 52px rgba(11, 35, 73, 0.12);
            --radius-xl: 30px;
            --radius-lg: 24px;
            --radius-md: 18px;
            --radius-sm: 12px;
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            font-family: "Bahnschrift", "Segoe UI", "Trebuchet MS", sans-serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(19, 104, 211, 0.08), transparent 28%),
                radial-gradient(circle at 100% 0, rgba(11, 119, 102, 0.08), transparent 24%),
                radial-gradient(circle at 50% 100%, rgba(221, 122, 14, 0.05), transparent 32%),
                linear-gradient(180deg, #f8fbff, var(--bg));
            min-height: 100vh;
        }

        body.mobile-menu-open {
            overflow: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(17, 42, 73, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(17, 42, 73, 0.03) 1px, transparent 1px);
            background-size: 34px 34px;
            pointer-events: none;
            z-index: 0;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        img {
            max-width: 100%;
        }

        .shell {
            position: relative;
            z-index: 1;
            width: min(1380px, calc(100% - 26px));
            margin: 16px auto 42px;
        }

        .layout {
            display: grid;
            grid-template-columns: 290px minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }

        .sidebar {
            position: sticky;
            top: 12px;
            align-self: start;
        }

        .mobile-topbar {
            display: none;
        }

        .mobile-overlay {
            display: none;
        }

        .sidebar-panel {
            padding: 18px;
            background: rgba(255, 255, 255, 0.94);
            border: 1px solid rgba(215, 226, 239, 0.94);
            border-radius: 28px;
            box-shadow: var(--shadow-soft);
            backdrop-filter: blur(16px);
        }

        .sidebar-brand {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(215, 226, 239, 0.9);
        }

        .sidebar-brand-main {
            min-width: 0;
            flex: 1;
            display: grid;
            gap: 12px;
        }

        .sidebar-logo-card {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 12px 14px;
            border-radius: 22px;
            border: 1px solid rgba(19, 104, 211, 0.14);
            background: linear-gradient(180deg, #ffffff, #f3f9ff);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.96);
        }

        .sidebar-logo {
            display: block;
            width: min(100%, 206px);
            height: auto;
        }

        .sidebar-brand .brand-copy {
            min-width: 0;
            display: grid;
            gap: 4px;
        }

        .sidebar-brand .brand-copy strong {
            font-size: 0.98rem;
            letter-spacing: 0.01em;
        }

        .sidebar-brand .brand-copy span {
            display: block;
            color: var(--muted);
            font-size: 0.86rem;
            line-height: 1.35;
        }

        .menu-toggle {
            display: none;
            margin-left: auto;
            width: 46px;
            height: 46px;
            padding: 0;
            border-radius: 14px;
            background: linear-gradient(135deg, #ffffff, #f4f8fc);
            border: 1px solid var(--line);
            box-shadow: none;
            color: var(--blue-deep);
            flex: none;
        }

        .menu-toggle:hover {
            transform: none;
            box-shadow: none;
        }

        .menu-toggle-lines {
            display: grid;
            gap: 5px;
        }

        .menu-toggle-lines span {
            display: block;
            width: 18px;
            height: 2px;
            border-radius: 999px;
            background: currentColor;
            transition: transform 0.2s ease, opacity 0.2s ease;
        }

        .sidebar-panel.is-open .menu-toggle-lines span:nth-child(1) {
            transform: translateY(7px) rotate(45deg);
        }

        .sidebar-panel.is-open .menu-toggle-lines span:nth-child(2) {
            opacity: 0;
        }

        .sidebar-panel.is-open .menu-toggle-lines span:nth-child(3) {
            transform: translateY(-7px) rotate(-45deg);
        }

        .menu-toggle.is-open .menu-toggle-lines span:nth-child(1) {
            transform: translateY(7px) rotate(45deg);
        }

        .menu-toggle.is-open .menu-toggle-lines span:nth-child(2) {
            opacity: 0;
        }

        .menu-toggle.is-open .menu-toggle-lines span:nth-child(3) {
            transform: translateY(-7px) rotate(-45deg);
        }

        .sidebar-mobile-content {
            display: block;
        }

        .sidebar-kicker {
            display: block;
            margin-top: 18px;
            margin-bottom: 10px;
            color: var(--muted);
            font-size: 0.76rem;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .side-nav {
            display: grid;
            gap: 10px;
        }

        .side-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 18px;
            border: 1px solid transparent;
            background: linear-gradient(180deg, #ffffff, #f7fbff);
            color: var(--muted);
            font-weight: 800;
            transition: transform 0.2s ease, border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
        }

        .side-link:hover,
        .side-link.active {
            transform: translateY(-1px);
            color: var(--blue-deep);
            border-color: rgba(19, 104, 211, 0.16);
            background: rgba(19, 104, 211, 0.08);
            box-shadow: 0 10px 18px rgba(19, 104, 211, 0.08);
        }

        .side-link small {
            color: inherit;
            font-size: 0.74rem;
            font-weight: 900;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            opacity: 0.8;
        }

        .sidebar-grid {
            display: grid;
            gap: 10px;
            margin-top: 10px;
        }

        .sidebar-stat {
            padding: 12px 14px;
            border-radius: 18px;
            background: linear-gradient(180deg, #ffffff, #f7fbff);
            border: 1px solid var(--line);
        }

        .sidebar-stat span {
            display: block;
            color: var(--muted);
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 900;
        }

        .sidebar-stat strong {
            display: block;
            margin-top: 6px;
            font-size: 1.04rem;
            line-height: 1.2;
        }

        .sidebar-note {
            margin-top: 14px;
            padding: 14px;
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(19, 104, 211, 0.08), rgba(11, 119, 102, 0.08));
            border: 1px solid rgba(19, 104, 211, 0.12);
            color: var(--muted);
            line-height: 1.55;
            font-size: 0.9rem;
        }

        .content {
            min-width: 0;
        }

        .topbar {
            position: sticky;
            top: 12px;
            z-index: 50;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            padding: 14px 18px;
            background: rgba(255, 255, 255, 0.92);
            border: 1px solid rgba(215, 226, 239, 0.94);
            border-radius: 24px;
            box-shadow: var(--shadow-soft);
            backdrop-filter: blur(16px);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }

        .brand-mark {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            border-radius: 16px;
            font-weight: 900;
            color: #fff;
            background: linear-gradient(135deg, var(--blue-deep), var(--blue), var(--teal));
            box-shadow: 0 16px 24px rgba(19, 104, 211, 0.25);
        }

        .brand-copy strong {
            display: block;
            font-size: 1.02rem;
            letter-spacing: 0.01em;
        }

        .brand-copy span {
            display: block;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .mobile-topbar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            flex: 1;
        }

        .mobile-topbar-logo-card {
            flex: none;
            display: flex;
            align-items: center;
            justify-content: center;
            min-width: 92px;
            min-height: 44px;
            padding: 6px 10px;
            border-radius: 15px;
            border: 1px solid rgba(19, 104, 211, 0.14);
            background: linear-gradient(180deg, #ffffff, #eef6ff);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, 0.94),
                0 10px 22px rgba(11, 35, 73, 0.06);
        }

        .mobile-topbar-logo {
            display: block;
            width: 80px;
            height: auto;
        }

        .mobile-topbar-copy {
            display: grid;
            gap: 2px;
            align-content: center;
        }

        .mobile-topbar-copy strong,
        .mobile-topbar-copy span {
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        .nav {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
            overflow-x: auto;
            scrollbar-width: none;
            scroll-snap-type: x proximity;
        }

        .nav::-webkit-scrollbar {
            display: none;
        }

        .nav-link {
            flex: none;
            padding: 10px 14px;
            border-radius: 999px;
            color: var(--muted);
            font-weight: 800;
            border: 1px solid transparent;
            scroll-snap-align: start;
            transition: transform 0.2s ease, background 0.2s ease, border-color 0.2s ease;
        }

        .nav-link:hover,
        .nav-link.active {
            color: var(--blue-deep);
            transform: translateY(-1px);
            background: rgba(19, 104, 211, 0.08);
            border-color: rgba(19, 104, 211, 0.16);
        }

        .messages {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: var(--radius-md);
            border: 1px solid transparent;
            box-shadow: var(--shadow-soft);
        }

        .messages ul {
            margin: 0;
            padding-left: 20px;
        }

        .messages.success {
            background: rgba(23, 135, 107, 0.09);
            border-color: rgba(23, 135, 107, 0.16);
            color: var(--teal);
        }

        .messages.error {
            background: rgba(217, 58, 58, 0.09);
            border-color: rgba(217, 58, 58, 0.16);
            color: var(--red);
        }

        .hero {
            margin-top: 18px;
            position: relative;
            overflow: hidden;
            border-radius: 36px;
            padding: 36px;
            background:
                radial-gradient(circle at 15% 20%, rgba(255, 255, 255, 0.18), transparent 26%),
                radial-gradient(circle at 85% 12%, rgba(14, 165, 198, 0.25), transparent 22%),
                linear-gradient(140deg, #0a3374, #1368d3 45%, #0b7766 100%);
            color: #fff;
            box-shadow: var(--shadow-strong);
        }

        .hero::after {
            content: "";
            position: absolute;
            inset: auto -120px -160px auto;
            width: 360px;
            height: 360px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
        }

        .hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1.3fr 0.95fr;
            gap: 24px;
            align-items: end;
        }

        .eyebrow {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            max-width: 100%;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.12);
            font-size: 0.8rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 800;
        }

        .eyebrow-logo {
            display: block;
            width: 102px;
            height: auto;
        }

        .eyebrow-text {
            display: inline-block;
        }

        h1,
        h2,
        h3 {
            margin: 0;
            font-family: "Bahnschrift SemiBold", "Franklin Gothic Demi", sans-serif;
            letter-spacing: 0.015em;
        }

        .hero h1 {
            margin-top: 16px;
            font-size: clamp(2rem, 4vw, 3.9rem);
            line-height: 0.98;
            max-width: 12ch;
        }

        .hero p {
            margin: 16px 0 0;
            color: rgba(255, 255, 255, 0.88);
            line-height: 1.65;
            max-width: 68ch;
        }

        .hero-stack {
            display: grid;
            gap: 14px;
        }

        .hero-summary {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .hero-card {
            padding: 18px;
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.16);
            backdrop-filter: blur(10px);
        }

        .hero-card span {
            display: block;
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: rgba(255, 255, 255, 0.72);
        }

        .hero-card strong {
            display: block;
            margin-top: 8px;
            font-size: 1.3rem;
        }

        .hero-card p {
            margin-top: 8px;
            font-size: 0.92rem;
            color: rgba(255, 255, 255, 0.82);
        }

        .score-ring {
            --score: 0;
            width: 180px;
            height: 180px;
            margin: 0 auto;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background:
                radial-gradient(circle at center, rgba(8, 29, 69, 0.94) 0 55%, transparent 56%),
                conic-gradient(#ffffff calc(var(--score) * 1%), rgba(255,255,255,0.18) 0);
            box-shadow: inset 0 0 0 12px rgba(255, 255, 255, 0.05);
        }

        .score-ring-inner {
            text-align: center;
            width: 120px;
        }

        .score-ring-inner strong {
            display: block;
            font-size: 2.3rem;
            line-height: 1;
        }

        .score-ring-inner span {
            display: block;
            margin-top: 8px;
            font-size: 0.84rem;
            color: rgba(255, 255, 255, 0.74);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .section {
            margin-top: 24px;
        }

        .screen-header {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 18px;
            margin-bottom: 18px;
        }

        .screen-header p {
            margin: 8px 0 0;
            color: var(--muted);
            line-height: 1.6;
            max-width: 74ch;
        }

        .grid-4,
        .grid-3,
        .grid-2 {
            display: grid;
            gap: 18px;
        }

        .grid-4 {
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .grid-3 {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .grid-2 {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .panel,
        .stat-card,
        .module-card,
        .guide-card {
            background: var(--surface);
            border: 1px solid rgba(215, 226, 239, 0.9);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-soft);
        }

        .panel {
            padding: 22px;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 18px;
        }

        .panel-header p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 0.95rem;
            line-height: 1.55;
        }

        .stat-card {
            padding: 20px;
            min-height: 170px;
            background: linear-gradient(180deg, #ffffff, #f7fbff);
        }

        .stat-card small {
            display: block;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.07em;
            font-size: 0.76rem;
            font-weight: 800;
        }

        .stat-card strong {
            display: block;
            margin-top: 14px;
            font-size: clamp(1.8rem, 3vw, 2.5rem);
            line-height: 1;
        }

        .stat-card p {
            margin: 12px 0 0;
            color: var(--muted);
            line-height: 1.58;
        }

        .module-card {
            position: relative;
            overflow: hidden;
            padding: 22px;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .module-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-strong);
        }

        .module-card::before {
            content: "";
            position: absolute;
            inset: 0 auto auto 0;
            width: 100%;
            height: 6px;
            background: var(--blue);
        }

        .module-card.teal::before {
            background: var(--teal);
        }

        .module-card.blue::before {
            background: var(--blue);
        }

        .module-card.gold::before {
            background: var(--gold);
        }

        .module-card.slate::before {
            background: var(--slate);
        }

        .module-top {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
        }

        .module-index {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: rgba(19, 104, 211, 0.08);
            color: var(--blue-deep);
            font-weight: 900;
        }

        .module-card h3 {
            margin-top: 16px;
            font-size: 1.18rem;
        }

        .module-card p {
            margin: 10px 0 0;
            color: var(--muted);
            line-height: 1.6;
            min-height: 78px;
        }

        .module-card a {
            display: inline-flex;
            margin-top: 18px;
            padding: 11px 16px;
            border-radius: 999px;
            background: rgba(19, 104, 211, 0.08);
            color: var(--blue-deep);
            font-weight: 900;
        }

        .guide-card {
            padding: 18px;
            background: linear-gradient(180deg, #ffffff, #f8fbff);
        }

        .guide-card strong {
            display: block;
            margin-top: 12px;
            font-size: 1.04rem;
        }

        .guide-card span {
            display: inline-flex;
            width: 38px;
            height: 38px;
            border-radius: 12px;
            align-items: center;
            justify-content: center;
            background: rgba(11, 119, 102, 0.09);
            color: var(--teal);
            font-weight: 900;
        }

        .guide-card p {
            margin: 10px 0 0;
            color: var(--muted);
            line-height: 1.55;
        }

        .five-s-vision {
            position: relative;
            overflow: hidden;
            padding: 28px;
            background:
                radial-gradient(circle at 0 0, rgba(19, 104, 211, 0.07), transparent 24%),
                radial-gradient(circle at 100% 0, rgba(11, 119, 102, 0.08), transparent 22%),
                linear-gradient(180deg, #ffffff, #f8fbff);
        }

        .five-s-vision::after {
            content: "";
            position: absolute;
            inset: auto -80px -110px auto;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(19, 104, 211, 0.05);
        }

        .five-s-vision-header {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 24px;
        }

        .five-s-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(19, 104, 211, 0.08);
            color: var(--blue-deep);
            font-size: 0.76rem;
            font-weight: 900;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .five-s-vision-header h2 {
            margin-top: 14px;
            max-width: 16ch;
            font-size: clamp(1.8rem, 2.8vw, 3rem);
            line-height: 1.02;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .five-s-vision-header p {
            margin: 14px 0 0;
            max-width: 70ch;
            color: var(--muted);
            line-height: 1.65;
        }

        .five-s-vision-summary {
            position: relative;
            z-index: 1;
            min-width: 220px;
            padding: 18px;
            border-radius: 22px;
            border: 1px solid rgba(215, 226, 239, 0.96);
            background: rgba(255, 255, 255, 0.88);
            box-shadow: var(--shadow-soft);
        }

        .five-s-vision-summary span {
            display: block;
            color: var(--muted);
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 900;
        }

        .five-s-vision-summary strong {
            display: block;
            margin-top: 8px;
            font-size: 2rem;
            line-height: 1;
        }

        .five-s-vision-summary p {
            margin: 10px 0 0;
            color: var(--muted);
            line-height: 1.55;
        }

        .five-s-track {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 0;
        }

        .five-s-step {
            position: relative;
            padding: 0 18px;
            text-align: center;
        }

        .five-s-step:not(:last-child)::after {
            content: "";
            position: absolute;
            top: 44px;
            right: 0;
            width: 1px;
            height: 86px;
            background: linear-gradient(180deg, transparent, rgba(88, 105, 126, 0.35), transparent);
        }

        .five-s-icon-shell {
            display: grid;
            place-items: center;
            margin: 0 auto;
            width: 128px;
            height: 128px;
            border-radius: 999px;
            background: radial-gradient(circle at 35% 30%, rgba(255, 255, 255, 0.18), transparent 35%), var(--pillar);
            box-shadow: 0 18px 28px rgba(17, 42, 73, 0.16);
        }

        .five-s-icon {
            display: grid;
            place-items: center;
            width: 72px;
            height: 72px;
            color: #ffffff;
        }

        .five-s-icon svg {
            width: 100%;
            height: 100%;
            display: block;
        }

        .five-s-step h3 {
            margin-top: 22px;
            font-size: 1.05rem;
            line-height: 1.15;
            text-transform: uppercase;
            color: var(--pillar);
        }

        .five-s-step p {
            margin: 10px auto 0;
            max-width: 22ch;
            color: var(--muted);
            line-height: 1.55;
            font-size: 0.92rem;
        }

        .five-s-step-footer {
            display: grid;
            gap: 8px;
            justify-items: center;
            margin-top: 14px;
        }

        .five-s-score {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 88px;
            padding: 7px 12px;
            border-radius: 999px;
            background: var(--pillar-soft);
            color: var(--pillar);
            font-size: 0.84rem;
            font-weight: 900;
        }

        .screen-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }

        .ghost-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
            padding: 11px 16px;
            border-radius: 999px;
            border: 1px solid rgba(19, 104, 211, 0.16);
            background: #ffffff;
            color: var(--blue-deep);
            font-weight: 900;
            box-shadow: var(--shadow-soft);
            cursor: pointer;
        }

        .filter-panel {
            padding: 22px;
            background:
                radial-gradient(circle at 0 0, rgba(19, 104, 211, 0.06), transparent 24%),
                linear-gradient(180deg, #ffffff, #f8fbff);
        }

        .filter-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(6, minmax(0, 1fr));
        }

        .filter-grid .wide {
            grid-column: span 2;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
            margin-top: 16px;
        }

        .filter-chip-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 16px;
        }

        .filter-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(19, 104, 211, 0.08);
            color: var(--blue-deep);
            font-size: 0.82rem;
            font-weight: 800;
        }

        .executive-grid {
            display: grid;
            gap: 18px;
            grid-template-columns: repeat(4, minmax(0, 1fr));
        }

        .executive-card {
            padding: 20px;
            border-radius: var(--radius-lg);
            border: 1px solid rgba(215, 226, 239, 0.9);
            background: linear-gradient(180deg, #ffffff, #f8fbff);
            box-shadow: var(--shadow-soft);
        }

        .executive-card span {
            display: block;
            color: var(--muted);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 900;
        }

        .executive-card strong {
            display: block;
            margin-top: 12px;
            font-size: clamp(1.5rem, 2.4vw, 2.2rem);
            line-height: 1;
        }

        .executive-card p {
            margin: 10px 0 0;
            color: var(--muted);
            line-height: 1.55;
        }

        .executive-split {
            display: grid;
            gap: 18px;
            grid-template-columns: 1.3fr 0.9fr;
        }

        .summary-list,
        .trend-list {
            display: grid;
            gap: 12px;
        }

        .summary-list-item,
        .trend-item {
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid var(--line);
            background: linear-gradient(180deg, #ffffff, #f9fbff);
        }

        .summary-list-item strong,
        .trend-item strong {
            display: block;
            font-size: 1rem;
        }

        .summary-list-item p,
        .trend-item p {
            margin: 8px 0 0;
            color: var(--muted);
            line-height: 1.5;
        }

        .step-form {
            display: grid;
            gap: 16px;
        }

        .step-header {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
        }

        .step-chip {
            display: grid;
            gap: 8px;
            padding: 14px;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: linear-gradient(180deg, #ffffff, #f8fbff);
            color: var(--muted);
            transition: border-color 0.2s ease, background 0.2s ease, transform 0.2s ease;
            cursor: pointer;
        }

        .step-chip.is-active {
            color: var(--blue-deep);
            border-color: rgba(19, 104, 211, 0.18);
            background: rgba(19, 104, 211, 0.08);
            transform: translateY(-1px);
        }

        .step-chip.is-done {
            color: var(--teal);
            border-color: rgba(11, 119, 102, 0.18);
            background: rgba(11, 119, 102, 0.08);
        }

        .step-chip-index {
            display: inline-flex;
            width: 32px;
            height: 32px;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            background: rgba(19, 104, 211, 0.12);
            font-size: 0.86rem;
            font-weight: 900;
        }

        .step-chip strong {
            font-size: 0.96rem;
        }

        .step-chip span:last-child {
            font-size: 0.84rem;
            line-height: 1.4;
            text-transform: none;
            letter-spacing: 0;
        }

        .step-pane {
            display: none;
            gap: 14px;
        }

        .step-pane.is-active {
            display: grid;
        }

        .step-actions {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 4px;
        }

        .step-actions .grow {
            flex: 1;
        }

        .mobile-dock {
            display: none;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 0.82rem;
            font-weight: 900;
            white-space: nowrap;
        }

        .dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: currentColor;
        }

        .badge.success {
            color: var(--green);
            background: rgba(23, 135, 107, 0.1);
        }

        .badge.warning {
            color: #8b560e;
            background: rgba(221, 122, 14, 0.12);
        }

        .badge.danger {
            color: var(--red);
            background: rgba(217, 58, 58, 0.1);
        }

        .badge.neutral {
            color: var(--slate);
            background: rgba(88, 105, 126, 0.1);
        }

        .badge.good {
            color: var(--green);
            background: rgba(23, 135, 107, 0.1);
        }

        .badge.attention {
            color: #8b560e;
            background: rgba(221, 122, 14, 0.12);
        }

        .badge.critical {
            color: var(--red);
            background: rgba(217, 58, 58, 0.1);
        }

        .metric-grid,
        .coach-grid,
        .mini-grid {
            display: grid;
            gap: 12px;
        }

        .mini-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .metric-item,
        .soft-box,
        .glossary-item,
        .coach-card {
            padding: 15px 16px;
            border-radius: var(--radius-md);
            background: linear-gradient(180deg, #ffffff, #f8fbff);
            border: 1px solid var(--line);
        }

        .metric-item span,
        .glossary-item span {
            display: block;
            color: var(--muted);
            font-size: 0.85rem;
        }

        .metric-item strong,
        .glossary-item strong {
            display: block;
            margin-top: 6px;
            font-size: 1.12rem;
        }

        .metric-item p,
        .glossary-item p,
        .coach-card p {
            margin: 9px 0 0;
            color: var(--muted);
            line-height: 1.55;
        }

        .coach-card strong {
            display: block;
            font-size: 1rem;
        }

        .score-strip {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 14px;
        }

        .progress {
            width: 100%;
            height: 10px;
            margin-top: 10px;
            overflow: hidden;
            border-radius: 999px;
            background: #e6eef7;
        }

        .progress > span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--blue), var(--cyan), var(--teal));
        }

        .panel-note {
            margin-top: 12px;
            padding: 14px 16px;
            border-radius: var(--radius-md);
            background: rgba(19, 104, 211, 0.07);
            border: 1px solid rgba(19, 104, 211, 0.12);
            color: #164f8a;
            line-height: 1.55;
        }

        .plain-card {
            padding: 18px;
            border-radius: var(--radius-md);
            background: linear-gradient(135deg, rgba(19, 104, 211, 0.08), rgba(11, 119, 102, 0.08));
            border: 1px solid rgba(19, 104, 211, 0.12);
        }

        .plain-card strong {
            display: block;
            font-size: 1.08rem;
        }

        .plain-card p {
            margin: 10px 0 0;
            color: var(--muted);
            line-height: 1.6;
        }

        .checklist {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            gap: 10px;
        }

        .checklist li {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.55;
        }

        .checklist li::before {
            content: "";
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: var(--blue);
            margin-top: 8px;
            flex: none;
        }

        form {
            display: grid;
            gap: 16px;
        }

        .field-grid,
        .field-grid-3 {
            display: grid;
            gap: 14px;
        }

        .field-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .field-grid-3 {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        label {
            display: grid;
            gap: 8px;
            font-size: 0.95rem;
            font-weight: 800;
        }

        input,
        textarea,
        select {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid var(--line-strong);
            border-radius: var(--radius-sm);
            background: #ffffff;
            color: var(--ink);
            font: inherit;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        }

        textarea {
            resize: vertical;
            min-height: 96px;
        }

        input:focus,
        textarea:focus,
        select:focus {
            outline: none;
            border-color: rgba(19, 104, 211, 0.56);
            box-shadow: 0 0 0 4px rgba(19, 104, 211, 0.12);
            transform: translateY(-1px);
        }

        button,
        .button-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border: 0;
            border-radius: 999px;
            min-height: 48px;
            padding: 13px 18px;
            background: linear-gradient(135deg, var(--blue), var(--blue-deep));
            color: #fff;
            font: inherit;
            font-weight: 900;
            cursor: pointer;
            box-shadow: 0 14px 26px rgba(19, 104, 211, 0.18);
        }

        button.secondary,
        .button-link.secondary {
            background: linear-gradient(135deg, var(--teal), #0d8e7b);
            box-shadow: 0 14px 26px rgba(11, 119, 102, 0.18);
        }

        .preview-box {
            padding: 16px;
            border-radius: var(--radius-md);
            border: 1px dashed var(--line-strong);
            background: #f8fbff;
        }

        .hint-grid {
            display: grid;
            gap: 14px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .glossary-grid {
            display: grid;
            gap: 12px;
        }

        .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        table {
            width: 100%;
            min-width: 680px;
            border-collapse: collapse;
        }

        th,
        td {
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid var(--line);
            font-size: 0.93rem;
        }

        th {
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-size: 0.78rem;
        }

        canvas {
            width: 100%;
            min-height: 250px;
            display: block;
            background: linear-gradient(180deg, #ffffff, #f8fbff);
            border: 1px solid var(--line);
            border-radius: var(--radius-md);
            padding: 10px;
            aspect-ratio: 16 / 9;
        }

        .chart-grid {
            display: grid;
            gap: 18px;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .gallery {
            display: grid;
            gap: 16px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }

        .gallery-card {
            overflow: hidden;
            border-radius: var(--radius-md);
            border: 1px solid var(--line);
            background: #fff;
            box-shadow: var(--shadow-soft);
        }

        .gallery-card img {
            display: block;
            width: 100%;
            height: 170px;
            object-fit: cover;
            background: linear-gradient(135deg, rgba(19, 104, 211, 0.12), rgba(11, 119, 102, 0.12));
        }

        .gallery-body {
            padding: 14px;
            display: grid;
            gap: 8px;
        }

        .photo-preview {
            display: grid;
            gap: 12px;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            margin-top: 8px;
        }

        .photo-preview figure {
            margin: 0;
            overflow: hidden;
            border-radius: var(--radius-sm);
            border: 1px solid var(--line);
            background: #fff;
        }

        .photo-preview img {
            width: 100%;
            height: 110px;
            object-fit: cover;
            display: block;
        }

        .photo-preview figcaption {
            padding: 10px;
            font-size: 0.82rem;
            color: var(--muted);
        }

        .empty {
            padding: 24px;
            border-radius: var(--radius-md);
            border: 1px dashed var(--line-strong);
            background: #fbfdff;
            color: var(--muted);
            text-align: center;
        }

        .footer-note {
            margin-top: 24px;
            text-align: center;
            color: var(--muted);
            font-size: 0.92rem;
        }

        @media (max-width: 1120px) {
            .layout {
                grid-template-columns: 250px minmax(0, 1fr);
            }

            .filter-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .executive-grid,
            .executive-split {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .five-s-vision-header {
                flex-direction: column;
            }

            .five-s-vision-summary {
                min-width: 0;
                width: min(100%, 320px);
            }

            .hero-grid,
            .grid-4,
            .grid-3,
            .grid-2,
            .chart-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 760px) {
            .shell {
                width: min(100% - 18px, 1380px);
                margin: 12px auto 30px;
            }

            .layout {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .sidebar {
                position: fixed;
                inset: 0 auto 0 0;
                z-index: 120;
                width: min(84vw, 340px);
                transform: translateX(calc(-100% - 18px));
                transition: transform 0.28s ease;
                pointer-events: none;
            }

            .sidebar-panel {
                height: 100dvh;
                margin: 0;
                padding: 0;
                border-radius: 0 26px 26px 0;
                background: #ffffff;
                border-color: #d7e2ef;
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                overflow-y: auto;
                overscroll-behavior: contain;
                box-shadow: 26px 0 48px rgba(8, 25, 55, 0.18);
            }

            .sidebar-brand {
                position: sticky;
                top: 0;
                z-index: 2;
                padding: max(14px, env(safe-area-inset-top)) 14px 12px;
                gap: 10px;
                background: #ffffff;
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
            }

            .menu-toggle {
                display: inline-flex;
                width: 44px;
                height: 44px;
                border-radius: 13px;
            }

            .sidebar.is-open {
                transform: translateX(0);
                pointer-events: auto;
            }

            .mobile-topbar {
                position: sticky;
                top: max(8px, env(safe-area-inset-top));
                z-index: 90;
                position: sticky;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                margin-bottom: 12px;
                padding: 12px 13px;
                background: #ffffff;
                border: 1px solid rgba(215, 226, 239, 0.94);
                border-radius: 18px;
                box-shadow: 0 14px 28px rgba(8, 25, 55, 0.1);
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
            }

            .mobile-topbar::before {
                content: "";
                position: absolute;
                inset: 0;
                border-radius: inherit;
                background: linear-gradient(180deg, rgba(255, 255, 255, 0.7), transparent 52%);
                pointer-events: none;
            }

            .mobile-topbar > * {
                position: relative;
                z-index: 1;
            }

            .mobile-topbar .brand-mark {
                width: 40px;
                height: 40px;
                border-radius: 13px;
                box-shadow: 0 12px 22px rgba(19, 104, 211, 0.2);
            }

            .mobile-topbar-logo-card {
                min-width: 88px;
                min-height: 42px;
                padding: 5px 8px;
                border-radius: 14px;
            }

            .mobile-topbar-logo {
                width: 76px;
            }

            .mobile-topbar-copy {
                min-width: 0;
            }

            .mobile-topbar-copy strong {
                display: block;
                font-size: 0.96rem;
                line-height: 1.15;
                font-weight: 900;
            }

            .mobile-topbar-copy span {
                display: block;
                margin-top: 1px;
                color: var(--muted);
                font-size: 0.76rem;
                line-height: 1.2;
            }

            #menu-toggle-mobile {
                width: 46px;
                height: 46px;
                border-radius: 15px;
                border-color: rgba(19, 104, 211, 0.16);
                background: linear-gradient(180deg, #ffffff, #eff6ff);
                box-shadow:
                    inset 0 1px 0 rgba(255, 255, 255, 0.96),
                    0 10px 20px rgba(11, 35, 73, 0.06);
            }

            .mobile-overlay {
                position: fixed;
                inset: 0;
                z-index: 110;
                background: rgba(6, 18, 36, 0.4);
                backdrop-filter: none;
                -webkit-backdrop-filter: none;
                opacity: 0;
                pointer-events: none;
                transition: opacity 0.24s ease;
            }

            .mobile-overlay.is-open {
                display: block;
                opacity: 1;
                pointer-events: auto;
            }

            .sidebar-mobile-content {
                opacity: 1;
                max-height: none;
                overflow: visible;
                pointer-events: auto;
                margin-top: 0;
                padding: 10px 12px max(22px, env(safe-area-inset-bottom));
            }

            .sidebar-brand-main {
                gap: 10px;
            }

            .sidebar-logo-card {
                padding: 10px 12px;
                border-radius: 18px;
            }

            .sidebar-logo {
                width: min(100%, 172px);
            }

            .side-nav {
                display: grid;
                gap: 7px;
                overflow: visible;
                padding-bottom: 0;
                scroll-snap-type: none;
            }

            .side-link {
                min-width: 0;
                padding: 12px 13px;
                border-radius: 15px;
                background: #ffffff;
            }

            .side-link span {
                font-size: 0.92rem;
                line-height: 1.35;
            }

            .side-link small {
                display: none;
            }

            .sidebar-grid {
                grid-template-columns: 1fr 1fr;
                gap: 7px;
            }

            .sidebar-kicker {
                margin-top: 10px;
                margin-bottom: 6px;
                font-size: 0.7rem;
            }

            .sidebar-stat {
                padding: 10px 11px;
                border-radius: 15px;
                background: #ffffff;
            }

            .sidebar-stat span {
                font-size: 0.68rem;
            }

            .sidebar-stat strong {
                font-size: 0.9rem;
            }

            .sidebar-note {
                display: none;
            }

            .sidebar,
            .sidebar-panel,
            .sidebar-brand,
            .sidebar-mobile-content,
            .side-link,
            .sidebar-stat,
            .mobile-topbar {
                filter: none !important;
            }

            .screen-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .brand {
                width: 100%;
                gap: 12px;
            }

            .brand-mark {
                width: 42px;
                height: 42px;
                border-radius: 14px;
                flex: none;
            }

            .content {
                min-width: 0;
                padding-bottom: calc(max(28px, env(safe-area-inset-bottom)) + 86px);
            }

            .mobile-menu-open .mobile-topbar {
                opacity: 0.96;
            }

            .messages {
                margin-top: 0;
                padding: 12px 14px;
                border-radius: 16px;
            }

            .hero,
            .panel,
            .module-card,
            .stat-card,
            .guide-card {
                padding-left: 15px;
                padding-right: 15px;
            }

            .five-s-vision {
                padding: 20px 16px;
                border-radius: 20px;
            }

            .five-s-vision-header {
                gap: 14px;
                margin-bottom: 18px;
            }

            .five-s-vision-header h2 {
                margin-top: 10px;
                max-width: 100%;
                font-size: 1.55rem;
            }

            .five-s-vision-header p {
                margin-top: 10px;
                font-size: 0.92rem;
                line-height: 1.55;
            }

            .five-s-vision-summary {
                width: 100%;
                padding: 14px;
                border-radius: 18px;
            }

            .five-s-vision-summary strong {
                font-size: 1.72rem;
            }

            .five-s-track {
                display: flex;
                gap: 14px;
                overflow-x: auto;
                padding-bottom: 4px;
                scroll-snap-type: x proximity;
                -webkit-overflow-scrolling: touch;
            }

            .five-s-track::-webkit-scrollbar {
                display: none;
            }

            .five-s-step {
                flex: 0 0 236px;
                padding: 0;
                scroll-snap-align: start;
            }

            .five-s-step:not(:last-child)::after {
                display: none;
            }

            .five-s-icon-shell {
                width: 104px;
                height: 104px;
            }

            .five-s-icon {
                width: 58px;
                height: 58px;
            }

            .five-s-step h3 {
                margin-top: 16px;
                font-size: 0.98rem;
            }

            .five-s-step p {
                max-width: none;
                font-size: 0.89rem;
            }

            .hero {
                margin-top: 0;
                padding-top: 22px;
                padding-bottom: 20px;
                border-radius: 24px;
            }

            .hero::after {
                inset: auto -86px -112px auto;
                width: 220px;
                height: 220px;
                opacity: 0.65;
            }

            .hero-grid {
                gap: 16px;
            }

            .hero-stack {
                gap: 10px;
            }

            .eyebrow {
                gap: 6px;
                padding: 7px 11px;
                font-size: 0.7rem;
                letter-spacing: 0.06em;
            }

            .eyebrow-logo {
                width: 88px;
            }

            .hero h1 {
                font-size: 1.94rem;
                line-height: 1.02;
                max-width: 100%;
            }

            .hero p {
                margin-top: 12px;
                font-size: 0.93rem;
                line-height: 1.58;
                max-width: 100%;
            }

            .score-strip {
                gap: 8px;
                margin-top: 12px;
            }

            .badge {
                gap: 6px;
                padding: 7px 10px;
                font-size: 0.74rem;
                white-space: normal;
            }

            .hero-summary,
            .field-grid,
            .field-grid-3,
            .hint-grid,
            .mini-grid {
                grid-template-columns: 1fr;
            }

            .section {
                margin-top: 18px;
            }

            .grid-4,
            .grid-3,
            .grid-2 {
                gap: 14px;
            }

            .nav {
                width: 100%;
                justify-content: flex-start;
                flex-wrap: nowrap;
                padding-bottom: 2px;
            }

            .nav-link {
                padding: 10px 12px;
                font-size: 0.88rem;
            }

            .screen-header {
                gap: 8px;
                margin-bottom: 14px;
            }

            .screen-header h2 {
                font-size: 1.28rem;
            }

            .screen-header p {
                font-size: 0.92rem;
                line-height: 1.5;
            }

            .panel,
            .stat-card,
            .module-card,
            .guide-card {
                border-radius: 18px;
            }

            .panel {
                padding-top: 16px;
                padding-bottom: 16px;
            }

            .panel-header {
                gap: 10px;
                margin-bottom: 12px;
            }

            .panel-header h3 {
                font-size: 1.04rem;
            }

            .panel-header p {
                font-size: 0.9rem;
                line-height: 1.5;
            }

            .hero-card {
                padding: 14px;
                border-radius: 18px;
            }

            .hero-card strong {
                font-size: 1.12rem;
            }

            .score-ring {
                width: 132px;
                height: 132px;
            }

            .score-ring-inner {
                width: 88px;
            }

            .score-ring-inner strong {
                font-size: 1.8rem;
            }

            .stat-card {
                min-height: auto;
                padding-top: 16px;
                padding-bottom: 16px;
            }

            .stat-card strong {
                margin-top: 10px;
                font-size: clamp(1.52rem, 7vw, 2rem);
            }

            .stat-card p {
                margin-top: 10px;
                font-size: 0.9rem;
                line-height: 1.5;
            }

            .module-top {
                gap: 10px;
            }

            .module-index {
                width: 38px;
                height: 38px;
                border-radius: 12px;
            }

            .module-card h3 {
                margin-top: 14px;
                font-size: 1.05rem;
            }

            .module-card p {
                margin-top: 8px;
                min-height: 0;
                font-size: 0.92rem;
                line-height: 1.55;
            }

            .module-card a {
                width: 100%;
                justify-content: center;
                margin-top: 14px;
            }

            .guide-card {
                padding-top: 16px;
                padding-bottom: 16px;
            }

            .guide-card span {
                width: 34px;
                height: 34px;
                border-radius: 11px;
            }

            .guide-card strong {
                font-size: 1rem;
            }

            .guide-card p {
                font-size: 0.9rem;
                line-height: 1.5;
            }

            .metric-item,
            .soft-box,
            .glossary-item,
            .coach-card,
            .plain-card,
            .preview-box {
                padding: 13px;
            }

            .metric-item strong,
            .glossary-item strong,
            .plain-card strong,
            .coach-card strong {
                font-size: 1rem;
            }

            .metric-item p,
            .glossary-item p,
            .coach-card p,
            .plain-card p {
                font-size: 0.9rem;
                line-height: 1.5;
            }

            .chart-grid,
            .gallery,
            .photo-preview {
                gap: 12px;
            }

            canvas {
                min-height: 210px;
                padding: 6px;
                aspect-ratio: 5 / 4;
            }

            .gallery {
                grid-template-columns: 1fr;
            }

            .gallery-card img {
                height: 180px;
            }

            .gallery-body {
                padding: 12px;
                gap: 6px;
            }

            form {
                gap: 14px;
            }

            label {
                gap: 6px;
                font-size: 0.89rem;
            }

            input,
            textarea,
            select {
                padding: 12px 13px;
                font-size: 16px;
            }

            textarea {
                min-height: 90px;
            }

            button,
            .button-link {
                width: 100%;
                min-height: 46px;
                padding: 12px 16px;
                font-size: 0.95rem;
            }

            .panel-note {
                margin-top: 10px;
                padding: 13px;
                font-size: 0.9rem;
            }

            .filter-panel {
                padding: 16px;
            }

            .filter-grid {
                grid-template-columns: 1fr 1fr;
            }

            .filter-grid .wide {
                grid-column: span 2;
            }

            .filter-actions,
            .screen-actions {
                width: 100%;
            }

            .executive-grid,
            .executive-split {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .executive-card {
                padding: 16px;
                border-radius: 18px;
            }

            .step-header {
                grid-template-columns: repeat(3, minmax(180px, 1fr));
                overflow-x: auto;
                padding-bottom: 2px;
                scroll-snap-type: x proximity;
            }

            .step-chip {
                min-width: 180px;
                scroll-snap-align: start;
            }

            .step-actions {
                flex-direction: column-reverse;
                align-items: stretch;
            }

            .mobile-dock {
                position: fixed;
                left: 10px;
                right: 10px;
                bottom: max(10px, env(safe-area-inset-bottom));
                z-index: 130;
                display: grid;
                grid-template-columns: repeat(5, minmax(0, 1fr));
                gap: 8px;
                padding: 10px;
                border: 1px solid rgba(215, 226, 239, 0.96);
                border-radius: 22px;
                background: rgba(255, 255, 255, 0.98);
                box-shadow: 0 18px 34px rgba(8, 25, 55, 0.16);
            }

            .dock-link,
            .dock-button {
                display: grid;
                place-items: center;
                gap: 4px;
                min-height: 56px;
                padding: 8px 6px;
                border-radius: 16px;
                border: 1px solid transparent;
                background: transparent;
                color: var(--muted);
                font: inherit;
                font-size: 0.7rem;
                font-weight: 900;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                box-shadow: none;
            }

            .dock-link.is-active,
            .dock-button.is-active {
                color: var(--blue-deep);
                background: rgba(19, 104, 211, 0.08);
                border-color: rgba(19, 104, 211, 0.12);
            }

            .dock-link strong,
            .dock-button strong {
                font-size: 0.74rem;
            }

            .dock-link span,
            .dock-button span {
                font-size: 0.62rem;
                color: inherit;
            }

            .table-wrap {
                overflow: visible;
            }

            table.responsive-stack {
                min-width: 0;
            }

            table.responsive-stack thead {
                display: none;
            }

            table.responsive-stack tbody {
                display: grid;
                gap: 10px;
            }

            table.responsive-stack tr {
                display: block;
                padding: 12px;
                border: 1px solid var(--line);
                border-radius: 14px;
                background: linear-gradient(180deg, #ffffff, #f8fbff);
                box-shadow: var(--shadow-soft);
            }

            table.responsive-stack td {
                display: grid;
                grid-template-columns: minmax(86px, 102px) 1fr;
                gap: 8px;
                padding: 7px 0;
                border-bottom: 1px dashed rgba(196, 211, 228, 0.9);
                font-size: 0.88rem;
                white-space: normal;
            }

            table.responsive-stack td:last-child {
                border-bottom: 0;
                padding-bottom: 0;
            }

            table.responsive-stack td::before {
                content: attr(data-label);
                color: var(--muted);
                font-size: 0.68rem;
                font-weight: 900;
                letter-spacing: 0.05em;
                text-transform: uppercase;
            }

            .footer-note {
                margin-top: 20px;
                font-size: 0.84rem;
                padding: 0 4px;
            }
        }

        @media (max-width: 420px) {
            .shell {
                width: min(100% - 14px, 1380px);
            }

            .sidebar {
                width: min(90vw, 308px);
            }

            .filter-grid {
                grid-template-columns: 1fr;
            }

            .filter-grid .wide {
                grid-column: auto;
            }

            .filter-chip-row {
                gap: 6px;
            }

            .filter-chip {
                width: 100%;
                justify-content: center;
                font-size: 0.76rem;
            }

            .step-chip {
                min-width: 168px;
            }

            .mobile-dock {
                left: 8px;
                right: 8px;
                gap: 6px;
                padding: 8px;
                border-radius: 18px;
            }

            .dock-link,
            .dock-button {
                min-height: 52px;
                padding: 7px 4px;
            }

            .five-s-vision {
                padding-left: 14px;
                padding-right: 14px;
            }

            .five-s-kicker {
                padding: 7px 11px;
                font-size: 0.68rem;
            }

            .five-s-vision-header h2 {
                font-size: 1.34rem;
            }

            .five-s-step {
                flex-basis: 216px;
            }

            .five-s-icon-shell {
                width: 94px;
                height: 94px;
            }

            .five-s-icon {
                width: 52px;
                height: 52px;
            }

            .mobile-topbar {
                top: max(6px, env(safe-area-inset-top));
                padding: 10px 11px;
                border-radius: 16px;
            }

            .mobile-topbar .brand-mark {
                width: 36px;
                height: 36px;
                border-radius: 12px;
                font-size: 0.82rem;
            }

            .mobile-topbar-logo-card {
                min-width: 78px;
                min-height: 38px;
                padding: 4px 7px;
                border-radius: 12px;
            }

            .mobile-topbar-logo {
                width: 68px;
            }

            .mobile-topbar-copy strong {
                font-size: 0.9rem;
            }

            .mobile-topbar-copy span {
                font-size: 0.74rem;
            }

            .menu-toggle {
                width: 40px;
                height: 40px;
                border-radius: 12px;
            }

            .hero {
                padding-left: 13px;
                padding-right: 13px;
            }

            .hero h1 {
                font-size: 1.72rem;
            }

            .hero p {
                font-size: 0.91rem;
            }

            .eyebrow {
                font-size: 0.66rem;
                padding: 6px 10px;
            }

            .eyebrow-logo {
                width: 72px;
            }

            .score-strip {
                align-items: stretch;
                flex-direction: column;
            }

            .badge {
                justify-content: center;
            }

            .brand-copy strong {
                font-size: 0.93rem;
            }

            .brand-copy span {
                font-size: 0.8rem;
            }

            .sidebar-grid {
                grid-template-columns: 1fr;
            }

            .hero-card,
            .stat-card,
            .module-card,
            .guide-card,
            .panel {
                border-radius: 16px;
            }

            table.responsive-stack td {
                grid-template-columns: 1fr;
                gap: 4px;
            }
        }
    </style>
</head>
<body>
    <div class="mobile-overlay" id="mobile-overlay"></div>
    <div class="shell">
        <div class="layout">
            <aside class="sidebar">
                <div class="sidebar-panel" id="sidebar-panel">
                    <div class="sidebar-brand">
                        <div class="sidebar-brand-main">
                            <?php if ($brandLogoUrl !== null): ?>
                                <div class="sidebar-logo-card">
                                    <img class="sidebar-logo" src="<?= e($brandLogoUrl) ?>" alt="<?= e($brandName) ?>">
                                </div>
                            <?php else: ?>
                                <div class="brand-mark">SF</div>
                            <?php endif; ?>
                            <div class="brand-copy">
                                <strong><?= e($brandTagline) ?></strong>
                                <span><?= e($brandSupportLine) ?></span>
                            </div>
                        </div>
                        <button type="button" class="menu-toggle" id="menu-toggle-close" aria-expanded="false" aria-controls="sidebar-mobile-content" aria-label="Cerrar menu">
                            <span class="menu-toggle-lines" aria-hidden="true">
                                <span></span>
                                <span></span>
                                <span></span>
                            </span>
                        </button>
                    </div>

                    <div class="sidebar-mobile-content" id="sidebar-mobile-content">
                        <span class="sidebar-kicker">Navegacion</span>
                        <nav class="side-nav">
                            <?php foreach ($pageTitles as $view => $meta): ?>
                                <a class="side-link <?= $currentView === $view ? 'active' : '' ?>" href="<?= e(buildViewUrl($view)) ?>">
                                    <span><?= e($meta['title']) ?></span>
                                    <small><?= $currentView === $view ? 'Actual' : 'Ir' ?></small>
                                </a>
                            <?php endforeach; ?>
                        </nav>

                        <span class="sidebar-kicker">Resumen rapido</span>
                        <div class="sidebar-grid">
                            <div class="sidebar-stat">
                                <span>Salud</span>
                                <strong><?= $processScore > 0 ? $processScore . '/100' : 'Sin base' ?></strong>
                            </div>
                            <div class="sidebar-stat">
                                <span>Sigma</span>
                                <strong><?= $hasProcessData ? formatNumber($overallSigma, 2) : 'Sin dato' ?></strong>
                            </div>
                            <div class="sidebar-stat">
                                <span>5S</span>
                                <strong><?= $overallFiveS > 0 ? formatNumber($overallFiveS, 2) . '/5' : 'Sin dato' ?></strong>
                            </div>
                            <div class="sidebar-stat">
                                <span>Proceso</span>
                                <strong><?= e((string) $standards['process_name']) ?></strong>
                            </div>
                        </div>

                        <div class="sidebar-note">
                            <strong style="display:block; color: var(--ink); margin-bottom:6px;">Estado actual</strong>
                            <?= e($assessment['plain_message']) ?>
                        </div>
                    </div>
                </div>
            </aside>

            <main class="content">
                <div class="mobile-topbar">
                    <div class="mobile-topbar-brand">
                        <?php if ($brandLogoUrl !== null): ?>
                            <div class="mobile-topbar-logo-card">
                                <img class="mobile-topbar-logo" src="<?= e($brandLogoUrl) ?>" alt="<?= e($brandName) ?>">
                            </div>
                        <?php else: ?>
                            <div class="brand-mark">SF</div>
                        <?php endif; ?>
                        <div class="mobile-topbar-copy">
                            <strong><?= e($pageMeta['title']) ?></strong>
                            <span><?= e($standards['process_name']) ?></span>
                        </div>
                    </div>
                    <button type="button" class="menu-toggle" id="menu-toggle-mobile" aria-expanded="false" aria-controls="sidebar-mobile-content" aria-label="Abrir menu">
                        <span class="menu-toggle-lines" aria-hidden="true">
                            <span></span>
                            <span></span>
                            <span></span>
                        </span>
                    </button>
                </div>

                <?php if ($messages !== []): ?>
                    <div class="messages success">
                        <ul>
                            <?php foreach ($messages as $message): ?>
                                <li><?= e($message) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <?php if ($errors !== []): ?>
                    <div class="messages error">
                        <ul>
                            <?php foreach ($errors as $error): ?>
                                <li><?= e($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

        <section class="hero">
            <div class="hero-grid">
                <div>
                    <span class="eyebrow">
                        <?php if ($brandLogoUrl !== null): ?>
                            <img class="eyebrow-logo" src="<?= e($brandLogoUrl) ?>" alt="<?= e($brandName) ?>">
                        <?php endif; ?>
                        <span class="eyebrow-text">Control visual + Six Sigma + 5S</span>
                    </span>
                    <h1><?= e($currentView === 'home' ? 'Una planta mas clara, visual y profesional' : $pageMeta['title']) ?></h1>
                    <p><?= e($currentView === 'home'
                        ? 'Esta version fue reforzada para que un principiante pueda entenderla rapido: usa colores claros, mensajes directos, bloques guiados y graficos pro para mostrar sigma, capacidad, produccion y 5S sin abrumar al operario.'
                        : $pageMeta['description']) ?></p>
                    <div class="score-strip">
                        <span class="badge <?= e($scoreMeta['status']) ?>"><span class="dot"></span><?= e($scoreMeta['label']) ?></span>
                        <span class="badge <?= e($status['class']) ?>"><span class="dot"></span><?= e($assessment['headline']) ?></span>
                        <span class="badge neutral"><?= e($standards['process_name']) ?></span>
                    </div>
                </div>
                <div class="hero-stack">
                    <div class="hero-summary">
                        <div class="hero-card">
                            <span>Salud del proceso</span>
                            <div class="score-ring" style="--score: <?= e((string) $processScore) ?>;">
                                <div class="score-ring-inner">
                                    <strong><?= $processScore ?></strong>
                                    <span>de 100</span>
                                </div>
                            </div>
                            <p><?= e($scoreMeta['message']) ?></p>
                        </div>
                        <div class="hero-card">
                            <span>Lectura rapida</span>
                            <strong><?= $hasProcessData ? formatNumber($overallSigma, 2) . ' sigma' : 'Sin base' ?></strong>
                            <p><?= $hasProcessData ? 'Defectos globales: ' . formatNumber($overallDefectPercent, 2, '%') . ' / Rendimiento: ' . formatNumber($overallYield, 2, '%') : 'Registra lotes para activar el analisis del proceso.' ?></p>
                        </div>
                    </div>
                    <div class="hero-summary">
                        <div class="hero-card">
                            <span>Ultimo operario</span>
                            <strong><?= e((string) $latestOperator) ?></strong>
                            <p>Turno: <?= e((string) $latestShift) ?></p>
                        </div>
                        <div class="hero-card">
                            <span>5S general</span>
                            <strong><?= $overallFiveS > 0 ? formatNumber($overallFiveS, 2) . '/5' : 'Sin dato' ?></strong>
                            <p><?= e($overallFiveSStatus['label']) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <?php if (in_array($currentView, ['home', 'five-s'], true)): ?>
            <section class="section">
                <article class="panel five-s-vision">
                    <div class="five-s-vision-header">
                        <div>
                            <span class="five-s-kicker">Ruta visual 5S</span>
                            <h2>Control inteligente de procesos y mejora continua</h2>
                            <p>Esta franja integra las 5S como una secuencia clara para planta: cada paso muestra su funcion y, cuando ya existe una evaluacion, deja ver el puntaje real que el sistema tiene registrado.</p>
                        </div>
                        <div class="five-s-vision-summary">
                            <span>Panorama actual 5S</span>
                            <strong><?= $overallFiveS > 0 ? formatNumber($overallFiveS, 2) . '/5' : 'Sin dato' ?></strong>
                            <p><?= e($overallFiveSStatus['label']) ?><?= $latestFiveS ? ' en ' . e((string) ($latestFiveS['area'] ?? $standards['area'])) : '. Registra una evaluacion para activar esta lectura.' ?></p>
                        </div>
                    </div>
                    <div class="five-s-track">
                        <?php foreach ($fiveSPillars as $pillar): ?>
                            <?php
                            $pillarScore = (float) ($pillar['score'] ?? 0);
                            $pillarStatus = $pillarScore > 0 ? classifyFiveS($pillarScore) : ['status' => 'neutral', 'label' => 'Sin evaluacion'];
                            ?>
                            <article class="five-s-step" style="--pillar: <?= e($pillar['color']) ?>; --pillar-soft: <?= e($pillar['soft']) ?>;">
                                <div class="five-s-icon-shell">
                                    <div class="five-s-icon"><?= renderFiveSIcon((string) $pillar['key']) ?></div>
                                </div>
                                <h3><?= e($pillar['step']) ?>. <?= e($pillar['title']) ?></h3>
                                <p><?= e($pillar['description']) ?></p>
                                <div class="five-s-step-footer">
                                    <span class="badge <?= e($pillarStatus['status']) ?>"><span class="dot"></span><?= e($pillarStatus['label']) ?></span>
                                    <span class="five-s-score"><?= $pillarScore > 0 ? formatNumber($pillarScore, 1) . '/5' : 'Sin dato' ?></span>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </article>
            </section>
        <?php endif; ?>

        <?php if ($currentView === 'home'): ?>
            <section class="section">
                <article class="panel filter-panel">
                    <div class="screen-header">
                        <div>
                            <h2>Dashboard ejecutivo con filtros</h2>
                            <p>Filtra la operación por fecha, turno, operario y área. Todo el dashboard, las gráficas, el historial y los reportes se actualizan con esta misma selección.</p>
                        </div>
                        <div class="screen-actions">
                            <a class="ghost-button" href="<?= e(buildViewUrl('home', ['export' => 'excel'])) ?>">Exportar Excel</a>
                            <a class="ghost-button" href="<?= e(buildViewUrl('home', ['export' => 'pdf'])) ?>">Reporte PDF</a>
                        </div>
                    </div>
                    <form method="get" action="">
                        <input type="hidden" name="view" value="home">
                        <div class="filter-grid">
                            <label>
                                Rango rapido
                                <select name="range_filter">
                                    <option value="7d" <?= $rangeFilter === '7d' ? 'selected' : '' ?>>Ultimos 7 dias</option>
                                    <option value="30d" <?= $rangeFilter === '30d' ? 'selected' : '' ?>>Ultimos 30 dias</option>
                                    <option value="90d" <?= $rangeFilter === '90d' ? 'selected' : '' ?>>Ultimos 90 dias</option>
                                    <option value="all" <?= $rangeFilter === 'all' ? 'selected' : '' ?>>Todo el historial</option>
                                </select>
                            </label>
                            <label>
                                Fecha inicial
                                <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
                            </label>
                            <label>
                                Fecha final
                                <input type="date" name="date_to" value="<?= e($dateTo) ?>">
                            </label>
                            <label>
                                Turno
                                <select name="shift_filter">
                                    <option value="">Todos</option>
                                    <?php foreach ($shiftOptions as $option): ?>
                                        <option value="<?= e($option) ?>" <?= $shiftFilter === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="wide">
                                Operario o responsable
                                <input type="text" name="operator_filter" list="operator-options" value="<?= e($operatorFilter) ?>" placeholder="Ejemplo: Juan o Maria">
                                <datalist id="operator-options">
                                    <?php foreach ($operatorOptions as $option): ?>
                                        <option value="<?= e($option) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </label>
                            <label class="wide">
                                Area
                                <select name="area_filter">
                                    <option value="">Todas</option>
                                    <?php foreach ($areaOptions as $option): ?>
                                        <option value="<?= e($option) ?>" <?= $areaFilter === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        </div>
                        <div class="filter-actions">
                            <button type="submit">Aplicar filtros</button>
                            <a class="ghost-button" href="<?= e(buildViewUrl('home', ['range_filter' => '30d', 'date_from' => null, 'date_to' => null, 'shift_filter' => null, 'operator_filter' => null, 'area_filter' => null])) ?>">Limpiar filtros</a>
                        </div>
                        <?php if ($activeFilterBadges !== []): ?>
                            <div class="filter-chip-row">
                                <?php foreach ($activeFilterBadges as $filterBadge): ?>
                                    <span class="filter-chip"><?= e($filterBadge) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </form>
                </article>
            </section>

            <section class="section">
                <div class="screen-header">
                    <div>
                        <h2>Resumen ejecutivo</h2>
                        <p>Lectura de supervisión con foco en volumen, criticidad y desempeño del turno más activo.</p>
                    </div>
                    <span class="badge <?= e($scoreMeta['status']) ?>"><span class="dot"></span><?= e($scoreMeta['label']) ?></span>
                </div>
                <div class="executive-grid">
                    <?php foreach ($executiveCards as $card): ?>
                        <article class="executive-card">
                            <span><?= e($card['title']) ?></span>
                            <strong><?= e($card['value']) ?></strong>
                            <p><?= e($card['text']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="section executive-split">
                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Punto rojo del periodo</h2>
                            <p>Identifica rápidamente dónde conviene intervenir primero dentro del filtro activo.</p>
                        </div>
                        <span class="badge <?= e($criticalLotsCount > 0 ? 'danger' : 'success') ?>"><span class="dot"></span><?= e($criticalLotsCount > 0 ? 'Hay lotes criticos' : 'Sin lotes criticos') ?></span>
                    </div>
                    <div class="summary-list">
                        <div class="summary-list-item">
                            <strong><?= e($topIssueName) ?></strong>
                            <p><?= $topIssueCount > 0 ? e('Se repitio en ' . $topIssueCount . ' lote(s) dentro del filtro.') : 'No hay una falla dominante en esta seleccion.' ?></p>
                        </div>
                        <div class="summary-list-item">
                            <strong><?= e($worstProcessLot ? ((string) ($worstProcessLot['operator'] ?? 'Sin operario')) : 'Sin lote critico') ?></strong>
                            <p><?= $worstProcessLot && $worstProcessLotMeta ? e(formatDateTime((string) ($worstProcessLot['timestamp'] ?? '')) . ' · ' . ($worstProcessLotMeta['issues'] !== [] ? implode(', ', $worstProcessLotMeta['issues']) : 'Sin desvio mayor')) : 'Cuando existan lotes fuera de rango, apareceran aqui.' ?></p>
                        </div>
                        <div class="summary-list-item">
                            <strong><?= e($assessment['actions'][0] ?? 'Seguir monitoreo') ?></strong>
                            <p><?= e($assessment['requiredData'][0] ?? 'No se requiere dato extra en este momento.') ?></p>
                        </div>
                    </div>
                </article>
                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Ritmo por turnos</h2>
                            <p>Comparación corta del desempeño operativo para apoyar decisiones de supervisión.</p>
                        </div>
                    </div>
                    <div class="trend-list">
                        <?php if ($shiftBreakdown !== []): ?>
                            <?php foreach (array_slice($shiftBreakdown, 0, 3) as $shiftItem): ?>
                                <div class="trend-item">
                                    <strong><?= e((string) $shiftItem['shift']) ?></strong>
                                    <p><?= e((string) $shiftItem['count']) ?> lotes · <?= e(number_format((int) $shiftItem['produced'], 0, ',', '.')) ?> unidades · <?= e(formatNumber((int) $shiftItem['produced'] > 0 ? ((int) $shiftItem['defects'] / (int) $shiftItem['produced']) * 100 : 0, 2, '%')) ?> defectos</p>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="trend-item">
                                <strong>Sin turnos filtrados</strong>
                                <p>Ajusta fechas o limpia filtros para ver actividad operativa.</p>
                            </div>
                        <?php endif; ?>
                        <div class="trend-item">
                            <strong><?= e($leadShiftLabel) ?></strong>
                            <p><?= $leadShiftCount > 0 ? e('Turno dominante con ' . $leadShiftCount . ' lotes y ' . formatNumber($leadShiftDefectPercent, 2, '%') . ' de defectos.') : 'Todavia no hay una referencia dominante para esta consulta.' ?></p>
                        </div>
                    </div>
                </article>
            </section>

            <section class="section">
                <div class="screen-header">
                    <div>
                        <h2>Como usar esta plataforma</h2>
                        <p>La interfaz esta organizada para que cualquier persona entre, entienda que debe hacer y vea resultados sin pelear con terminos tecnicos.</p>
                    </div>
                </div>
                <div class="grid-4">
                    <?php foreach ($onboardingSteps as $step): ?>
                        <article class="guide-card">
                            <span><?= e($step['step']) ?></span>
                            <strong><?= e($step['title']) ?></strong>
                            <p><?= e($step['text']) ?></p>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="section">
                <div class="screen-header">
                    <div>
                        <h2>Modulos del sistema</h2>
                        <p>Cada modulo tiene una funcion clara. Asi evitamos una pantalla enredada y la experiencia se siente mas profesional y guiada.</p>
                    </div>
                </div>
                <div class="grid-4">
                    <?php foreach ($moduleCards as $index => $card): ?>
                        <article class="module-card <?= e($card['accent']) ?>">
                            <div class="module-top">
                                <div class="module-index"><?= $index + 1 ?></div>
                                <span class="badge neutral"><?= e($card['status']) ?></span>
                            </div>
                            <h3><?= e($card['title']) ?></h3>
                            <p><?= e($card['description']) ?></p>
                            <a href="<?= e(buildViewUrl($card['view'])) ?>">Abrir modulo</a>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="section grid-4">
                <article class="stat-card">
                    <small>Salud del proceso</small>
                    <strong><?= $processScore > 0 ? $processScore . '/100' : 'Sin dato' ?></strong>
                    <p><?= e($scoreMeta['message']) ?></p>
                </article>
                <article class="stat-card">
                    <small>Nivel sigma</small>
                    <strong><?= $hasProcessData ? formatNumber($overallSigma, 2) : 'Sin dato' ?></strong>
                    <p><?= $hasProcessData ? e(classifySigma($overallSigma)) . ' segun el total de defectos.' : 'Necesitas registros para calcular sigma.' ?></p>
                </article>
                <article class="stat-card">
                    <small>Tamano de muestra</small>
                    <strong><?= $sampleSize ?></strong>
                    <p><?= $sampleSize > 0 ? 'Cantidad de lotes usados en el analisis estadistico.' : 'Todavia no hay lotes almacenados.' ?></p>
                </article>
                <article class="stat-card">
                    <small>5S promedio</small>
                    <strong><?= $overallFiveS > 0 ? formatNumber($overallFiveS, 2) . '/5' : 'Sin dato' ?></strong>
                    <p><?= e($overallFiveSStatus['label']) ?> con evidencia visual del area.</p>
                </article>
            </section>

            <section class="section grid-3">
                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Semaforo del proceso</h2>
                            <p>Una lectura simple que resume si la planta va bien, necesita atencion o requiere accion inmediata.</p>
                        </div>
                        <span class="badge <?= e($status['class']) ?>"><span class="dot"></span><?= e($status['label']) ?></span>
                    </div>
                    <div class="plain-card">
                        <strong><?= e($assessment['headline']) ?></strong>
                        <p><?= e($assessment['plain_message']) ?></p>
                    </div>
                    <div class="mini-grid" style="margin-top: 14px;">
                        <div class="metric-item">
                            <span>Ultimo lote</span>
                            <strong><?= $latestRecord ? e(formatDateTime((string) $latestRecord['timestamp'])) : 'Sin registro' ?></strong>
                        </div>
                        <div class="metric-item">
                            <span>Observacion reciente</span>
                            <strong><?= e((string) $lastObservation) ?></strong>
                        </div>
                        <div class="metric-item">
                            <span>Meta cumplida</span>
                            <strong><?= $latestRecord ? formatNumber($targetCompliance, 1, '%') : 'Sin dato' ?></strong>
                            <div class="progress"><span style="width: <?= e((string) round($targetCompliance, 1)) ?>%"></span></div>
                        </div>
                        <div class="metric-item">
                            <span>Defectos del ultimo lote</span>
                            <strong><?= $latestRecord ? formatNumber($latestDefectPercent, 2, '%') : 'Sin dato' ?></strong>
                        </div>
                    </div>
                </article>
                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Coach del sistema</h2>
                            <p>Mensajes redactados para que hasta un principiante sepa que mirar primero.</p>
                        </div>
                    </div>
                    <div class="coach-grid">
                        <?php foreach ($coachCards as $card): ?>
                            <div class="coach-card">
                                <strong><?= e($card['title']) ?></strong>
                                <p><?= e($card['text']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Glosario rapido</h2>
                            <p>Conceptos traducidos a lenguaje simple para no perderse.</p>
                        </div>
                    </div>
                    <div class="glossary-grid">
                        <?php foreach ($glossary as $item): ?>
                            <div class="glossary-item">
                                <strong><?= e($item['term']) ?></strong>
                                <p><?= e($item['meaning']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            </section>

            <section class="section chart-grid">
                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Grafico maestro del proceso</h2>
                            <p>Muestra el cumplimiento del estandar de temperatura y tiempo en los ultimos lotes, para comparar ambos sin mezclar unidades distintas.</p>
                        </div>
                    </div>
                    <canvas id="overview-chart" width="620" height="300"></canvas>
                </article>
                <article class="panel">
                    <div class="panel-header">
                        <div>
                            <h2>Radar de 5S</h2>
                            <p>Visualiza orden, limpieza y disciplina del area de un solo vistazo.</p>
                        </div>
                    </div>
                    <canvas id="fives-radar" width="620" height="300"></canvas>
                </article>
            </section>
        <?php endif; ?>

        <?php if ($currentView === 'standards'): ?>
            <section class="section">
                <div class="screen-header">
                    <div>
                        <h2>Definir CTQ del proceso</h2>
                        <p>Este modulo deja claros los estandares base de la planta. Si el CTQ esta bien definido, el resto del sistema puede orientar mucho mejor al operario.</p>
                    </div>
                    <span class="badge neutral"><?= $standards['updated_at'] ? 'Actualizado ' . e(formatDateTime($standards['updated_at'])) : 'Base inicial' ?></span>
                </div>
                <div class="grid-2">
                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Formulario CTQ</h3>
                                <p>Llena los limites del proceso con el mismo lenguaje que se usa en planta.</p>
                            </div>
                        </div>
                        <form method="post" action="<?= e(buildViewUrl('standards')) ?>">
                            <input type="hidden" name="action" value="save_standards">
                            <input type="hidden" name="return_view" value="standards">
                            <div class="field-grid">
                                <label>
                                    Nombre del proceso
                                    <input type="text" name="process_name" value="<?= e((string) $standards['process_name']) ?>" required>
                                </label>
                                <label>
                                    Area de trabajo
                                    <input type="text" name="area" value="<?= e((string) $standards['area']) ?>" required>
                                </label>
                            </div>
                            <div class="field-grid">
                                <label>
                                    Temperatura minima (C)
                                    <input type="number" name="temp_min" step="0.01" value="<?= e((string) $standards['temp_min']) ?>" required>
                                </label>
                                <label>
                                    Temperatura maxima (C)
                                    <input type="number" name="temp_max" step="0.01" value="<?= e((string) $standards['temp_max']) ?>" required>
                                </label>
                            </div>
                            <div class="field-grid">
                                <label>
                                    Tiempo minimo (min)
                                    <input type="number" name="time_min" step="0.01" value="<?= e((string) $standards['time_min']) ?>" required>
                                </label>
                                <label>
                                    Tiempo maximo (min)
                                    <input type="number" name="time_max" step="0.01" value="<?= e((string) $standards['time_max']) ?>" required>
                                </label>
                            </div>
                            <div class="field-grid">
                                <label>
                                    Meta de produccion
                                    <input type="number" name="target_output" min="1" value="<?= e((string) $standards['target_output']) ?>" required>
                                </label>
                                <label>
                                    Limite de defectos (%)
                                    <input type="number" name="max_defect_rate" step="0.01" min="0" value="<?= e((string) $standards['max_defect_rate']) ?>" required>
                                </label>
                            </div>
                            <label>
                                Notas del proceso
                                <textarea name="notes"><?= e((string) $standards['notes']) ?></textarea>
                            </label>
                            <div class="panel-note">
                                Consejo: si no tienes un valor exacto, empieza con el rango operativo que hoy considera bueno tu supervisor. Luego el sistema te ayudara a refinarlo.
                            </div>
                            <button type="submit">Guardar CTQ</button>
                        </form>
                    </article>

                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Explicacion para principiantes</h3>
                                <p>Que significa cada campo y por que importa.</p>
                            </div>
                        </div>
                        <div class="glossary-grid">
                            <?php foreach ($ctqGuide as $item): ?>
                                <div class="glossary-item">
                                    <strong><?= e($item['title']) ?></strong>
                                    <p><?= e($item['text']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="panel-note" style="margin-top: 14px;">
                            El sistema comparara cada lote contra este CTQ y, si algo sale mal, te dira automaticamente que dato extra pedir, que revisar y que accion tomar.
                        </div>
                        <div class="mini-grid" style="margin-top: 14px;">
                            <div class="metric-item">
                                <span>Rango de temperatura</span>
                                <strong><?= formatNumber((float) $standards['temp_min'], 2, ' C') ?> a <?= formatNumber((float) $standards['temp_max'], 2, ' C') ?></strong>
                            </div>
                            <div class="metric-item">
                                <span>Rango de tiempo</span>
                                <strong><?= formatNumber((float) $standards['time_min'], 2, ' min') ?> a <?= formatNumber((float) $standards['time_max'], 2, ' min') ?></strong>
                            </div>
                            <div class="metric-item">
                                <span>Meta de lote</span>
                                <strong><?= (int) $standards['target_output'] ?> unidades</strong>
                            </div>
                            <div class="metric-item">
                                <span>Defecto maximo</span>
                                <strong><?= formatNumber((float) $standards['max_defect_rate'], 2, '%') ?></strong>
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($currentView === 'process'): ?>
            <section class="section">
                <div class="screen-header">
                    <div>
                        <h2>Captura de lote super clara</h2>
                        <p>La idea aqui es que el operario no piense en formulas. Solo registra el lote y la pantalla le traduce el estado de la produccion en palabras y graficos.</p>
                    </div>
                    <span class="badge <?= e($status['class']) ?>"><span class="dot"></span><?= e($assessment['headline']) ?></span>
                </div>
                <div class="grid-2">
                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Formulario del lote</h3>
                                <p>Ahora funciona como flujo guiado: contexto, mediciones y cierre del lote.</p>
                            </div>
                        </div>
                        <form method="post" id="process-form" class="step-form" data-step-form action="<?= e(buildViewUrl('process')) ?>">
                            <input type="hidden" name="action" value="save_process">
                            <input type="hidden" name="return_view" value="process">
                            <div class="step-header">
                                <div class="step-chip is-active" data-step-jump="0">
                                    <span class="step-chip-index">1</span>
                                    <strong>Contexto</strong>
                                    <span>Area, operario y turno del lote.</span>
                                </div>
                                <div class="step-chip" data-step-jump="1">
                                    <span class="step-chip-index">2</span>
                                    <strong>Mediciones</strong>
                                    <span>Temperatura, tiempo, produccion y defectos.</span>
                                </div>
                                <div class="step-chip" data-step-jump="2">
                                    <span class="step-chip-index">3</span>
                                    <strong>Cierre</strong>
                                    <span>Observaciones y lectura previa antes de guardar.</span>
                                </div>
                            </div>
                            <div class="step-pane is-active" data-step-pane>
                                <div class="field-grid">
                                    <label>
                                        Area del lote
                                        <input type="text" name="process_area" value="<?= e((string) ($standards['area'] ?? '')) ?>" required>
                                    </label>
                                    <label>
                                        Operario responsable
                                        <input type="text" name="operator" required>
                                    </label>
                                </div>
                                <div class="field-grid">
                                    <label>
                                        Turno
                                        <select name="shift" required>
                                            <option value="">Selecciona</option>
                                            <option value="Manana">Manana</option>
                                            <option value="Tarde">Tarde</option>
                                            <option value="Noche">Noche</option>
                                        </select>
                                    </label>
                                    <label>
                                        Meta visual del lote
                                        <input type="text" value="<?= e((string) $standards['target_output']) ?> unidades esperadas" readonly>
                                    </label>
                                </div>
                                <div class="step-actions">
                                    <span class="grow"></span>
                                    <button type="button" data-step-next>Continuar con mediciones</button>
                                </div>
                            </div>
                            <div class="step-pane" data-step-pane>
                                <div class="field-grid">
                                    <label>
                                        Temperatura actual (C)
                                        <input type="number" id="temperature" name="temperature" step="0.01" required>
                                    </label>
                                    <label>
                                        Tiempo actual (min)
                                        <input type="number" id="time_minutes" name="time_minutes" step="0.01" required>
                                    </label>
                                </div>
                                <div class="field-grid">
                                    <label>
                                        Cantidad producida
                                        <input type="number" id="produced_qty" name="produced_qty" min="1" required>
                                    </label>
                                    <label>
                                        Producto defectuoso
                                        <input type="number" id="defective_qty" name="defective_qty" min="0" required>
                                    </label>
                                </div>
                                <div class="step-actions">
                                    <button type="button" class="ghost-button" data-step-prev>Volver</button>
                                    <button type="button" data-step-next>Ir al cierre del lote</button>
                                </div>
                            </div>
                            <div class="step-pane" data-step-pane>
                                <label>
                                    Observaciones del lote
                                    <textarea name="observations" placeholder="Ejemplo: paro corto, cambio de materia prima, limpieza, ajuste de equipo."></textarea>
                                </label>
                                <div class="preview-box" id="live-preview">
                                    <strong>Lectura previa del lote</strong>
                                    <p style="margin: 8px 0 0; color: var(--muted);">Completa los campos y veras la lectura previa del sistema antes de guardar.</p>
                                </div>
                                <div class="step-actions">
                                    <button type="button" class="ghost-button" data-step-prev>Volver a mediciones</button>
                                    <button type="submit">Guardar lote y analizar</button>
                                </div>
                            </div>
                        </form>
                    </article>

                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Lectura inmediata del sistema</h3>
                                <p>Bloques faciles de leer para saber donde esta el riesgo.</p>
                            </div>
                        </div>
                        <div class="grid-3">
                            <div class="metric-item">
                                <span>Temperatura</span>
                                <strong><?= $latestRecord ? formatNumber((float) $latestRecord['temperature'], 2, ' C') : 'Sin dato' ?></strong>
                                <p><?= e($latestTempMeta['message']) ?></p>
                            </div>
                            <div class="metric-item">
                                <span>Tiempo</span>
                                <strong><?= $latestRecord ? formatNumber((float) $latestRecord['time_minutes'], 2, ' min') : 'Sin dato' ?></strong>
                                <p><?= e($latestTimeMeta['message']) ?></p>
                            </div>
                            <div class="metric-item">
                                <span>Produccion</span>
                                <strong><?= $latestRecord ? (int) $latestRecord['produced_qty'] . ' u' : 'Sin dato' ?></strong>
                                <p><?= e($productionMeta['message']) ?></p>
                            </div>
                        </div>
                        <div class="plain-card" style="margin-top: 14px;">
                            <strong><?= e($assessment['headline']) ?></strong>
                            <p><?= e($assessment['plain_message']) ?></p>
                        </div>
                        <div class="panel-note">
                            Si el color cambia a amarillo o rojo, baja a los bloques de acciones y datos requeridos. La pantalla esta pensada para guiar al usuario sin experiencia.
                        </div>
                        <div class="mini-grid" style="margin-top: 14px;">
                            <div class="metric-item">
                                <span>Primera accion</span>
                                <strong><?= e($assessment['actions'][0] ?? 'Sin accion aun') ?></strong>
                            </div>
                            <div class="metric-item">
                                <span>Dato a pedir</span>
                                <strong><?= e($assessment['requiredData'][0] ?? 'Sin dato extra aun') ?></strong>
                            </div>
                        </div>
                    </article>
                </div>

                <div class="section chart-grid">
                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Comparacion del ultimo lote</h3>
                                <p>Compara el cumplimiento del ultimo lote frente al objetivo ideal de cada indicador.</p>
                            </div>
                        </div>
                        <canvas id="comparison-chart" width="620" height="300"></canvas>
                    </article>
                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Lo que hara el sistema despues de guardar</h3>
                                <p>Resumen simple del flujo automatico.</p>
                            </div>
                        </div>
                        <div class="coach-grid">
                            <div class="coach-card">
                                <strong>1. Compara contra el CTQ</strong>
                                <p>Revisa si temperatura, tiempo, produccion y defectos caen dentro del estandar.</p>
                            </div>
                            <div class="coach-card">
                                <strong>2. Calcula variacion y sigma</strong>
                                <p>Actualiza desviacion, sigma, limites de control y capacidad del proceso.</p>
                            </div>
                            <div class="coach-card">
                                <strong>3. Traduce a acciones</strong>
                                <p>Convierte los numeros en mensajes simples, alertas y datos que faltan por pedir.</p>
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($currentView === 'capability'): ?>
            <section class="section">
                <div class="screen-header">
                    <div>
                        <h2>Estudio de capacidad profesional</h2>
                        <p>Esta vista junta los indicadores estadisticos con explicaciones visuales para que no se sienta como un software complejo. Aqui ves rendimiento, estabilidad y capacidad real del proceso.</p>
                    </div>
                    <span class="badge <?= e($scoreMeta['status']) ?>"><span class="dot"></span><?= e($scoreMeta['label']) ?></span>
                </div>
                <div class="grid-4">
                    <article class="stat-card">
                        <small>Nivel sigma</small>
                        <strong><?= $hasProcessData ? formatNumber($overallSigma, 2) : 'Sin dato' ?></strong>
                        <p><?= $hasProcessData ? e(classifySigma($overallSigma)) . ' segun el total de defectos registrados.' : 'Necesitas lotes para calcularlo.' ?></p>
                    </article>
                    <article class="stat-card">
                        <small>Rendimiento global</small>
                        <strong><?= $hasProcessData ? formatNumber($overallYield, 2, '%') : 'Sin dato' ?></strong>
                        <p><?= $hasProcessData ? 'Porcentaje bueno frente al total producido.' : 'Aun no hay produccion almacenada.' ?></p>
                    </article>
                    <article class="stat-card">
                        <small>Capacidad temperatura</small>
                        <strong><?= $tempCpk !== null ? formatNumber($tempCpk, 2) : 'Sin dato' ?></strong>
                        <p><?= e($tempCapability['message']) ?></p>
                    </article>
                    <article class="stat-card">
                        <small>Capacidad tiempo</small>
                        <strong><?= $timeCpk !== null ? formatNumber($timeCpk, 2) : 'Sin dato' ?></strong>
                        <p><?= e($timeCapability['message']) ?></p>
                    </article>
                </div>

                <div class="section chart-grid">
                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Cumplimiento de temperatura y tiempo</h3>
                                <p>Los dos indicadores se transforman a una escala comun de 0 a 100 para que un principiante los lea sin confusion.</p>
                            </div>
                        </div>
                        <canvas id="capability-trend-chart" width="620" height="300"></canvas>
                    </article>
                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Defectos por lote</h3>
                                <p>Visual profesional para ver cuando los defectos se acercan o superan el limite.</p>
                            </div>
                        </div>
                        <canvas id="defect-chart" width="620" height="300"></canvas>
                    </article>
                </div>

                <div class="section chart-grid">
                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Barras de capacidad</h3>
                                <p>Cp y Cpk de temperatura y tiempo contra el umbral recomendado.</p>
                            </div>
                        </div>
                        <canvas id="capability-bars" width="620" height="300"></canvas>
                    </article>
                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Gauge del proceso</h3>
                                <p>Grafico tipo tablero para leer la salud general del proceso.</p>
                            </div>
                        </div>
                        <canvas id="health-gauge" width="620" height="300"></canvas>
                    </article>
                </div>

                <div class="section grid-2">
                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Interpretacion sencilla</h3>
                                <p>Esto significa el analisis, sin hablar como software estadistico pesado.</p>
                            </div>
                        </div>
                        <div class="coach-grid">
                            <div class="coach-card">
                                <strong>Temperatura</strong>
                                <p><?= e($tempCapability['message']) ?></p>
                            </div>
                            <div class="coach-card">
                                <strong>Tiempo</strong>
                                <p><?= e($timeCapability['message']) ?></p>
                            </div>
                            <div class="coach-card">
                                <strong>Defectos</strong>
                                <p><?= $hasProcessData ? 'El proceso lleva ' . formatNumber($overallDefectPercent, 2, '%') . ' de defectos globales frente a un maximo de ' . formatNumber((float) $standards['max_defect_rate'], 2, '%') . '.' : 'Aun no hay defectos suficientes para interpretar.' ?></p>
                            </div>
                        </div>
                    </article>
                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Alertas y acciones</h3>
                                <p>Lo mas importante del analisis resumido en dos listas cortas.</p>
                            </div>
                        </div>
                        <div class="grid-2">
                            <div>
                                <strong style="display:block; margin-bottom:10px;">Alertas</strong>
                                <ul class="checklist">
                                    <?php foreach ($assessment['alerts'] as $alert): ?>
                                        <li><?= e($alert) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div>
                                <strong style="display:block; margin-bottom:10px;">Acciones</strong>
                                <ul class="checklist">
                                    <?php foreach ($assessment['actions'] as $action): ?>
                                        <li><?= e($action) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </article>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($currentView === 'five-s'): ?>
            <section class="section">
                <div class="screen-header">
                    <div>
                        <h2>5S visual y profesional</h2>
                        <p>Este modulo ya no es solo una lista. Ahora se acompana con radar, lectura visual, galeria y explicaciones para que la mejora continua se vea mas seria y convincente.</p>
                    </div>
                    <span class="badge <?= e($latestFiveSStatus['status']) ?>"><span class="dot"></span><?= e($latestFiveSStatus['label']) ?></span>
                </div>

                <div class="grid-2">
                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Registrar evaluacion 5S</h3>
                                <p>Flujo guiado por pasos para registrar contexto, puntuacion y cierre visual.</p>
                            </div>
                        </div>
                        <form method="post" enctype="multipart/form-data" class="step-form" data-step-form action="<?= e(buildViewUrl('five-s')) ?>">
                            <input type="hidden" name="action" value="save_fives">
                            <input type="hidden" name="return_view" value="five-s">
                            <div class="step-header">
                                <div class="step-chip is-active" data-step-jump="0">
                                    <span class="step-chip-index">1</span>
                                    <strong>Contexto</strong>
                                    <span>Area, responsable y evidencia fotografica.</span>
                                </div>
                                <div class="step-chip" data-step-jump="1">
                                    <span class="step-chip-index">2</span>
                                    <strong>Calificacion</strong>
                                    <span>Puntua cada S con lenguaje simple.</span>
                                </div>
                                <div class="step-chip" data-step-jump="2">
                                    <span class="step-chip-index">3</span>
                                    <strong>Cierre</strong>
                                    <span>Compromisos finales y revision visual.</span>
                                </div>
                            </div>
                            <div class="step-pane is-active" data-step-pane>
                                <div class="field-grid">
                                    <label>
                                        Area evaluada
                                        <input type="text" name="five_s_area" value="<?= e((string) ($standards['area'] ?? '')) ?>" required>
                                    </label>
                                    <label>
                                        Responsable
                                        <input type="text" name="responsible" required>
                                    </label>
                                </div>
                                <label>
                                    Fotos del area
                                    <input type="file" name="five_s_photos[]" id="five_s_photos" accept="image/*" multiple>
                                </label>
                                <div class="step-actions">
                                    <span class="grow"></span>
                                    <button type="button" data-step-next>Continuar con la calificacion</button>
                                </div>
                            </div>
                            <div class="step-pane" data-step-pane>
                                <div class="field-grid-3">
                                    <label>
                                        Clasificar
                                        <select name="seiri" required>
                                            <option value="1">1 - Muy deficiente</option>
                                            <option value="2">2 - Bajo</option>
                                            <option value="3" selected>3 - Aceptable</option>
                                            <option value="4">4 - Bueno</option>
                                            <option value="5">5 - Excelente</option>
                                        </select>
                                    </label>
                                    <label>
                                        Ordenar
                                        <select name="seiton" required>
                                            <option value="1">1 - Muy deficiente</option>
                                            <option value="2">2 - Bajo</option>
                                            <option value="3" selected>3 - Aceptable</option>
                                            <option value="4">4 - Bueno</option>
                                            <option value="5">5 - Excelente</option>
                                        </select>
                                    </label>
                                    <label>
                                        Limpiar
                                        <select name="seiso" required>
                                            <option value="1">1 - Muy deficiente</option>
                                            <option value="2">2 - Bajo</option>
                                            <option value="3" selected>3 - Aceptable</option>
                                            <option value="4">4 - Bueno</option>
                                            <option value="5">5 - Excelente</option>
                                        </select>
                                    </label>
                                    <label>
                                        Estandarizar
                                        <select name="seiketsu" required>
                                            <option value="1">1 - Muy deficiente</option>
                                            <option value="2">2 - Bajo</option>
                                            <option value="3" selected>3 - Aceptable</option>
                                            <option value="4">4 - Bueno</option>
                                            <option value="5">5 - Excelente</option>
                                        </select>
                                    </label>
                                    <label>
                                        Disciplina
                                        <select name="shitsuke" required>
                                            <option value="1">1 - Muy deficiente</option>
                                            <option value="2">2 - Bajo</option>
                                            <option value="3" selected>3 - Aceptable</option>
                                            <option value="4">4 - Bueno</option>
                                            <option value="5">5 - Excelente</option>
                                        </select>
                                    </label>
                                    <label>
                                        Escala guia
                                        <input type="text" value="1-2 critico / 3 aceptable / 4-5 fuerte" readonly>
                                    </label>
                                </div>
                                <div class="step-actions">
                                    <button type="button" class="ghost-button" data-step-prev>Volver</button>
                                    <button type="button" data-step-next>Ir al cierre 5S</button>
                                </div>
                            </div>
                            <div class="step-pane" data-step-pane>
                                <label>
                                    Hallazgos o compromisos
                                    <textarea name="five_s_notes" placeholder="Ejemplo: retirar objetos innecesarios, remarcar zonas, reforzar limpieza final, mejorar disciplina del turno."></textarea>
                                </label>
                                <div class="preview-box">
                                    <strong>Vista previa de evidencias</strong>
                                    <div class="photo-preview" id="photo-preview">
                                        <span style="color: var(--muted);">Selecciona una o varias fotos para verlas antes de guardar.</span>
                                    </div>
                                </div>
                                <div class="step-actions">
                                    <button type="button" class="ghost-button" data-step-prev>Volver a la calificacion</button>
                                    <button type="submit" class="secondary">Guardar evaluacion 5S</button>
                                </div>
                            </div>
                        </form>
                    </article>

                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Lectura visual del area</h3>
                                <p>Radar, score y guia de interpretacion para la ultima evaluacion.</p>
                            </div>
                        </div>
                        <canvas id="fives-radar-detail" width="620" height="300"></canvas>
                        <div class="mini-grid" style="margin-top: 14px;">
                            <div class="metric-item">
                                <span>Promedio 5S general</span>
                                <strong><?= $overallFiveS > 0 ? formatNumber($overallFiveS, 2) . '/5' : 'Sin dato' ?></strong>
                                <p><?= e($overallFiveSStatus['label']) ?></p>
                            </div>
                            <div class="metric-item">
                                <span>Ultima evaluacion</span>
                                <strong><?= $latestFiveS ? e(formatDateTime((string) $latestFiveS['timestamp'])) : 'Sin registro' ?></strong>
                                <p><?= e((string) $lastFiveSNotes) ?></p>
                            </div>
                        </div>
                        <div class="panel-note">
                            Escala simple: 1 y 2 significan zona critica; 3 es aceptable pero inestable; 4 y 5 representan un area ordenada, limpia y disciplinada.
                        </div>
                    </article>
                </div>

                <div class="section panel">
                    <div class="panel-header">
                        <div>
                            <h3>Galeria de evidencias 5S</h3>
                            <p>Comparacion visual de las areas registradas con su puntaje y comentario.</p>
                        </div>
                    </div>
                    <?php if ($recentFiveSRecords !== []): ?>
                        <div class="gallery">
                            <?php foreach ($recentFiveSRecords as $record): ?>
                                <?php
                                $recordStatus = classifyFiveS((float) ($record['average'] ?? 0));
                                $firstPhoto = $record['photos'][0] ?? null;
                                ?>
                                <article class="gallery-card">
                                    <?php if ($firstPhoto): ?>
                                        <img src="<?= e((string) $firstPhoto) ?>" alt="Evidencia 5S de <?= e((string) $record['area']) ?>">
                                    <?php else: ?>
                                        <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 800 500'%3E%3Crect width='800' height='500' fill='%23eef5fb'/%3E%3Ctext x='50%25' y='50%25' fill='%235f7288' font-size='36' font-family='Segoe UI' text-anchor='middle'%3ESin foto cargada%3C/text%3E%3C/svg%3E" alt="Sin foto">
                                    <?php endif; ?>
                                    <div class="gallery-body">
                                        <span class="badge <?= e($recordStatus['status']) ?>"><span class="dot"></span><?= e($recordStatus['label']) ?></span>
                                        <strong><?= e((string) $record['area']) ?></strong>
                                        <span style="color: var(--muted);"><?= e(formatDateTime((string) $record['timestamp'])) ?> · <?= e((string) $record['responsible']) ?></span>
                                        <span>Puntaje <?= formatNumber((float) ($record['average'] ?? 0), 2) ?>/5</span>
                                        <span style="color: var(--muted);"><?= e((string) ($record['notes'] ?? 'Sin observaciones.')) ?></span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty">Todavia no hay evaluaciones 5S guardadas.</div>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($currentView === 'history'): ?>
            <section class="section">
                <div class="screen-header">
                    <div>
                        <h2>Historial completo</h2>
                        <p>Consulta lotes, responsables y evaluaciones de orden sin tener que buscar en archivos por separado.</p>
                    </div>
                </div>
                <div class="grid-2">
                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Lotes recientes</h3>
                                <p>Se muestran las ultimas capturas guardadas por el sistema.</p>
                            </div>
                        </div>
                        <?php if ($recentProcessRecords !== []): ?>
                            <div class="table-wrap">
                                <table class="responsive-stack">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Area</th>
                                            <th>Operario</th>
                                            <th>Turno</th>
                                            <th>Temp</th>
                                            <th>Tiempo</th>
                                            <th>Producido</th>
                                            <th>Defectos</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentProcessRecords as $record): ?>
                                            <tr>
                                                <td data-label="Fecha"><?= e(formatDateTime((string) $record['timestamp'])) ?></td>
                                                <td data-label="Area"><?= e((string) ($record['area'] ?? $standards['area'])) ?></td>
                                                <td data-label="Operario"><?= e((string) $record['operator']) ?></td>
                                                <td data-label="Turno"><?= e((string) $record['shift']) ?></td>
                                                <td data-label="Temperatura"><?= formatNumber((float) $record['temperature'], 2, ' C') ?></td>
                                                <td data-label="Tiempo"><?= formatNumber((float) $record['time_minutes'], 2, ' min') ?></td>
                                                <td data-label="Producido"><?= (int) $record['produced_qty'] ?></td>
                                                <td data-label="Defectos"><?= (int) $record['defective_qty'] ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty">Aun no se han registrado lotes.</div>
                        <?php endif; ?>
                    </article>

                    <article class="panel">
                        <div class="panel-header">
                            <div>
                                <h3>Evaluaciones 5S recientes</h3>
                                <p>Historial de orden, limpieza y disciplina.</p>
                            </div>
                        </div>
                        <?php if ($recentFiveSRecords !== []): ?>
                            <div class="table-wrap">
                                <table class="responsive-stack">
                                    <thead>
                                        <tr>
                                            <th>Fecha</th>
                                            <th>Area</th>
                                            <th>Responsable</th>
                                            <th>Puntaje</th>
                                            <th>Notas</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentFiveSRecords as $record): ?>
                                            <tr>
                                                <td data-label="Fecha"><?= e(formatDateTime((string) $record['timestamp'])) ?></td>
                                                <td data-label="Area"><?= e((string) $record['area']) ?></td>
                                                <td data-label="Responsable"><?= e((string) $record['responsible']) ?></td>
                                                <td data-label="Puntaje"><?= formatNumber((float) ($record['average'] ?? 0), 2) ?>/5</td>
                                                <td data-label="Notas"><?= e((string) ($record['notes'] ?? 'Sin observaciones.')) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty">Aun no se han registrado evaluaciones 5S.</div>
                        <?php endif; ?>
                    </article>
                </div>
            </section>
        <?php endif; ?>

                <p class="footer-note">Base local en <strong>data/</strong> y fotos en <strong>uploads/</strong>. Esta version ya esta preparada para crecer luego a login, exportes y reportes mas avanzados.</p>
            </main>
        </div>
    </div>
    <nav class="mobile-dock" aria-label="Accesos rapidos">
        <a class="dock-link <?= $currentView === 'home' ? 'is-active' : '' ?>" href="<?= e(buildViewUrl('home')) ?>">
            <strong>Inicio</strong>
            <span>Panel</span>
        </a>
        <a class="dock-link <?= $currentView === 'standards' ? 'is-active' : '' ?>" href="<?= e(buildViewUrl('standards')) ?>">
            <strong>CTQ</strong>
            <span>Base</span>
        </a>
        <a class="dock-link <?= $currentView === 'process' ? 'is-active' : '' ?>" href="<?= e(buildViewUrl('process')) ?>">
            <strong>Lote</strong>
            <span>Captura</span>
        </a>
        <a class="dock-link <?= $currentView === 'five-s' ? 'is-active' : '' ?>" href="<?= e(buildViewUrl('five-s')) ?>">
            <strong>5S</strong>
            <span>Visual</span>
        </a>
        <button type="button" class="dock-button" id="dock-menu-toggle" aria-expanded="false" aria-controls="sidebar-mobile-content">
            <strong>Mas</strong>
            <span>Menu</span>
        </button>
    </nav>

    <script>
        const standards = <?= json_encode($standards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const chartLabels = <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const chartTemps = <?= json_encode($chartTemps, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const chartTimes = <?= json_encode($chartTimes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const chartDefects = <?= json_encode($chartDefects, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const chartTempCompliance = <?= json_encode($chartTempCompliance, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const chartTimeCompliance = <?= json_encode($chartTimeCompliance, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const fiveSLabels = ['Clasificar', 'Ordenar', 'Limpiar', 'Estandarizar', 'Disciplina'];
        const fiveSValues = <?= json_encode($latestFiveSSeries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const capabilityValues = <?= json_encode([
            $tempCp ?? 0,
            $tempCpk ?? 0,
            $timeCp ?? 0,
            $timeCpk ?? 0,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const comparisonValues = <?= json_encode([
            'labels' => ['Temp', 'Tiempo', 'Produccion', 'Defectos'],
            'actual' => $comparisonScores,
            'target' => [100, 100, 100, 100],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const processScore = <?= (int) $processScore ?>;
        const overallSigma = <?= json_encode($overallSigma, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const sidebar = document.querySelector('.sidebar');
        const sidebarPanel = document.getElementById('sidebar-panel');
        const mobileOverlay = document.getElementById('mobile-overlay');
        const menuToggles = [
            document.getElementById('menu-toggle-mobile'),
            document.getElementById('menu-toggle-close'),
            document.getElementById('dock-menu-toggle')
        ].filter(Boolean);
        const sidebarLinks = document.querySelectorAll('.side-link');

        function isMobileMenu() {
            return window.matchMedia('(max-width: 760px)').matches;
        }

        function setMenuState(open) {
            if (!sidebarPanel || !sidebar) return;
            sidebar.classList.toggle('is-open', open);
            sidebarPanel.classList.toggle('is-open', open);
            mobileOverlay?.classList.toggle('is-open', open);
            document.body.classList.toggle('mobile-menu-open', open && isMobileMenu());
            menuToggles.forEach(toggle => {
                toggle.classList.toggle('is-open', open);
                toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                toggle.setAttribute('aria-label', open ? 'Cerrar menu' : 'Abrir menu');
            });
        }

        menuToggles.forEach(toggle => {
            toggle.addEventListener('click', () => {
                const nextState = !sidebarPanel.classList.contains('is-open');
                setMenuState(nextState);
            });
        });

        mobileOverlay?.addEventListener('click', () => setMenuState(false));

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && sidebarPanel?.classList.contains('is-open')) {
                setMenuState(false);
            }
        });

        sidebarLinks.forEach(link => {
            link.addEventListener('click', () => {
                if (isMobileMenu()) {
                    setMenuState(false);
                }
            });
        });

        window.addEventListener('resize', () => {
            if (!isMobileMenu()) {
                setMenuState(false);
            }
        });

        setMenuState(false);

        function drawAxis(ctx, width, height, padding, rows = 4) {
            ctx.strokeStyle = 'rgba(95, 114, 136, 0.16)';
            ctx.lineWidth = 1;
            for (let i = 0; i <= rows; i++) {
                const y = padding + ((height - padding * 2) / rows) * i;
                ctx.beginPath();
                ctx.moveTo(padding, y);
                ctx.lineTo(width - padding, y);
                ctx.stroke();
            }
        }

        function drawMultiLineChart(canvasId, series, labels, options = {}) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            const width = canvas.width;
            const height = canvas.height;
            const padding = 36;

            ctx.clearRect(0, 0, width, height);
            ctx.fillStyle = '#fbfdff';
            ctx.fillRect(0, 0, width, height);
            drawAxis(ctx, width, height, padding);

            const allValues = series.flatMap(item => item.values);
            if (!allValues.length) {
                ctx.fillStyle = '#5f7288';
                ctx.font = '16px Segoe UI';
                ctx.fillText('Aun no hay datos para graficar.', padding, height / 2);
                return;
            }

            const minValue = Math.min(...allValues, options.min ?? Math.min(...allValues));
            const maxValue = Math.max(...allValues, options.max ?? Math.max(...allValues), minValue + 1);
            const graphWidth = width - padding * 2;
            const graphHeight = height - padding * 2;
            const span = maxValue - minValue || 1;

            if (options.references) {
                options.references.forEach(ref => {
                    const y = height - padding - ((ref.value - minValue) / span) * graphHeight;
                    ctx.setLineDash([6, 6]);
                    ctx.strokeStyle = ref.color;
                    ctx.lineWidth = 1.5;
                    ctx.beginPath();
                    ctx.moveTo(padding, y);
                    ctx.lineTo(width - padding, y);
                    ctx.stroke();
                    ctx.setLineDash([]);
                });
            }

            series.forEach(item => {
                const points = item.values.map((value, index) => {
                    const x = padding + (graphWidth / Math.max(item.values.length - 1, 1)) * index;
                    const y = height - padding - ((value - minValue) / span) * graphHeight;
                    return { x, y, value };
                });

                ctx.beginPath();
                ctx.moveTo(points[0].x, points[0].y);
                points.forEach(point => ctx.lineTo(point.x, point.y));
                ctx.strokeStyle = item.color;
                ctx.lineWidth = 3;
                ctx.stroke();

                points.forEach(point => {
                    ctx.beginPath();
                    ctx.arc(point.x, point.y, 4, 0, Math.PI * 2);
                    ctx.fillStyle = item.color;
                    ctx.fill();
                });
            });

            ctx.fillStyle = '#5f7288';
            ctx.font = '12px Segoe UI';
            ctx.fillText(labels[0] || '', padding, height - 10);
            ctx.fillText(labels[labels.length - 1] || '', width - padding - 30, height - 10);
            ctx.fillText(maxValue.toFixed(1), 6, padding + 8);
            ctx.fillText(minValue.toFixed(1), 6, height - padding);

            let legendX = padding;
            series.forEach(item => {
                ctx.fillStyle = item.color;
                ctx.fillRect(legendX, 10, 14, 8);
                ctx.fillStyle = '#44576d';
                ctx.fillText(item.label, legendX + 20, 18);
                legendX += 110;
            });
        }

        function drawBarComparison(canvasId, labels, values, targets, options = {}) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            const width = canvas.width;
            const height = canvas.height;
            const padding = 40;
            const barGroupWidth = (width - padding * 2) / Math.max(labels.length, 1);
            const barWidth = Math.min(26, barGroupWidth / 3);
            const maxValue = Math.max(...values, ...targets, 1);
            const graphHeight = height - padding * 2;

            ctx.clearRect(0, 0, width, height);
            ctx.fillStyle = '#fbfdff';
            ctx.fillRect(0, 0, width, height);
            drawAxis(ctx, width, height, padding);

            labels.forEach((label, index) => {
                const groupX = padding + index * barGroupWidth + barGroupWidth / 2;
                const actualHeight = (values[index] / maxValue) * graphHeight;
                const targetHeight = (targets[index] / maxValue) * graphHeight;

                ctx.fillStyle = '#1368d3';
                ctx.fillRect(groupX - barWidth - 2, height - padding - actualHeight, barWidth, actualHeight);

                ctx.fillStyle = '#0b7766';
                ctx.fillRect(groupX + 2, height - padding - targetHeight, barWidth, targetHeight);

                ctx.fillStyle = '#5f7288';
                ctx.font = '12px Segoe UI';
                ctx.fillText(label, groupX - 24, height - 12);
            });

            ctx.fillStyle = '#1368d3';
            ctx.fillRect(padding, 12, 14, 8);
            ctx.fillStyle = '#44576d';
            ctx.fillText('Actual', padding + 20, 20);
            ctx.fillStyle = '#0b7766';
            ctx.fillRect(padding + 90, 12, 14, 8);
            ctx.fillStyle = '#44576d';
            ctx.fillText('Objetivo', padding + 110, 20);
        }

        function drawRadarChart(canvasId, labels, values, maxValue = 5) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            const width = canvas.width;
            const height = canvas.height;
            const cx = width / 2;
            const cy = height / 2;
            const radius = Math.min(width, height) * 0.32;
            const levels = 5;

            ctx.clearRect(0, 0, width, height);
            ctx.fillStyle = '#fbfdff';
            ctx.fillRect(0, 0, width, height);

            for (let level = 1; level <= levels; level++) {
                const currentRadius = (radius / levels) * level;
                ctx.beginPath();
                labels.forEach((label, index) => {
                    const angle = ((Math.PI * 2) / labels.length) * index - Math.PI / 2;
                    const x = cx + Math.cos(angle) * currentRadius;
                    const y = cy + Math.sin(angle) * currentRadius;
                    if (index === 0) {
                        ctx.moveTo(x, y);
                    } else {
                        ctx.lineTo(x, y);
                    }
                });
                ctx.closePath();
                ctx.strokeStyle = 'rgba(95, 114, 136, 0.18)';
                ctx.stroke();
            }

            labels.forEach((label, index) => {
                const angle = ((Math.PI * 2) / labels.length) * index - Math.PI / 2;
                const x = cx + Math.cos(angle) * radius;
                const y = cy + Math.sin(angle) * radius;
                ctx.beginPath();
                ctx.moveTo(cx, cy);
                ctx.lineTo(x, y);
                ctx.strokeStyle = 'rgba(95, 114, 136, 0.15)';
                ctx.stroke();
                ctx.fillStyle = '#44576d';
                ctx.font = '12px Segoe UI';
                ctx.fillText(label, x + (x >= cx ? 6 : -56), y + (y >= cy ? 14 : -4));
            });

            ctx.beginPath();
            values.forEach((value, index) => {
                const angle = ((Math.PI * 2) / labels.length) * index - Math.PI / 2;
                const valueRadius = (clampValue(value, 0, maxValue) / maxValue) * radius;
                const x = cx + Math.cos(angle) * valueRadius;
                const y = cy + Math.sin(angle) * valueRadius;
                if (index === 0) {
                    ctx.moveTo(x, y);
                } else {
                    ctx.lineTo(x, y);
                }
            });
            ctx.closePath();
            ctx.fillStyle = 'rgba(19, 104, 211, 0.18)';
            ctx.strokeStyle = '#1368d3';
            ctx.lineWidth = 3;
            ctx.fill();
            ctx.stroke();
        }

        function drawGauge(canvasId, value, maxValue = 100) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;
            const ctx = canvas.getContext('2d');
            const width = canvas.width;
            const height = canvas.height;
            const cx = width / 2;
            const cy = height * 0.82;
            const radius = Math.min(width, height) * 0.36;
            const start = Math.PI;
            const end = 0;
            const normalized = clampValue(value / maxValue, 0, 1);

            ctx.clearRect(0, 0, width, height);
            ctx.fillStyle = '#fbfdff';
            ctx.fillRect(0, 0, width, height);

            const segments = [
                { from: 0, to: 0.5, color: '#d93a3a' },
                { from: 0.5, to: 0.75, color: '#dd7a0e' },
                { from: 0.75, to: 1, color: '#17876b' }
            ];

            ctx.lineWidth = 24;
            segments.forEach(segment => {
                ctx.beginPath();
                ctx.strokeStyle = segment.color;
                ctx.arc(
                    cx,
                    cy,
                    radius,
                    start + (Math.PI * segment.from),
                    start + (Math.PI * segment.to)
                );
                ctx.stroke();
            });

            ctx.beginPath();
            ctx.strokeStyle = 'rgba(18, 32, 51, 0.15)';
            ctx.lineWidth = 4;
            ctx.arc(cx, cy, radius + 18, Math.PI, 0);
            ctx.stroke();

            const needleAngle = Math.PI + (Math.PI * normalized);
            const needleLength = radius - 6;
            const needleX = cx + Math.cos(needleAngle) * needleLength;
            const needleY = cy + Math.sin(needleAngle) * needleLength;

            ctx.beginPath();
            ctx.moveTo(cx, cy);
            ctx.lineTo(needleX, needleY);
            ctx.strokeStyle = '#122033';
            ctx.lineWidth = 5;
            ctx.stroke();

            ctx.beginPath();
            ctx.arc(cx, cy, 10, 0, Math.PI * 2);
            ctx.fillStyle = '#122033';
            ctx.fill();

            ctx.fillStyle = '#122033';
            ctx.font = '700 34px Segoe UI';
            ctx.textAlign = 'center';
            ctx.fillText(String(Math.round(value)), cx, cy - 26);
            ctx.font = '14px Segoe UI';
            ctx.fillStyle = '#5f7288';
            ctx.fillText('salud del proceso', cx, cy + 6);
            ctx.textAlign = 'left';
        }

        function clampValue(value, min, max) {
            return Math.max(min, Math.min(max, value));
        }

        drawMultiLineChart('overview-chart', [
            { label: 'Temp estandar', values: chartTempCompliance, color: '#1368d3' },
            { label: 'Tiempo estandar', values: chartTimeCompliance, color: '#0b7766' }
        ], chartLabels, {
            min: 0,
            max: 100
        });

        drawRadarChart('fives-radar', fiveSLabels, fiveSValues, 5);
        drawRadarChart('fives-radar-detail', fiveSLabels, fiveSValues, 5);

        drawBarComparison(
            'comparison-chart',
            comparisonValues.labels,
            comparisonValues.actual,
            comparisonValues.target
        );

        drawMultiLineChart('capability-trend-chart', [
            { label: 'Temp estandar', values: chartTempCompliance, color: '#1368d3' },
            { label: 'Tiempo estandar', values: chartTimeCompliance, color: '#0b7766' }
        ], chartLabels, {
            min: 0,
            max: 100,
            references: [
                { value: 100, color: 'rgba(23, 135, 107, 0.65)' },
                { value: 70, color: 'rgba(221, 122, 14, 0.55)' }
            ]
        });

        drawBarComparison(
            'defect-chart',
            chartLabels.length ? chartLabels : ['Sin datos'],
            chartDefects.length ? chartDefects : [0],
            chartDefects.length ? chartDefects.map(() => Number(standards.max_defect_rate)) : [Number(standards.max_defect_rate)]
        );

        drawBarComparison(
            'capability-bars',
            ['Cp Temp', 'Cpk Temp', 'Cp Tiempo', 'Cpk Tiempo'],
            capabilityValues,
            [1.33, 1.33, 1.33, 1.33]
        );

        drawGauge('health-gauge', processScore, 100);

        const processPreview = document.getElementById('live-preview');
        const producedInput = document.getElementById('produced_qty');
        const defectiveInput = document.getElementById('defective_qty');
        const temperatureInput = document.getElementById('temperature');
        const timeInput = document.getElementById('time_minutes');

        function buildLotPreview() {
            if (!processPreview) return;

            const produced = Number(producedInput?.value || 0);
            const defective = Number(defectiveInput?.value || 0);
            const temp = Number(temperatureInput?.value || 0);
            const minutes = Number(timeInput?.value || 0);
            const defectRate = produced > 0 ? (defective / produced) * 100 : 0;
            const yieldRate = produced > 0 ? 100 - defectRate : 0;
            const compliance = produced > 0 ? Math.min((produced / Number(standards.target_output || 1)) * 100, 100) : 0;

            const tempState = temp
                ? (temp >= Number(standards.temp_min) && temp <= Number(standards.temp_max) ? 'Dentro del rango' : 'Fuera del rango')
                : 'Sin lectura';
            const timeState = minutes
                ? (minutes >= Number(standards.time_min) && minutes <= Number(standards.time_max) ? 'Dentro del rango' : 'Fuera del rango')
                : 'Sin lectura';
            const defectState = produced
                ? (defectRate <= Number(standards.max_defect_rate) ? 'Controlado' : 'Alto')
                : 'Sin lectura';

            processPreview.innerHTML = `
                <strong>Lectura previa del lote</strong>
                <div class="mini-grid" style="margin-top: 12px;">
                    <div class="metric-item">
                        <span>Defecto estimado</span>
                        <strong>${defectRate.toFixed(2)}%</strong>
                    </div>
                    <div class="metric-item">
                        <span>Rendimiento estimado</span>
                        <strong>${yieldRate.toFixed(2)}%</strong>
                    </div>
                    <div class="metric-item">
                        <span>Temperatura</span>
                        <strong>${tempState}</strong>
                    </div>
                    <div class="metric-item">
                        <span>Tiempo</span>
                        <strong>${timeState}</strong>
                    </div>
                    <div class="metric-item">
                        <span>Meta de produccion</span>
                        <strong>${compliance.toFixed(1)}%</strong>
                    </div>
                    <div class="metric-item">
                        <span>Lectura de defectos</span>
                        <strong>${defectState}</strong>
                    </div>
                </div>
                <p style="margin: 12px 0 0; color: var(--muted);">Si los bloques salen fuera de rango, el sistema convertira ese resultado en alertas, datos requeridos y acciones concretas al guardar.</p>
            `;
        }

        [producedInput, defectiveInput, temperatureInput, timeInput].forEach(input => {
            input?.addEventListener('input', buildLotPreview);
        });

        document.querySelectorAll('[data-step-form]').forEach(form => {
            const panes = Array.from(form.querySelectorAll('[data-step-pane]'));
            const chips = Array.from(form.querySelectorAll('.step-chip'));
            const nextButtons = Array.from(form.querySelectorAll('[data-step-next]'));
            const prevButtons = Array.from(form.querySelectorAll('[data-step-prev]'));
            let currentStep = 0;

            function syncSteps() {
                panes.forEach((pane, index) => {
                    pane.classList.toggle('is-active', index === currentStep);
                });

                chips.forEach((chip, index) => {
                    chip.classList.toggle('is-active', index === currentStep);
                    chip.classList.toggle('is-done', index < currentStep);
                });
            }

            function validateCurrentStep() {
                const fields = Array.from(panes[currentStep]?.querySelectorAll('input, select, textarea') || []);
                for (const field of fields) {
                    if (field.disabled || !field.willValidate) {
                        continue;
                    }

                    if (!field.checkValidity()) {
                        field.reportValidity();
                        return false;
                    }
                }

                return true;
            }

            nextButtons.forEach(button => {
                button.addEventListener('click', () => {
                    if (!validateCurrentStep()) {
                        return;
                    }

                    currentStep = Math.min(currentStep + 1, panes.length - 1);
                    syncSteps();
                });
            });

            prevButtons.forEach(button => {
                button.addEventListener('click', () => {
                    currentStep = Math.max(currentStep - 1, 0);
                    syncSteps();
                });
            });

            chips.forEach((chip, index) => {
                chip.addEventListener('click', () => {
                    if (index > currentStep && !validateCurrentStep()) {
                        return;
                    }

                    currentStep = index;
                    syncSteps();
                });
            });

            form.addEventListener('submit', event => {
                if (currentStep < panes.length - 1) {
                    event.preventDefault();
                    if (!validateCurrentStep()) {
                        return;
                    }

                    currentStep = Math.min(currentStep + 1, panes.length - 1);
                    syncSteps();
                }
            });

            syncSteps();
        });

        const photoInput = document.getElementById('five_s_photos');
        const photoPreview = document.getElementById('photo-preview');

        photoInput?.addEventListener('change', event => {
            if (!photoPreview) return;

            const files = Array.from(event.target.files || []);
            photoPreview.innerHTML = files.length ? '' : '<span style="color: var(--muted);">Selecciona una o varias fotos para verlas antes de guardar.</span>';

            files.forEach(file => {
                const figure = document.createElement('figure');
                const image = document.createElement('img');
                const caption = document.createElement('figcaption');
                image.src = URL.createObjectURL(file);
                image.alt = file.name;
                caption.textContent = file.name;
                figure.appendChild(image);
                figure.appendChild(caption);
                photoPreview.appendChild(figure);
            });
        });

        buildLotPreview();
    </script>
</body>
</html>
