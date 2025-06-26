<?php
// compare.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'api.php';
require_once 'config.php'; // Asegúrate de que BRANDMENTION_PROJECT_ID esté definido aquí.

header('Content-Type: text/html; charset=utf-8');

$compare_user = $_GET['compare_user'] ?? '';
$period = $_GET['period'] ?? '2weeks';
$platform = $_GET['platform'] ?? 'all'; // Este parámetro viene del index, pero no lo usaremos para filtrar en compare.php si tu lógica original no lo hacía explícitamente.

// Configurar el período para la comparación
$end_period = date('Y-m-d H:i:s');
$start_period = match ($period) {
    '6months' => date('Y-m-d H:i:s', strtotime('-6 months')),
    '3months' => date('Y-m-d H:i:s', strtotime('-3 months')),
    '1month' => date('Y-m-d H:i:s', strtotime('-1 month')),
    '2weeks' => date('Y-m-d H:i:s', strtotime('-2 weeks')),
    '1week' => date('Y-m-d H:i:s', strtotime('-1 week')),
    default => date('Y-m-d H:i:s', strtotime('-2 weeks'))
};

// Las fuentes para la comparación. Tu original tenía todas estas.
$sources_for_comparison_api = ['twitter', 'instagram', 'facebook', 'web', 'youtube', 'linkedin', 'pinterest', 'reddit'];

$compare_user_mentions_to_felifer_count = 0;
$error_message = '';
$compare_user_mentions_to_felifer_sentiments = ['positive' => 0, 'neutral' => 0, 'negative' => 0];
// Inicializar el conteo por fuente para todas las fuentes posibles, para evitar "Undefined array key"
$compare_user_mentions_to_felifer_by_source = array_fill_keys($sources_for_comparison_api, 0); // Inicializa con todas las fuentes posibles
$mentions_list_for_display = []; // Para almacenar las menciones y mostrarlas

try {
    // Obtener las menciones HECHAS POR EL USUARIO COMPARADO HACIA Felifer
    if (!empty($compare_user)) {
        // --- CAMBIO CLAVE AQUÍ: Llama a getMentionsByAuthorAndKeyword con per_page=5000 y max_mentions_to_find=5000 ---
        $mentions_by_compare_user_to_felifer = getMentionsByAuthorAndKeyword(
            BRANDMENTION_PROJECT_ID,
            $compare_user,
            $start_period,
            $end_period,
            $sources_for_comparison_api,
            5000, // <-- Aumentar el per_page para cada llamada a la API interna
            5000  // <-- Aumentar el max_mentions_to_find para la función
        );

        if ($mentions_by_compare_user_to_felifer['status'] === 'success') {
            $mentions_list_for_display = $mentions_by_compare_user_to_felifer['mentions'] ?? [];
            $compare_user_mentions_to_felifer_count = count($mentions_list_for_display);

            foreach ($mentions_list_for_display as $mention) {
                $sentiment = trim($mention['sentiment'] ?? 'neutral');
                if (in_array($sentiment, ['positive', 'neutral', 'negative'])) {
                    $compare_user_mentions_to_felifer_sentiments[$sentiment]++;
                }
                // Contar menciones por fuente para la gráfica de pastel
                $source_lower = strtolower($mention['social_network'] ?? 'web'); // Si es null, asumimos que es 'web'
                if (isset($compare_user_mentions_to_felifer_by_source[$source_lower])) {
                    $compare_user_mentions_to_felifer_by_source[$source_lower]++;
                } else {
                    // Si la fuente no está en nuestra lista predefinida, añádela dinámicamente o ignórala.
                    // Para evitar errores y mantener la flexibilidad, la añadimos.
                    $compare_user_mentions_to_felifer_by_source[$source_lower] = ($compare_user_mentions_to_felifer_by_source[$source_lower] ?? 0) + 1;
                }
            }
        } else {
            $error_message .= 'Error al obtener menciones de ' . htmlspecialchars($compare_user) . ' hacia Felifer: ' . htmlspecialchars($mentions_by_compare_user_to_felifer['message']) . '<br>';
        }
    }

} catch (Exception $e) {
    $error_message .= 'Excepción al obtener datos: ' . htmlspecialchars($e->getMessage()) . '<br>';
}

