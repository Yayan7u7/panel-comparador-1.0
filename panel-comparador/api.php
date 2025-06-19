<?php
// api.php
set_time_limit(300); // Aumenta el límite a 300 segundos (5 minutos) para operaciones largas
require_once 'config.php';

function callBrandmentionAPI($command, $params = []) {
    $url = BASE_URL . '?api_key=' . BRANDMENTION_API_KEY . '&command=' . $command;
    foreach ($params as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $index => $val) {
                $url .= "&$key" . "[]=" . urlencode($val);
            }
        } else {
            $url .= "&$key=" . urlencode($value);
        }
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout de 30 segundos
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_error) {
        return ['status' => 'error', 'message' => 'cURL error: ' . $curl_error];
    }

    if ($http_code !== 200) {
        $data = json_decode($response, true);
        $errorMessage = $data['message'] ?? 'Error HTTP desconocido';
        return ['status' => 'error', 'message' => 'HTTP error: ' . $http_code . ' - ' . $errorMessage];
    }

    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['status' => 'error', 'message' => 'JSON decode error: ' . json_last_error_msg() . ' Raw response: ' . $response];
    }

    if (!$data || !isset($data['status']) || $data['status'] === 'error') {
        return ['status' => 'error', 'message' => $data['message'] ?? 'Unknown API error'];
    }

    return $data;
}

/**
 * Obtiene menciones de un proyecto.
 * @param string $project_id El ID del proyecto en Brandmention.
 * @param string|null $start_period Fecha de inicio en formato 'Y-m-d H:i:s'.
 * @param string|null $end_period Fecha de fin en formato 'Y-m-d H:i:s'.
 * @param array $sources Redes sociales a incluir (ej. ['twitter', 'instagram', 'facebook']).
 * @param int $page Número de página de resultados.
 * @param int $per_page Cantidad de menciones por página.
 * @return array Datos de las menciones o un arreglo de error.
 */
function getProjectMentions($project_id, $start_period = null, $end_period = null, $sources = ['twitter', 'instagram', 'facebook'], $page = 1, $per_page = 5000) {
    $params = [
        'project_id' => $project_id,
        'page' => $page,
        'per_page' => $per_page,
        'sources' => $sources
    ];
    if ($start_period) $params['start_period'] = $start_period;
    if ($end_period) $params['end_period'] = $end_period;

    return callBrandmentionAPI('GetProjectMentions', $params);
}

/**
 * Obtiene influencers de un proyecto.
 * @param string $project_id El ID del proyecto en Brandmention.
 * @param string|null $start_period Fecha de inicio en formato 'Y-m-d H:i:s'.
 * @param string|null $end_period Fecha de fin en formato 'Y-m-d H:i:s'.
 * @param array $sources Redes sociales a incluir (ej. ['twitter', 'instagram', 'facebook']).
 * @return array Datos de los influencers o un arreglo de error.
 */
function getProjectInfluencers($project_id, $start_period = null, $end_period = null, $sources = ['twitter', 'instagram', 'facebook']) {
    $params = [
        'project_id' => $project_id,
        'sources' => $sources
    ];
    if ($start_period) $params['start_period'] = $start_period;
    if ($end_period) $params['end_period'] = $end_period;

    return callBrandmentionAPI('GetProjectInfluencers', $params);
}

/**
 * Crea un nuevo proyecto en Brandmention.
 * (Generalmente se ejecuta una vez para configurar el monitoreo de keywords).
 * @param array $keywords Lista de palabras clave para el proyecto.
 * @param array $sources Redes sociales a monitorear.
 * @return array Resultado de la operación de creación de proyecto.
 */
function addProject($keywords, $sources = ['twitter', 'instagram', 'facebook']) {
    $params = [
        'keyword1' => $keywords[0],
        'match_type1' => 'exact',
        'active_sources' => $sources,
        'name' => 'felifer Mentions'
    ];
    if (isset($keywords[1])) $params['keyword2'] = $keywords[1];
    if (isset($keywords[2])) $params['keyword3'] = $keywords[2];

    return callBrandmentionAPI('AddProject', $params);
}

/**
 * Obtiene menciones de un proyecto filtradas por un autor específico.
 * @param string $project_id El ID del proyecto en Brandmention (el de Felifer).
 * @param string $author_input El nombre de usuario o nombre de display del perfil a buscar.
 * @param string|null $start_period Fecha de inicio en formato 'Y-m-d H:i:s'.
 * @param string|null $end_period Fecha de fin en formato 'Y-m-d H:i:s'.
 * @param array $sources Redes sociales a incluir.
 * @param int $per_page Cantidad de menciones por página.
 * @param int $max_mentions_to_find Cantidad máxima de menciones a buscar y devolver.
 * @return array Menciones filtradas.
 */
