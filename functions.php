<?php
session_start();

function get_config() {
    static $config = null;
    if ($config === null) {
        $config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
        if ($config === null) {
            die('Error: No se pudo cargar config.json');
        }
    }
    return $config;
}

// ==================== LDAP ====================
function ldap_connect_samba($config) {
    $ldapconn = ldap_connect($config['ldap']['host'], $config['ldap']['port']);
    if (!$ldapconn) {
        return ['success' => false, 'error' => 'No se pudo conectar al host LDAP'];
    }
    ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldapconn, LDAP_OPT_REFERRALS, 0);
    if ($config['ldap']['use_tls']) {
        if (!ldap_start_tls($ldapconn)) {
            return ['success' => false, 'error' => 'Error al iniciar TLS: ' . ldap_error($ldapconn)];
        }
    }
    $domain_parts = explode(',', $config['ldap']['base_dn']);
    $domain_fqdn = '';
    foreach ($domain_parts as $part) {
        if (stripos($part, 'dc=') === 0) {
            $domain_fqdn .= substr($part, 3) . '.';
        }
    }
    $domain_fqdn = rtrim($domain_fqdn, '.');
    $admin_upn = 'administrator@' . $domain_fqdn;
    $bind = @ldap_bind($ldapconn, $admin_upn, $config['ldap']['admin_password']);
    if (!$bind) {
        $bind = @ldap_bind($ldapconn, $config['ldap']['admin_dn'], $config['ldap']['admin_password']);
    }
    if (!$bind) {
        return ['success' => false, 'error' => 'Error de autenticación LDAP: ' . ldap_error($ldapconn)];
    }
    return ['success' => true, 'conn' => $ldapconn];
}

function get_all_ous($ldapconn, $base_dn) {
    $search = ldap_search($ldapconn, $base_dn, '(objectClass=organizationalUnit)', ['ou', 'distinguishedName']);
    if (!$search) return [];
    $entries = ldap_get_entries($ldapconn, $search);
    $ous = [];
    for ($i = 0; $i < $entries['count']; $i++) {
        $ous[] = [
            'ou' => $entries[$i]['ou'][0] ?? '',
            'dn' => $entries[$i]['distinguishedname'][0]
        ];
    }
    return $ous;
}

function get_ou_dropdown($ldapconn, $base_dn, $selected = '') {
    $ous = get_all_ous($ldapconn, $base_dn);
    $html = '<option value="">Todas las unidades</option>';
    foreach ($ous as $ou) {
        $selected_attr = ($selected == $ou['dn']) ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($ou['dn']) . '" ' . $selected_attr . '>' . htmlspecialchars($ou['ou']) . '</option>';
    }
    return $html;
}

function get_all_groups($ldapconn, $base_dn) {
    $config = get_config();
    $include_list = $config['group_filter']['include_list'] ?? [];
    $search = ldap_search($ldapconn, $base_dn, '(objectClass=group)', ['cn', 'distinguishedName']);
    if (!$search) return [];
    $entries = ldap_get_entries($ldapconn, $search);
    $groups = [];
    for ($i = 0; $i < $entries['count']; $i++) {
        $cn = $entries[$i]['cn'][0] ?? '';
        if (empty($include_list) || in_array($cn, $include_list)) {
            $groups[] = [
                'cn' => $cn,
                'dn' => $entries[$i]['distinguishedname'][0]
            ];
        }
    }
    return $groups;
}

function is_user_excluded($username, $config) {
    $exclude_list = $config['exclude_users'] ?? [];
    return in_array($username, $exclude_list);
}

function get_ou_from_dn($dn) {
    $parts = explode(',', $dn);
    foreach ($parts as $part) {
        if (stripos($part, 'OU=') === 0) {
            return substr($part, 3);
        }
    }
    return '';
}

