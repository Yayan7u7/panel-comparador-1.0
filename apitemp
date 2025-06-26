<?php

// Importar el archivo de configuración
require_once 'config.php';

// Establecer el límite de tiempo de ejecución del script
// Esto es importante para permitir que el script se ejecute el tiempo suficiente para obtener todas las menciones.
set_time_limit(600); // 10 minutos (600 segundos). Ajusta si es necesario.

/**
 * Función genérica para realizar llamadas a la API de Brandmention.
 * Esta versión espera un array de parámetros donde 'command' es uno de los elementos.
 * Se ha mejorado la robustez y el logging.
 *
 * @param array $params Parámetros para la llamada a la API (debe incluir 'command').
 * @return array Respuesta decodificada de la API.
 */
function callBrandmentionAPI($params) {
    // Verificar si la clave API y la URL base están definidas
    if (!defined('BRANDMENTION_API_KEY') || empty(BRANDMENTION_API_KEY)) {
        error_log("ERROR: BRANDMENTION_API_KEY no está definida o está vacía en config.php.");
        return ['status' => 'error', 'message' => 'API Key no configurada.'];
    }
    if (!defined('BASE_URL') || empty(BASE_URL)) {
        error_log("ERROR: BASE_URL no está definida o está vacía en config.php.");
        return ['status' => 'error', 'message' => 'URL Base de la API no configurada.'];
    }

    $url = BASE_URL . '?api_key=' . BRANDMENTION_API_KEY;

    // Construir la URL con los parámetros
    foreach ($params as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $item) {
                $url .= '&' . urlencode($key) . '[]=' . urlencode($item);
            }
        } else {
            $url .= '&' . urlencode($key) . '=' . urlencode($value);
        }
    }

    // Opcional: Descomentar para depurar la URL de la API
    // error_log("DEBUG: Calling Brandmention API URL: " . $url);

    // Realizar la llamada a la API usando cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // Timeout de 5 minutos para la llamada cURL
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("ERROR: cURL Error: " . $curl_error);
        return ['status' => 'error', 'message' => 'Error de conexión con la API: ' . $curl_error];
    }

    // Decodificar la respuesta JSON
    $decoded_response = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("ERROR: JSON Decode Error: " . json_last_error_msg() . " Raw Response: " . $response);
        return ['status' => 'error', 'message' => 'Error al decodificar la respuesta de la API: ' . json_last_error_msg()];
    }

    if (isset($decoded_response['status']) && $decoded_response['status'] === 'error') {
        error_log("API ERROR: " . ($decoded_response['message'] ?? 'Error desconocido de la API'));
    }

    return $decoded_response;
}

/**
 * Obtiene menciones de un proyecto.
 * Esta función realiza UNA SOLA llamada a la API de Brandmention.
 * @param string $project_id El ID del proyecto en Brandmention.
 * @param string|null $start_period Fecha de inicio en formato 'Y-m-d H:i:s'.
 * @param string|null $end_period Fecha de fin en formato 'Y-m-d H:i:s'.
 * @param array $sources Redes sociales a incluir (ej. ['twitter', 'instagram', 'facebook']).
 * @param int $page La página de resultados a recuperar.
 * @param int $per_page El número de menciones por página. Máximo 100 según la API de Brandmention.
 * @return array Datos de las menciones o un arreglo de error.
 */
function getProjectMentions($project_id, $start_period = null, $end_period = null, $sources = ['twitter', 'instagram', 'facebook'], $page = 1, $per_page = 100) {
    // Asegurarse de que per_page no exceda el máximo permitido por la API (100)
    $per_page = min($per_page, 5000);

    $params = [
        'command' => 'GetProjectMentions', // Comando específico
        'project_id' => $project_id,
        'page' => $page,
        'per_page' => $per_page,
    ];

    if ($start_period) {
        $params['start_period'] = $start_period;
    }
    if ($end_period) {
        $params['end_period'] = $end_period;
    }
    // Asegúrate de que las fuentes se añadan correctamente si no están vacías
    if (!empty($sources)) {
        // La API espera 'sources[]=' para cada elemento si se envían como array
        foreach ($sources as $source) {
            $params['sources'][] = $source;
        }
    }

    $response = callBrandmentionAPI($params);

    if ($response['status'] === 'success') {
        $total_results = $response['number_of_mentions']['total'] ?? 0;
        $total_pages = ceil($total_results / $per_page);

        // Debugging log
        error_log("DEBUG: GetProjectMentions - Page: $page, Per Page: $per_page, Total Mentions Reported by API: $total_results, Total Pages Reported: $total_pages");
        return [
            'status' => 'success',
            'mentions' => $response['mentions'] ?? [],
            'pagination' => [
                'total_results' => $total_results,
                'total_pages' => $total_pages
            ]
        ];
    } else {
        error_log("ERROR in getProjectMentions: " . ($response['message'] ?? 'Error desconocido al obtener menciones del proyecto.'));
        return ['status' => 'error', 'message' => $response['message'] ?? 'Error desconocido al obtener menciones del proyecto.'];
    }
}