// Calcular porcentajes de sentimiento para las menciones del usuario comparado hacia Felifer
$total_compare_user_mentions_to_felifer = array_sum($compare_user_mentions_to_felifer_sentiments);
$compare_user_mentions_to_felifer_percentage = [
    'positive' => $total_compare_user_mentions_to_felifer ? ($compare_user_mentions_to_felifer_sentiments['positive'] / $total_compare_user_mentions_to_felifer) * 100 : 0,
    'neutral' => $total_compare_user_mentions_to_felifer ? ($compare_user_mentions_to_felifer_sentiments['neutral'] / $total_compare_user_mentions_to_felifer) * 100 : 0,
    'negative' => $total_compare_user_mentions_to_felifer ? ($compare_user_mentions_to_felifer_sentiments['negative'] / $total_compare_user_mentions_to_felifer) * 100 : 0
];

// Datos para la gráfica de pastel de las menciones del usuario comparado HACIA Felifer
$chart_labels = [];
$chart_data = [];
$chart_background_colors = [];
$source_colors = [
    'facebook' => '#1877F2',
    'twitter' => '#1DA1F2', // O '#000000' para el nuevo logo de X
    'instagram' => '#C13584',
    'web' => '#4CAF50', // Verde para web - mantenemos para consistencia con tu original
    'youtube' => '#FF0000',
    'linkedin' => '#0A66C2',
    'pinterest' => '#E60023',
    'reddit' => '#FF4500'
    // Puedes añadir más colores para otras fuentes si las usas
];


$total_mentions_for_pie_chart = array_sum($compare_user_mentions_to_felifer_by_source);

if ($total_mentions_for_pie_chart > 0) {
    // --- MODIFICACIÓN: Filtrar solo Twitter, Instagram, Facebook para la gráfica ---
    $allowed_pie_sources = ['twitter', 'instagram', 'facebook'];
    foreach ($compare_user_mentions_to_felifer_by_source as $source => $count) {
        if ($count > 0 && in_array($source, $allowed_pie_sources)) { // Solo incluir fuentes permitidas
            $label = ucfirst($source);
            if ($source == 'twitter') $label = 'X (Twitter)'; // Ajustar el nombre para X
            $chart_labels[] = $label;
            $chart_data[] = ($count / $total_mentions_for_pie_chart) * 100;
            $chart_background_colors[] = $source_colors[$source] ?? '#6c757d'; // Color predeterminado si no se encuentra
        }
    }
}

?>