function filter_by_ou($users, $ou_dn_filter, $config) {
    if (empty($ou_dn_filter)) return $users;
    $ou_name = get_ou_from_dn($ou_dn_filter);
    if (empty($ou_name)) return $users;
    $filtered = [];
    foreach ($users as $user) {
        $user_dn = $user['distinguishedname'][0] ?? '';
        $user_ou = get_ou_from_dn($user_dn);
        if (strcasecmp($user_ou, $ou_name) === 0) {
            $filtered[] = $user;
        }
    }
    return $filtered;
}

// Función para ordenar array de usuarios
function sort_users($users, $sort_by, $sort_order) {
    if (empty($sort_by)) return $users;
    
    $order = ($sort_order === 'desc') ? -1 : 1;
    
    usort($users, function($a, $b) use ($sort_by, $order) {
        $val_a = '';
        $val_b = '';
        switch ($sort_by) {
            case 'username':
                $val_a = strtolower($a['samaccountname'][0] ?? '');
                $val_b = strtolower($b['samaccountname'][0] ?? '');
                break;
            case 'fullname':
                $given_a = $a['givenname'][0] ?? '';
                $sn_a = $a['sn'][0] ?? '';
                $full_a = trim($given_a . ' ' . $sn_a);
                if (empty($full_a)) $full_a = $a['samaccountname'][0] ?? '';
                $given_b = $b['givenname'][0] ?? '';
                $sn_b = $b['sn'][0] ?? '';
                $full_b = trim($given_b . ' ' . $sn_b);
                if (empty($full_b)) $full_b = $b['samaccountname'][0] ?? '';
                $val_a = strtolower($full_a);
                $val_b = strtolower($full_b);
                break;
            case 'email':
                $val_a = strtolower(($a['samaccountname'][0] ?? '') . '@ecim.co.cu');
                $val_b = strtolower(($b['samaccountname'][0] ?? '') . '@ecim.co.cu');
                break;
            case 'ou':
                $dn_a = $a['distinguishedname'][0] ?? '';
                $dn_b = $b['distinguishedname'][0] ?? '';
                $val_a = strtolower(get_ou_from_dn($dn_a));
                $val_b = strtolower(get_ou_from_dn($dn_b));
                break;
            default:
                return 0;
        }
        if ($val_a == $val_b) return 0;
        return ($val_a < $val_b) ? -$order : $order;
    });
    return $users;
}

function get_users_paged($ldapconn, $base_dn, $page = 1, $perpage = 20, $search = '', $ou_filter = '', $sort_by = '', $sort_order = 'asc') {
    $config = get_config();
    $exclude_list = $config['exclude_users'] ?? [];
    $filter = '(&(objectClass=user)(!(objectClass=computer)))';
    if (!empty($search)) {
        $search_escaped = ldap_escape($search, '', LDAP_ESCAPE_FILTER);
        $filter = '(&(objectClass=user)(!(objectClass=computer))(|(cn=*' . $search_escaped . '*)(givenName=*' . $search_escaped . '*)(sn=*' . $search_escaped . '*)))';
    }
    $attrs = ['cn', 'givenName', 'sn', 'userAccountControl', 'distinguishedName', 'sAMAccountName'];
    $cookie = '';
    $all_users = [];
    
    if (function_exists('ldap_control_paged_result')) {
        ldap_control_paged_result($ldapconn, 1000, true, $cookie);
        do {
            $search_result = ldap_search($ldapconn, $base_dn, $filter, $attrs, 0, 0, 0, LDAP_DEREF_NEVER);
            if (!$search_result) break;
            $entries = ldap_get_entries($ldapconn, $search_result);
            $count = $entries['count'];
            for ($i = 0; $i < $count; $i++) {
                $username = $entries[$i]['samaccountname'][0] ?? '';
                if (!is_user_excluded($username, $config)) {
                    $all_users[] = $entries[$i];
                }
            }
            ldap_control_paged_result_response($ldapconn, $search_result, $cookie);
        } while ($cookie !== null && $cookie != '');
    } else {
        $search_result = ldap_search($ldapconn, $base_dn, $filter, $attrs);
        if ($search_result) {
            $entries = ldap_get_entries($ldapconn, $search_result);
            for ($i = 0; $i < $entries['count']; $i++) {
                $username = $entries[$i]['samaccountname'][0] ?? '';
                if (!is_user_excluded($username, $config)) {
                    $all_users[] = $entries[$i];
                }
            }
        }
    }
    
    // Aplicar filtro de OU
    $all_users = filter_by_ou($all_users, $ou_filter, $config);
    
    // Ordenar
    $all_users = sort_users($all_users, $sort_by, $sort_order);
    
    $total = count($all_users);
    $start = ($page - 1) * $perpage;
    $page_users = array_slice($all_users, $start, $perpage);
    
    return [
        'users' => $page_users,
        'total' => $total,
        'page' => $page,
        'perpage' => $perpage
    ];
}