/**
 * Obtiene todas las menciones de un proyecto a través de paginación.
 * Esta función itera sobre múltiples páginas para recolectar un número mayor de menciones.
 *
 * @param string $project_id El ID del proyecto en Brandmention.
 * @param int $max_mentions_to_collect Cantidad máxima de menciones a recolectar.
 * @param string|null $start_period Fecha de inicio en formato 'Y-m-d H:i:s'.
 * @param string|null $end_period Fecha de fin en formato 'Y-m-d H:i:s'.
 * @param array $sources Redes sociales a incluir.
 * @return array Contiene 'status', 'mentions' (arreglo completo de menciones) y 'volume_data' (para la gráfica).
 */
function getAllProjectMentionsPaginated($project_id, $max_mentions_to_collect = 5000, $start_period = null, $end_period = null, $sources = ['twitter', 'instagram', 'facebook']) {
    $all_mentions = [];
    $page = 1;
    $initial_total_mentions_api = null; // El total de menciones que la API dice que existen
    $api_per_page = 5000; // Máximo que la API de Brandmention permite por página

    error_log("DEBUG: Pagination - Starting for Project ID: " . $project_id . ", Max Collect (local): " . $max_mentions_to_collect . ", Start: " . ($start_period ?? 'N/A') . ", End: " . ($end_period ?? 'N/A'));

    do {
        error_log("DEBUG: Pagination - Calling API for page: " . $page . ", Mentions collected so far: " . count($all_mentions));

        $response = getProjectMentions($project_id, $start_period, $end_period, $sources, $page, $api_per_page);

        if ($response['status'] === 'success' && !empty($response['mentions'])) {
            // Actualizar el total de menciones reportado por la API en la primera llamada
            if ($page === 1) {
                $initial_total_mentions_api = $response['pagination']['total_results'] ?? count($response['mentions']);
                error_log("DEBUG: Pagination - API reported total_results on first page: " . $initial_total_mentions_api . ", Total Pages API: " . ($response['pagination']['total_pages'] ?? 'N/A'));
            }

            // Añadir menciones a la lista total, respetando el límite $max_mentions_to_collect
            foreach ($response['mentions'] as $mention) {
                if (count($all_mentions) >= $max_mentions_to_collect) {
                    break 2; // Salir de ambos bucles (do-while y foreach)
                }
                $all_mentions[] = $mention;
            }

            $page++; // Preparar para la siguiente página

            // La condición para continuar será que haya más páginas según la API
            // Y que no hayamos alcanzado nuestro límite de recolección
            // Y que el número de menciones actuales sea menor que el total reportado por la API
            $continue_pagination = ($page <= ($response['pagination']['total_pages'] ?? 1)) &&
                (count($all_mentions) < $max_mentions_to_collect) &&
                (count($all_mentions) < $initial_total_mentions_api);

        } else {
            // Si hay un error, no hay más menciones, o la respuesta no fue 'success', detener la paginación.
            error_log("DEBUG: Pagination - No more mentions or API error on page " . $page . ". Status: " . ($response['status'] ?? 'N/A') . ", Message: " . ($response['message'] ?? 'No más menciones.') . ", Mentions received: " . count($response['mentions'] ?? []));
            $continue_pagination = false;
        }

    } while ($continue_pagination); // Continuar mientras haya más páginas y no hayamos alcanzado los límites

    error_log("DEBUG: Pagination - Finished. Total mentions collected: " . count($all_mentions));

    // --- Procesamiento para la gráfica de volumen de menciones ---
    $volume_data_processed = processMentionsForVolume($all_mentions); // Llama a la función auxiliar

    return [
        'status' => 'success',
        'mentions' => $all_mentions,
        'volume_data' => $volume_data_processed,
        'pagination' => [
            'total_results' => $initial_total_mentions_api ?? count($all_mentions),
            'total_pages_fetched' => $page - 1
        ]
    ];
}

