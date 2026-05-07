# Instalación de SCUserManager

Guía paso a paso para instalar y configurar **SCUserManager** en un servidor web con **Ubuntu 20.04/22.04/24.04**, **Nginx** y **PHP-FPM**.

---

## 📋 Requisitos previos

Antes de comenzar, asegúrate de tener:

- Un servidor **Samba 4** funcionando como Controlador de Dominio (Active Directory)
- Un servidor **Zextras Carbonio CE** configurado y autenticando contra Samba 4
- Acceso SSH (con contraseña o clave pública) desde el servidor web hacia ambos servidores
- Usuario administrador de Samba 4 con permisos para crear/eliminar usuarios y grupos
- Usuario con permisos para ejecutar comandos `carbonio prov` en el servidor Carbonio (usuario `zextras`)

### Requisitos del servidor web

| Componente | Requisito |
|------------|-----------|
| **Sistema Operativo** | Ubuntu 20.04 / 22.04 / 24.04 |
| **PHP** | 7.4 o superior (recomendado 8.1+) |
| **Extensiones PHP** | `ldap`, `ssh2`, `json`, `session` |
| **Servidor web** | Nginx (o Apache) |
| **Acceso de red** | Puertos 389 (LDAP) y 22 (SSH) abiertos |
| **Conexión a internet** | Para descargar Bootstrap y dependencias |

---

## 1️⃣ Instalar PHP y extensiones

### Para Ubuntu 20.04 (PHP 7.4)