function get_all_users_filtered($ldapconn, $base_dn, $search = '', $ou_filter = '', $sort_by = '', $sort_order = 'asc') {
    $config = get_config();
    $exclude_list = $config['exclude_users'] ?? [];
    $filter = '(&(objectClass=user)(!(objectClass=computer)))';
    if (!empty($search)) {
        $search_escaped = ldap_escape($search, '', LDAP_ESCAPE_FILTER);
        $filter = '(&(objectClass=user)(!(objectClass=computer))(|(cn=*' . $search_escaped . '*)(givenName=*' . $search_escaped . '*)(sn=*' . $search_escaped . '*)))';
    }
    $attrs = ['cn', 'givenName', 'sn', 'distinguishedName', 'sAMAccountName'];
    $search_result = ldap_search($ldapconn, $base_dn, $filter, $attrs);
    if (!$search_result) return [];
    $entries = ldap_get_entries($ldapconn, $search_result);
    $users = [];
    for ($i = 0; $i < $entries['count']; $i++) {
        $username = $entries[$i]['samaccountname'][0] ?? '';
        if (!is_user_excluded($username, $config)) {
            $users[] = $entries[$i];
        }
    }
    $users = filter_by_ou($users, $ou_filter, $config);
    $users = sort_users($users, $sort_by, $sort_order);
    return $users;
}

function user_in_security_ou($username, $config) {
    $admin_result = ldap_connect_samba($config);
    if (!$admin_result['success']) return false;
    $admin_conn = $admin_result['conn'];
    $search = ldap_search($admin_conn, $config['ldap']['base_dn'], "(&(objectClass=user)(sAMAccountName=$username))", ['distinguishedName']);
    if (!$search) {
        ldap_unbind($admin_conn);
        return false;
    }
    $entries = ldap_get_entries($admin_conn, $search);
    if ($entries['count'] == 0) {
        ldap_unbind($admin_conn);
        return false;
    }
    $user_dn = $entries[0]['distinguishedname'][0];
    $security_ou = $config['security_ou']['name'] ?? '';
    if (empty($security_ou)) {
        ldap_unbind($admin_conn);
        return false;
    }
    $pattern = '/OU=' . preg_quote($security_ou, '/') . '/i';
    $result = preg_match($pattern, $user_dn);
    ldap_unbind($admin_conn);
    return $result === 1;
}

