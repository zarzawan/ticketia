<?php

function llm_find_first_json_object(string $text): ?string {
    $len = strlen($text);
    $start = -1;
    $depth = 0;
    $inString = false;
    $escaped = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $text[$i];

        if ($start < 0) {
            if ($ch === '{') {
                $start = $i;
                $depth = 1;
                $inString = false;
                $escaped = false;
            }
            continue;
        }

        if ($inString) {
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($ch === '\\') {
                $escaped = true;
                continue;
            }
            if ($ch === '"') {
                $inString = false;
            }
            continue;
        }

        if ($ch === '"') {
            $inString = true;
            continue;
        }

        if ($ch === '{') {
            $depth++;
            continue;
        }

        if ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($text, $start, $i - $start + 1);
            }
        }
    }

    return null;
}

function llm_extract_json_payload(?string $raw): ?array {
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }

    $clean = trim($raw);
    $clean = preg_replace('/<think>[\\s\\S]*?<\\/think>/iu', '', $clean);
    $clean = preg_replace('/^```(?:json)?\\s*|\\s*```$/iu', '', $clean);

    $decoded = json_decode($clean, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        return $decoded;
    }

    $candidate = llm_find_first_json_object($clean);
    if ($candidate !== null) {
        $decoded = json_decode($candidate, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}
