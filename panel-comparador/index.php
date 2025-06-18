<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'api.php';
require_once 'config.php';

header('Content-Type: text/html; charset=utf-8');

// Configuración de periodo y plataforma
$period = $_GET['period'] ?? '2weeks';
$platform = $_GET['platform'] ?? 'all'; // Default a 'all'

$end_period = date('Y-m-d H:i:s');
$start_period = match ($period) {
    '6months' => date('Y-m-d H:i:s', strtotime('-6 months')),
    '3months' => date('Y-m-d H:i:s', strtotime('-3 months')),
    '1month' => date('Y-m-d H:i:s', strtotime('-1 month')),
    '2weeks' => date('Y-m-d H:i:s', strtotime('-2 weeks')),
    '1week' => date('Y-m-d H:i:s', strtotime('-1 week')),
    default => date('Y-m-d H:i:s', strtotime('-2 weeks'))
};

// Mapeo de plataformas a fuentes de la API
$all_sources = ['twitter', 'instagram', 'facebook', 'web', 'youtube', 'linkedin', 'pinterest', 'reddit'];
// Las fuentes para la API se basarán en la selección del usuario.
// Si el usuario selecciona 'all', se usarán las fuentes definidas en $all_sources.
// Si selecciona una específica, solo esa se usará.
$sources_for_api = ($platform === 'all') ? $all_sources : [$platform];

$mentions = [];
$influencers = [];
$error_message = '';

try {
    // Obtener menciones de Felifer (panel general) - Se mantiene limitado a 100 para la carga rápida
    $mentions_data = getProjectMentions(BRANDMENTION_PROJECT_ID, $start_period, $end_period, $sources_for_api);
    if ($mentions_data['status'] === 'success') {
        $mentions = $mentions_data['mentions'] ?? [];
    } else {
        $error_message = 'Error al obtener menciones del proyecto: ' . htmlspecialchars($mentions_data['message']);
    }

    // Obtener influencers de Felifer
    $influencers_data = getProjectInfluencers(BRANDMENTION_PROJECT_ID, $start_period, $end_period, $sources_for_api);
    if ($influencers_data['status'] === 'success') {
        $influencers = $influencers_data['influencers'] ?? [];
    } else {
        $error_message .= (empty($error_message) ? '' : ' | ') . 'Error al obtener influencers del proyecto: ' . htmlspecialchars($influencers_data['message']);
    }

} catch (Exception $e) {
    $error_message .= (empty($error_message) ? '' : ' | ') . 'Excepción al obtener datos: ' . htmlspecialchars($e->getMessage());
}

// Calcular sentimiento general para el panel principal
$sentiments = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
foreach ($mentions as $mention) {
    $sentiment = trim($mention['sentiment'] ?? 'neutral');
    if (in_array($sentiment, ['positive', 'neutral', 'negative'])) {
        $sentiments[$sentiment]++;
    }
}
$total_mentions_general = array_sum($sentiments);
$sentiments_percentage = [
    'positive' => $total_mentions_general ? ($sentiments['positive'] / $total_mentions_general) * 100 : 0,
    'neutral' => $total_mentions_general ? ($sentiments['neutral'] / $total_mentions_general) * 100 : 0,
    'negative' => $total_mentions_general ? ($sentiments['negative'] / $total_mentions_general) * 100 : 0
];

// Datos para la nube de palabras general
$word_cloud_data = getWordCloudData($mentions);

// --- MODIFICACIÓN: Filtro y ordenamiento para Top Influencers (excluir a Felifer) ---
// Agrega todos los nombres de usuario y nombres reales de Felifer aquí (en minúsculas)
$felifer_usernames_and_names = [
    strtolower(BRANDMENTION_PROJECT_MAIN_KEYWORD), // Ej: 'felifermacias'
    strtolower(ltrim(BRANDMENTION_PROJECT_MAIN_KEYWORD, '@ ')), // Para manejar el '@' si existe
    'felifermacias', 'felifermaciaso', 'macias', 'maciaso', 'maciasfelipe', 'felipe', 'feli', 'fernando', 'fer', 'ferna', 'fel',
    // ¡Añade cualquier otra variación aquí!
];

// Primero ordenar por menciones
usort($influencers, function($a, $b) {
    return ($b['mentions_count'] ?? 0) <=> ($a['mentions_count'] ?? 0);
});