// ==================== SSH Samba ====================
function exec_ssh_samba($command, $config) {
    if (!function_exists('ssh2_connect')) {
        return ['success' => false, 'error' => 'Extensión SSH2 no instalada'];
    }
    $connection = @ssh2_connect($config['samba_ssh']['host'], $config['samba_ssh']['port']);
    if (!$connection) return ['success' => false, 'error' => 'Conexión SSH a Samba fallida'];
    
    if (!empty($config['samba_ssh']['private_key_path']) && file_exists($config['samba_ssh']['private_key_path'])) {
        $auth = ssh2_auth_pubkey_file($connection, $config['samba_ssh']['user'], 
            $config['samba_ssh']['private_key_path'] . '.pub', 
            $config['samba_ssh']['private_key_path']);
    } else {
        $auth = ssh2_auth_password($connection, $config['samba_ssh']['user'], $config['samba_ssh']['password']);
    }
    if (!$auth) return ['success' => false, 'error' => 'Autenticación SSH a Samba fallida'];
    
    if ($config['samba_ssh']['sudo_to_user'] !== 'root') {
        $full_command = "sudo -u " . escapeshellarg($config['samba_ssh']['sudo_to_user']) . " " . $command;
    } else {
        $full_command = $command;
    }
    
    $stream = ssh2_exec($connection, $full_command);
    stream_set_blocking($stream, true);
    $output = stream_get_contents($stream);
    $error_stream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
    stream_set_blocking($error_stream, true);
    $error_output = stream_get_contents($error_stream);
    fclose($stream);
    fclose($error_stream);
    
    error_log("SSH Samba command: $full_command");
    error_log("STDOUT: $output");
    error_log("STDERR: $error_output");
    
    $error_lower = strtolower($error_output);
    $output_lower = strtolower($output);
    if (strpos($error_lower, 'error') !== false || 
        strpos($output_lower, 'error') !== false ||
        strpos($error_lower, 'failed') !== false ||
        strpos($output_lower, 'failed') !== false ||
        strpos($error_lower, 'cannot') !== false) {
        return ['success' => false, 'error' => trim($error_output ?: $output)];
    }
    return ['success' => true, 'output' => $output, 'error' => $error_output];
}

function extract_ou_path($ou_dn, $base_dn) {
    $base_dn_lower = strtolower($base_dn);
    $ou_dn_lower = strtolower($ou_dn);
    if (substr($ou_dn_lower, -strlen($base_dn_lower)) == $base_dn_lower) {
        $relative = substr($ou_dn, 0, -strlen($base_dn));
        $relative = rtrim($relative, ',');
        return $relative;
    }
    return $ou_dn;
}

function samba_create_user($username, $password, $givenname, $sn, $ou_dn, $config) {
    $ou_path = extract_ou_path($ou_dn, $config['ldap']['base_dn']);
    $cmd = "samba-tool user create " . escapeshellarg($username) . " " . escapeshellarg($password) .
           " --given-name=" . escapeshellarg($givenname) .
           " --surname=" . escapeshellarg($sn) .
           " --userou=" . escapeshellarg($ou_path);
    return exec_ssh_samba($cmd, $config);
}

function samba_delete_user($username, $config) {
    $cmd = "samba-tool user delete " . escapeshellarg($username);
    return exec_ssh_samba($cmd, $config);
}

function samba_set_password($username, $newpassword, $config) {
    $cmd = "samba-tool user setpassword " . escapeshellarg($username) . " --newpassword=" . escapeshellarg($newpassword);
    return exec_ssh_samba($cmd, $config);
}

function samba_enable_user($username, $config) {
    $cmd = "samba-tool user enable " . escapeshellarg($username);
    return exec_ssh_samba($cmd, $config);
}

function samba_disable_user($username, $config) {
    $cmd = "samba-tool user disable " . escapeshellarg($username);
    return exec_ssh_samba($cmd, $config);
}

function samba_add_user_to_groups($username, $groups_dn, $config) {
    $errors = [];
    $ignored_groups = ['Domain Users'];
    foreach ($groups_dn as $group_dn) {
        if (preg_match('/^CN=([^,]+)/', $group_dn, $matches)) {
            $group_cn = $matches[1];
            if (in_array($group_cn, $ignored_groups)) continue;
            $cmd = "samba-tool group addmembers " . escapeshellarg($group_cn) . " " . escapeshellarg($username);
            $result = exec_ssh_samba($cmd, $config);
            if (!$result['success']) {
                if (strpos($result['error'], 'already set via primaryGroupID') !== false) continue;
                $errors[] = "Grupo $group_cn: " . $result['error'];
            }
        }
    }
    if (empty($errors)) return ['success' => true];
    return ['success' => false, 'errors' => $errors];
}

