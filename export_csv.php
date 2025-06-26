<?php
// export_csv.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'api.php';
require_once 'config.php'; // Necesitamos BRANDMENTION_PROJECT_ID

// --- 1. Obtener parámetros de filtro de la URL ---
$start_date = $_GET['start_date'] ?? ''; // Aunque los inputs estén ocultos, es buena práctica mantener la capacidad
$end_date = $_GET['end_date'] ?? '';     // de recibirlos si se envían.
$period = $_GET['period'] ?? '2weeks';
$platform = $_GET['platform'] ?? 'all';

// Replicar la lógica de fechas de index.php para asegurar consistencia
$current_end_period = date('Y-m-d H:i:s');
$current_start_period = date('Y-m-d H:i:s', strtotime('-2 weeks')); // Default if nothing else is chosen

// If custom dates were passed (even if inputs are hidden on frontend for now)
if (!empty($start_date) && !empty($end_date)) {
    $current_start_period = date('Y-m-d 00:00:00', strtotime($start_date));
    $current_end_period = date('Y-m-d 23:59:59', strtotime($end_date));
} else {
    // If using predefined period
    $current_start_period = match ($period) {
        '6months' => date('Y-m-d H:i:s', strtotime('-6 months')),
        '3months' => date('Y-m-d H:i:s', strtotime('-3 months')),
        '1month' => date('Y-m-d H:i:s', strtotime('-1 month')),
        '2weeks' => date('Y-m-d H:i:s', strtotime('-2 weeks')),
        '1week' => date('Y-m-d H:i:s', strtotime('-1 week')),
        default => date('Y-m-d H:i:s', strtotime('-2 weeks'))
    };
}


// Definir fuentes para la API, igual que en index.php
$all_sources = ['twitter', 'instagram', 'facebook', 'web', 'youtube', 'linkedin', 'pinterest', 'reddit'];
$sources_for_api = ($platform === 'all') ? $all_sources : [$platform];

// --- 2. Obtener datos de la API ---
// Para la exportación, queremos todas las menciones posibles, no solo el límite para la UI.
// Aumentamos el límite a 10000 o más, según lo que tu API de Brandmention soporte por proyecto
// y lo que sea razonable para un CSV. ¡Cuidado con el memory_limit y execution_time!
$max_mentions_for_export = 10000; // Puedes ajustar este valor
$all_project_data = getAllProjectMentionsPaginated(BRANDMENTION_PROJECT_ID, $max_mentions_for_export, $current_start_period, $current_end_period, $sources_for_api);

$mentions_to_export = [];
if ($all_project_data['status'] === 'success') {
    $mentions_to_export = $all_project_data['mentions'] ?? [];
} else {
    // Si hay un error, puedes redirigir o mostrar un mensaje simple
    die("Error al obtener datos para exportar: " . htmlspecialchars($all_project_data['message']));
}

// --- 3. Generar el archivo CSV ---

// Nombre del archivo CSV
$filename = 'reporte_menciones_' . date('Ymd_His') . '.csv';

// Establecer cabeceras para forzar la descarga de un archivo CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Abrir el "output" para escritura (php://output envía al navegador)
$output = fopen('php://output', 'w');

// Escribir la cabecera del CSV
// Define las columnas que quieres en tu CSV
fputcsv($output, [
    'ID Mencion',
    'Fecha Publicacion',
    'Texto',
    'URL',
    'Autor Username',
    'Autor Nombre',
    'Red Social',
    'Sentimiento',
    'Followers', // Si la API de Brandmention proporciona este dato
    'Engagement', // Si la API de Brandmention proporciona este dato
    'Reach' // Si la API de Brandmention proporciona este dato
]);

// Escribir los datos de las menciones
foreach ($mentions_to_export as $mention) {
    // Asegúrate de que las claves existan para evitar errores
    $row = [
        $mention['id'] ?? '',
        $mention['published'] ?? '',
        // Limpiar el texto para CSV: eliminar saltos de línea y comillas dobles que puedan romper el formato
        str_replace(["\r", "\n", '"'], ['', '', "'"], $mention['text'] ?? ''),
        $mention['url'] ?? '',
        $mention['author']['username'] ?? '',
        $mention['author']['name'] ?? '',
        $mention['social_network'] ?? '',
        $mention['sentiment'] ?? '',
        $mention['author']['followers'] ?? '', // Ajusta estas claves según la estructura real de tu API de Brandmention
        $mention['engagement'] ?? '', // Estas son solo ejemplos
        $mention['reach'] ?? ''       // Estas son solo ejemplos
    ];
    fputcsv($output, $row);
}

// Cerrar el archivo de salida
fclose($output);

exit(); // Asegura que no se envíe nada más
?>