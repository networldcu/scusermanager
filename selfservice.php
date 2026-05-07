<?php
require_once 'functions.php';
$config = get_config();
if (!isset($_SESSION['auth']) || $_SESSION['role'] !== 'user') {
    header('Location: index.php');
    exit;
}

$message = '';
$error = '';
$user_fullname = '';
$username = $_SESSION['username'];

// Obtener nombre y apellidos del usuario desde LDAP (usando administrador)
$admin_result = ldap_connect_samba($config);
if ($admin_result['success']) {
    $admin_conn = $admin_result['conn'];
    $search = ldap_search($admin_conn, $config['ldap']['base_dn'], "(sAMAccountName=$username)", ['givenName', 'sn', 'displayName']);
    if ($search) {
        $entries = ldap_get_entries($admin_conn, $search);
        if ($entries['count'] > 0) {
            $given = $entries[0]['givenname'][0] ?? '';
            $sn = $entries[0]['sn'][0] ?? '';
            $user_fullname = trim($given . ' ' . $sn);
            if (empty($user_fullname)) $user_fullname = $entries[0]['displayname'][0] ?? $username;
        }
    }
    ldap_unbind($admin_conn);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = $_POST['current_password'];
    $new = $_POST['new_password'];
    $confirm = $_POST['confirm_password'];
    
    // Validación de complejidad
    $complexity_ok = true;
    if (strlen($new) < 8) {
        $error = "La nueva contraseña debe tener al menos 8 caracteres.";
        $complexity_ok = false;
    } elseif (!preg_match('/[0-9]/', $new)) {
        $error = "La nueva contraseña debe contener al menos un número.";
        $complexity_ok = false;
    } elseif (!preg_match('/[^A-Za-z0-9]/', $new)) {
        $error = "La nueva contraseña debe contener al menos un carácter especial (por ejemplo, !@#$%^&*).";
        $complexity_ok = false;
    } elseif ($new !== $confirm) {
        $error = "Las contraseñas nuevas no coinciden.";
        $complexity_ok = false;
    }
    
    if ($complexity_ok) {
        // 1. Validar contraseña actual con bind LDAP
        $ldapconn = ldap_connect($config['ldap']['host'], $config['ldap']['port']);
        if (!$ldapconn) {
            $error = "No se pudo conectar al servidor LDAP.";
        } else {
            ldap_set_option($ldapconn, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldapconn, LDAP_OPT_NETWORK_TIMEOUT, 10);
            $domain_parts = explode(',', $config['ldap']['base_dn']);
            $domain_fqdn = '';
            foreach ($domain_parts as $part) {
                if (stripos($part, 'dc=') === 0) {
                    $domain_fqdn .= substr($part, 3) . '.';
                }
            }
            $domain_fqdn = rtrim($domain_fqdn, '.');
            $user_upn = $username . '@' . $domain_fqdn;
            $bind = @ldap_bind($ldapconn, $user_upn, $current);
            if (!$bind) {
                $error = "Contraseña actual incorrecta.";
            } else {
                // 2. Cambiar contraseña usando samba-tool vía SSH
                $result = samba_set_password($username, $new, $config);
                if ($result['success']) {
                    $message = "Contraseña actualizada correctamente.";
                } else {
                    $error = "Error al cambiar la contraseña: " . $result['error'];
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
    <title>Cambiar contraseña - <?php echo htmlspecialchars($config['app']['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        .help-icon { margin-left: 5px; color: #6c757d; cursor: help; }
    </style>
</head>
<body>
<nav class="navbar navbar-dark bg-dark">
    <div class="container-fluid">
        <span class="navbar-brand"><?php echo htmlspecialchars($config['app']['name']); ?> - Cambio de contraseña</span>
        <a href="logout.php" class="btn btn-outline-light">Cerrar sesión</a>
    </div>
</nav>
<div class="container mt-5" style="max-width: 550px;">
    <div class="card shadow">
        <div class="card-header bg-info text-white">
            <h4 class="mb-0">Cambiar su contraseña</h4>
        </div>
        <div class="card-body">
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="mb-3">
                <label class="form-label">Nombre completo</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_fullname); ?>" disabled>
            </div>
            <div class="mb-3">
                <label class="form-label">Nombre de usuario</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($username); ?>" disabled>
            </div>
            
            <form method="post">
                <div class="mb-3">
                    <label for="current_password" class="form-label">Contraseña actual</label>
                    <i class="bi bi-question-circle help-icon" data-bs-toggle="tooltip" title="Escriba su contraseña actual"></i>
                    <input type="password" name="current_password" id="current_password" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label for="new_password" class="form-label">Nueva contraseña</label>
                    <i class="bi bi-question-circle help-icon" data-bs-toggle="tooltip" title="Nueva contraseña para su cuenta. Debe tener al menos 8 caracteres, incluir un número y un carácter especial."></i>
                    <input type="password" name="new_password" id="new_password" class="form-control" required>
                    <small class="text-muted">Requisitos: mínimo 8 caracteres, al menos un número y un carácter especial (ej. !@#$%^&*).</small>
                </div>
                <div class="mb-3">
                    <label for="confirm_password" class="form-label">Confirmar nueva contraseña</label>
                    <i class="bi bi-question-circle help-icon" data-bs-toggle="tooltip" title="Escriba nuevamente la misma contraseña"></i>
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Cambiar contraseña</button>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
</script>
<?php echo get_footer(); ?>
</body>
</html>