<div class="comparison-result">
    <h4>Menciones de @<?= htmlspecialchars($compare_user) ?> hacia Felifer</h4>

    <?php if (!empty($error_message)): ?>
        <div class="alert alert-warning"><?= $error_message ?></div>
    <?php endif; ?>

    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card shadow"> <div class="card-header">
                    <h6>Resumen de Menciones</h6>
                </div>
                <div class="card-body">
                    <p>Total de menciones: <strong><?= $compare_user_mentions_to_felifer_count ?></strong></p>
                    <p>Sentimiento General:</p>
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
                            <td><?= round($compare_user_mentions_to_felifer_percentage['positive'], 1) ?>%</td>
                            <td><?= $compare_user_mentions_to_felifer_sentiments['positive'] ?></td>
                        </tr>
                        <tr>
                            <td class="sentiment-neutral">Neutral</td>
                            <td><?= round($compare_user_mentions_to_felifer_percentage['neutral'], 1) ?>%</td>
                            <td><?= $compare_user_mentions_to_felifer_sentiments['neutral'] ?></td>
                        </tr>
                        <tr>
                            <td class="sentiment-negative">Negativo</td>
                            <td><?= round($compare_user_mentions_to_felifer_percentage['negative'], 1) ?>%</td>
                            <td><?= $compare_user_mentions_to_felifer_sentiments['negative'] ?></td>
                        </tr>
                        </tbody>
                    </table>

                    <h6 class="mt-4">Distribución de Menciones por Red Social</h6>
                    <div class="chart-container" style="position: relative; height:250px; width:100%">
                        <canvas id="mentionsPieChart"></canvas>
                    </div>
                    <?php if (empty($chart_data)): ?>
                        <p class="text-center mt-2">No hay datos de menciones por red social disponibles.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card shadow"> <div class="card-header">
                    <h6>Últimas Publicaciones de @<?= htmlspecialchars($compare_user) ?> hacia Felifer</h6>
                </div>
                <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                    <?php if (!empty($mentions_list_for_display)): ?>
                        <ul class="list-group">
                            <?php foreach ($mentions_list_for_display as $mention):
                                $author_username_display = htmlspecialchars($mention['author']['username'] ?? 'Usuario Desconocido');
                                $author_name_display = htmlspecialchars($mention['author']['name'] ?? 'Usuario Desconocido');
                                $profile_pic_url = htmlspecialchars($mention['author']['profile_pic'] ?? 'https://via.placeholder.com/20');
                                $social_network = htmlspecialchars($mention['social_network'] ?? 'Web');
                                $mention_date = $mention['published'] ?? null;
                                $mention_content = htmlspecialchars($mention['text'] ?? 'No content available.');
                                $mention_url = htmlspecialchars($mention['url'] ?? '');
                                $sentiment_class = strtolower($mention['sentiment'] ?? 'secondary');
                                $sentiment_display = ucfirst($mention['sentiment'] ?? 'Neutral');
                                ?>
                                <li class="list-group-item mb-2 shadow-sm">
                                    <p class="mb-1 d-flex align-items-center">
                                        <img src="<?= $profile_pic_url ?>" alt="Profile Pic" class="rounded-circle me-2" style="width: 25px; height: 25px;">
                                        <small class="text-muted">
                                            <?= $social_network ?> -
                                            <?= $author_name_display ?> (<?= $author_username_display ?>)
                                            (
                                            <?php
                                            if ($mention_date) {
                                                echo date('Y-m-d H:i', strtotime($mention_date));
                                            } else {
                                                echo 'Fecha Desconocida';
                                            }
                                            ?>
                                            )
                                        </small>
                                        <span class="badge bg-<?= $sentiment_class ?> ms-auto">
                                            <?= $sentiment_display ?>
                                        </span>
                                    </p>
                                    <p class="mb-1">
                                        <?= nl2br($mention_content) ?>
                                    </p>
                                    <?php if (!empty($mention_url)): ?>
                                        <a href="<?= $mention_url ?>" target="_blank" class="btn btn-sm btn-outline-primary mt-2">Ver publicación original</a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="alert alert-info text-center">
                            No se encontraron publicaciones de @<?= htmlspecialchars($compare_user) ?> mencionando a Felifer en el período seleccionado.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    // Script para la gráfica de pastel de menciones por red social del usuario comparado
    var chartLabels = <?php echo json_encode($chart_labels); ?>;
    var chartData = <?php echo json_encode($chart_data); ?>;
    var chartBackgroundColors = <?php echo json_encode($chart_background_colors); ?>;

    const mentionsPieChartCanvas = document.getElementById('mentionsPieChart');

    if (chartData.length > 0 && mentionsPieChartCanvas) {
        console.log('Inicializando gráfica de pastel para @<?= htmlspecialchars($compare_user) ?> hacia Felifer:', chartLabels, chartData);

        // Asegurar que el canvas tiene dimensiones
        mentionsPieChartCanvas.style.width = '100%';
        mentionsPieChartCanvas.style.height = '250px'; // La altura definida en el chart-container

        // Pequeño retraso para asegurar que el canvas tenga dimensiones antes de dibujar
        setTimeout(() => {
            new Chart(mentionsPieChartCanvas, {
                type: 'pie',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        data: chartData,
                        backgroundColor: chartBackgroundColors,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false, // Importante para que Chart.js use la altura del contenedor
                    plugins: {
                        legend: {
                            position: 'right', // Puedes cambiar a 'top', 'bottom', 'left'
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
            console.log('Gráfica de pastel de menciones por red social dibujada.');
        }, 50); // 50ms de retraso
    } else if (mentionsPieChartCanvas) {
        mentionsPieChartCanvas.style.display = 'none';
        const parentDiv = mentionsPieChartCanvas.closest('.card-body'); // Busca el padre más cercano con card-body
        if (parentDiv && !parentDiv.querySelector('p.no-data-message-pie-chart')) {
            parentDiv.insertAdjacentHTML('beforeend', '<p class="text-center mt-2 no-data-message-pie-chart">No hay datos de menciones por red social disponibles para esta comparación.</p>');
        }
        console.log('No hay datos para la gráfica de pastel o canvas no válido, ocultando canvas.');
    }
</script>