// ==================== SSH Carbonio ====================
function exec_ssh_carbonio($command, $config) {
    if (!function_exists('ssh2_connect')) {
        return ['success' => false, 'error' => 'Extensión SSH2 no instalada'];
    }
    $connection = @ssh2_connect($config['carbonio']['host'], $config['carbonio']['port']);
    if (!$connection) return ['success' => false, 'error' => 'Conexión SSH fallida'];
    
    if (!empty($config['carbonio']['private_key_path']) && file_exists($config['carbonio']['private_key_path'])) {
        $auth = ssh2_auth_pubkey_file($connection, $config['carbonio']['user'], 
            $config['carbonio']['private_key_path'] . '.pub', 
            $config['carbonio']['private_key_path']);
    } else {
        $auth = ssh2_auth_password($connection, $config['carbonio']['user'], $config['carbonio']['password']);
    }
    if (!$auth) return ['success' => false, 'error' => 'Autenticación SSH fallida'];
    
    $full_command = "su - " . escapeshellarg($config['carbonio']['sudo_to_user']) . " -c " . escapeshellarg($command);
    $stream = ssh2_exec($connection, $full_command);
    stream_set_blocking($stream, true);
    $output = stream_get_contents($stream);
    $error_stream = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
    stream_set_blocking($error_stream, true);
    $error_output = stream_get_contents($error_stream);
    fclose($stream);
    fclose($error_stream);
    
    error_log("SSH Carbonio command: $full_command");
    error_log("STDOUT: $output");
    error_log("STDERR: $error_output");
    
    $error_lower = strtolower($error_output);
    $output_lower = strtolower($output);
    if (strpos($error_lower, 'error') !== false || 
        strpos($output_lower, 'error') !== false ||
        strpos($error_lower, 'failed') !== false) {
        return ['success' => false, 'error' => trim($error_output ?: $output)];
    }
    return ['success' => true, 'output' => $output, 'error' => $error_output];
}

function create_carbonio_mailbox($username, $givenname, $sn, $displayname, $config) {
    $create_cmd = sprintf($config['carbonio']['create_account_cmd'], $username);
    $result1 = exec_ssh_carbonio($create_cmd, $config);
    if (!$result1['success']) return ['success' => false, 'step' => 'create', 'error' => $result1['error']];
    sleep($config['carbonio']['delay_seconds']);
    $set_given = sprintf($config['carbonio']['set_givenname_cmd'], $username, $givenname);
    $result2 = exec_ssh_carbonio($set_given, $config);
    if (!$result2['success']) return ['success' => false, 'step' => 'givenname', 'error' => $result2['error']];
    $set_sn = sprintf($config['carbonio']['set_sn_cmd'], $username, $sn);
    $result3 = exec_ssh_carbonio($set_sn, $config);
    if (!$result3['success']) return ['success' => false, 'step' => 'sn', 'error' => $result3['error']];
    $set_display = sprintf($config['carbonio']['set_displayname_cmd'], $username, $displayname);
    $result4 = exec_ssh_carbonio($set_display, $config);
    if (!$result4['success']) return ['success' => false, 'step' => 'displayname', 'error' => $result4['error']];
    return ['success' => true];
}

function delete_carbonio_mailbox($username, $config) {
    $delete_cmd = sprintf($config['carbonio']['delete_account_cmd'], $username);
    return exec_ssh_carbonio($delete_cmd, $config);
}
function get_footer() {
    $config = get_config();
    $version = $config['app']['version'] ?? '1.0';
    return '<footer class="text-center mt-4 py-3 text-muted border-top">
                <small>Versión: ' . htmlspecialchars($version) . ' | Desarrollado por: <a href="https://networldcu.com" target="_blank">NETWORLD</a></small>
            </footer>';
}
?>