```bash
sudo apt update
sudo apt install -y nginx php7.4-fpm php7.4-cli php7.4-ldap php7.4-ssh2 php7.4-json

Para Ubuntu 22.04/24.04 (PHP 8.1)
bash

sudo apt update
sudo apt install -y nginx php8.1-fpm php8.1-cli php8.1-ldap php8.1-ssh2 php8.1-json

Verificar extensiones
bash

php -m | grep -E "ldap|ssh2"

Debe mostrar ldap y ssh2.
Configurar PHP (evitar timeouts)
Si usas PHP 7.4:
bash

sudo nano /etc/php/7.4/fpm/php.ini

Si usas PHP 8.1:
bash

sudo nano /etc/php/8.1/fpm/php.ini

Modifica las siguientes líneas:
ini

max_execution_time = 300
max_input_time = 300
memory_limit = 256M

Reinicia PHP-FPM:
bash

# Para PHP 7.4
sudo systemctl restart php7.4-fpm

# Para PHP 8.1
sudo systemctl restart php8.1-fpm

2️⃣ Configurar Nginx
Crear configuración del sitio
bash

sudo nano /etc/nginx/sites-available/scusermanager

Pega el siguiente contenido (cambia server_name por tu IP o dominio):
nginx

server {
    listen 80;
    listen [::]:80;

    server_name gestion.tudominio.com;

    root /var/www/html/SCUserManager;
    index index.php;

    location ~ \.(json|ini|conf|log|sh|sql|bak)$ {
        deny all;
        return 403;
    }

    location ~ /\. {
        deny all;
        return 404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300s;
    }

    location ~* \.(css|js|jpg|jpeg|png|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        log_not_found off;
    }

    location / {
        try_files $uri $uri/ =404;
    }
}

    Nota: Si usas PHP 7.4, cambia php8.1-fpm.sock por php7.4-fpm.sock

Activar el sitio
bash

sudo ln -s /etc/nginx/sites-available/scusermanager /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx

3️⃣ Descargar SCUserManager
Clonar desde GitHub (recomendado)
bash

cd /var/www/html
sudo git clone https://github.com/TUUSUARIO/SCUserManager.git

O copiar manualmente los archivos
bash

sudo mkdir -p /var/www/html/SCUserManager
# Copia tus archivos aquí (usando scp, sftp o rsync)

Establecer permisos
bash

sudo chown -R www-data:www-data /var/www/html/SCUserManager
sudo chmod -R 755 /var/www/html/SCUserManager

4️⃣ Configurar SCUserManager
Crear archivo de configuración real
bash

cd /var/www/html/SCUserManager
sudo cp config.json.example config.json
sudo chmod 600 config.json
sudo chown www-data:www-data config.json

Editar config.json con tus datos
bash

sudo nano config.json

Configuración LDAP (Samba4)
json

"ldap": {
    "host": "192.168.1.2",
    "port": 389,
    "use_tls": false,
    "base_dn": "dc=midominio,dc=local",
    "admin_dn": "cn=Administrator,cn=Users,dc=midominio,dc=local",
    "admin_password": "tu_contraseña",
    "domain_admins_group": "CN=Domain Admins,CN=Users,dc=midominio,dc=local"
}

Configuración SSH para Samba4
json

"samba_ssh": {
    "host": "192.168.1.2",
    "port": 22,
    "user": "root",
    "password": "tu_contraseña_ssh",
    "private_key_path": "",
    "sudo_to_user": "root"
}

Configuración SSH para Carbonio
json

"carbonio": {
    "host": "192.168.1.4",
    "port": 22,
    "user": "root",
    "password": "tu_contraseña_ssh",
    "private_key_path": "",
    "sudo_to_user": "zextras",
    "create_account_cmd": "carbonio prov ca \"%s@midominio.local\" \"\"",
    "set_givenname_cmd": "carbonio prov ma \"%s@midominio.local\" givenName \"%s\"",
    "set_sn_cmd": "carbonio prov ma \"%s@midominio.local\" sn \"%s\"",
    "set_displayname_cmd": "carbonio prov ma \"%s@midominio.local\" displayName \"%s\"",
    "delete_account_cmd": "carbonio prov da \"%s@midominio.local\"",
    "delay_seconds": 2
}

Exclusión de usuarios (opcional)
json

"exclude_users": [
    "sistema_bot",
    "servicio_antivirus"
]

Unidad Organizativa para seguridad
json

"security_ou": {
    "name": "Seguridad informática"
}

Grupos a mostrar (lista blanca)
json

"group_filter": {
    "include_list": [
        "Domain Admins",
        "Domain Users",
        "Administrators",
        "Correo"
    ]
}

Configuración general
json

"app": {
    "name": "SCUserManager - Gestión de usuarios Samba + Carbonio",
    "version": "1.2",
    "session_lifetime": 3600,
    "login_attempts_limit": 5
}

5️⃣ Configurar autenticación SSH por clave (recomendado)
Generar clave SSH en el servidor web
bash

sudo mkdir -p /var/www/.ssh
sudo chown www-data:www-data /var/www/.ssh
sudo chmod 700 /var/www/.ssh
sudo -u www-data ssh-keygen -t rsa -b 4096 -f /var/www/.ssh/id_rsa -N ""

Copiar clave pública a Samba4
bash

sudo cat /var/www/.ssh/id_rsa.pub

Copia la salida y agrégala a /root/.ssh/authorized_keys en el servidor Samba4.

Repite el proceso para el servidor Carbonio.
Actualizar config.json para usar la clave
json

"samba_ssh": {
    "private_key_path": "/var/www/.ssh/id_rsa",
    "password": ""
}

6️⃣ Probar la instalación

    Abre tu navegador y ve a http://gestion.tudominio.com (o la IP del servidor web)

    Inicia sesión con un usuario de dominio que pertenezca a:

        Domain Admins → panel de administración completo

        Seguridad informática (definida en security_ou) → panel de solo lectura + cambio de contraseña

        Cualquier otro usuario → solo cambio de contraseña

    Prueba las funcionalidades: listar usuarios, crear, editar, eliminar, exportar.

7️⃣ Solución de problemas comunes
Error 500 al iniciar sesión o cambiar contraseña

    Verifica que las credenciales LDAP en config.json sean correctas.

    Prueba manualmente la conexión LDAP:
    bash

    ldapsearch -H ldap://IP_SAMBA -D "administrator@tudominio" -w "contraseña" -b "dc=ejemplo,dc=local"

Error de conexión SSH

    Verifica que el servidor web tenga acceso SSH a Samba4 y Carbonio:
    bash

    sudo -u www-data ssh -i /var/www/.ssh/id_rsa root@IP_SAMBA "samba-tool user list"

    Revisa que el puerto 22 esté abierto y que la autenticación funcione.

Timeout 504 Gateway

    Aumenta fastcgi_read_timeout en Nginx a 300s.

    Aumenta max_execution_time en php.ini.

Los grupos no se muestran

    Verifica include_list en group_filter dentro de config.json.

    Si está vacío, se muestran todos los grupos.

Los filtros por Unidad Organizativa no funcionan

    Verifica que los nombres de las OU en config.json coincidan exactamente con los del directorio.

    Ejecuta samba-tool ou list en el servidor Samba4 para ver los nombres correctos.

8️⃣ Actualizar SCUserManager
bash

cd /var/www/html/SCUserManager
sudo git pull
sudo systemctl reload nginx

Si instalaste manualmente, descarga la nueva versión y reemplaza los archivos.
9️⃣ Seguridad recomendada

    Proteger config.json:
    bash

    sudo chmod 600 /var/www/html/SCUserManager/config.json

    Usar HTTPS (certificado SSL) si el servidor es accesible desde internet.

    Restringir acceso por IP en Nginx para entornos LAN controlados.

    Auditar logs de PHP y SSH:
    bash

    sudo tail -f /var/log/nginx/error.log
    sudo tail -f /var/log/php8.1-fpm.log

📞 Soporte

    Documentación adicional: https://github.com/TUUSUARIO/SCUserManager

    Reportar errores: Abrir un "Issue" en GitHub

    Desarrollado por: NETWORLD – https://networldcu.com

📄 Licencia

Código abierto. Puedes usarlo, modificarlo y distribuirlo libremente manteniendo los créditos originales.
