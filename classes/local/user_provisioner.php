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
 * Creates or loads users by email and returns WhatsApp-ready credentials text.
 *
 * @package   local_gimidashboard
 * @copyright 2026
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_gimidashboard\local;

use core_text;
use Exception;
use stdClass;

/**
 * Creates or loads users by email and returns WhatsApp-ready credentials text.
 */
class user_provisioner {
    /**
     * Split full name into firstname and lastname.
     *
     * @param string $fullname
     * @return array [firstname, lastname]
     */
    public static function split_name(string $fullname): array {
        $fullname = trim(preg_replace('/\s+/', ' ', $fullname));
        if ($fullname === '') {
            return ['Student', '.'];
        }

        $parts = explode(' ', $fullname);
        $firstname = array_shift($parts);
        $lastname = trim(implode(' ', $parts));

        // Moodle requires lastname to be non-empty.
        if ($lastname === '') {
            $lastname = '.';
        }

        return [$firstname, $lastname];
    }

    /**
     * Get existing user by email (non-deleted), or create a new user.
     *
     * @param string $fullname
     * @param string $email
     * @return array
     *   [
     *     'user' => \stdClass,
     *     'isnew' => bool,
     *     'password' => string|null,
     *     'username' => string
     *   ]
     * @throws Exception
     */
    public static function get_or_create_by_email(string $fullname, string $email): array {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/user/lib.php');

        $email = trim(core_text::strtolower($email));

        $existing = $DB->get_record('user', ['email' => $email, 'deleted' => 0], '*', IGNORE_MISSING);
        if ($existing) {
            return [
                'user' => $existing,
                'isnew' => false,
                'password' => null,
                'username' => $existing->username,
            ];
        }

        [$firstname, $lastname] = self::split_name($fullname);

        // Base username: email (common and simple).
        $baseusername = $email;
        $username = $baseusername;

        // Ensure username uniqueness.
        $suffix = 1;
        while ($DB->record_exists('user', ['username' => $username])) {
            $suffix++;
            $username = $baseusername . '.' . $suffix;
        }

        $password = $email; // generate_password(12);

        $user = (object) [
            'auth' => 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
            'username' => $username,
            'email' => $email,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'lang' => 'en',
            'timezone' => 99,
            'password' => $password,
        ];

        $user->id = user_create_user($user, true, false);
        set_user_preference('auth_forcepasswordchange', 1, $user->id);

        return [
            'user' => $user,
            'isnew' => true,
            'password' => $password,
            'username' => $user->username,
        ];
    }

    /**
     * Build WhatsApp-ready message.
     *
     * @param stdClass $user
     * @param bool $isnew
     * @param string|null $password
     * @return string
     */
    public static function build_whatsapp_text(stdClass $user, bool $isnew, ?string $password): string {
        global $CFG;

        $loginurl = "{$CFG->wwwroot}/login/";
        $fullname = fullname($user);

        if ($isnew) {
            $ssourl = self::sso($user);
            return "Welcome {$fullname},\n\n" .
                "Your account has been successfully created and your access is now available.\n\n" .
                "You can log in using the information below:\n" .
                "Direct Access URL: {$CFG->wwwroot}/{$ssourl}\n" .
                "Login URL: {$loginurl}\n" .
                "Username: {$user->username}\n" .
                "Password: {$password}\n\n" .
                "If you have any questions or experience any issues, please feel free to contact our support team.\n\n" .
                "Best regards,\n" .
                "LMS administration Team";
        }

        return "Welcome {$fullname},\n\n" .
            "You have been added to the cohort and your access is now available.\n\n" .
            "You can log in using the information below:\n" .
            "Login URL: {$loginurl}\n" .
            "Username: {$user->username}\n" .
            "Password: (use your current password)\n\n" .
            "If you have any questions or experience any issues, please feel free to contact our support team.\n\n" .
            "Best regards,\n" .
            "LMS administration Team";
    }

    public static $token = "a9f3c1d7e4b8f2a6c0d9e1f7b4a8c6d2e9f0b1a3d5c7e8f4a6b2";

    /**
     * Create sso link
     *
     * @param $user
     * @return string
     */
    private static function sso($user) {
        // Helpers base64url.
        $b64url_encode = static function(string $data): string {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };

        // Header + payload (inclui exp curto por segurança).
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'username' => $user->username,
            'nbf' => time() - 5,
            'exp' => time() + 15 * 60, // 15 minutos
        ];

        $headerb64 = $b64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadb64 = $b64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        // Assina com o external_tokens.token (HS256).
        $data = "{$headerb64}.{$payloadb64}";
        $sig = hash_hmac('sha256', $data, self::$token, true);
        $sigb64 = $b64url_encode($sig);

        $jwt = "{$headerb64}.{$payloadb64}.{$sigb64}";

        $afterLoginPath = '/my/';

        return 'local/gimidashboard/sso.php?jwt=' . rawurlencode($jwt) . '&url=' . rawurlencode($afterLoginPath);
    }
}
