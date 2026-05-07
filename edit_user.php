<?php
require_once 'functions.php';
$config = get_config();
if (!isset($_SESSION['auth']) || $_SESSION['role'] !== 'admin') {
    header('Location: index.php');
    exit;
}

$username = $_POST['username'];
$givenname = $_POST['givenname'];
$sn = $_POST['sn'];
$newpassword = $_POST['newpassword'];
$enable = isset($_POST['enable_account']) ? true : false;

$errors = [];
$messages = [];

// Conectar LDAP para modificar givenName y sn
$ldap_result = ldap_connect_samba($config);
if (!$ldap_result['success']) {
    die("Error de conexión LDAP: " . $ldap_result['error']);
}
$ldapconn = $ldap_result['conn'];

// Buscar DN del usuario
$search = ldap_search($ldapconn, $config['ldap']['base_dn'], "(sAMAccountName=$username)", ['distinguishedName']);
$entries = ldap_get_entries($ldapconn, $search);
if ($entries['count'] == 0) {
    die("Usuario no encontrado en LDAP");
}
$user_dn = $entries[0]['distinguishedname'][0];

// Modificar givenName y sn mediante LDAP
$mod = [];
if (!empty($givenname)) $mod['givenName'] = $givenname;
if (!empty($sn)) $mod['sn'] = $sn;
if (!empty($mod)) {
    if (ldap_modify($ldapconn, $user_dn, $mod)) {
        $messages[] = "Nombre y apellidos actualizados.";
    } else {
        $errors[] = "Error al modificar nombre/apellidos: " . ldap_error($ldapconn);
    }
}

// Cambiar contraseña con samba-tool
if (!empty($newpassword)) {
    $result = samba_set_password($username, $newpassword, $config);
    if ($result['success']) {
        $messages[] = "Contraseña actualizada.";
    } else {
        $errors[] = "Error al cambiar contraseña: " . $result['error'];
    }
}

// Habilitar cuenta si se solicitó
if ($enable) {
    $result = samba_enable_user($username, $config);
    if ($result['success']) {
        $messages[] = "Cuenta habilitada.";
    } else {
        $errors[] = "Error al habilitar cuenta: " . $result['error'];
    }
}

if (!empty($errors)) {
    $_SESSION['error'] = implode(" ", $errors);
} else {
    $_SESSION['message'] = implode(" ", $messages);
}
header('Location: admin.php');
exit;