/**
 * Obtiene influencers de un proyecto.
 * Adaptada para usar la nueva `callBrandmentionAPI` con array de parámetros.
 *
 * @param string $project_id El ID del proyecto en Brandmention.
 * @param string|null $start_period Fecha de inicio en formato 'Y-m-d H:i:s'.
 * @param string|null $end_period Fecha de fin en formato 'Y-m-d H:i:s'.
 * @param array $sources Redes sociales a incluir (ej. ['twitter', 'instagram', 'facebook']).
 * @return array Datos de los influencers o un arreglo de error.
 */
function getProjectInfluencers($project_id, $start_period = null, $end_period = null, $sources = ['twitter', 'instagram', 'facebook']) {
    $params = [
        'command' => 'GetProjectInfluencers', // Comando específico
        'project_id' => $project_id,
        'per_page' => 5000, // Puedes ajustar esto, Brandmention suele permitir hasta 100 o 200
        'page' => 1, // Para esta función, usualmente queremos la primera página de los top influencers
    ];
    if ($start_period) $params['start_period'] = $start_period;
    if ($end_period) $params['end_period'] = $end_period;
    if (!empty($sources)) {
        foreach ($sources as $source) {
            $params['sources'][] = $source;
        }
    }

    $response = callBrandmentionAPI($params);

    if ($response['status'] === 'success') {
        return [
            'status' => 'success',
            'influencers' => $response['influencers'] ?? [], // La API devuelve una clave 'influencers'
            'pagination' => $response['pagination'] ?? []
        ];
    } else {
        error_log("API ERROR in getProjectInfluencers: " . ($response['message'] ?? 'Error desconocido al obtener influencers.'));
        return ['status' => 'error', 'message' => $response['message'] ?? 'Error desconocido al obtener influencers.'];
    }
}

/**
 * Crea un nuevo proyecto en Brandmention.
 * Adaptada para usar la nueva `callBrandmentionAPI` con array de parámetros.
 * (Generalmente se ejecuta una vez para configurar el monitoreo de keywords).
 *
 * @param array $keywords Lista de palabras clave para el proyecto.
 * @param array $sources Redes sociales a monitorear.
 * @return array Resultado de la operación de creación de proyecto.
 */
function addProject($keywords, $sources = ['twitter', 'instagram', 'facebook']) {
    $params = [
        'command' => 'AddProject', // Comando específico
        'keyword1' => $keywords[0],
        'match_type1' => 'exact',
        'active_sources' => $sources,
        'name' => 'felifer Mentions' // Ajusta el nombre del proyecto si es necesario
    ];
    if (isset($keywords[1])) $params['keyword2'] = $keywords[1];
    if (isset($keywords[2])) $params['keyword3'] = $keywords[2];

    return callBrandmentionAPI($params);
}

/**
 * Obtiene las menciones por un autor y palabra clave específicos, paginando.
 * @param string $project_id El ID del proyecto en Brandmention.
 * @param string $author_input El nombre de usuario o nombre del autor a buscar.
 * @param string|null $start_period Fecha de inicio en formato 'Y-m-d H:i:s'.
 * @param string|null $end_period Fecha de fin en formato 'Y-m-d H:i:s'.
 * @param array $sources Redes sociales a incluir.
 * @param int $per_page Cuántas menciones solicitar en cada llamada a la API (máximo 100).
 * @param int $max_mentions_to_find Cantidad máxima de menciones a encontrar para este autor.
 * @return array Arreglo de menciones filtradas por el autor.
 */