function getMentionsByAuthorAndKeyword($project_id, $author_input, $start_period = null, $end_period = null, $sources = ['twitter', 'instagram', 'facebook'], $per_page = 100, $max_mentions_to_find = 500) {
    $all_mentions = [];
    $page = 1;
    $has_more_pages = true;

    // Normalizar el input del autor para comparación
    $author_input_normalized = strtolower(trim($author_input, '@ '));

    while ($has_more_pages && count($all_mentions) < $max_mentions_to_find) { // Añadir condición de límite
        $response = getProjectMentions($project_id, $start_period, $end_period, $sources, $page, $per_page);

        if ($response['status'] === 'success' && !empty($response['mentions'])) {
            $filtered_mentions = array_filter($response['mentions'], function($mention) use ($author_input_normalized) {
                $author_username = strtolower($mention['author']['username'] ?? '');
                $author_name = strtolower($mention['author']['name'] ?? '');

                $match = false;
                // Coincidencia exacta con username o name
                if (!empty($author_username) && $author_username === $author_input_normalized) {
                    $match = true;
                }
                if (!$match && !empty($author_name) && $author_name === $author_input_normalized) {
                    $match = true;
                }

                // Si el input es un @username (como @voz_imparcial), también verificar el username/name de la mención sin el @
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

            // Añadir solo las menciones filtradas, deteniéndose si se alcanza el límite
            foreach ($filtered_mentions as $mention) {
                if (count($all_mentions) >= $max_mentions_to_find) {
                    $has_more_pages = false; // Detener la paginación si ya tenemos suficientes
                    break;
                }
                $all_mentions[] = $mention;
            }

            // Lógica de paginación
            if (isset($response['pagination']) && isset($response['pagination']['total_pages'])) {
                $has_more_pages = ($page < $response['pagination']['total_pages']);
            } elseif (count($response['mentions']) < $per_page) {
                // Si no hay paginación explícita o recibimos menos que per_page, asumimos que no hay más.
                $has_more_pages = false;
            } else {
                // Si recibimos per_page, podría haber más, por lo que seguimos.
                $has_more_pages = true;
            }
            $page++;
        } else {
            $has_more_pages = false;
        }
    }
    return ['status' => 'success', 'mentions' => $all_mentions];
}


/**
 * Función auxiliar para generar datos de la nube de palabras, incluyendo N-gramas.
 * @param array $mentions Arreglo de menciones.
 * @param int $limit Número máximo de palabras/frases a mostrar.
 * @return array Arreglo de palabras/frases y sus frecuencias para la nube de palabras.
 */
function getWordCloudData($mentions, $limit = 50) {
    $word_counts = [];
    $stop_words = [
        'de','nos', 'la', 'el', 'en', 'y', 'a', 'con', 'que', 'por', 'para', 'un', 'una', 'los', 'las', 'del', 'al', 'se', 'es', 'su', 'sus', 'no', 'si', 'pero', 'como', 'cuando', 'este', 'esta', 'estos', 'estas', 'ser', 'hacer', 'tener', 'ir', 'mi', 'me', 'tu', 'te', 'lo', 'la', 'les', 'le', 'uno', 'unas',
        'mil', '0', 'qro', 'gobierno', 'estado', 'municipio', 'del', 'mas', 'sera', 'bien', 'solo', 'gran', 'este', 'estos', 'esta', 'estas', 'hoy', 'siempre', 'asi', 'hay', 'sin', 'poder', 'desde', 'muy', 'todo', 'todos', 'toda', 'todas', 'mucho', 'muchos', 'muchas', 'parte', 'donde', 'vamos', 'hacia', 'entre', 'ver', 'hacer', 'tener', 'ir', 'estar', 'dijo', 'dicen', 'hace', 'va', 'son', 'han', 'hay', 'fue', 'fueron', 'esta', 'estan', 'este', 'esta', 'estos', 'estas',
        // --- Palabras específicas de Felifer a excluir (NORMALIZADAS a sin acentos) ---
        'felifer', 'felifermacias', 'felifermaciaso', 'macias', 'maciaso', 'maciasfelipe', 'felipe', 'feli', 'fernando', 'fer', 'ferna', 'fel',

    ];
    $stop_words_map = array_flip($stop_words);

    foreach ($mentions as $mention) {
        $text = $mention['text'] ?? '';
        if (empty($text)) continue;

        // 1. Eliminar URLs, menciones de usuarios y hashtags
        $text = preg_replace('/(https?:\/\/[^\s]+)/', '', $text);
        $text = preg_replace('/@[^\s]+/u', '', $text);
        $text = preg_replace('/#[^\s]+/u', '', $text);

        // 2. Transliterar (quitar acentos, etc.) y limpiar caracteres no deseados
        $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = str_replace(["'", "`", "´"], "", $text); // Eliminar apóstrofes y similares

        // 3. Limpiar puntuación y dejar solo letras, números y espacios. Convertir a minúsculas
        $text = preg_replace('/[^a-zA-Z0-9\s]+/', ' ', $text); // Regex compatible con ASCII
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
        return [$word, max(1, $count * 8)];
    }, array_keys($final_word_cloud), array_values($final_word_cloud));
}
?>