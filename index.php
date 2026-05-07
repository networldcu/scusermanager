<?php
set_time_limit(120);
require_once 'functions.php';
$config = get_config();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    if (empty($username) || empty($password)) {
        $error = 'Debe ingresar usuario y contraseña.';
    } else {
        $ldapconn = ldap_connect($config['ldap']['host'], $config['ldap']['port']);
        if (!$ldapconn) {
            $error = 'No se pudo conectar al servidor LDAP.';
        } else {
            ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldapconn, LDAP_OPT_REFERRALS, 0);
            ldap_set_option($ldapconn, LDAP_OPT_NETWORK_TIMEOUT, 10);
            ldap_set_option($ldapconn, LDAP_OPT_TIMEOUT, 10);
            
            $domain_parts = explode(',', $config['ldap']['base_dn']);
            $domain_fqdn = '';
            foreach ($domain_parts as $part) {
                if (stripos($part, 'dc=') === 0) {
                    $domain_fqdn .= substr($part, 3) . '.';
                }
            }
            $domain_fqdn = rtrim($domain_fqdn, '.');
            $user_upn = $username . '@' . $domain_fqdn;
            
            $bind = @ldap_bind($ldapconn, $user_upn, $password);
            if (!$bind) {
                $admin_result = ldap_connect_samba($config);
                if ($admin_result['success']) {
                    $admin_conn = $admin_result['conn'];
                    $search = ldap_search($admin_conn, $config['ldap']['base_dn'], "(&(objectClass=user)(sAMAccountName=$username))", ['userAccountControl']);
                    if ($search) {
                        $entries = ldap_get_entries($admin_conn, $search);
                        if ($entries['count'] > 0) {
                            $uac = $entries[0]['useraccountcontrol'][0] ?? 512;
                            $disabled = ($uac & 2) != 0;
                            if ($disabled) {
                                $error = 'Su cuenta está bloqueada, contacte con el administrador de red para desbloquearla.';
                            } else {
                                $error = 'Credenciales inválidas o usuario no existe.';
                            }
                        } else {
                            $error = 'Credenciales inválidas o usuario no existe.';
                        }
                    } else {
                        $error = 'Credenciales inválidas o usuario no existe.';
                    }
                    ldap_unbind($admin_conn);
                } else {
                    $error = 'Credenciales inválidas o usuario no existe.';
                }
            } else {
                $search_filter = "(&(objectClass=user)(sAMAccountName=$username))";
                $search = ldap_search($ldapconn, $config['ldap']['base_dn'], $search_filter, ['memberOf', 'distinguishedName']);
                if (!$search) {
                    $error = 'Error al buscar usuario.';
                } else {
                    $entries = ldap_get_entries($ldapconn, $search);
                    if ($entries['count'] == 0) {
                        $error = 'No se encontró el usuario.';
                    } else {
                        $user_dn = $entries[0]['distinguishedname'][0];
                        $memberOf = [];
                        if (isset($entries[0]['memberof'])) {
                            for ($i = 0; $i < $entries[0]['memberof']['count']; $i++) {
                                $memberOf[] = $entries[0]['memberof'][$i];
                            }
                        }
                        $admin_group_dn = $config['ldap']['domain_admins_group'];
                        $admin_group_dn_lower = strtolower($admin_group_dn);
                        $is_admin = false;
                        foreach ($memberOf as $group_dn) {
                            if (strtolower($group_dn) === $admin_group_dn_lower) {
                                $is_admin = true;
                                break;
                            }
                        }
                        if (!$is_admin) {
                            $group_search = ldap_search($ldapconn, $config['ldap']['base_dn'], "(&(objectClass=group)(cn=Domain Admins)(member=$user_dn))", ['cn']);
                            $group_entries = ldap_get_entries($ldapconn, $group_search);
                            if ($group_entries['count'] > 0) {
                                $is_admin = true;
                            }
                        }
                        
                        $is_security = false;
                        if (!$is_admin) {
                            $is_security = user_in_security_ou($username, $config);
                        }
                        
                        $_SESSION['username'] = $username;
                        $_SESSION['auth'] = true;
                        $_SESSION['user_dn'] = $user_dn;
                        
                        if ($is_admin) {
                            $_SESSION['role'] = 'admin';
                            header('Location: admin.php');
                        } elseif ($is_security) {
                            $_SESSION['role'] = 'security';
                            header('Location: security.php');
                        } else {
                            $_SESSION['role'] = 'user';
                            header('Location: selfservice.php');
                        }
                        exit;
                    }
                }
            }
            ldap_unbind($ldapconn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($config['app']['name']); ?> - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            margin: 0;
            padding: 20px;
        }
        .login-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 450px;
            margin: 20px 0;
        }
        .card-header {
            background: #2c3e50;
            color: white;
            border-radius: 15px 15px 0 0 !important;
            text-align: center;
            font-weight: bold;
        }
        .btn-primary {
            background: #2c3e50;
            border: none;
        }
        .btn-primary:hover {
            background: #1a252f;
        }
        footer {
            text-align: center;
            padding: 15px;
            color: rgba(255,255,255,0.7);
            font-size: 12px;
        }
        footer a {
            color: #ffc107;
            text-decoration: none;
        }
        footer a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="card">
            <div class="card-header py-3">
                <h3 class="mb-0"><?php echo htmlspecialchars($config['app']['name']); ?></h3>
            </div>
            <div class="card-body p-4">
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <label for="username" class="form-label">Nombre de usuario</label>
                        <input type="text" class="form-control" id="username" name="username" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Contraseña</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 py-2">Iniciar sesión</button>
                </form>
            </div>
        </div>
    </div>
    <?php echo get_footer(); ?>
</body>
</html>