function getMentionsByAuthorAndKeyword($project_id, $author_input, $start_period = null, $end_period = null, $sources = ['twitter', 'instagram', 'facebook'], $per_page = 100, $max_mentions_to_find = 5000) {
    $all_mentions = [];
    $page = 1;
    $initial_total_mentions_api = null; // Para el comparador también
    $author_input_normalized = strtolower(trim($author_input, '@ '));

    do {
        $current_per_page = min($per_page, 5000);

        $response = getProjectMentions($project_id, $start_period, $end_period, $sources, $page, $current_per_page);

        if ($response['status'] === 'success' && !empty($response['mentions'])) {
            // Actualizar el total de menciones reportado por la API en la primera llamada
            if ($page === 1) {
                $initial_total_mentions_api = $response['pagination']['total_results'] ?? count($response['mentions']);
            }

            $filtered_mentions_on_page = array_filter($response['mentions'], function($mention) use ($author_input_normalized) {
                $author_username = strtolower($mention['author']['username'] ?? '');
                $author_name = strtolower($mention['author']['name'] ?? '');

                $match = false;
                if (!empty($author_username) && $author_username === $author_input_normalized) {
                    $match = true;
                }
                if (!$match && !empty($author_name) && $author_name === $author_input_normalized) {
                    $match = true;
                }

                if (!$match && str_starts_with($author_input_normalized, '@')) {
                    $author_input_without_at = ltrim($author_input_normalized, '@');
                    if (!empty($author_username) && $author_username === $author_input_without_at) {
                        $match = true;
                    }
                    if (!$match && !empty($author_name) && $author_name === $author_input_without_at) {
                        $match = true;
                    }
                }
                return $match;
            });

            // Añadir menciones filtradas a la lista total, respetando el límite
            foreach ($filtered_mentions_on_page as $mention) {
                if (count($all_mentions) >= $max_mentions_to_find) {
                    break 2; // Salir de ambos bucles (do-while y foreach)
                }
                $all_mentions[] = $mention;
            }

            $page++; // Preparar para la siguiente página

            // Condición para continuar paginando (similar a getAllProjectMentionsPaginated)
            $continue_pagination = ($page <= ($response['pagination']['total_pages'] ?? 1)) &&
                (count($all_mentions) < $max_mentions_to_find) &&
                (count($all_mentions) < $initial_total_mentions_api);

        } else {
            $continue_pagination = false;
            error_log("ERROR: getMentionsByAuthorAndKeyword - Paginación detenida por error de la API o no más menciones. Estado: " . ($response['status'] ?? 'N/A') . " Mensaje: " . ($response['message'] ?? 'No más menciones.'));
        }

        // Si ya hemos alcanzado el límite de menciones para el autor, detenerse
        if (count($all_mentions) >= $max_mentions_to_find) {
            break;
        }

        // usleep(50000); // Pequeña pausa opcional para evitar saturar la API.
    } while ($continue_pagination);

    return ['status' => 'success', 'mentions' => $all_mentions];
}


/**
 * Función auxiliar para generar datos de la nube de palabras, incluyendo N-gramas.
 *
 * @param array $mentions Arreglo de menciones.
 * @param int $limit Número máximo de palabras/frases a mostrar.
 * @return array Arreglo de palabras/frases y sus frecuencias para la nube de palabras.
 */
