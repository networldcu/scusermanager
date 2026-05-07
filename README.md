# SCUserManager - Samba + Carbonio User Manager

**SCUserManager** es una aplicación web que permite administrar usuarios en un **dominio Samba 4 (Active Directory)** y sincronizarlos con **Zextras Carbonio CE** (servidor de correo), todo desde una interfaz amigable.

## ✨ Características

- Gestión completa de usuarios (crear, editar, eliminar, habilitar/bloquear)
- Asignación a Unidades Organizativas y Grupos
- Creación automática de buzón de correo en Carbonio CE
- Panel de autoservicio para cambio de contraseña
- Exportación de lista de usuarios a HTML/PDF con formato profesional
- Filtros, búsqueda y ordenamiento por columnas
- Exclusión de usuarios no deseados
- Totalmente configurable mediante `config.json`

## 🔧 Requisitos del servidor web

- PHP 7.4 o superior con extensiones: `ldap`, `ssh2`
- Servidor web Nginx o Apache
- Acceso SSH (con autenticación por clave o contraseña) a los servidores Samba4 y Carbonio CE

## 📦 Instalación rápida

1. Copie los archivos a su servidor web.
2. Copie `config.json.example` a `config.json` y edite con sus datos reales.
3. Asegure los permisos de `config.json` (600).
4. Acceda via web y comience a gestionar sus usuarios.

## 🌐 Demo y documentación

Próximamente en [https://networldcu.com](https://networldcu.com)

## 🧑‍💻 Desarrollado por

**NETWORLD** – [https://networldcu.com](https://networldcu.com)

## 📄 Licencia

Código abierto – uso libre con atribución.