// Luego filtrar y limitar a Top 10
$filtered_influencers = [];
$count_filtered = 0;
foreach ($influencers as $influencer) {
    $username_lower = strtolower($influencer['username'] ?? '');
    $name_lower = strtolower($influencer['name'] ?? '');

    // Si el influencer no tiene username o name, o si cualquiera de ellos está en la lista de Felifer, se excluye.
    $is_felifer = in_array($username_lower, $felifer_usernames_and_names) || in_array($name_lower, $felifer_usernames_and_names);

    if (!$is_felifer) {
        $filtered_influencers[] = $influencer;
        $count_filtered++;
        if ($count_filtered >= 10) { // Limitar a los top 10 después del filtrado
            break;
        }
    }
}

// --- MODIFICACIÓN: Datos para la gráfica de pastel del panel general (solo Twitter, Facebook, Instagram) ---
$mentions_by_source_general = [
    'twitter' => 0,
    'instagram' => 0,
    'facebook' => 0,
];

foreach ($mentions as $mention) {
    $source_lower = strtolower($mention['social_network'] ?? '');
    if (isset($mentions_by_source_general[$source_lower])) { // Solo si la fuente está en nuestra lista limitada
        $mentions_by_source_general[$source_lower]++;
    }
}

$chart_labels_general = [];
$chart_data_general = [];
$chart_background_colors_general = [];
$source_colors_general = [
    'facebook' => '#1877F2',
    'twitter' => '#1DA1F2',
    'instagram' => '#C13584',
];

$total_mentions_for_pie_chart_general = array_sum($mentions_by_source_general);