function getWordCloudData($mentions, $limit = 50) {
    $word_counts = [];
    $stop_words = [
        'de','nos', 'la', 'el', 'en', 'y', 'a', 'con', 'que', 'por', 'para', 'un', 'una', 'los', 'las', 'del', 'al', 'se', 'es', 'su', 'sus', 'no', 'si', 'pero', 'como', 'cuando', 'este', 'esta', 'estos', 'estas', 'ser', 'hacer', 'tener', 'ir', 'mi', 'me', 'tu', 'te', 'lo', 'la', 'les', 'le', 'uno', 'unas',
        'mil', '0', 'qro', 'gobierno', 'estado', 'del', 'mas', 'sera', 'bien', 'solo', 'gran', 'este', 'estos', 'esta', 'estas', 'hoy', 'siempre', 'asi', 'hay', 'sin', 'poder', 'desde', 'muy', 'todo', 'todos', 'toda', 'todas', 'mucho', 'muchos', 'muchas', 'parte', 'donde', 'vamos', 'hacia', 'entre', 'ver', 'hacer', 'tener', 'ir', 'estar', 'dijo', 'dicen', 'hace', 'va', 'son', 'han', 'hay', 'fue', 'fueron', 'esta', 'estan', 'este', 'esta', 'estos', 'estas',
        // --- Palabras específicas de Felifer a excluir (NORMALIZADAS a sin acentos) ---
        'felifer', 'felifermacias', 'felifermaciaso', 'macias', 'maciaso', 'maciasfelipe', 'felipe', 'feli', 'fernando', 'fer', 'ferna', 'fel',
    ];
    $stop_words_map = array_flip($stop_words);

    foreach ($mentions as $mention) {
        $text = $mention['text'] ?? ''; // Usar 'text' en lugar de 'content' si la API lo devuelve así
        if (empty($text)) continue;

        // 1. Eliminar URLs, menciones de usuarios y hashtags
        $text = preg_replace('/(https?:\/\/[^\s]+)/', '', $text);
        $text = preg_replace('/@[^\s]+/u', '', $text);
        $text = preg_replace('/#[^\s]+/u', '', $text);

        // 2. Transliterar (quitar acentos, etc.) y limpiar caracteres no deseados
        if (function_exists('iconv')) {
            $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        } else {
            $text = strtr($text,
                'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûüýþÿ',
                'AAAAAAACEEEEIIIIDNOOOOOOUUUUYBaaaaaaaceeeeiiiidnoooooouuuuyby'
            );
        }

        $text = str_replace(["'", "`", "´"], "", $text);

        // 3. Limpiar puntuación y dejar solo letras, números y espacios. Convertir a minúsculas
        $text = preg_replace('/[^a-zA-Z0-9\s]+/', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);
        $text = strtolower($text);

        // 4. Dividir la cadena en palabras
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        $clean_words = [];
        foreach ($words as $word) {
            if (mb_strlen($word, 'UTF-8') > 2 && !isset($stop_words_map[$word]) && !is_numeric($word)) {
                $clean_words[] = $word;
            }
        }

        $num_clean_words = count($clean_words);

        for ($i = 0; $i < $num_clean_words; $i++) {
            $word = $clean_words[$i];
            $word_counts[$word] = ($word_counts[$word] ?? 0) + 1;

            if ($i + 1 < $num_clean_words) {
                $bigram = $clean_words[$i] . ' ' . $clean_words[$i+1];
                if (!isset($stop_words_map[$clean_words[$i]]) && !isset($stop_words_map[$clean_words[$i+1]])) {
                    $word_counts[$bigram] = ($word_counts[$bigram] ?? 0) + 1;
                }
            }

            if ($i + 2 < $num_clean_words) {
                $trigram = $clean_words[$i] . ' ' . $clean_words[$i+1] . ' ' . $clean_words[$i+2];
                if (!isset($stop_words_map[$clean_words[$i]]) && !isset($stop_words_map[$clean_words[$i+1]]) && !isset($stop_words_map[$clean_words[$i+2]])) {
                    $word_counts[$trigram] = ($word_counts[$trigram] ?? 0) + 1;
                }
            }
        }
    }

    arsort($word_counts);

    $final_word_cloud = [];
    $current_count = 0;
    foreach ($word_counts as $word => $count) {
        $parts = explode(' ', $word);
        $is_valid_phrase = true;
        foreach ($parts as $part) {
            if (mb_strlen($part, 'UTF-8') <= 2 || isset($stop_words_map[$part]) || is_numeric($part)) {
                $is_valid_phrase = false;
                break;
            }
        }

        if ($is_valid_phrase) {
            $final_word_cloud[$word] = $count;
            $current_count++;
            if ($current_count >= $limit) {
                break;
            }
        }
    }

    return array_map(function($word, $count) {
        return [$word, max(1, $count * 8)]; // Multiplicar por 8 para darles más peso visual
    }, array_keys($final_word_cloud), array_values($final_word_cloud));
}


/**
 * Procesa las menciones recolectadas para generar los datos de volumen por fecha.
 * @param array $mentions Array de menciones obtenidos de la API.
 * @return array Array con 'labels' (fechas) y 'data' (conteos).
 */
function processMentionsForVolume($mentions) {
    $volume_counts = [];

    foreach ($mentions as $mention) {
        // Asegúrate de que la clave 'published' exista y no sea nula
        if (isset($mention['published']) && $mention['published'] !== null) {
            // Extrae solo la parte de la fecha (YYYY-MM-DD)
            $date = date('Y-m-d', strtotime($mention['published']));
            $volume_counts[$date] = ($volume_counts[$date] ?? 0) + 1;
        }
    }

    // Ordenar las fechas cronológicamente
    ksort($volume_counts);

    $labels = array_keys($volume_counts);
    $data = array_values($volume_counts);

    return [
        'labels' => $labels,
        'data' => $data
    ];
}
?>