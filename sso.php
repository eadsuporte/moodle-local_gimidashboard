<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * SSO simples via JWT assinado com mdl_external_tokens.token.
 * Payload deve conter { "username": "..." }.
 * Algoritmo esperado: HS256.
 *
 * @package   local_gimidashboard
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\session\manager;
use local_gimidashboard\local\user_provisioner;

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/moodlelib.php');

@header('X-Content-Type-Options: nosniff');

$jwt = required_param('jwt', PARAM_RAW_TRIMMED);

$parts = gimi_parse_jwt($jwt);
if (empty($parts)) {
    http_response_code(400);
    echo "Invalid link (format).";
    exit;
}

[$headerb64, $payloadb64, $sigb64] = $parts;

$header = gimi_decode_segment_json($headerb64);
$payload = gimi_decode_segment_json($payloadb64);

if (!$header || !$payload) {
    http_response_code(400);
    echo "Invalid link (header/payload).";
    exit;
}

// Restringe algoritmo por segurança.
$alg = $header['alg'] ?? '';
if ($alg !== 'HS256') {
    http_response_code(400);
    echo "Unsupported link algorithm.";
    exit;
}

// Username no payload.
$username = $payload['username'] ?? '';
if (!is_string($username) || $username === '') {
    http_response_code(400);
    echo "Payload without 'username'.";
    exit;
}

// Valida exp, se existir.
$now = time();
if (isset($payload['exp']) && is_numeric($payload['exp']) && $now >= (int)$payload['exp']) {
    http_response_code(401);
    echo "Link expired.";
    exit;
}

// Busca usuário pelo username.
$user = $DB->get_record('user', ['username' => $username, 'deleted' => 0, 'suspended' => 0]);
if (!$user) {
    http_response_code(404);
    echo "User not found.";
    exit;
}

// Bloqueia SSO para administradores do site.
if (is_siteadmin($user)) {
    http_response_code(403);
    echo "SSO not allowed for administrators.";
    exit;
}

if (gimi_verify_hs256($headerb64, $payloadb64, $sigb64, user_provisioner::$token)) {
    // Faz login.
    $SESSION->tool_mfa_authenticated = true;
    manager::login_user($user);

    // Update login times.
    update_user_login_times();

    // Extra session prefs init.
    set_login_session_preferences();

    // Redirecionamento seguro.
    $url = optional_param("url", '/course/', PARAM_RAW);
    $target = $CFG->wwwroot . $url;
    redirect($target);
}

http_response_code(401);
echo "Invalid signature.";
exit;

/**
 * Base64 URL decode.
 *
 * @param string $data
 * @return string
 */
function gimi_base64url_decode(string $data): string {
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    $data = strtr($data, '-_', '+/');
    $decoded = base64_decode($data, true);
    return $decoded === false ? '' : $decoded;
}

/**
 * Base64 URL encode.
 *
 * @param string $data
 * @return string
 */
function gimi_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Parse JWT into parts.
 *
 * @param string $jwt
 * @return array
 */
function gimi_parse_jwt(string $jwt): array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return [];
    }
    return $parts;
}

/**
 * Decode JSON from base64url segment.
 *
 * @param string $segment
 * @return array|null
 */
function gimi_decode_segment_json(string $segment): ?array {
    $json = gimi_base64url_decode($segment);
    if ($json === '') {
        return null;
    }
    $data = json_decode($json, true);
    return is_array($data) ? $data : null;
}

/**
 * Constant-time string compare.
 *
 * @param string $a
 * @param string $b
 * @return bool
 */
function gimi_hash_equals(string $a, string $b): bool {
    if (function_exists('hash_equals')) {
        return hash_equals($a, $b);
    }
    if (strlen($a) !== strlen($b)) {
        return false;
    }
    $res = 0;
    for ($i = 0; $i < strlen($a); $i++) {
        $res |= ord($a[$i]) ^ ord($b[$i]);
    }
    return $res === 0;
}

/**
 * Verify HS256 signature.
 *
 * @param string $headerb64
 * @param string $payloadb64
 * @param string $sigb64
 * @param string $secret
 * @return bool
 */
function gimi_verify_hs256(string $headerb64, string $payloadb64, string $sigb64, string $secret): bool {
    $data = $headerb64 . '.' . $payloadb64;
    $raw = hash_hmac('sha256', $data, $secret, true);
    $expected = gimi_base64url_encode($raw);
    return gimi_hash_equals($expected, $sigb64);
}