if ($total_mentions_for_pie_chart_general > 0) {
    foreach ($mentions_by_source_general as $source => $count) {
        if ($count > 0) {
            $label = ucfirst($source);
            if ($source == 'twitter') $label = 'X (Twitter)';
            $chart_labels_general[] = $label;
            // Asegúrate de que los datos sean porcentajes si la gráfica es de pastel
            $chart_data_general[] = ($count / $total_mentions_for_pie_chart_general) * 100;
            $chart_background_colors_general[] = $source_colors_general[$source] ?? '#6c757d'; // Fallback por si acaso
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Análisis de Menciones de Felifermacias</title>
    <link rel="stylesheet" href="assets/lib/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="assets/lib/chart.min.js"></script>
    <script src="assets/lib/wordcloud2.js"></script>
</head>
<body>
<div class="container-fluid mt-4">
    <header class="mb-4 text-center">
        <h1>Análisis de Menciones de Felifermacias</h1>
        <p class="lead">Monitoreo y Comparación de Presencia Digital</p>
    </header>

    <nav class="navbar navbar-expand-lg navbar-light bg-light mb-4 shadow-sm rounded">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">Dashboard</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" aria-current="page" href="index.php">General</a>
                    </li>
                </ul>
                <form class="d-flex" method="GET" action="index.php">
                    <div class="input-group">
                        <select name="period" class="form-select me-2" onchange="this.form.submit()">
                            <option value="1week" <?= $period == '1week' ? 'selected' : '' ?>>Última Semana</option>
                            <option value="2weeks" <?= $period == '2weeks' ? 'selected' : '' ?>>Últimas 2 Semanas</option>
                            <option value="1month" <?= $period == '1month' ? 'selected' : '' ?>>Último Mes</option>
                            <option value="3months" <?= $period == '3months' ? 'selected' : '' ?>>Últimos 3 Meses</option>
                            <option value="6months" <?= $period == '6months' ? 'selected' : '' ?>>Últimos 6 Meses</option>
                        </select>
                        <select name="platform" id="platformSelect" class="form-select me-2" onchange="this.form.submit()">
                            <option value="all" <?= $platform == 'all' ? 'selected' : '' ?>>Todas las plataformas</option>
                            <option value="twitter" <?= $platform == 'twitter' ? 'selected' : '' ?>>X (Twitter)</option>
                            <option value="facebook" <?= $platform == 'facebook' ? 'selected' : '' ?>>Facebook</option>
                            <option value="instagram" <?= $platform == 'instagram' ? 'selected' : '' ?>>Instagram</option>
                            <option value="web" <?= $platform == 'web' ? 'selected' : '' ?>>Web</option>
                            <option value="youtube" <?= $platform == 'youtube' ? 'selected' : '' ?>>YouTube</option>
                            <option value="linkedin" <?= $platform == 'linkedin' ? 'selected' : '' ?>>LinkedIn</option>
                            <option value="pinterest" <?= $platform == 'pinterest' ? 'selected' : '' ?>>Pinterest</option>
                            <option value="reddit" <?= $platform == 'reddit' ? 'selected' : '' ?>>Reddit</option>
                        </select>
                    </div>
                </form>
            </div>
        </div>
    </nav>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger" role="alert">
            <?= $error_message ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Sentimiento General del Proyecto (<?= BRANDMENTION_PROJECT_MAIN_KEYWORD ?>)</h5>
                </div>
                <div class="card-body">
                    <p class="card-text">Total de menciones analizadas: <strong><?= $total_mentions_general ?></strong></p>
                    <table class="table table-bordered table-sm">
                        <thead>
                        <tr>
                            <th>Sentimiento</th>
                            <th>Porcentaje</th>
                            <th>Conteo</th>
                        </tr>
                        </thead>
                        <tbody>
                        <tr>
                            <td class="sentiment-positive">Positivo</td>
                            <td><?= round($sentiments_percentage['positive'], 1) ?>%</td>
                            <td><?= $sentiments['positive'] ?></td>
                        </tr>
                        <tr>
                            <td class="sentiment-neutral">Neutral</td>
                            <td><?= round($sentiments_percentage['neutral'], 1) ?>%</td>
                            <td><?= $sentiments['neutral'] ?></td>
                        </tr>
                        <tr>
                            <td class="sentiment-negative">Negativo</td>
                            <td><?= round($sentiments_percentage['negative'], 1) ?>%</td>
                            <td><?= $sentiments['negative'] ?></td>
                        </tr>
                        </tbody>
                    </table>
                    <h6 class="mt-4">Distribución por Red Social</h6>
                    <div class="chart-container" style="position: relative; height:250px; width:100%">
                        <canvas id="generalMentionsPieChart"></canvas>
                    </div>
                    <?php if (empty($chart_data_general)): ?>
                        <p class="text-center mt-2">No hay datos de menciones por red social disponibles para el panel general.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-6 mb-4">
            <div class="card shadow">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Nube de Palabras General</h5>
                </div>
                <div class="card-body text-center">
                    <?php if (!empty($word_cloud_data)): ?>
                        <div id="wordcloud" style="width: 100%; height: 300px; position: relative;"></div>
                    <?php else: ?>
                        <div class="alert alert-info">No hay datos suficientes para generar la nube de palabras.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow mt-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Top 10 Influencers</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($filtered_influencers)): ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($filtered_influencers as $influencer):
                                $username_display = htmlspecialchars($influencer['username'] ?? 'N/A');
                                $total_mentions = htmlspecialchars($influencer['mentions_count'] ?? 0);
                                $network = strtolower($influencer['social_network'] ?? '');
                                $profile_pic = htmlspecialchars($influencer['profile_pic'] ?? 'https://via.placeholder.com/20'); // Imagen placeholder

                                // Lógica para generar URL del perfil (retomada de tu código original)
                                $profile_url = '#'; // Default
                                if (!empty($username_display) && $username_display !== 'N/A') {
                                    switch ($network) {
                                        case 'twitter':
                                            $profile_url = 'https://twitter.com/' . ltrim($username_display, '@');
                                            break;
                                        case 'instagram':
                                            $profile_url = 'https://instagram.com/' . ltrim($username_display, '@');
                                            break;
                                        case 'facebook':
                                            // Facebook es más complejo, a veces es por ID numérico
                                            if (is_numeric($username_display)) {
                                                $profile_url = 'https://www.facebook.com/profile.php?id=' . $username_display;
                                            } else {
                                                $profile_url = 'https://www.facebook.com/' . $username_display;
                                            }
                                            break;
                                        // Puedes añadir más casos para otras redes si Brandmention las devuelve
                                        default:
                                            $profile_url = $influencer['profile_url'] ?? '#'; // Usar la URL de la API si existe
                                            break;
                                    }
                                }
                                ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <img src="<?= $profile_pic ?>" alt="Profile Pic" class="rounded-circle me-2" style="width: 25px; height: 25px;">
                                        <?php if ($profile_url !== '#'): ?>
                                            <a href="<?= $profile_url ?>" target="_blank" rel="noopener noreferrer">
                                                <strong><?= htmlspecialchars($influencer['name'] ?? $username_display) ?></strong>
                                            </a>
                                        <?php else: ?>
                                            <strong><?= htmlspecialchars($influencer['name'] ?? $username_display) ?></strong>
                                        <?php endif; ?>
                                        <small class="text-muted">(@<?= $username_display ?>)</small>
                                        <span class="badge bg-secondary ms-2"><?= htmlspecialchars(ucfirst($network)) ?></span>
                                    </div>
                                    <span class="badge bg-primary rounded-pill">Menciones: <?= $total_mentions ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            No se encontraron influencers para el período y plataformas seleccionados.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-4">
        <div class="col-12 mb-4">
            <div class="card shadow">
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">Comparar Interacción con Otro Usuario</h5>
                </div>
                <div class="card-body">
                    <form id="compareForm" class="row g-3" onsubmit="loadComparison(event)">
                        <div class="col-md-6">
                            <label for="compareUserInput" class="form-label">Nombre de usuario o perfil a comparar (ej. @voz_imparcial):</label>
                            <input type="text" class="form-control" id="compareUserInput" name="compare_user" placeholder="Ej: @nombredeusuario" required>
                        </div>
                        <div class="col-md-3">
                            <label for="comparePeriod" class="form-label">Período:</label>
                            <select name="period" class="form-select" id="comparePeriod">
                                <option value="1week" <?= $period == '1week' ? 'selected' : '' ?>>Última Semana</option>
                                <option value="2weeks" <?= $period == '2weeks' ? 'selected' : '' ?>>Últimas 2 Semanas</option>
                                <option value="1month" <?= $period == '1month' ? 'selected' : '' ?>>Último Mes</option>
                                <option value="3months" <?= $period == '3months' ? 'selected' : '' ?>>Últimos 3 Meses</option>
                                <option value="6months" <?= $period == '6months' ? 'selected' : '' ?>>Últimos 6 Meses</option>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <button type="submit" class="btn btn-warning w-100">Comparar</button>
                        </div>
                    </form>
                    <div class="loader" id="compareLoader"></div> <div id="comparisonResultContainer" class="mt-4" style="display: none;">
                        <div class="alert alert-info text-center" role="alert">
                            Ingresa un nombre de usuario y haz clic en "Comparar" para ver las menciones que ha hecho hacia Felifer.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/lib/bootstrap.bundle.min.js"></script>
<script>
    // Carga de la nube de palabras (APLICANDO TUS PARÁMETROS QUE SÍ FUNCIONABAN)
    var wordCloudDataGeneral = <?php echo json_encode($word_cloud_data); ?>; // Usamos $word_cloud_data

    if (wordCloudDataGeneral && wordCloudDataGeneral.length > 0) {
        setTimeout(function() { // Pequeño retraso para asegurar que el DOM esté listo y dimensionado
            const wordcloudDiv = document.getElementById('wordcloud');
            if (wordcloudDiv) {
                console.log('Div #wordcloud existe. Ancho:', wordcloudDiv.offsetWidth, 'Alto:', wordcloudDiv.offsetHeight);
                if (wordcloudDiv.offsetWidth === 0 || wordcloudDiv.offsetHeight === 0) {
                    console.warn('El div #wordcloud tiene dimensiones cero. Esto puede causar problemas de renderizado.');
                }
            } else {
                console.error('El div #wordcloud NO existe.');
            }

            WordCloud(document.getElementById('wordcloud'), { // Apuntando al ID 'wordcloud'
                list: wordCloudDataGeneral,
                weightFactor: 0.1, // Tus valores que funcionaban
                fontFamily: 'Arial, sans-serif',
                color: function(word) {
                    return word && (word.toLowerCase().includes('felifer') || word.toLowerCase().includes('macías'))
                        ? '#28A745' // Color verde para palabras clave
                        : '#000000'; // Color negro para otras palabras
                },
                backgroundColor: '#ffffff',
                minSize: 10,
                rotateRatio: 0, // No rotar palabras
                gridSize: 8, // Tus valores que funcionaban
                drawOutOfBound: false,
                // clearCanvas: true // Mantener si quieres limpiar el canvas antes de dibujar
            });
            console.log('WordCloud general inicializado.');
        }, 100); // Retraso de 100ms
    } else {
        const wordcloudDiv = document.getElementById('wordcloud');
        if (wordcloudDiv) {
            wordcloudDiv.innerHTML = '<div class="alert alert-info">No hay datos suficientes para generar la nube de palabras.</div>';
            // Ocultar el canvas si no hay datos y mostrar el mensaje
            const canvas = wordcloudDiv.querySelector('canvas');
            if (canvas) canvas.style.display = 'none';
        }
        console.log('No hay datos para la nube de palabras.');
    }

    // Script para la gráfica de pastel del panel general
    var chartLabelsGeneral = <?php echo json_encode($chart_labels_general); ?>;
    var chartDataGeneral = <?php echo json_encode($chart_data_general); ?>;
    var chartBackgroundColorsGeneral = <?php echo json_encode($chart_background_colors_general); ?>;

    const generalMentionsPieChartCanvas = document.getElementById('generalMentionsPieChart');

    if (chartDataGeneral.length > 0 && generalMentionsPieChartCanvas) {
        // Asegurarse de que el canvas tenga dimensiones antes de inicializar Chart.js
        generalMentionsPieChartCanvas.style.width = '100%';
        generalMentionsPieChartCanvas.style.height = '250px'; // Ajusta esto si lo necesitas

        setTimeout(() => { // Pequeño retraso para que el DOM se asiente
            new Chart(generalMentionsPieChartCanvas, {
                type: 'pie',
                data: {
                    labels: chartLabelsGeneral,
                    datasets: [{
                        data: chartDataGeneral,
                        backgroundColor: chartBackgroundColorsGeneral,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false, // Permitir que la altura fija funcione
                    plugins: {
                        legend: {
                            position: 'right', // Posición de la leyenda a la derecha
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed !== null) {
                                        label += context.parsed.toFixed(1) + '%';
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }, 50); // Retraso de 50ms
    } else if (generalMentionsPieChartCanvas) {
        generalMentionsPieChartCanvas.style.display = 'none';
        const parentDiv = generalMentionsPieChartCanvas.closest('.card-body');
        if (parentDiv && !parentDiv.querySelector('p.no-data-message-pie-chart-general')) {
            parentDiv.insertAdjacentHTML('beforeend', '<p class="text-center mt-2 no-data-message-pie-chart-general">No hay datos de menciones por red social disponibles para el panel general.</p>');
        }
    }


    // Función para cargar la comparación vía AJAX (TU LÓGICA ORIGINAL CON FETCH Y EVAL)
    function loadComparison(event) {
        event.preventDefault();

        const compareUser = document.getElementById('compareUserInput').value.trim();
        const period = document.getElementById('comparePeriod').value; // Usar el select de período de comparación
        const platform = document.getElementById('platformSelect').value; // Usar el select de plataforma general (CORREGIDO ID)
        const comparisonResultContainer = document.getElementById('comparisonResultContainer');
        const loader = document.getElementById('compareLoader');

        if (!compareUser) {
            comparisonResultContainer.innerHTML = '<div class="alert alert-warning">Por favor, introduce un usuario para comparar.</div>';
            comparisonResultContainer.style.display = 'block';
            return;
        }

        // Ocultar el contenedor de resultados y mostrar el loader al inicio de cada nueva búsqueda
        comparisonResultContainer.style.display = 'none';
        loader.style.display = 'block';
        comparisonResultContainer.innerHTML = ''; // Vaciar cualquier contenido anterior

        // Realizar la petición AJAX a compare.php
        fetch(`compare.php?compare_user=${encodeURIComponent(compareUser)}&period=${encodeURIComponent(period)}&&platform=${encodeURIComponent(platform)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! Status: ${response.status}`);
                }
                return response.text();
            })
            .then(html => {
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = html;

                const scriptTag = tempDiv.querySelector('script');
                let scriptContent = '';
                if (scriptTag) {
                    scriptContent = scriptTag.textContent;
                    scriptTag.remove();
                }

                comparisonResultContainer.innerHTML = tempDiv.innerHTML;

                setTimeout(() => {
                    comparisonResultContainer.style.display = 'block';

                    if (scriptContent) {
                        try {
                            console.log('Attempting to execute comparison script from compare.php.');
                            // Usamos una función constructora para un entorno de ejecución más seguro que eval directo
                            new Function(scriptContent)();
                            console.log('Comparison script executed.');
                        } catch (e) {
                            console.error('Error executing comparison script from compare.php:', e);
                        }
                    } else {
                        console.warn('No script found for comparison in loaded HTML. This might be expected if no data was returned.');
                    }
                }, 150); // Retraso para mayor fiabilidad

            })
            .catch(error => {
                console.error('Error cargando la comparación:', error);
                comparisonResultContainer.innerHTML = `<div class="alert alert-danger">Error al cargar la comparación: ${error.message}</div>`;
                comparisonResultContainer.style.display = 'block';
            })
            .finally(() => {
                loader.style.display = 'none';
            });
    }
</script>
</body>
</html>